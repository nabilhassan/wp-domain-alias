# Hosting guides

**No hosting provider has been tested with WP Domain Alias.** Two server stacks were verified locally (0.1.1-beta): Nginx + PHP-FPM and Apache + PHP-FPM, both over HTTPS ([testing.md](../testing.md#server-stacks-nginx-and-apache)). That shows the plugin works with those servers. It doesn't show that any provider's proxies, edge caches, redirects or certificate handling work with it.

The provider guides describe what each platform must offer, based on the provider's public documentation. Menu names change, so follow the provider's current documentation and test on staging.

If you test the plugin on one of these platforms, please open an issue with the result so the compatibility matrix can be updated.

## What every host must provide

For each alias:

1. **The domain is attached to the same WordPress site.** Not a separate site, not a redirect, not set as the primary domain.
2. **DNS** points the alias to the host.
3. **A valid HTTPS certificate** covers the alias.
4. **No server-level redirect** from the alias to the primary domain. Many hosts add one automatically between `www` and non-`www`, or when a domain is added as "redirect".
5. **The cache key includes the hostname** (see [../caching.md](../caching.md)).
6. **The `Host` header reaches PHP unchanged.** If a proxy or load balancer rewrites `Host` to the primary domain, the plugin cannot see the alias. It reads `HTTP_HOST` only and deliberately ignores `X-Forwarded-Host`.

Then install the plugin and run the health check from **Settings → WP Domain Alias**.

## Guides

| Environment | Guide | Status |
|---|---|---|
| Nginx + PHP-FPM (self-managed) | [nginx.md](nginx.md) | Verified locally (Nginx 1.31.8, PHP-FPM 8.4.13, HTTPS) |
| Apache + PHP-FPM with `.htaccess` | [apache-cpanel.md](apache-cpanel.md) | Verified locally (Apache 2.4.67, PHP-FPM 8.4.13, HTTPS); cPanel itself not tested |
| Generic shared hosting (cPanel, Plesk, DirectAdmin and similar), no SSH | [shared-hosting.md](shared-hosting.md) | Not tested |
| LiteSpeed / OpenLiteSpeed | [litespeed.md](litespeed.md) | Not tested |
| Kinsta | [kinsta.md](kinsta.md) | Not tested |
| WP Engine | [wp-engine.md](wp-engine.md) | Not tested |

## What was verified on each local stack

Every item below is an automated test that passed on both stacks over HTTPS:

- 144 tests per stack: integration (79), compatibility (30), cache (17), browser (18).
- Plus 9 Nginx FastCGI cache scenarios on the Nginx stack.

| Area | Result |
|---|---|
| Routing by `Host` (server_name / ServerAlias, default server) | Pass. Spoofed, look-alike and port-suffixed hosts get primary output |
| HTTPS | Pass. Certificate verified against the test CA for every name; links on aliases use `https://` |
| Primary and alias homepages, internal pages, 404 | Pass |
| Navigation, desktop and mobile (Chrome) | Pass. No console errors or failed requests |
| Permalink redirects (missing trailing slash, `?p=`, `?page_id=`) | Pass. Stay on the alias, no loops |
| Forms (plain form, `formaction`, Contact Form 7 via REST, `admin-post.php` opt-in) | Pass |
| REST API and `admin-ajax.php` on an alias | Pass |
| Admin and login | Pass. Stay on the primary; health check runs over HTTPS with certificate verification |
| Canonicals, `og:url`, hreflang, sitemaps (core, Yoast SEO, Rank Math) | Pass. Primary URLs |
| Multilingual (Polylang) | Pass |
| Page cache: Cache Enabler (both stacks), Nginx FastCGI (Nginx) | Pass. See [../caching.md](../caching.md) |
| Cache invalidation | Pass: Cache Enabler purges alias copies; Nginx FastCGI by expiry or manual purge |

Differences found between the stacks:

- **Nginx removes a trailing dot** from the `Host` header before PHP sees it; Apache passes it through. No effect on output.
- **Apple's Apache build has no TLS 1.3** (it is linked against LibreSSL). This does not affect the plugin.

Not covered locally: DNS (hosts were mapped to 127.0.0.1), publicly trusted certificates, HTTP→HTTPS redirects, load balancers, CDNs, and the providers' own caches.
