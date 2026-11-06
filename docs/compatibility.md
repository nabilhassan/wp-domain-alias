# Compatibility matrix

Status of 0.1.1-beta (2026-11-06). Status words:

- **Verified**: covered by the automated tests in this repository, which passed in the environments named.
- **Simulated**: tested against a stand-in (a stub or a small proxy), not the real product.
- **Not tested**: no evidence either way.
- **Not supported**: known not to work, or out of scope.

The test environments are local and are described in [testing.md](testing.md). No hosting provider has been tested.

| Environment | Server | TLS |
|---|---|---|
| PHP built-in server | PHP 8.4.13 CLI server, SQLite | HTTP only |
| Nginx stack | Nginx 1.31.8 + PHP-FPM 8.4.13, SQLite | HTTPS (TLS 1.2/1.3, HTTP/2), test CA |
| Apache stack | Apache 2.4.67 (mod_proxy_fcgi, `.htaccess` rewrites, mod_ssl) + PHP-FPM 8.4.13, SQLite | HTTPS (TLS 1.2), test CA |

## WordPress and PHP

| Component | Version | Status |
|---|---|---|
| WordPress | 7.1.4 | Verified: all suites, on all three environments |
| WordPress | 6.4.5 | Verified on the PHP built-in server: integration, health and browser suites. Third-party plugins not tested on 6.4. Inline font and import-map rewriting need 6.7+ (2 tests skipped). |
| WordPress | 6.5 – 7.0 | Not tested directly. Expected to work: same APIs as the tested range. |
| WordPress | < 6.4 | Not supported (refused by the plugin header). |
| PHP | 8.4.13 | Verified (CLI server and PHP-FPM) |
| PHP | 7.4 – 8.3 | Syntax checked in CI on every version; unit tests run on 7.4 in CI. |
| Multisite | — | Not supported. Activation is refused. |

## Themes

| Theme | Version | Status |
|---|---|---|
| Twenty Twenty-Five | 1.5 | Verified (WP 7.1.4): desktop and mobile navigation, fonts, script modules |
| Twenty Twenty-Four | 1.0 | Verified (WP 6.4.5): desktop and mobile navigation, fonts |
| Classic themes | — | Not tested. Classic menus are ordinary links, so they are expected to work. |

## Plugins

| Plugin | Version | Status | Notes |
|---|---|---|---|
| Yoast SEO | 28.8 | Verified (3 environments) | One canonical tag, primary canonical and `og:url` on every host (including a stored 0.1.0-beta "alias" setting), JSON-LD unchanged, sitemap lists primary URLs |
| Rank Math | 1.0.281 | Verified (3 environments) | Same checks as Yoast SEO |
| Polylang | 3.9.1 | Verified (3 environments) | `/es/` directory mode: switcher stays on the alias, translated slugs kept, canonical and hreflang on the primary, cookies set on the alias |
| Elementor | 4.3.6 | Verified (3 environments) | Button and heading links keep the alias; no console errors |
| Contact Form 7 | 6.2.4.1 | Verified (3 environments) | Submits through the alias REST API in a real browser (HTTP 200, processed) |
| Cache Enabler | 1.8.17 | Verified (3 environments) | See [caching.md](caching.md) |
| Gravity Forms | — | **Not tested** | Commercial plugin; no licensed copy was available. Nothing is assumed. To verify: a Gravity Forms licence (any tier) installed on a staging site, then run the browser form checks on each alias: AJAX and non-AJAX submission, confirmation redirect, multi-page forms, file upload. |
| Weglot | — | **Not tested** (integration simulated with stubs) | Integration code exists: translated canonical path, link pass after Weglot renders. To verify: a Weglot account and project API key (the free tier is enough for a test), connected to a staging site with at least one translated language. |
| WPML, TranslatePress | — | Not tested | |
| WPForms, Ninja Forms | — | Not tested | |
| WP Super Cache | 3.1.5 | Not verified | See [caching.md](caching.md) |
| WP Rocket, W3 Total Cache, LiteSpeed Cache | — | Not tested | See [caching.md](caching.md) for what to check |
| WooCommerce | — | Not supported on aliases | Checkout, account and payment flows are not supported on aliases. |
| Membership, SSO, OAuth plugins | — | Not supported on aliases | Sessions are per domain. |

## WordPress features

| Feature | Status |
|---|---|
| Menus, logo, content links, pagination, 404 pages | Verified (3 environments) |
| Mobile navigation (block navigation overlay, script modules) | Verified in Chrome at 390×844 (3 environments) |
| Trailing-slash and `?p=` / `?page_id=` canonical redirects | Verified: stay on the alias, no loops. Apache uses WordPress's `.htaccess` rewrites; Nginx uses `try_files` |
| REST API (`/wp-json/`) | Verified: answers on the alias; response data keeps primary URLs |
| `admin-ajax.php` from the frontend | Verified: same-origin on the alias |
| Theme fonts | Verified (6.4.5 and 7.1.4) |
| Core XML sitemap, `robots.txt`, feeds | Verified: primary URLs |
| Admin, login, cron, XML-RPC on an alias | Verified: stay on the primary |
| HTTPS on aliases | Verified with Nginx and Apache and a certificate covering every name |
| `admin-post.php` forms | Verified: post to the primary by default and the visitor lands on the primary. Opt-in per action keeps them on the alias ([review](review-0.1.1-beta.md#3-admin-postphp-forms)) |
| Comments for logged-out visitors | Expected to work (the comment form posts to the alias); not tested in a browser |

## Hosting

| Environment | Status |
|---|---|
| Nginx + PHP-FPM (self-managed VPS shape) | Verified locally; see [hosting/nginx.md](hosting/nginx.md) |
| Apache + PHP-FPM with `.htaccess` (cPanel shape) | Verified locally, without cPanel itself; see [hosting/apache-cpanel.md](hosting/apache-cpanel.md) |
| cPanel, Plesk, shared hosting | Not tested |
| LiteSpeed / OpenLiteSpeed | Not tested (not available in the test environment) |
| Kinsta, WP Engine | Not tested (needs a staging site on the platform) |

These local stacks show that the plugin works behind Nginx and Apache with PHP-FPM and HTTPS. They do not prove that any particular provider works: provider proxies, edge caches, redirects and certificate handling are not tested. See [hosting/README.md](hosting/README.md).

## Reporting results

If you test a combination that is not listed, please open an issue using the **Compatibility report** template.
