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

Health check against real HTTP behaviour (slow, redirecting, unresolvable and bad-certificate domains, batching, AJAX, authorization, DNS pinning). The pinning test needs public DNS and skips without it:

```sh
node --test --test-concurrency=1 tests/e2e/health.test.mjs
```

Performance benchmark (server-side time and peak memory, plugin inactive vs. primary vs. alias):

```sh
node tests/perf/bench.mjs <label>        # writes tests/perf/results-<label>.json
```

README screenshots: `WPDA_SCREENSHOTS=1 npm run test:browser`.

## Server stacks (Nginx and Apache)

`tests/hosting/stack.sh` runs the same suites behind real web servers over HTTPS:

- Nginx + PHP-FPM, and Apache (mod_proxy_fcgi, `.htaccess` rewrites, mod_ssl) + PHP-FPM.
- A separate HTTPS test site, `.wp-test-https`, with a copy of the plugin rather than a symlink.
- A throwaway certificate authority. Its key is deleted once it has signed the one test certificate.

No system trust store or `/etc/hosts` entry is changed. Node trusts the test CA through `WPDA_CA`. Chrome accepts only the test certificate's public key (`--ignore-certificate-errors-spki-list`), not every certificate.

Needs `nginx`, `php-fpm`, an Apache `httpd` with mod_proxy_fcgi and mod_ssl, and `openssl`. Paths can be overridden with `NGINX`, `PHP_FPM`, `HTTPD`, `APACHE_MODULES`, `APACHE_MIME` and `OPENSSL`.

```sh
tests/hosting/stack.sh setup             # HTTPS site + certificates (once)
tests/hosting/stack.sh start             # copies the plugin in, starts PHP-FPM, Nginx, Apache
eval "export $(tests/hosting/stack.sh env nginx)"     # or: env apache
node --test --test-concurrency=1 tests/e2e/integration.test.mjs tests/e2e/compat.test.mjs tests/e2e/cache.test.mjs tests/e2e/browser.test.mjs
node --test --test-concurrency=1 tests/hosting/server-cache.test.mjs   # Nginx only
tests/hosting/stack.sh versions          # exact server, PHP, WordPress and plugin versions
tests/hosting/stack.sh stop
```

| Port | Server |
|---|---|
| 8443 | Nginx, HTTPS |
| 8445 | Nginx, HTTPS, FastCGI cache keyed on host |
| 8446 | Nginx, HTTPS, FastCGI cache keyed on path only (negative control) |
| 8444 | Apache, HTTPS |

These stacks run locally with `.test` names mapped to 127.0.0.1. They don't prove compatibility with any hosting provider.

## Suites

| Suite | File | What it covers |
|---|---|---|
| Unit | `tests/unit/*.php` | Host validation (including IDN, wildcards, IPs, ports, control characters), settings sanitising, URL moving, protected paths, redirects, the HTML pass against WordPress core's real HTML API and `esc_url`, SEO tag policy, admin handlers (capability, nonce, validation), health-check error mapping and rate limit, loader and duplicate guard, Weglot language paths (stubs). |
| Integration | `tests/e2e/integration.test.mjs` | Every page type on every host; links, forms, REST, AJAX; redirects and loop checks; 404s; sitemap, robots.txt, feeds; spoofed and malformed `Host` headers; settings changes; authenticated admin screen, nonce refusal, health check. |
| Compatibility | `tests/e2e/compat.test.mjs` | Yoast SEO, Rank Math, Polylang, Elementor, Contact Form 7. |
| Cache | `tests/e2e/cache.test.mjs` | Cache Enabler isolation, both request orders, purges on content, menu and settings changes, alias removal, expiry; simulated proxy caches keyed by host + URL (supported) and by path (demonstrates the unsupported case). |
| Browser | `tests/e2e/browser.test.mjs` | Real Chrome: desktop and mobile navigation, console errors, failed requests, forms, redirects, CF7 submission, Elementor, Polylang switcher and cookies, `admin-post.php` forms (default and opt-in, cross-origin `fetch`), admin screen and the JavaScript health check. |
| Health | `tests/e2e/health.test.mjs` | Batched no-JavaScript run, AJAX run with 25 aliases, slow / redirecting / unresolvable / bad-TLS domains, rate limit, stale runs, non-admin users, bad nonces, DNS pinning with a negative control. |
| Server cache | `tests/hosting/server-cache.test.mjs` | Nginx FastCGI cache: cold/warm, both orders, bypass, content edit and expiry, alias removal, full purge, settings change, and a path-only key that must leak. |
| Lifecycle | `tests/e2e/lifecycle.test.mjs` | ZIP upload through the admin, activate, deactivate, reactivate, uninstall cleanup, Must-Use install, duplicate copy, missing MU folder. |

Third-party groups are skipped when the plugin is not installed on the test site.

## Results for 0.1.1-beta (local, 2026-11-06)

Environment: macOS 27.2, PHP 8.4.13 (CLI server and FPM), Node 24.8.0, Google Chrome 156 via Playwright 1.56.1, SQLite Database Integration 3.1.3, curl 8.16.0 / OpenSSL 3.6.3. Counts are tests passed / run; nothing failed or was cancelled.

| Suite | WP 7.1.4, PHP server | WP 6.4.5, PHP server | WP 7.1.4, Nginx + FPM, HTTPS | WP 7.1.4, Apache + FPM, HTTPS |
|---|---|---|---|---|
| Unit (assertions) | 403 / 403 | — (uses the 7.1.4 core HTML API) | — | — |
| Integration | 79 / 79 | 77 pass, 2 skipped (need WP 6.7+) | 79 / 79 | 79 / 79 |
| Compatibility | 30 / 30 | not run (plugins not installed) | 30 / 30 | 30 / 30 |
| Cache | 17 / 17 | not run | 17 / 17 | 17 / 17 |
| Browser | 18 / 18 | 15 / 15 (3 plugin groups skipped: plugins not installed) | 18 / 18 | 18 / 18 |
| Health | 8 / 8 | 8 / 8 | not run (scenario hosts need the PHP server's router) | not run |
| Server cache (Nginx FastCGI) | — | — | 9 / 9 | — |
| Lifecycle (built ZIP) | 9 / 9 | — | — | — |
| PHPCS | clean | — | — | — |

The integration suite's health check runs on the HTTPS stacks too, over verified TLS.

Continuous integration (`.github/workflows/ci.yml`) runs PHP syntax checks on PHP 7.4–8.4, PHPCS, unit tests on PHP 7.4 and 8.4, and the integration, health and browser suites on WordPress 6.4.5 and latest (with compatibility and cache on latest). The server stacks are not run in CI.

### Performance (0.1.0-beta vs 0.1.1-beta)

`tests/perf/bench.mjs`: server-side time measured inside PHP, median of 30 requests, PHP built-in server, WordPress 7.1.4, Twenty Twenty-Five.

| Page | Host | 0.1.0-beta median ms | 0.1.1-beta median ms | Peak memory KB (0.1.0 → 0.1.1) |
|---|---|---|---|---|
| Homepage | primary, plugin inactive | 38.11 | 38.25 | 7809 → 7812 |
| Homepage | primary | 37.91 | 38.34 | 7847 → 7851 |
| Homepage | alias | 40.25 | 39.97 | 7864 → 7867 |
| Internal page | primary | 30.70 | 30.69 | 7954 → 7957 |
| Internal page | alias | 31.94 | 32.37 | 7971 → 7974 |
| Large page (2,000 links) | primary | 38.27 | 38.30 | 8466 → 8469 |
| Large page (2,000 links) | alias | 73.99 | 73.58 | 8482 → 8486 |
| Large page, 25 aliases | alias | 74.50 | 74.56 | 8485 → 8489 |

Differences are within run-to-run noise. Primary-domain overhead stays near zero.

Health check: worst case per request drops from about 130 s (26 × 5 s in one request, computed) to 15 s without JavaScript and 5 s with it. Measured: 25 aliases + primary in 26 AJAX requests, the longest 116 ms. See [review-0.1.1-beta.md](review-0.1.1-beta.md#1-health-check-timeout).

## What the tests do not cover

- Hosting providers, real DNS, publicly trusted certificates, CDNs, host edge caches, load balancers.
- LiteSpeed / OpenLiteSpeed (not available in the test environment).
- Weglot and Gravity Forms (need a Weglot account and a Gravity Forms licence), WPML, WooCommerce and other plugins not listed in [compatibility.md](compatibility.md).
- Browsers other than Chrome.
- Load and concurrency beyond 4 PHP workers.

## Negative controls

These checks prove the tests can fail:

- With same-origin asset handling disabled (`wp_domain_alias_same_origin_assets` returning `false`), the browser tests fail: fonts and module scripts are blocked cross-origin, and the mobile menu does not open.
- With the alias-removal purge disabled, the Cache Enabler "removing an alias purges its cached copies" test fails.
- A real Nginx FastCGI cache keyed on the path only serves primary HTML on an alias (`server-cache.test.mjs`).
- Without the DNS pin, the pinning test's request reaches the real host instead of the validated address.
