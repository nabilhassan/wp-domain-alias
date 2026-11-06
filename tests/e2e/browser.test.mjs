// Real-browser tests (Playwright) against the local site.
// Chrome maps every *.test hostname to the local server, so each page loads
// with its real Host header, exactly as a visitor's browser would send it.
// Run: node --test --test-concurrency=1 tests/e2e/browser.test.mjs
import { test, before, after, describe } from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { chromium } from 'playwright';
import { ROOT, DIR, PORT, PRIMARY, ALIASES, wp, setSettings } from './lib.mjs';

const fixture = name => path.join(ROOT, 'tests/e2e/fixtures', name);
// Third-party groups are skipped when the plugin is not installed on this test site.
const needs = slug => (fs.existsSync(path.join(DIR, 'wordpress/wp-content/plugins', slug)) ? {} : { skip: `${slug} not installed` });
const SHOTS = process.env.WPDA_SCREENSHOTS ? path.join(ROOT, 'assets/screenshots') : null;
let browser;

async function newPage(viewport = { width: 1280, height: 900 }) {
  const context = await browser.newContext({ viewport });
  const page = await context.newPage();
  const problems = [];
  page.on('console', m => { if (m.type() === 'error') problems.push(`console: ${m.text()}`); });
  page.on('pageerror', e => problems.push(`pageerror: ${e.message}`));
  page.on('requestfailed', r => problems.push(`requestfailed: ${r.url()} ${r.failure()?.errorText}`));
  return { context, page, problems };
}
const host = page => new URL(page.url()).hostname;

before(async () => {
  wp('plugin', 'activate', 'wp-domain-alias');
  setSettings({});
  const channel = process.env.WPDA_BROWSER_CHANNEL ?? (fs.existsSync('/Applications/Google Chrome.app') ? 'chrome' : undefined);
  browser = await chromium.launch({
    channel,
    args: [`--host-resolver-rules=MAP *.test 127.0.0.1:${PORT}`],
  });
});
after(async () => {
  await browser?.close();
  setSettings({});
});

describe('desktop navigation', () => {
  for (const h of [PRIMARY, ...ALIASES]) {
    test(`${h}: header, content and logo links keep the hostname; no console errors`, async () => {
      const { context, page, problems } = await newPage();
      await page.goto(`http://${h}/`);
      await page.locator('header').getByRole('link', { name: 'About', exact: true }).first().click();
      await page.waitForURL(`http://${h}/about/`);
      await page.locator('#abs-internal').click();
      await page.waitForLoadState('domcontentloaded');
      assert.equal(host(page), h);
      assert.equal(new URL(page.url()).search, '?utm_source=test&gclid=abc', 'tracking parameters kept');
      await page.locator('header .wp-block-site-title a').first().click();
      await page.waitForLoadState('domcontentloaded');
      assert.equal(page.url(), `http://${h}/`);
      await page.goto(`http://${h}/page/2/`);
      await page.getByRole('link', { name: /Previous|Newer/i }).first().click();
      await page.waitForLoadState('domcontentloaded');
      assert.equal(host(page), h, 'pagination');
      assert.deepEqual(problems, []);
      await context.close();
    });
  }
});

describe('mobile navigation (390x844)', () => {
  for (const h of ALIASES) {
    test(`${h}: overlay menu works and keeps the hostname`, async () => {
      const { context, page, problems } = await newPage({ width: 390, height: 844 });
      await page.goto(`http://${h}/`);
      // The block navigation overlay is powered by script modules; it only
      // opens if the module scripts loaded without cross-origin errors.
      await page.getByRole('button', { name: 'Open menu' }).first().click();
      const dialog = page.getByRole('dialog');
      await dialog.getByRole('link', { name: 'Contact', exact: true }).click();
      await page.waitForURL(`http://${h}/contact/`);
      assert.deepEqual(problems, []);
      if (SHOTS && h === 'alias-one.test') await page.screenshot({ path: path.join(SHOTS, 'mobile-alias.png') });
      await context.close();
    });
  }
});

describe('forms and redirects', () => {
  test('plain form submits to the alias; formaction too', async () => {
    const { context, page } = await newPage();
    await page.goto('http://alias-two.test/contact/');
    await Promise.all([page.waitForNavigation(), page.locator('#plain-form button').click()]);
    assert.equal(page.url(), 'http://alias-two.test/about/');
    await context.close();
  });
  test('clickable column URL points at the alias', async () => {
    const { context, page } = await newPage();
    await page.goto('http://www.alias-two.test/contact/');
    assert.equal(await page.locator('#clickable').getAttribute('data-column-clickable'), 'http://www.alias-two.test/about/');
    await context.close();
  });
  test('missing trailing slash is fixed on the alias, with parameters', async () => {
    const { context, page } = await newPage();
    const r = await page.goto('http://alias-one.test/about?gclid=x1');
    assert.equal(page.url(), 'http://alias-one.test/about/?gclid=x1');
    assert.equal(r.status(), 200);
    await context.close();
  });
  test('admin on an alias ends on the primary login page', async () => {
    const { context, page } = await newPage();
    await page.goto('http://alias-one.test/wp-admin/');
    assert.equal(host(page), PRIMARY);
    assert.match(new URL(page.url()).pathname, /wp-login\.php/);
    await context.close();
  });
});

describe('Contact Form 7 in the browser', needs('contact-form-7'), () => {
  before(() => { wp('plugin', 'activate', 'contact-form-7'); wp('eval-file', fixture('cf7.php')); });
  after(() => wp('plugin', 'deactivate', 'contact-form-7'));
  test('submission goes to the alias REST API and gets a response', async () => {
    const { context, page, problems } = await newPage();
    await page.goto('http://alias-one.test/cf7-page/');
    await page.fill('input[name="your-name"]', 'Browser Test');
    await page.fill('input[name="your-email"]', 'browser@example.org');
    await page.fill('input[name="your-subject"]', 'Alias');
    await page.fill('textarea[name="your-message"]', 'Hello from the alias');
    const [response] = await Promise.all([
      page.waitForResponse(r => r.url().includes('/contact-form-7/v1/contact-forms/')),
      page.locator('input[type="submit"], button[type="submit"]').first().click(),
    ]);
    assert.equal(new URL(response.url()).hostname, 'alias-one.test', 'same-origin REST request');
    assert.equal(response.status(), 200);
    const json = await response.json();
    // No mail server here: "mail_failed" still proves the alias request was processed.
    assert.ok(['mail_sent', 'mail_failed'].includes(json.status), json.status);
    await page.locator('.wpcf7-response-output').filter({ hasText: /\S/ }).waitFor();
    assert.deepEqual(problems.filter(p => !/the server responded with a status of/.test(p)), []);
    await context.close();
  });
});

describe('Elementor in the browser', needs('elementor'), () => {
  before(() => { wp('plugin', 'activate', 'elementor'); wp('eval-file', fixture('elementor.php')); });
  after(() => wp('plugin', 'deactivate', 'elementor'));
  test('Elementor button keeps the alias', async () => {
    const { context, page, problems } = await newPage();
    await page.goto('http://alias-two.test/elementor-page/');
    await page.locator('a.elementor-button').click();
    await page.waitForURL('http://alias-two.test/contact/');
    assert.deepEqual(problems, []);
    await context.close();
  });
});

describe('Polylang in the browser', needs('polylang'), () => {
  before(() => { wp('plugin', 'activate', 'polylang'); wp('eval-file', fixture('polylang.php')); wp('rewrite', 'flush'); });
  after(() => { wp('plugin', 'deactivate', 'polylang'); wp('rewrite', 'flush'); });
  test('language switcher and cookies stay on the alias', async () => {
    const { context, page, problems } = await newPage();
    await page.goto('http://alias-one.test/about/');
    await page.locator('a[hreflang^="es"]').first().click();
    await page.waitForURL('http://alias-one.test/es/sobre-nosotros/');
    const canonical = await page.locator('link[rel="canonical"]').getAttribute('href');
    assert.equal(canonical, `http://${PRIMARY}/es/sobre-nosotros/`);
    const cookies = await context.cookies();
    assert.ok(cookies.every(c => c.domain.replace(/^\./, '') === 'alias-one.test'), JSON.stringify(cookies.map(c => [c.name, c.domain])));
    assert.deepEqual(problems, []);
    await context.close();
  });
});

describe('admin screen', () => {
  test('settings page and health check (screenshots when WPDA_SCREENSHOTS=1)', async () => {
    wp('transient', 'delete', 'wpda_hc_lock_1');
    const { context, page } = await newPage({ width: 1280, height: 1000 });
    await page.goto(`http://${PRIMARY}/wp-login.php`);
    await page.fill('#user_login', 'admin');
    await page.fill('#user_pass', 'password');
    await Promise.all([page.waitForNavigation(), page.click('#wp-submit')]);
    await page.goto(`http://${PRIMARY}/wp-admin/options-general.php?page=wp-domain-alias`);
    await page.locator('h1', { hasText: 'WP Domain Alias' }).waitFor();
    if (SHOTS) {
      fs.mkdirSync(SHOTS, { recursive: true });
      await page.locator('#wpbody-content').screenshot({ path: path.join(SHOTS, 'settings.png') });
    }
    await page.fill('#wpda-alias', '*.invalid-wildcard.test');
    await Promise.all([page.waitForNavigation(), page.getByRole('button', { name: 'Add alias' }).click()]);
    assert.match(await page.locator('.notice-error').innerText(), /Wildcard/);
    await Promise.all([page.waitForNavigation(), page.getByRole('button', { name: 'Run health check' }).click()]);
    const health = page.locator('#wpda-health');
    await health.locator('h3', { hasText: 'alias-one.test' }).waitFor();
    assert.match(await health.innerText(), /Internal links stay on this domain/);
    if (SHOTS) await health.screenshot({ path: path.join(SHOTS, 'health-check.png') });
    await context.close();
  });
});
