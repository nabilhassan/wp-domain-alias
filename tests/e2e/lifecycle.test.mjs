// Install / activate / deactivate / uninstall and Must-Use tests.
// Needs a separate site built with WPDA_NO_LINK=1 (the plugin is NOT linked
// to the working tree, because uninstall deletes plugin files):
//   WPDA_DIR=$PWD/.wp-test-lifecycle WPDA_PORT=8892 WPDA_NO_LINK=1 tests/e2e/setup-wp.sh
//   bin/build-zip.sh
//   WPDA_DIR=... WPDA_PORT=8892 tests/e2e/server.sh start
//   WPDA_DIR=... WPDA_PORT=8892 node --test --test-concurrency=1 tests/e2e/lifecycle.test.mjs
import { test, before, after, describe } from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { chromium } from 'playwright';
import { ROOT, DIR, PORT, PRIMARY, wp, setSettings, request, navigationUrls, isProtected, login } from './lib.mjs';

const WPROOT = path.join(DIR, 'wordpress');
const PLUGIN_DIR = path.join(WPROOT, 'wp-content/plugins/wp-domain-alias');
const MU = path.join(WPROOT, 'wp-content/mu-plugins');
const VERSION = fs.readFileSync(path.join(ROOT, 'wp-domain-alias.php'), 'utf8').match(/Version:\s*(\S+)/)[1];
const ZIP = path.join(ROOT, 'dist', `wp-domain-alias-${VERSION}.zip`);
const linkHosts = html => [...new Set(navigationUrls(html, 'http://x.invalid/').filter(n => !isProtected(n.url)).map(n => n.url.hostname))];
const option = () => { try { return JSON.parse(wp('option', 'get', 'wp_domain_alias_settings', '--format=json')); } catch { return null; } };

before(() => {
  if (fs.existsSync(PLUGIN_DIR) && fs.lstatSync(PLUGIN_DIR).isSymbolicLink()) {
    throw new Error('Refusing to run: the plugin is linked to the working tree. Build this site with WPDA_NO_LINK=1.');
  }
  assert.ok(fs.existsSync(ZIP), `build the ZIP first (${ZIP})`);
});

describe('regular plugin lifecycle', () => {
  test('upload through Plugins → Add New → Upload Plugin and activate', async () => {
    const browser = await chromium.launch({ channel: fs.existsSync('/Applications/Google Chrome.app') ? 'chrome' : undefined, args: [`--host-resolver-rules=MAP *.test 127.0.0.1:${PORT}`] });
    const page = await browser.newPage();
    await page.goto(`http://${PRIMARY}/wp-login.php`);
    await page.fill('#user_login', 'admin');
    await page.fill('#user_pass', 'password');
    await Promise.all([page.waitForNavigation(), page.click('#wp-submit')]);
    await page.goto(`http://${PRIMARY}/wp-admin/plugin-install.php?tab=upload`);
    await page.setInputFiles('#pluginzip', ZIP);
    await Promise.all([page.waitForNavigation(), page.click('#install-plugin-submit')]);
    await page.getByText('Plugin installed successfully').waitFor();
    await Promise.all([page.waitForNavigation(), page.getByRole('link', { name: 'Activate Plugin' }).click()]);
    await page.locator('#message, .notice-success').first().waitFor();
    const row = page.locator('tr[data-slug="wp-domain-alias"]');
    assert.match(await row.innerText(), new RegExp(`Version ${VERSION.replace(/\./g, '\\.')}`));
    assert.ok(await row.getByRole('link', { name: 'Settings' }).isVisible(), 'Settings link on the plugin row');
    await browser.close();
    assert.equal(wp('plugin', 'get', 'wp-domain-alias', '--field=status'), 'active');
    assert.deepEqual(option(), { enabled: true, aliases: [], canonical_mode: 'primary', diagnostics: false }, 'safe defaults stored');
  });
  test('works after configuration', async () => {
    setSettings({ aliases: ['alias-one.test'] });
    assert.deepEqual(linkHosts((await request('alias-one.test', '/about/')).body), ['alias-one.test']);
  });
  test('deactivation stops rewriting and keeps settings', async () => {
    wp('plugin', 'deactivate', 'wp-domain-alias');
    assert.deepEqual(linkHosts((await request('alias-one.test', '/about/')).body), [PRIMARY]);
    assert.deepEqual(option().aliases, ['alias-one.test']);
  });
  test('reactivation restores behaviour', async () => {
    wp('plugin', 'activate', 'wp-domain-alias');
    assert.deepEqual(linkHosts((await request('alias-one.test', '/about/')).body), ['alias-one.test']);
  });
  test('uninstall removes settings and transients, site keeps working', async () => {
    wp('transient', 'set', 'wpda_notices_1', 'x', '60');
    wp('plugin', 'deactivate', 'wp-domain-alias');
    wp('plugin', 'uninstall', 'wp-domain-alias');
    assert.equal(option(), null, 'option deleted');
    assert.equal(wp('eval', 'global $wpdb; echo $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE \'%transient%wpda\\_%\' OR option_name LIKE \'wp\\_domain\\_alias%\'" );'), '0');
    assert.ok(!fs.existsSync(PLUGIN_DIR), 'plugin files removed by WordPress');
    assert.equal((await request(PRIMARY, '/about/')).status, 200);
  });
});

describe('Must-Use installation', () => {
  after(() => {
    fs.rmSync(path.join(MU, 'wp-domain-alias'), { recursive: true, force: true });
    fs.rmSync(path.join(MU, 'wp-domain-alias-loader.php'), { force: true });
    try { wp('plugin', 'deactivate', 'wp-domain-alias'); wp('plugin', 'uninstall', 'wp-domain-alias'); } catch { /* not installed */ }
  });
  test('loader + folder in mu-plugins', async () => {
    wp('eval', `$z = new ZipArchive(); $z->open(${JSON.stringify(ZIP)}); $z->extractTo(${JSON.stringify(MU)}); $z->close();`);
    fs.copyFileSync(path.join(MU, 'wp-domain-alias/mu-loader/wp-domain-alias-loader.php'), path.join(MU, 'wp-domain-alias-loader.php'));
    const mu = JSON.parse(wp('plugin', 'list', '--status=must-use', '--fields=name,version,title', '--format=json'));
    const loader = mu.find(p => p.name === 'wp-domain-alias-loader');
    assert.ok(loader, JSON.stringify(mu));
    assert.equal(loader.version, VERSION);
    setSettings({ aliases: ['alias-two.test'] });
    assert.deepEqual(linkHosts((await request('alias-two.test', '/contact/')).body), ['alias-two.test']);
  });
  test('settings page reports the Must-Use install', async () => {
    const cookie = await login();
    const r = await request(PRIMARY, '/wp-admin/options-general.php?page=wp-domain-alias', { headers: { Cookie: cookie } });
    assert.equal(r.status, 200);
    assert.match(r.body, /Must-Use plugin \(cannot be deactivated from the Plugins screen\)/);
  });
  test('a second regular copy does not load twice or fatal', async () => {
    wp('plugin', 'install', ZIP, '--activate');
    const cookie = await login();
    const r = await request(PRIMARY, '/wp-admin/plugins.php', { headers: { Cookie: cookie } });
    assert.equal(r.status, 200);
    assert.match(r.body, /installed twice/);
    assert.deepEqual(linkHosts((await request('alias-two.test', '/contact/')).body), ['alias-two.test']);
  });
  test('missing plugin folder: site stays up, admins see a notice', async () => {
    wp('plugin', 'deactivate', 'wp-domain-alias');
    fs.rmSync(path.join(MU, 'wp-domain-alias'), { recursive: true, force: true });
    assert.equal((await request(PRIMARY, '/')).status, 200);
    const cookie = await login();
    const r = await request(PRIMARY, '/wp-admin/', { headers: { Cookie: cookie } });
    assert.equal(r.status, 200);
    assert.match(r.body, /could not find wp-content\/mu-plugins\/wp-domain-alias\//);
  });
});
