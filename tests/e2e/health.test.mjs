// Health check against real HTTP behaviour: batching, the AJAX path, slow,
// redirecting, unresolvable and bad-TLS domains, authorization, and DNS
// pinning. Scenario hosts are described in fixtures/wpda-test-env.php.
// Run: node --test --test-concurrency=1 tests/e2e/health.test.mjs
import { test, before, after, describe } from 'node:test';
import assert from 'node:assert/strict';
import https from 'node:https';
import dns from 'node:dns/promises';
import fs from 'node:fs';
import path from 'node:path';
import { execFileSync } from 'node:child_process';
import { ROOT, DIR, PORT, PRIMARY, wp, setSettings, request, login } from './lib.mjs';

const ALL = ['alias-one.test', 'alias-two.test', 'www.alias-two.test', 'slow.test', 'redirect-away.test', 'badtls.test', 'unresolvable.invalid'];
let admin;
let tls;

const page = cookie => request(PRIMARY, '/wp-admin/options-general.php?page=wp-domain-alias', { headers: { Cookie: cookie } });
const section = html => html.slice(html.indexOf('id="wpda-health"'));
const formNonce = (html, action) => {
  const form = html.split('<form').find(f => f.includes(`value="${action}"`));
  return form ? form.match(/name="_wpnonce" value="([^"]+)"/)[1] : null;
};
const scriptNonce = (html, key) => (html.match(new RegExp(`"${key}":"([^"]+)"`)) || [])[1];
const form = (cookie, target, body) => request(PRIMARY, target, {
  method: 'POST', headers: { Cookie: cookie, 'Content-Type': 'application/x-www-form-urlencoded' }, body: new URLSearchParams(body).toString(),
});
const resetLimits = () => { for (const u of [1, 2]) { wp('transient', 'delete', `wpda_hc_lock_${u}`); wp('transient', 'delete', `wpda_health_${u}`); wp('transient', 'delete', `wpda_hc_step_${u}`); } };
async function timed(fn) { const t = Date.now(); const r = await fn(); return [r, Date.now() - t]; }

before(async () => {
  wp('plugin', 'activate', 'wp-domain-alias');
  setSettings({ aliases: ALL });
  admin = await login();
  // HTTPS server with a self-signed certificate nobody trusts (badtls.test).
  const dir = path.join(DIR, 'tls-bad');
  fs.mkdirSync(dir, { recursive: true });
  if (!fs.existsSync(path.join(dir, 'key.pem'))) {
    execFileSync('openssl', ['req', '-x509', '-newkey', 'rsa:2048', '-nodes', '-days', '30', '-subj', '/CN=badtls.test', '-keyout', path.join(dir, 'key.pem'), '-out', path.join(dir, 'cert.pem')], { stdio: 'ignore' });
  }
  tls = https.createServer({ key: fs.readFileSync(path.join(dir, 'key.pem')), cert: fs.readFileSync(path.join(dir, 'cert.pem')) }, (req, res) => res.end('<a href="http://badtls.test/x">x</a>'));
  await new Promise(r => tls.listen(PORT + 1000, '127.0.0.1', r));
});
after(() => { tls?.close(); setSettings({}); resetLimits(); });

describe('batched health check (no JavaScript)', () => {
  test('8 domains: 3 requests of at most 3 domains, each bounded, all results reported', async () => {
    resetLimits();
    let html = (await page(admin)).body;
    const [start, t1] = await timed(() => form(admin, '/wp-admin/admin-post.php', { action: 'wpda_health_check', _wpnonce: formNonce(html, 'wpda_health_check') }));
    assert.equal(start.status, 302);
    html = (await page(admin)).body;
    assert.match(section(html), /Checked 3 of 8 domains\./);
    assert.equal((section(html).match(/<h3>/g) || []).length, 3);
    const durations = [t1];
    for (let i = 0; i < 2; i++) {
      const [r, t] = await timed(() => form(admin, '/wp-admin/admin-post.php', { action: 'wpda_health_continue', _wpnonce: formNonce(html, 'wpda_health_continue') }));
      assert.equal(r.status, 302);
      durations.push(t);
      html = (await page(admin)).body;
    }
    const s = section(html);
    assert.equal((s.match(/<h3>/g) || []).length, 8, 'every domain reported');
    assert.ok(!s.includes('wpda_health_continue'), 'no continue button when complete');
    assert.match(s, /<h3>slow\.test<\/h3>[\s\S]*?The request timed out\./);
    assert.match(s, /<h3>redirect-away\.test<\/h3>[\s\S]*?Redirects to another domain: https:\/\/elsewhere\.example\/landing\//);
    assert.match(s, /<h3>badtls\.test<\/h3>[\s\S]*?certificate could not be verified/);
    assert.match(s, /<h3>unresolvable\.invalid<\/h3>[\s\S]*?does not resolve/);
    assert.match(s, /<h3>alias-one\.test<\/h3>[\s\S]*?Internal links stay on this domain/);
    // Worst case per request: 3 domains x 5 s timeout. The slow host adds one full timeout.
    assert.ok(Math.max(...durations) < 3 * 5000 + 3000, `request durations ${durations}`);
    assert.ok(durations[1] >= 4500, `the batch containing slow.test waited for the timeout (${durations[1]} ms)`);
    console.log(`# no-JS batch durations (ms): ${durations.join(', ')}`);
  });
  test('restarting within 20 seconds is refused without new requests', async () => {
    const html = (await page(admin)).body;
    const r = await form(admin, '/wp-admin/admin-post.php', { action: 'wpda_health_check', _wpnonce: formNonce(html, 'wpda_health_check') });
    assert.equal(r.status, 302);
    assert.match((await page(admin)).body, /ran a few seconds ago/);
  });
});

describe('progressive health check (AJAX, one domain per request)', () => {
  test('25 aliases + primary: 26 short requests, progress reported, completes', async () => {
    resetLimits();
    const many = [...ALL.filter(h => h !== 'slow.test')];
    for (let i = 0; many.length < 25; i++) many.push(`missing-${i}.invalid`);
    setSettings({ aliases: many });
    const html = (await page(admin)).body;
    const ajax = body => form(admin, '/wp-admin/admin-ajax.php', body);
    const start = await ajax({ action: 'wpda_health_start', nonce: scriptNonce(html, 'startNonce') });
    const s = JSON.parse(start.body);
    assert.equal(s.success, true);
    assert.equal(s.data.total, 26);
    const times = [];
    let reply;
    do {
      const [r, t] = await timed(() => ajax({ action: 'wpda_health_step', nonce: scriptNonce(html, 'stepNonce'), run: s.data.run }));
      times.push(t);
      reply = JSON.parse(r.body);
      assert.equal(reply.success, true, r.body);
    } while (!reply.data.done && times.length < 40);
    assert.equal(reply.data.checked, 26);
    assert.equal(times.length, 26);
    assert.ok(Math.max(...times) < 5000 + 3000, `step durations ${Math.max(...times)} ms`);
    const total = times.reduce((a, b) => a + b, 0);
    console.log(`# AJAX: 26 steps, max ${Math.max(...times)} ms, total ${total} ms`);
    setSettings({ aliases: ALL });
  });
  test('stale run id is refused', async () => {
    const html = (await page(admin)).body;
    const r = await form(admin, '/wp-admin/admin-ajax.php', { action: 'wpda_health_step', nonce: scriptNonce(html, 'stepNonce'), run: 'stale' });
    assert.equal(r.status, 410);
  });
});

describe('authorization', () => {
  let editor;
  before(async () => {
    try { wp('user', 'create', 'editor1', 'editor1@primary.test', '--role=editor', '--user_pass=editorpass'); } catch { /* exists */ }
    editor = await login('editor1', 'editorpass');
  });
  test('non-admin users are refused on every health endpoint', async () => {
    resetLimits();
    const adminHtml = (await page(admin)).body;
    for (const [action, nonceKey] of [['wpda_health_start', 'startNonce'], ['wpda_health_step', 'stepNonce']]) {
      const r = await form(editor, '/wp-admin/admin-ajax.php', { action, nonce: scriptNonce(adminHtml, nonceKey) });
      assert.equal(r.status, 403, action);
    }
    for (const action of ['wpda_health_check', 'wpda_health_continue']) {
      const r = await form(editor, '/wp-admin/admin-post.php', { action, _wpnonce: 'x' });
      assert.equal(r.status, 403, action);
    }
  });
  test('invalid or missing nonces are refused', async () => {
    for (const body of [{ action: 'wpda_health_start', nonce: 'bad' }, { action: 'wpda_health_start' }, { action: 'wpda_health_step', nonce: 'bad' }]) {
      const r = await form(admin, '/wp-admin/admin-ajax.php', body);
      assert.equal(r.status, 403, JSON.stringify(body));
    }
    for (const action of ['wpda_health_check', 'wpda_health_continue']) {
      const r = await form(admin, '/wp-admin/admin-post.php', { action, _wpnonce: 'bad' });
      assert.equal(r.status, 403, action);
    }
  });
  test('anonymous AJAX requests get nothing', async () => {
    const r = await request(PRIMARY, '/wp-admin/admin-ajax.php', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: 'action=wpda_health_start' });
    assert.ok([400, 403].includes(r.status) || r.body === '0', `HTTP ${r.status} ${r.body}`);
  });
});

describe('DNS pinning (needs public DNS)', () => {
  test('the request connects to the validated address, not to a second DNS answer', async (t) => {
    try { await dns.lookup('example.com', { family: 4 }); } catch { t.skip('no public DNS'); return; }
    // Validation is told example.com is 127.0.0.1 (allowed via core's filter);
    // curl itself would resolve the real address. Port 80 on 127.0.0.1 is closed,
    // so a pinned request fails to connect, while an unpinned one gets HTTP 200.
    const out = wp('eval-file', path.join(ROOT, 'tests/e2e/fixtures/pin-check.php'));
    const res = JSON.parse(out);
    assert.equal(res.pinned.status, 'fail', JSON.stringify(res));
    assert.match(res.pinned.debug, /127\.0\.0\.1|Couldn't connect|Failed to connect/i);
    assert.equal(res.unpinned.code, 200, 'control: without the pin, example.com answers');
  });
});
