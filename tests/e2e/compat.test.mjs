// Compatibility tests with real third-party plugins on the local site.
// Each group activates one plugin, runs its checks and deactivates it again.
// Run: node --test --test-concurrency=1 tests/e2e/compat.test.mjs
import { test, before, after, describe } from 'node:test';
import assert from 'node:assert/strict';
import path from 'node:path';
import {
  ROOT, PRIMARY, ALIASES, OWN, wp, setSettings, request, navigationUrls, canonicals, hreflangs, meta, jsonLd, isProtected, tags,
} from './lib.mjs';

const fixture = name => path.join(ROOT, 'tests/e2e/fixtures', name);
const ids = () => JSON.parse(wp('option', 'get', 'wpda_test_ids', '--format=json'));

function ownUrlsIn(value, out = []) {
  if (typeof value === 'string') {
    for (const m of value.matchAll(/https?:\/\/([a-z0-9.-]+)/gi)) if (OWN.includes(m[1].toLowerCase())) out.push(m[1].toLowerCase());
  } else if (value && typeof value === 'object') {
    for (const v of Object.values(value)) ownUrlsIn(v, out);
  }
  return out;
}

function assertNavigation(html, host) {
  const urls = navigationUrls(html, `http://${host}/`).filter(n => !isProtected(n.url));
  assert.ok(urls.length > 2, 'has internal links');
  assert.deepEqual(urls.filter(n => n.url.hostname !== host).map(n => n.url.href), []);
}

before(() => {
  wp('plugin', 'activate', 'wp-domain-alias');
  setSettings({});
});
after(() => setSettings({}));

for (const seo of [
  { slug: 'wordpress-seo', name: 'Yoast SEO' },
  { slug: 'seo-by-rank-math', name: 'Rank Math' },
]) {
  describe(seo.name, () => {
    before(() => {
      wp('plugin', 'activate', seo.slug);
      if (seo.slug === 'seo-by-rank-math') {
        // WP-CLI activation skips Rank Math's installer, and its frontend only
        // loads once the account step is skipped (the wizard's "Skip" button).
        wp('eval', 'do_action( "activate_seo-by-rank-math/rank-math.php", false );');
        wp('option', 'update', 'rank_math_registration_skip', '1');
      }
      wp('rewrite', 'flush');
    });
    after(() => { setSettings({}); wp('plugin', 'deactivate', seo.slug); wp('rewrite', 'flush'); });

    for (const host of [PRIMARY, ...ALIASES]) {
      test(`${host}: one primary canonical, primary og:url and structured data`, async () => {
        const r = await request(host, '/about/');
        assert.equal(r.status, 200);
        assert.deepEqual(canonicals(r.body), [`http://${PRIMARY}/about/`]);
        const og = meta(r.body, 'og:url');
        assert.ok(og.length <= 1 && og.every(u => new URL(u).hostname === PRIMARY), `og:url ${og}`);
        const schema = jsonLd(r.body);
        assert.ok(schema.length > 0, 'structured data present');
        const hosts = new Set(ownUrlsIn(schema));
        assert.deepEqual([...hosts], [PRIMARY], 'structured data uses the primary domain only');
        assertNavigation(r.body, host);
      });
    }
    test('a stored 0.1.0-beta "alias" canonical setting still yields one primary canonical and og:url', async () => {
      setSettings({ canonical_mode: 'alias' });
      const r = await request('alias-one.test', '/about/');
      assert.deepEqual(canonicals(r.body), [`http://${PRIMARY}/about/`]);
      assert.ok(meta(r.body, 'og:url').every(u => new URL(u).hostname === PRIMARY));
      setSettings({});
    });
    test('XML sitemap on an alias lists primary URLs', async () => {
      const index = await request('alias-two.test', '/sitemap_index.xml');
      assert.equal(index.status, 200, `HTTP ${index.status} ${index.headers.location || ''}`);
      const subs = [...index.body.matchAll(/<loc>([^<]+)/g)].map(m => m[1]);
      assert.ok(subs.length && subs.every(u => new URL(u).hostname === PRIMARY), subs.join(' '));
      const page = await request('alias-two.test', new URL(subs[0]).pathname);
      const locs = [...page.body.matchAll(/<loc>([^<]+)/g)].map(m => m[1]);
      assert.ok(locs.length && locs.every(u => new URL(u).hostname === PRIMARY));
    });
  });
}

describe('Polylang (multilingual, /es/ directory)', () => {
  before(() => {
    wp('plugin', 'activate', 'polylang');
    wp('eval-file', fixture('polylang.php'));
    wp('rewrite', 'flush');
  });
  after(() => { wp('plugin', 'deactivate', 'polylang'); wp('rewrite', 'flush'); });

  for (const host of [PRIMARY, ...ALIASES]) {
    test(`${host}/es/sobre-nosotros/: canonical and hreflang on primary, links on ${host}`, async () => {
      const r = await request(host, '/es/sobre-nosotros/');
      assert.equal(r.status, 200, `HTTP ${r.status} ${r.headers.location || ''}`);
      assert.deepEqual(canonicals(r.body), [`http://${PRIMARY}/es/sobre-nosotros/`]);
      const alt = hreflangs(r.body);
      assert.ok(alt.length >= 2, 'hreflang alternates present');
      assert.ok(alt.every(([, href]) => new URL(href).hostname === PRIMARY), JSON.stringify(alt));
      assertNavigation(r.body, host);
    });
    test(`${host}: language switcher stays on ${host}`, async () => {
      const r = await request(host, '/about/');
      const switcher = tags(r.body).filter(t => t.name === 'a' && t.attrs.hreflang);
      assert.ok(switcher.length >= 1, 'switcher links present');
      for (const t of switcher) assert.equal(new URL(t.attrs.href).hostname, host, t.attrs.href);
      const es = switcher.find(t => t.attrs.hreflang.startsWith('es'));
      assert.equal(new URL(es.attrs.href).pathname, '/es/sobre-nosotros/');
    });
  }
  test('Spanish URL without trailing slash is normalised on the alias', async () => {
    const r = await request('alias-one.test', '/es/sobre-nosotros?utm_source=x');
    assert.equal(r.status, 301);
    assert.equal(r.headers.location, 'http://alias-one.test/es/sobre-nosotros/?utm_source=x');
  });
});

describe('Elementor', () => {
  before(() => {
    wp('plugin', 'activate', 'elementor');
    wp('eval-file', fixture('elementor.php'));
  });
  after(() => wp('plugin', 'deactivate', 'elementor'));

  for (const host of [PRIMARY, ...ALIASES]) {
    test(`${host}: Elementor button and heading links`, async () => {
      const r = await request(host, '/elementor-page/');
      assert.equal(r.status, 200);
      const buttons = tags(r.body).filter(t => t.name === 'a' && /elementor-button/.test(t.attrs.class || ''));
      assert.equal(buttons.length, 1, 'button rendered');
      assert.equal(buttons[0].attrs.href, `http://${host}/contact/`);
      assert.ok(tags(r.body).some(t => t.name === 'a' && t.attrs.href === `http://${host}/about/`), 'heading link');
      const config = r.body.match(/elementorFrontendConfig = (\{.*?\});/s);
      if (config) {
        const ajax = JSON.parse(config[1]).urls?.ajaxurl;
        if (ajax) assert.equal(new URL(ajax).hostname, host, 'Elementor AJAX URL same-origin');
      }
    });
  }
});

describe('Contact Form 7', () => {
  before(() => {
    wp('plugin', 'activate', 'contact-form-7');
    wp('eval-file', fixture('cf7.php'));
  });
  after(() => wp('plugin', 'deactivate', 'contact-form-7'));

  for (const host of [PRIMARY, ...ALIASES]) {
    test(`${host}: form posts and REST root stay on ${host}`, async () => {
      const r = await request(host, '/cf7-page/');
      assert.equal(r.status, 200);
      const form = tags(r.body).find(t => t.name === 'form' && /wpcf7-form/.test(t.attrs.class || ''));
      assert.ok(form, 'form rendered');
      const action = new URL(form.attrs.action, `http://${host}/cf7-page/`);
      assert.equal(action.hostname, host);
      const api = r.body.match(/"api":\s*\{\s*"root":\s*"([^"]+)"/);
      assert.ok(api, 'wpcf7 API config present');
      assert.equal(new URL(api[1].replace(/\\\//g, '/')).hostname, host, 'REST root same-origin');
    });
  }
  test('form submission through REST on an alias is processed', async () => {
    const { cf7_form: id } = ids();
    const page = await request('alias-one.test', '/cf7-page/');
    const unit = page.body.match(/name="_wpcf7_unit_tag" value="([^"]+)"/)[1];
    const boundary = '----wpda' + Date.now();
    const fields = { _wpcf7: String(id), _wpcf7_version: '6', _wpcf7_locale: 'en_US', _wpcf7_unit_tag: unit, _wpcf7_container_post: '0', 'your-name': 'Test', 'your-email': 'test@example.org', 'your-subject': 'Alias', 'your-message': 'Hello' };
    const body = Object.entries(fields).map(([k, v]) => `--${boundary}\r\nContent-Disposition: form-data; name="${k}"\r\n\r\n${v}\r\n`).join('') + `--${boundary}--\r\n`;
    const r = await request('alias-one.test', `/wp-json/contact-form-7/v1/contact-forms/${id}/feedback`, {
      method: 'POST', headers: { 'Content-Type': `multipart/form-data; boundary=${boundary}`, 'User-Agent': 'wpda-tests' }, body,
    });
    assert.equal(r.status, 200, r.body.slice(0, 200));
    const json = JSON.parse(r.body);
    // No mail server in the test environment: "mail_failed" proves the
    // submission was validated and processed on the alias host.
    assert.ok(['mail_sent', 'mail_failed'].includes(json.status), json.status);
  });
});
