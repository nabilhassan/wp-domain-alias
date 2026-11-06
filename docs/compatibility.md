# Compatibility matrix

Status of 0.1.0-beta. "Tested" means covered by the automated tests in this repository, run in the local environment described in [testing.md](testing.md): PHP 8.4 built-in server, SQLite, Chrome via Playwright, `.test` hostnames over HTTP. Nothing has been tested on a live host or over real HTTPS.

## WordPress and PHP

| Component | Version | Status |
|---|---|---|
| WordPress | 7.1.4 | Tested: all suites |
| WordPress | 6.4.5 | Tested: core integration and browser suites. Third-party plugins not tested on 6.4. Inline font and import-map rewriting needs 6.7+ (2 tests skipped). |
| WordPress | 6.5 – 7.0 | Not tested directly. Expected to work: same APIs as the tested range. |
| WordPress | < 6.4 | Not supported (refused by header). |
| PHP | 8.4.13 | Tested |
| PHP | 7.4 – 8.3 | Syntax checked in CI; runtime not tested locally. |
| Multisite | — | Not supported. Activation is refused. |

## Themes

| Theme | Version | Status |
|---|---|---|
| Twenty Twenty-Five | 1.5 | Tested (WP 7.1.4): desktop and mobile navigation, fonts, script modules |
| Twenty Twenty-Four | 1.0 | Tested (WP 6.4.5): desktop and mobile navigation, fonts |
| Classic themes | — | Not tested. Classic menus are ordinary links, so they are expected to work. |

## Plugins

| Plugin | Version | Status | Notes |
|---|---|---|---|
| Yoast SEO | 28.8 | Tested | One canonical tag, primary `og:url`, JSON-LD unchanged, sitemap lists primary URLs, alias canonical mode works |
| Rank Math | 1.0.281 | Tested | Same checks as Yoast SEO |
| Polylang | 3.9.1 | Tested | `/es/` directory mode: switcher stays on alias, translated slugs kept, canonical/hreflang on primary, cookies set on the alias |
| Elementor | 4.3.6 | Tested | Button and heading links keep the alias; no console errors |
| Contact Form 7 | 6.2.4.1 | Tested | Form submits through the alias REST API (HTTP 200, processed) in a real browser |
| Cache Enabler | 1.8.17 | Tested | Per-host copies; purges repeated for aliases; alias removal purge. See [caching.md](caching.md) |
| Weglot | — | **Not tested** | Integration code present, unit-tested with stubs only. Needs a Weglot account to verify. |
| WPML | — | Not tested | |
| TranslatePress | — | Not tested | |
| Gravity Forms | — | **Not tested** | Commercial plugin, not available in the test environment. Forms posting to the page itself or through AJAX are expected to work like CF7; verify before use. |
| WPForms, Ninja Forms | — | Not tested | |
| WooCommerce | — | Not supported on aliases | Checkout, account and payment flows are not supported on aliases. |
| WP Rocket, W3 Total Cache, WP Super Cache, LiteSpeed Cache | — | Not tested | See [caching.md](caching.md) for what to check |
| Membership, SSO, OAuth plugins | — | Not supported on aliases | Sessions are per domain |

## Cache layers

| Layer | Status |
|---|---|
| Cache keyed on host + URL (simulated proxy) | Tested: domains isolated |
| Cache keyed on path only (simulated proxy) | Tested to demonstrate the failure: **not supported** |
| Real CDNs (Cloudflare, Fastly, etc.) | Not tested |
| Host caches (Kinsta, WP Engine, SiteGround, LiteSpeed server cache, Nginx FastCGI) | Not tested |

## WordPress features

| Feature | Status |
|---|---|
| Menus, logo, content links, pagination, 404 pages | Tested |
| Trailing-slash and `?p=` / `?page_id=` canonical redirects | Tested: stay on alias, no loops |
| REST API (`/wp-json/`) | Tested: answers on alias; response data keeps primary URLs |
| `admin-ajax.php` from the frontend | Tested: same-origin on alias |
| Script modules / Interactivity API (block navigation overlay) | Tested in a real browser on mobile |
| Theme fonts | Tested (6.4.5 and 7.1.4) |
| Core XML sitemap, `robots.txt`, feeds | Tested: primary URLs |
| Admin, login, cron, XML-RPC on an alias | Tested: stay on primary |
| `admin-post.php` forms | Stay on primary (cross-domain POST); not changed by design |
| Comments for logged-out visitors | Expected to work (comment form posts to the alias); not tested in a browser |

## Hosting

No hosting environment has been tested. See [hosting/README.md](hosting/README.md).

## Reporting results

If you test a combination that is not listed, please open an issue using the **Compatibility report** template.
