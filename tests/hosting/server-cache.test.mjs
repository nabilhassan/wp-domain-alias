// Nginx FastCGI page cache in front of WordPress (tests/hosting/stack.sh).
//   8445: cache key "$scheme$request_method$host$request_uri"  (supported)
//   8446: cache key "$scheme$request_method$request_uri"        (misconfigured)
// The critical property: one hostname never receives another hostname's HTML.
// Nginx open source has no purge here, so invalidation is by expiry (TTL) or
// by deleting the cache directory, as many VPS setups do.
// Run: eval "export $(tests/hosting/stack.sh env nginx)"; node --test tests/hosting/server-cache.test.mjs
import { test, before, after, describe } from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { ROOT, PRIMARY, ALIASES, SCHEME, wp, setSettings, request, navigationUrls, isProtected } from '../e2e/lib.mjs';

const HOST_KEYED = 8445;
const PATH_KEYED = 8446;
const TTL = Number(process.env.WPDA_FCGI_TTL || 8) * 1000;
const RUN = path.join(ROOT, '.wp-test-hosting');
const HOSTS = [PRIMARY, ...ALIASES];
const wait = ms => new Promise(r => setTimeout(r, ms));

const linkHosts = html => [...new Set(navigationUrls(html, `${SCHEME}://x.invalid/`).filter(n => !isProtected(n.url)).map(n => n.url.hostname))];
const get = (host, p, port = HOST_KEYED, headers = {}) => request(host, p, { port, headers: { Accept: 'text/html', ...headers } });
const status = r => r.headers['x-fastcgi-cache'];
const purgeAll = () => { for (const d of ['fcgi-cache', 'fcgi-cache-path']) fs.rmSync(path.join(RUN, d), { recursive: true, force: true }); };
const postId = slug => wp('post', 'list', `--name=${slug}`, '--post_type=post', '--field=ID');
const title = html => (html.match(/<title>([^<]*)/) || [])[1];

before(() => {
  assert.equal(SCHEME, 'https', 'run with the env printed by tests/hosting/stack.sh env nginx');
  wp('plugin', 'deactivate', 'cache-enabler');
  wp('plugin', 'activate', 'wp-domain-alias');
  setSettings({});
  purgeAll();
});
after(() => { setSettings({}); purgeAll(); });

describe('FastCGI cache keyed on host (supported configuration)', () => {
  test('cold then warm: MISS then HIT, each host keeps its own links', async () => {
    for (const round of ['MISS', 'HIT']) {
      for (const host of HOSTS) {
        const r = await get(host, '/contact/');
        assert.equal(r.status, 200);
        assert.equal(status(r), round, `${round} ${host}`);
        assert.deepEqual(linkHosts(r.body), [host], `${round} ${host}`);
      }
    }
  });
  test('primary first, then alias: the alias gets its own copy', async () => {
    const p = await get(PRIMARY, '/test-post-1/');
    const a = await get('alias-one.test', '/test-post-1/');
    assert.equal(status(p), 'MISS');
    assert.equal(status(a), 'MISS', 'separate cache entry');
    assert.deepEqual(linkHosts(a.body), ['alias-one.test']);
    assert.deepEqual(linkHosts((await get(PRIMARY, '/test-post-1/')).body), [PRIMARY]);
  });
  test('alias first, then primary: the primary is not served alias HTML', async () => {
    const a = await get('alias-two.test', '/test-post-2/');
    const p = await get(PRIMARY, '/test-post-2/');
    assert.equal(status(a), 'MISS');
    assert.equal(status(p), 'MISS');
    assert.deepEqual(linkHosts(p.body), [PRIMARY]);
    assert.deepEqual(linkHosts((await get(PRIMARY, '/test-post-2/')).body), [PRIMARY], 'warm primary');
  });
  test('logged-in visitors and query strings bypass the cache', async () => {
    const r = await get(PRIMARY, '/contact/', HOST_KEYED, { Cookie: 'wordpress_logged_in_x=1' });
    assert.equal(status(r), 'BYPASS');
    assert.equal(status(await get('alias-one.test', '/contact/?utm_source=x')), 'BYPASS');
  });
  test('content edit: stale until expiry on every host, then fresh and still isolated', async () => {
    const id = postId('test-post-3');
    const before = {};
    for (const host of HOSTS) before[host] = await get(host, '/test-post-3/');
    const original = title(before[PRIMARY].body);
    wp('post', 'update', id, '--post_title=Edited title for cache test');
    try {
      for (const host of HOSTS) {
        const r = await get(host, '/test-post-3/');
        assert.equal(status(r), 'HIT', `${host} served from cache before expiry`);
        assert.equal(title(r.body), title(before[host].body), 'stale copy (no purge configured)');
      }
      await wait(TTL + 1500);
      for (const host of HOSTS) {
        const r = await get(host, '/test-post-3/');
        assert.match(status(r), /EXPIRED|MISS/);
        assert.match(title(r.body), /Edited title for cache test/, host);
        assert.deepEqual(linkHosts(r.body), [host], host);
      }
    } finally {
      wp('post', 'update', id, `--post_title=${original.split(' &#8211; ')[0]}`);
    }
  });
  test('alias removed: its cached copy lingers until expiry or purge, then primary links', async () => {
    await get('www.alias-two.test', '/test-post-4/');
    setSettings({ aliases: ['alias-one.test', 'alias-two.test'] });
    try {
      const cached = await get('www.alias-two.test', '/test-post-4/');
      assert.equal(status(cached), 'HIT');
      assert.deepEqual(linkHosts(cached.body), ['www.alias-two.test'], 'stale until purge — purge after removing an alias');
      purgeAll();
      const fresh = await get('www.alias-two.test', '/test-post-4/');
      assert.equal(status(fresh), 'MISS');
      assert.deepEqual(linkHosts(fresh.body), [PRIMARY]);
    } finally {
      setSettings({});
    }
  });
  test('full purge (cache directory removed): every host rebuilds its own copy', async () => {
    purgeAll();
    for (const host of HOSTS) {
      const r = await get(host, '/about/');
      assert.equal(status(r), 'MISS', host);
      assert.deepEqual(linkHosts(r.body).filter(h => h !== 'example.org' && !h.endsWith('evil.example')), [host], host);
    }
  });
  test('settings change (plugin disabled): cached alias copies persist until purge', async () => {
    await get('alias-one.test', '/test-post-5/');
    setSettings({ enabled: false });
    try {
      assert.deepEqual(linkHosts((await get('alias-one.test', '/test-post-5/')).body), ['alias-one.test'], 'cached');
      purgeAll();
      assert.deepEqual(linkHosts((await get('alias-one.test', '/test-post-5/')).body), [PRIMARY], 'after purge');
    } finally {
      setSettings({});
    }
  });
});

describe('FastCGI cache keyed on path only (misconfiguration — must be detected)', () => {
  test('the alias receives the primary copy from the cache', async () => {
    purgeAll();
    const first = await get(PRIMARY, '/contact/', PATH_KEYED);
    assert.equal(status(first), 'MISS');
    const leaked = await get('alias-one.test', '/contact/', PATH_KEYED);
    assert.equal(status(leaked), 'HIT');
    assert.deepEqual(linkHosts(leaked.body), [PRIMARY], 'cross-host cache hit: such a cache must include the host in its key');
  });
});
