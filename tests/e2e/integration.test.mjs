// Integration tests against a real local WordPress (no browser).
// Run: node --test tests/e2e/integration.test.mjs   (server must be running)
import { test, before, after, describe } from 'node:test';
import assert from 'node:assert/strict';
import {
  PRIMARY, ALIASES, wp, setSettings, request, navigationUrls, canonicals, hreflangs, meta, isProtected, login, tags,
} from './lib.mjs';

const HOSTS = [PRIMARY, ...ALIASES];
let ids;
// Script modules (6.5+) and editable inline text (6.7+) are newer than the
// oldest supported WordPress version.
const WP_VERSION = wp('core', 'version');
const atLeast = v => WP_VERSION.localeCompare(v, undefined, { numeric: true }) >= 0;

before(() => {
  wp('plugin', 'activate', 'wp-domain-alias');
  ids = JSON.parse(wp('option', 'get', 'wpda_test_ids', '--format=json'));
  setSettings({});
});
after(() => setSettings({}));

function assertNavigationOn(html, host, base, label) {
  const urls = navigationUrls(html, base).filter(n => !isProtected(n.url));
  assert.ok(urls.length > 3, `${label}: expected several internal links, found ${urls.length}`);
  const wrong = urls.filter(n => n.url.hostname !== host).map(n => `${n.tag}[${n.attr}]=${n.url.href}`);
  assert.deepEqual(wrong, [], `${label}: links that left ${host}`);
}

describe('pages on every host', () => {
  for (const host of HOSTS) {
    for (const page of ['/', '/about/', '/contact/', '/category/news/', '/page/2/', '/test-post-3/']) {
      test(`${host}${page}`, async () => {
        const r = await request(host, page);
        assert.equal(r.status, 200, `HTTP ${r.status} location=${r.headers.location}`);
        assert.equal(r.headers.location, undefined);
        assert.match(r.headers['content-type'], /text\/html/);
        assertNavigationOn(r.body, host, `http://${host}${page}`, `${host}${page}`);
        const c = canonicals(r.body);
        if (['/about/', '/contact/', '/test-post-3/'].includes(page)) { // core prints canonicals on singular views only
          assert.equal(c.length, 1, `one canonical: ${c}`);
          assert.equal(new URL(c[0]).hostname, PRIMARY, 'canonical on primary');
          assert.equal(new URL(c[0]).pathname, page, 'canonical path matches');
        }
      });
    }
  }
});

describe('links, forms and endpoints on an alias', () => {
  let about;
  let contact;
  before(async () => {
    about = (await request('alias-one.test', '/about/')).body;
    contact = (await request('alias-one.test', '/contact/')).body;
  });
  const attr = (html, id, name) => tags(html).find(t => t.attrs.id === id)?.attrs[name];

  test('absolute internal link keeps query and fragment', () => {
    assert.equal(attr(about, 'abs-internal', 'href'), 'http://alias-one.test/contact/?utm_source=test&gclid=abc#form');
  });
  test('relative link untouched', () => assert.equal(attr(about, 'rel-internal', 'href'), '/contact/'));
  test('external link untouched', () => assert.equal(attr(about, 'external', 'href'), 'https://example.org/page/'));
  test('look-alike domain untouched', () => assert.equal(attr(about, 'lookalike', 'href'), 'https://primary.test.evil.example/'));
  test('admin link stays on primary', () => assert.equal(new URL(attr(about, 'admin-link', 'href')).hostname, PRIMARY));
  test('login link stays on primary', () => assert.equal(new URL(attr(about, 'login-link', 'href')).hostname, PRIMARY));
  test('form action', () => assert.equal(attr(contact, 'plain-form', 'action'), 'http://alias-one.test/contact/'));
  test('button formaction', () => assert.match(contact, /formaction="http:\/\/alias-one\.test\/about\/"/));
  test('clickable column', () => assert.equal(attr(contact, 'clickable', 'data-column-clickable'), 'http://alias-one.test/about/'));
  test('REST discovery link same-origin', () => {
    const l = tags(about).find(t => t.name === 'link' && t.attrs.rel === 'https://api.w.org/');
    assert.equal(new URL(l.attrs.href).hostname, 'alias-one.test');
  });
  test('script modules and import map same-origin', { skip: !atLeast('6.7') && 'needs WordPress 6.7+' }, () => {
    const map = about.match(/<script[^>]*type="importmap"[^>]*>([\s\S]*?)<\/script>/);
    assert.ok(map, 'import map present');
    for (const url of Object.values(JSON.parse(map[1]).imports)) assert.equal(new URL(url).hostname, 'alias-one.test');
  });
  test('theme web fonts same-origin', { skip: !atLeast('6.7') && 'needs WordPress 6.7+' }, () => {
    const fonts = [...about.matchAll(/url\('([^']+\.woff2)'\)/g)].map(m => new URL(m[1]).hostname);
    assert.ok(fonts.length > 0);
    assert.ok(fonts.every(h => h === 'alias-one.test'), fonts.join(','));
  });
  test('feeds and shortlink stay on primary', () => {
    const feeds = tags(about).filter(t => t.name === 'link' && /rss/.test(t.attrs.type || ''));
    assert.ok(feeds.length && feeds.every(t => new URL(t.attrs.href).hostname === PRIMARY));
  });
});

describe('status codes and redirects', () => {
  for (const host of ALIASES) {
    test(`${host}: 404 keeps the hostname`, async () => {
      const r = await request(host, '/no-such-page-xyz/');
      assert.equal(r.status, 404);
      assertNavigationOn(r.body, host, `http://${host}/`, '404');
    });
    test(`${host}: missing trailing slash normalised on the alias`, async () => {
      const r = await request(host, '/about?utm_source=qa&gclid=g1');
      assert.equal(r.status, 301);
      assert.equal(r.headers.location, `http://${host}/about/?utm_source=qa&gclid=g1`);
    });
    test(`${host}: ?p= / ?page_id= normalised on the alias`, async () => {
      const r = await request(host, `/?page_id=${ids.about}&utm_campaign=c`);
      assert.equal(r.status, 301);
      assert.equal(r.headers.location, `http://${host}/about/?utm_campaign=c`);
    });
    test(`${host}: admin goes to primary login`, async () => {
      const r = await request(host, '/wp-admin/');
      assert.equal(r.status, 302);
      assert.equal(new URL(r.headers.location).hostname, PRIMARY);
    });
    test(`${host}: login form posts to primary`, async () => {
      const r = await request(host, '/wp-login.php');
      assert.equal(r.status, 200);
      const form = tags(r.body).find(t => t.name === 'form' && t.attrs.id === 'loginform');
      assert.equal(new URL(form.attrs.action).hostname, PRIMARY);
    });
  }
  test('no redirect loop on any alias homepage', async () => {
    for (const host of ALIASES) {
      const r = await request(host, '/');
      assert.equal(r.status, 200);
    }
  });
});

describe('REST, AJAX, sitemap and robots', () => {
  test('REST API answers on an alias', async () => {
    const r = await request('alias-one.test', '/wp-json/');
    assert.equal(r.status, 200);
    assert.equal(JSON.parse(r.body).home, `http://${PRIMARY}`, 'REST data keeps the configured home URL');
  });
  test('REST post data keeps primary links (not rewritten)', async () => {
    const r = await request('alias-one.test', `/wp-json/wp/v2/pages/${ids.about}`);
    assert.equal(r.status, 200);
    assert.equal(new URL(JSON.parse(r.body).link).hostname, PRIMARY);
  });
  test('admin-ajax responds on an alias', async () => {
    const r = await request('alias-one.test', '/wp-admin/admin-ajax.php', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: 'action=wpda_unknown' });
    assert.equal(r.status, 400);
    assert.equal(r.body, '0');
  });
  for (const host of HOSTS) {
    test(`${host}: core sitemap lists primary URLs`, async () => {
      const index = await request(host, '/wp-sitemap.xml');
      assert.equal(index.status, 200);
      assert.match(index.headers['content-type'], /xml/);
      const sub = [...index.body.matchAll(/<loc>([^<]+)/g)].map(m => m[1]);
      assert.ok(sub.length > 0 && sub.every(u => new URL(u).hostname === PRIMARY), sub.join(' '));
      const pages = await request(host, new URL(sub[0]).pathname);
      const locs = [...pages.body.matchAll(/<loc>([^<]+)/g)].map(m => m[1]);
      assert.ok(locs.length > 0 && locs.every(u => new URL(u).hostname === PRIMARY));
    });
  }
  test('robots.txt sitemap line points to primary', async () => {
    const r = await request('alias-two.test', '/robots.txt');
    assert.match(r.body, /Sitemap: http:\/\/primary\.test\/wp-sitemap\.xml/);
  });
  test('feed is not rewritten', async () => {
    const r = await request('alias-one.test', '/feed/');
    assert.equal(r.status, 200);
    assert.ok(!r.body.includes('alias-one.test'));
  });
});

describe('host header safety', () => {
  for (const host of ['evil.test', 'alias-one.test.evil.test', 'alias-one.test:8443', 'alias-one.test.', '127.0.0.1', 'ALIAS-ONE.TEST']) {
    test(`Host: ${host}`, async () => {
      const r = await request(host, '/about/');
      const hosts = new Set(navigationUrls(r.body, 'http://primary.test/').filter(n => !isProtected(n.url)).map(n => n.url.hostname));
      if (host === 'ALIAS-ONE.TEST') {
        assert.deepEqual([...hosts], ['alias-one.test'], 'case-insensitive match');
      } else {
        assert.ok(!r.body.includes('evil.test"') && !r.body.includes(':8443'), 'unapproved host never appears in output');
        assert.ok(!hosts.has('alias-one.test'), 'unapproved host is not treated as an alias');
      }
    });
  }
  test('X-Forwarded-Host is ignored', async () => {
    const spoof = await request('primary.test', '/about/', { headers: { 'X-Forwarded-Host': 'evil.test' } });
    assert.ok(!spoof.body.includes('evil.test'), 'forwarded host never appears in output');
    const asAlias = await request('evil.test', '/about/', { headers: { 'X-Forwarded-Host': 'alias-one.test' } });
    const hosts = new Set(navigationUrls(asAlias.body, 'http://primary.test/').filter(n => !isProtected(n.url)).map(n => n.url.hostname));
    assert.ok(!hosts.has('alias-one.test'), 'forwarded alias name does not activate the alias');
  });
});

describe('settings change behaviour', () => {
  after(() => setSettings({}));
  test('disabled: aliases show primary links, no redirect', async () => {
    setSettings({ enabled: false });
    const r = await request('alias-one.test', '/about/');
    assert.equal(r.status, 200);
    const hosts = new Set(navigationUrls(r.body, 'http://alias-one.test/').filter(n => !isProtected(n.url)).map(n => n.url.hostname));
    assert.ok(hosts.has(PRIMARY) && !hosts.has('alias-one.test'));
  });
  test('removed alias is no longer preserved', async () => {
    setSettings({ aliases: ['alias-two.test'] });
    const r = await request('alias-one.test', '/about/');
    assert.ok(!navigationUrls(r.body, 'http://alias-one.test/').some(n => n.url.hostname === 'alias-one.test'));
  });
  test('alias canonical mode', async () => {
    setSettings({ canonical_mode: 'alias' });
    const r = await request('alias-two.test', '/about/');
    assert.deepEqual(canonicals(r.body), ['http://alias-two.test/about/']);
  });
  test('diagnostics header and comment', async () => {
    setSettings({ diagnostics: true });
    const r = await request('alias-one.test', '/about/');
    assert.equal(r.headers['x-wp-domain-alias'], 'alias-one.test');
    assert.match(r.body, /<!-- WP Domain Alias: \d+ URL\(s\) adjusted for alias-one\.test -->/);
    const p = await request(PRIMARY, '/about/');
    assert.equal(p.headers['x-wp-domain-alias'], undefined, 'primary unaffected');
  });
});

describe('admin screen (authenticated, primary host)', () => {
  let cookie;
  before(async () => {
    setSettings({ aliases: ['alias-one.test'] });
    cookie = await login();
  });
  after(() => setSettings({}));

  const page = () => request(PRIMARY, '/wp-admin/options-general.php?page=wp-domain-alias', { headers: { Cookie: cookie } });
  const nonce = (html, action) => {
    const form = html.split('<form').find(f => f.includes(`value="${action}"`));
    return form.match(/name="_wpnonce" value="([^"]+)"/)[1];
  };
  const post = (body) => request(PRIMARY, '/wp-admin/admin-post.php', {
    method: 'POST', headers: { Cookie: cookie, 'Content-Type': 'application/x-www-form-urlencoded' }, body: new URLSearchParams(body).toString(),
  });

  test('settings page renders for an administrator', async () => {
    const r = await page();
    assert.equal(r.status, 200);
    assert.match(r.body, /WP Domain Alias/);
    assert.match(r.body, /primary\.test/);
  });
  test('anonymous visitors are sent to login', async () => {
    const r = await request(PRIMARY, '/wp-admin/options-general.php?page=wp-domain-alias');
    assert.equal(r.status, 302);
    assert.match(r.headers.location, /wp-login\.php/);
  });
  test('add alias via form, with validation', async () => {
    let html = (await page()).body;
    let r = await post({ action: 'wpda_add_alias', _wpnonce: nonce(html, 'wpda_add_alias'), wpda_alias: 'https://Alias-Two.test/' });
    assert.equal(r.status, 302);
    html = (await page()).body;
    assert.match(html, /alias-two\.test was added/);
    assert.deepEqual(JSON.parse(wp('option', 'get', 'wp_domain_alias_settings', '--format=json')).aliases, ['alias-one.test', 'alias-two.test']);
    r = await post({ action: 'wpda_add_alias', _wpnonce: nonce(html, 'wpda_add_alias'), wpda_alias: '*.bad.test' });
    html = (await page()).body;
    assert.match(html, /Wildcard domains are not supported/);
  });
  test('forged request without nonce is refused', async () => {
    const r = await post({ action: 'wpda_add_alias', wpda_alias: 'forged.test' });
    assert.equal(r.status, 403);
    assert.ok(!wp('option', 'get', 'wp_domain_alias_settings', '--format=json').includes('forged.test'));
  });
  test('health check runs and reports per domain', async () => {
    setSettings({ aliases: ['alias-one.test', 'unresolvable.invalid'] });
    wp('transient', 'delete', 'wpda_hc_lock_1'); // per-user rate limit from an earlier run
    const html = (await page()).body;
    const r = await post({ action: 'wpda_health_check', _wpnonce: nonce(html, 'wpda_health_check'), url: 'http://127.0.0.1/' });
    assert.equal(r.status, 302);
    const result = (await page()).body;
    const section = result.slice(result.indexOf('id="wpda-health"'));
    assert.match(section, /<h3>alias-one\.test<\/h3>/);
    assert.match(section, /Internal links stay on this domain/);
    assert.match(section, /<h3>unresolvable\.invalid<\/h3>[\s\S]*?does not resolve, or it resolves to a private/);
    assert.ok(!/127\.0\.0\.1/.test(section), 'request input is not used as a destination');
  });
});
