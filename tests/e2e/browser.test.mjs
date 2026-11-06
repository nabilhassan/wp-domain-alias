// Real-browser tests (Playwright) against the local site.
// Chrome maps every *.test hostname to the local server, so each page loads
// with its real Host header, exactly as a visitor's browser would send it.
// Run: node --test --test-concurrency=1 tests/e2e/browser.test.mjs
import { test, before, after, describe } from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { chromium } from 'playwright';
import { ROOT, DIR, PORT, PRIMARY, ALIASES, wp, setSettings, SCHEME } from './lib.mjs';

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
    args: [
      `--host-resolver-rules=MAP *.test 127.0.0.1:${PORT}`,
      // Over HTTPS, trust only the test leaf certificate (tests/hosting/), not every certificate.
      ...(process.env.WPDA_TLS_SPKI ? [`--ignore-certificate-errors-spki-list=${process.env.WPDA_TLS_SPKI}`] : []),
    ],
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
      await page.goto(`${SCHEME}://${h}/`);
      await page.locator('header').getByRole('link', { name: 'About', exact: true }).first().click();
      await page.waitForURL(`${SCHEME}://${h}/about/`);
      await page.locator('#abs-internal').click();
      await page.waitForLoadState('domcontentloaded');
      assert.equal(host(page), h);
      assert.equal(new URL(page.url()).search, '?utm_source=test&gclid=abc', 'tracking parameters kept');
      await page.locator('header .wp-block-site-title a').first().click();
      await page.waitForLoadState('domcontentloaded');
      assert.equal(page.url(), `${SCHEME}://${h}/`);
      await page.goto(`${SCHEME}://${h}/page/2/`);
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
      await page.goto(`${SCHEME}://${h}/`);
      // The block navigation overlay is powered by script modules; it only
      // opens if the module scripts loaded without cross-origin errors.
      await page.getByRole('button', { name: 'Open menu' }).first().click();
      const dialog = page.getByRole('dialog');
      await dialog.getByRole('link', { name: 'Contact', exact: true }).click();
      await page.waitForURL(`${SCHEME}://${h}/contact/`);
      assert.deepEqual(problems, []);
      if (SHOTS && h === 'alias-one.test') await page.screenshot({ path: path.join(SHOTS, 'mobile-alias.png') });
      await context.close();
    });
  }
});

describe('forms and redirects', () => {
  test('plain form submits to the alias; formaction too', async () => {
    const { context, page } = await newPage();
    await page.goto(`${SCHEME}://alias-two.test/contact/`);
    await Promise.all([page.waitForNavigation(), page.locator('#plain-form button').click()]);
    assert.equal(page.url(), `${SCHEME}://alias-two.test/about/`);
    await context.close();
  });
  test('clickable column URL points at the alias', async () => {
    const { context, page } = await newPage();
    await page.goto(`${SCHEME}://www.alias-two.test/contact/`);
    assert.equal(await page.locator('#clickable').getAttribute('data-column-clickable'), `${SCHEME}://www.alias-two.test/about/`);
    await context.close();
  });
  test('missing trailing slash is fixed on the alias, with parameters', async () => {
    const { context, page } = await newPage();
    const r = await page.goto(`${SCHEME}://alias-one.test/about?gclid=x1`);
    assert.equal(page.url(), `${SCHEME}://alias-one.test/about/?gclid=x1`);
    assert.equal(r.status(), 200);
    await context.close();
  });
  test('admin on an alias ends on the primary login page', async () => {
    const { context, page } = await newPage();
    await page.goto(`${SCHEME}://alias-one.test/wp-admin/`);
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
    await page.goto(`${SCHEME}://alias-one.test/cf7-page/`);
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
    await page.goto(`${SCHEME}://alias-two.test/elementor-page/`);
    await page.locator('a.elementor-button').click();
    await page.waitForURL(`${SCHEME}://alias-two.test/contact/`);
    assert.deepEqual(problems, []);
    await context.close();
  });
});

describe('Polylang in the browser', needs('polylang'), () => {
  before(() => { wp('plugin', 'activate', 'polylang'); wp('eval-file', fixture('polylang.php')); wp('rewrite', 'flush'); });
  after(() => { wp('plugin', 'deactivate', 'polylang'); wp('rewrite', 'flush'); });
  test('language switcher and cookies stay on the alias', async () => {
    const { context, page, problems } = await newPage();
    await page.goto(`${SCHEME}://alias-one.test/about/`);
    await page.locator('a[hreflang^="es"]').first().click();
    await page.waitForURL(`${SCHEME}://alias-one.test/es/sobre-nosotros/`);
    const canonical = await page.locator('link[rel="canonical"]').getAttribute('href');
    assert.equal(canonical, `${SCHEME}://${PRIMARY}/es/sobre-nosotros/`);
    const cookies = await context.cookies();
    assert.ok(cookies.every(c => c.domain.replace(/^\./, '') === 'alias-one.test'), JSON.stringify(cookies.map(c => [c.name, c.domain])));
    assert.deepEqual(problems, []);
    await context.close();
  });
});

describe('admin-post.php forms', () => {
  const mu = path.join(DIR, 'wordpress/wp-content/mu-plugins/wpda-admin-post-form.php');
  let pageId;
  before(() => {
    fs.copyFileSync(fixture('admin-post-form.php'), mu);
    pageId = wp('post', 'create', '--post_type=page', '--post_status=publish', '--post_title=Newsletter', '--post_name=newsletter', '--post_content=[wpda_admin_post_form]', '--porcelain');
  });
  after(() => {
    wp('post', 'delete', pageId, '--force');
    wp('option', 'delete', 'wpda_test_admin_post_optin');
    fs.rmSync(mu, { force: true });
  });
  const submit = async () => {
    const { context, page, problems } = await newPage();
    await page.goto(`${SCHEME}://alias-one.test/newsletter/`);
    const action = await page.locator('#ap-form').getAttribute('action');
    await Promise.all([page.waitForNavigation(), page.locator('#ap-form button').click()]);
    const cookies = (await context.cookies()).filter(c => c.name === 'wpda_form_sent').map(c => c.domain.replace(/^\./, ''));
    const result = { action, url: page.url(), cookies, problems };
    await context.close();
    return result;
  };
  test('default: the form posts to the primary domain and the visitor ends there (documented limitation)', async () => {
    wp('option', 'delete', 'wpda_test_admin_post_optin');
    const r = await submit();
    assert.equal(r.action, `${SCHEME}://${PRIMARY}/wp-admin/admin-post.php`);
    assert.equal(r.url, `${SCHEME}://${PRIMARY}/newsletter/?sent=1`, 'nonce accepted, but the visitor left the alias');
    assert.deepEqual(r.cookies, [PRIMARY], 'cookie set on the primary domain');
  });
  test('opted-in action: the form posts on the alias, nonce accepted, visitor and cookie stay on the alias', async () => {
    wp('option', 'update', 'wpda_test_admin_post_optin', '1');
    const r = await submit();
    assert.equal(r.action, `${SCHEME}://alias-one.test/wp-admin/admin-post.php`);
    assert.equal(r.url, `${SCHEME}://alias-one.test/newsletter/?sent=1`);
    assert.deepEqual(r.cookies, ['alias-one.test']);
    assert.deepEqual(r.problems, []);
  });
  test('cross-origin fetch from an alias page to the primary admin-post.php cannot read the response', async () => {
    const { context, page } = await newPage();
    await page.goto(`${SCHEME}://alias-one.test/newsletter/`);
    const outcome = await page.evaluate(async url => {
      try { const r = await fetch(url, { method: 'POST', body: new URLSearchParams({ action: 'wpda_test_form' }) }); return `read ${r.status}`; } catch (e) { return 'blocked'; }
    }, `${SCHEME}://${PRIMARY}/wp-admin/admin-post.php`);
    assert.equal(outcome, 'blocked', 'browsers block reading cross-origin responses; WordPress only allows its own origin');
    await context.close();
  });
});

describe('admin screen', () => {
  test('settings page and health check (screenshots when WPDA_SCREENSHOTS=1)', async () => {
    wp('transient', 'delete', 'wpda_hc_lock_1');
    const { context, page } = await newPage({ width: 1280, height: 1000 });
    await page.goto(`${SCHEME}://${PRIMARY}/wp-login.php`);
    await page.fill('#user_login', 'admin');
    await page.fill('#user_pass', 'password');
    await Promise.all([page.waitForNavigation(), page.click('#wp-submit')]);
    await page.goto(`${SCHEME}://${PRIMARY}/wp-admin/options-general.php?page=wp-domain-alias`);
    await page.locator('h1', { hasText: 'WP Domain Alias' }).waitFor();
    if (SHOTS) {
      fs.mkdirSync(SHOTS, { recursive: true });
      await page.locator('#wpbody-content').screenshot({ path: path.join(SHOTS, 'settings.png') });
    }
    await page.fill('#wpda-alias', '*.invalid-wildcard.test');
    await Promise.all([page.waitForNavigation(), page.getByRole('button', { name: 'Add alias' }).click()]);
    assert.match(await page.locator('.notice-error').innerText(), /Wildcard/);
    // With JavaScript the check runs one domain per AJAX request, in place.
    const url = page.url();
    await page.getByRole('button', { name: 'Run health check' }).click();
    const health = page.locator('#wpda-health');
    await health.locator('h3', { hasText: 'alias-one.test' }).waitFor();
    await page.locator('#wpda-health-progress', { hasText: /complete/i }).waitFor();
    assert.equal(page.url(), url, 'no page reload');
    assert.match(await health.innerText(), /Internal links stay on this domain/);
    if (SHOTS) await health.screenshot({ path: path.join(SHOTS, 'health-check.png') });
    await context.close();
  });
});
