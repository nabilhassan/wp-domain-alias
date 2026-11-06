# WP Domain Alias

Show one WordPress site on several approved domains while keeping visitors on the domain they typed. Canonical URLs, sitemaps and structured data keep pointing at the primary domain.

> **Status: 0.1.0-beta.** Tested locally only (see [what was tested](#what-has-been-tested)). No hosting provider has been tested yet. Try it on a staging copy first.

![Settings screen](assets/screenshots/settings.png)

## What it does

Suppose your site lives at `primary-example.com`, and `alias-example.com` also points to the same WordPress installation. Without this plugin, every menu link, button and form on `alias-example.com` sends visitors to `primary-example.com`. With it:

- Navigation links, buttons, pagination, forms and language switchers on an alias keep the alias hostname.
- Canonical tags (by default), sitemaps and JSON-LD stay on the primary domain, so search engines see one site.
- Admin, login, sign-up, cron and XML-RPC always stay on the primary domain.
- Only domains an administrator adds are used. Requests with any other `Host` header are not treated as aliases.
- A health check shows whether each domain answers, redirects, keeps its hostname, and has the expected canonical URL.

What it does **not** do:

- It does not change the database, run search-replace, or touch the WordPress Address / Site Address settings.
- It does not create DNS records, hosting domain mappings, virtual hosts or SSL certificates. You set those up at your host first.
- It makes no outgoing requests except the health check that an admin starts. There is no telemetry, licensing or remote service.

## When to use it — and when not to

| You want… | Use |
|---|---|
| Several domains to show the **same** site, each keeping its own hostname | **WP Domain Alias** |
| Old domains to send visitors to one main domain | A 301 redirect at your host or CDN (no plugin needed) |
| Separate sites, each with its own content, users or settings | WordPress Multisite (not supported by this plugin) |
| Checkout, membership or single sign-on on each domain | Not supported. Keep those flows on the primary domain. |

## Requirements

- WordPress 6.4 or newer (tested on 6.4.5 and 7.1.4)
- PHP 7.4 or newer (tested on PHP 8.4)
- A single-site installation. Multisite is not supported.
- Each alias must already reach this WordPress installation, ideally over HTTPS with a valid certificate.

## Installation

1. Set up each alias at your host (domain mapping, DNS, HTTPS). See [hosting guides](docs/hosting/README.md).
2. Download `wp-domain-alias-<version>.zip` from the [Releases](../../releases) page.
3. In WordPress go to **Plugins → Add New → Upload Plugin**, choose the ZIP, install and activate.
4. Go to **Settings → WP Domain Alias**, add each alias (add `www.` and non-`www` forms separately if both are used).
5. Click **Run health check**.
6. Purge your page cache, then visit each alias and click through menus, forms and any language switcher.

A Must-Use installation is available for advanced users: [docs/must-use.md](docs/must-use.md).

Step-by-step guides: [docs/installation.md](docs/installation.md).

## Settings

| Setting | Default | What it does |
|---|---|---|
| Aliases | none | Exact hostnames, up to 25. Wildcards, IP addresses, ports and paths are rejected. Internationalised domains are stored as punycode. |
| Hostname preservation | on | Off = aliases still load, but links point to the primary domain. |
| Canonical URLs | primary | *Primary*: canonical, hreflang and `og:url` on alias pages point to the primary domain (recommended). *Alias*: they point to the alias being viewed. |
| Diagnostics | off | Adds an `X-WP-Domain-Alias` response header and an HTML comment on alias pages, to help debug caching. |

The primary domain is read from **Settings → General → Site Address** and cannot be changed here.

## Health check

![Health check results](assets/screenshots/health-check.png)

The health check runs only when an administrator clicks the button. It requests each saved domain's homepage from the server, without following redirects, with a 5-second timeout and a 1 MB response limit. Requests to private or reserved IP addresses are refused, and it can be run once every 20 seconds per user. It reports:

- whether the domain answers with HTTP 200 and a valid certificate,
- any redirect, and whether it goes to another domain,
- whether internal links keep the alias hostname (a cache serving another domain's copy shows up here),
- the canonical URL and whether it matches the setting.

It does not run JavaScript, submit forms or test the site in a browser.

## How it works

WordPress keeps generating primary-domain URLs. On an approved alias page view, the plugin:

1. buffers the final HTML and changes only navigation attributes (`a`/`area` `href`, `form action`, `formaction`, `data-column-clickable`) that point to this site, using WordPress's HTML API (`WP_HTML_Tag_Processor`);
2. serves scripts, stylesheets, the script-module import map and theme fonts from the alias, because browsers block module scripts and web fonts loaded from another origin without CORS headers (images stay on the primary domain);
3. keeps REST API and `admin-ajax.php` requests made by frontend scripts on the alias, so forms and AJAX stay same-origin;
4. keeps WordPress's own canonical redirects (trailing slash, etc.) on the alias, and cancels only redirects that would switch the visitor to another host.

Details: [docs/architecture.md](docs/architecture.md).

![Mobile menu on an alias](assets/screenshots/mobile-alias.png)

## SEO

- Default: every alias page has a canonical tag pointing to the same page on the primary domain. XML sitemaps (core, Yoast SEO, Rank Math) and JSON-LD list only primary URLs.
- Canonical tags are hints that search engines usually follow, not guarantees.
- Hreflang alternates follow the canonical setting.

More: [docs/seo.md](docs/seo.md).

## Caching

Your page cache and CDN must store a separate copy per hostname. Most do by default. A cache keyed only on the path would show one domain's links on another. The health check detects this. Purge all caches after adding or removing an alias.

Cache Enabler is supported directly: purges are repeated for each alias. Details and per-cache notes: [docs/caching.md](docs/caching.md).

## What has been tested

All testing so far was done in a local environment: PHP's built-in web server with SQLite, using `.test` hostnames. No live hosting provider has been tested.

| Area | Tested with |
|---|---|
| WordPress | 6.4.5 and 7.1.4 (Twenty Twenty-Four, Twenty Twenty-Five) |
| SEO | WordPress core, Yoast SEO 28.8, Rank Math 1.0.281 |
| Multilingual | Polylang 3.9.1 |
| Builders and forms | Elementor 4.3.6, Contact Form 7 6.2.4.1 |
| Cache | Cache Enabler 1.8.17; simulated proxy caches keyed by host + URL and by path only |
| Browser | Chrome via Playwright, desktop and 390×844 mobile |

Not tested yet: Weglot (integration code exists, unit-tested with stubs only), WPML, Gravity Forms, WooCommerce, WP Rocket, LiteSpeed Cache, W3 Total Cache, any CDN, and all hosting providers.

Full matrix: [docs/compatibility.md](docs/compatibility.md). How to run the tests: [docs/testing.md](docs/testing.md).

## Known limitations

- **Multisite** is not supported. The plugin refuses network activation and does nothing on a network.
- **Logged-in sessions** are per domain. Admin and login stay on the primary domain, so logged-in features on alias pages (admin bar, comment forms for logged-in users) behave as for a logged-out visitor.
- **Checkout, membership, SSO and OAuth** flows on aliases are not supported. Use the `wp_domain_alias_rewrite_url` filter to keep such URLs on the primary domain.
- **`admin-post.php` form handlers** stay on the primary domain, so such forms post cross-domain.
- **Page caches keyed only by path** are not supported.
- **WordPress below 6.7**: font URLs that other plugins print inline in `<style>` tags are not adjusted. Theme and Font Library fonts are.
- **Structured data** (JSON-LD) always uses primary URLs, even in alias canonical mode.
- **The www/non-www form of the primary domain** is usually redirected by the host before WordPress runs. The plugin cannot override that.
- **DNS rebinding**: the health check relies on WordPress core's URL validation, which resolves the hostname separately from the request. This is a limitation in WordPress core.
- **Hosting providers**: none tested yet. The hosting guides are based on each provider's documented features and must be verified.

## Troubleshooting

See [docs/troubleshooting.md](docs/troubleshooting.md). Quick checks:

- **Alias redirects to the primary domain**: a redirect rule at the host, CDN or a redirect plugin. The health check shows where it goes.
- **Links on an alias point to another domain**: a page cache served another domain's copy. Purge and check the cache key includes the host.
- **Fonts or the mobile menu broken on an alias**: an optimisation plugin or CDN is rewriting asset URLs. See [docs/caching.md](docs/caching.md#fonts-and-cors).

## Developer filters

| Filter | Purpose |
|---|---|
| `wp_domain_alias_aliases` | Modify the alias list (results are validated again). |
| `wp_domain_alias_rewrite_url` | Return `false` to keep a specific URL on its original host. |
| `wp_domain_alias_url_attributes` | Change which HTML attributes count as navigation URLs. |
| `wp_domain_alias_same_origin_assets` | Return `false` to leave scripts, styles and fonts on the primary domain. |
| `wp_domain_alias_buffer_output` | Return `false` to skip the HTML pass for a request. |

## Security

See [SECURITY.md](SECURITY.md) for how to report a vulnerability, and [docs/security-review.md](docs/security-review.md) for the threat model and review notes.

## Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md).

## License

GPL-2.0-or-later. See [LICENSE](LICENSE).
