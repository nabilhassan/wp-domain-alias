# Hosting guides

**None of these environments has been tested with WP Domain Alias yet.** The only tested environment is the local one described in [../testing.md](../testing.md): PHP's built-in web server with SQLite. These guides describe what each type of hosting must provide, based on the providers' publicly documented features. Menu names change; follow your provider's current documentation, and test on staging.

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
| Generic shared hosting (cPanel, Plesk, DirectAdmin and similar), no SSH | [shared-hosting.md](shared-hosting.md) | Not tested |
| Apache / cPanel | [apache-cpanel.md](apache-cpanel.md) | Not tested |
| Nginx (self-managed) | [nginx.md](nginx.md) | Not tested |
| LiteSpeed / OpenLiteSpeed | [litespeed.md](litespeed.md) | Not tested |
| Kinsta | [kinsta.md](kinsta.md) | Not tested |
| WP Engine | [wp-engine.md](wp-engine.md) | Not tested |
