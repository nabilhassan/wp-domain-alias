# Testing

The tests need no Docker or MySQL. A disposable WordPress runs on PHP's built-in web server with the [SQLite Database Integration](https://wordpress.org/plugins/sqlite-database-integration/) plugin. Test hostnames (`primary.test`, `alias-one.test`, `alias-two.test`, `www.alias-two.test`) are sent as `Host` headers to `127.0.0.1`. Chrome maps them with `--host-resolver-rules`, so no `/etc/hosts` changes are needed.

Everything runs locally. No test contacts a real domain, and none needs credentials.

## Requirements

- PHP 8.1+ with `pdo_sqlite`, `sqlite3`, `intl`, `mbstring`, `zip` (the plugin itself supports 7.4+)
- Node.js 20+
- `curl`, `unzip`, `zip`
- Composer (coding standards only)

## Commands

```sh
npm ci                                   # Playwright (dev only)
npx playwright install chromium          # once
composer install                         # PHPCS (dev only)

# Build the local site (WordPress latest + test plugins) and start it on :8890
npm run env:setup
npm run env:start

php tests/unit/run.php                   # unit tests
npm run test:integration                 # integration, compatibility, cache
npm run test:browser                     # Playwright browser tests
vendor/bin/phpcs                         # WordPress Coding Standards + PHP 7.4 compatibility
npm run env:stop
```

Another WordPress version on another port:

```sh
WPDA_DIR=$PWD/.wp-test-64 WPDA_PORT=8891 tests/e2e/setup-wp.sh 6.4.5
WPDA_DIR=$PWD/.wp-test-64 WPDA_PORT=8891 tests/e2e/server.sh start
WPDA_DIR=$PWD/.wp-test-64 WPDA_PORT=8891 node --test --test-concurrency=1 tests/e2e/integration.test.mjs tests/e2e/browser.test.mjs
```

Lifecycle tests (install the built ZIP through the browser, activate, deactivate, uninstall, Must-Use). They use a separate site where the plugin is **not** linked to the working tree, because uninstall deletes plugin files:

```sh
bin/build-zip.sh
WPDA_DIR=$PWD/.wp-test-lifecycle WPDA_PORT=8892 WPDA_NO_LINK=1 tests/e2e/setup-wp.sh
WPDA_DIR=$PWD/.wp-test-lifecycle WPDA_PORT=8892 tests/e2e/server.sh start
WPDA_DIR=$PWD/.wp-test-lifecycle WPDA_PORT=8892 node --test --test-concurrency=1 tests/e2e/lifecycle.test.mjs
```

README screenshots: `WPDA_SCREENSHOTS=1 npm run test:browser`.

## Suites

| Suite | File | What it covers |
|---|---|---|
| Unit | `tests/unit/*.php` | Host validation (including IDN, wildcards, IPs, ports, control characters), settings sanitising, URL moving, protected paths, redirects, the HTML pass against WordPress core's real HTML API and `esc_url`, SEO tag policy, admin handlers (capability, nonce, validation), health-check error mapping and rate limit, loader and duplicate guard, Weglot language paths (stubs). |
| Integration | `tests/e2e/integration.test.mjs` | Every page type on every host; links, forms, REST, AJAX; redirects and loop checks; 404s; sitemap, robots.txt, feeds; spoofed and malformed `Host` headers; settings changes; authenticated admin screen, nonce refusal, health check. |
| Compatibility | `tests/e2e/compat.test.mjs` | Yoast SEO, Rank Math, Polylang, Elementor, Contact Form 7. |
| Cache | `tests/e2e/cache.test.mjs` | Cache Enabler isolation and purges; simulated proxy caches keyed by host + URL (supported) and by path (demonstrates the unsupported case). |
| Browser | `tests/e2e/browser.test.mjs` | Real Chrome: desktop and mobile navigation, console errors, failed requests, forms, redirects, CF7 submission, Elementor, Polylang switcher and cookies, admin screen and health check. |
| Lifecycle | `tests/e2e/lifecycle.test.mjs` | ZIP upload through the admin, activate, deactivate, reactivate, uninstall cleanup, Must-Use install, duplicate copy, missing MU folder. |

Third-party groups are skipped when the plugin is not installed on the test site.

## Results for 0.1.0-beta (local, 2026-11-06)

Environment: macOS, PHP 8.4.13, Node 24.8.0, Google Chrome via Playwright 1.56.1, SQLite Database Integration 3.1.3.

| Suite | WordPress 7.1.4 | WordPress 6.4.5 |
|---|---|---|
| Unit (assertions) | 299 / 299 | — (uses the 7.1.4 core HTML API) |
| Integration | 75 / 75 | 73 pass, 2 skipped (need WP 6.7+) |
| Compatibility | 30 / 30 | not run (plugins not installed on the 6.4 site) |
| Cache | 14 / 14 | not run |
| Browser | 15 / 15 | 12 / 12 (third-party groups skipped) |
| Lifecycle | 9 / 9 | — |
| PHPCS | clean | — |

Continuous integration (`.github/workflows/ci.yml`) runs PHP syntax checks on PHP 7.4–8.4, PHPCS, unit tests, and the integration and browser suites on WordPress 6.4 and latest.

## What the tests do not cover

- Real hosting, DNS, HTTPS certificates, CDNs and server caches.
- Weglot, WPML, Gravity Forms, WooCommerce and other plugins not listed in [compatibility.md](compatibility.md).
- Browsers other than Chrome.
- Load and concurrency beyond 4 PHP workers.

## Negative controls

Two checks prove the tests can fail:

- With same-origin asset handling disabled (`wp_domain_alias_same_origin_assets` returning `false`), the browser tests fail: fonts and module scripts are blocked cross-origin, and the mobile menu does not open.
- With the alias-removal purge disabled, the Cache Enabler "removing an alias purges its cached copies" test fails.
