# Troubleshooting

Start with **Settings → WP Domain Alias → Run health check**. Most problems show up there.

## The alias redirects to the primary domain

The redirect happens before WordPress runs, or in another plugin. The health check's **Redirects** row shows the target. Check, in this order:

1. Hosting control panel: the alias may have been added as a redirect instead of an attached domain.
2. CDN rules (page rules, redirect rules, "always use primary domain").
3. `.htaccess` or Nginx rules forcing one hostname.
4. Redirect plugins (Redirection, Yoast Premium redirects, Rank Math redirects).
5. The www/non-www form of the **primary** domain is usually redirected by the host on purpose. The plugin cannot change that.

## The alias shows a certificate error

The host has not issued a certificate covering the alias yet. Certificates are usually issued only after DNS points to the host. The health check reports "The HTTPS certificate could not be verified".

## Links on an alias point to the primary domain

- Is the alias in the list, spelled exactly as visitors type it (with or without `www.`)?
- Is **Hostname preservation** on?
- Is a page cache serving a copy built before the alias was added? Purge all caches.
- Is a proxy rewriting the `Host` header? Enable Diagnostics. If alias pages have no `X-WP-Domain-Alias` header and no HTML comment, WordPress did not see the alias host.
- Links built by JavaScript at runtime (not present in the HTML) are not changed. Most use relative URLs or the REST root, which are handled.

## Links on one domain point to another alias

A cache is serving a copy generated for another domain. The cache key must include the hostname. See [caching.md](caching.md).

## The mobile menu, icons or fonts are broken on an alias

Something serves scripts or fonts from a fixed domain after the plugin runs, typically an asset optimisation plugin or a CDN URL rewrite. Browsers block cross-origin fonts and module scripts without CORS headers. Open the browser console on the alias and look for CORS errors, then see [caching.md → Fonts and CORS](caching.md#fonts-and-cors).

On WordPress older than 6.7, fonts that other plugins print inline are not adjusted; update WordPress or add the CORS header.

## A form does not submit on an alias

- Forms that post to the page itself, to `admin-ajax.php` or to the REST API are kept on the alias.
- Forms that post to `admin-post.php` post to the primary domain by design. They work if the primary domain accepts the request, but any cookie the handler sets is set on the primary domain.
- Forms with security tokens bound to a session or cookie may fail across domains. Test each form on each alias.

## "WP Domain Alias is installed twice"

The plugin exists both as a Must-Use plugin and as a regular plugin. Only the first copy loaded runs. Delete one of them; see [must-use.md](must-use.md).

## The health check says "Not checked"

- *Outgoing requests are blocked*: `WP_HTTP_BLOCK_EXTERNAL` is set in `wp-config.php`. Allow your own domains with `WP_ACCESSIBLE_HOSTS`, or check the domains in a browser.
- *The domain does not resolve, or resolves to a private address*: the health check refuses private and reserved IP addresses on purpose. This is common in local development and behind some load balancers. Check in a browser instead.
- *Please wait*: the check can be run once every 20 seconds.

## Logged in on the primary, logged out on the alias

Expected. Login cookies belong to one domain, and admin and login stay on the primary domain.

## Search engines index the alias

Check that alias pages have a single canonical tag pointing to the primary domain (view source, search for `rel="canonical"`). Canonical tags are hints; it can take weeks for search engines to consolidate. See [seo.md](seo.md).

## Reporting a bug

Open an issue with: WordPress and PHP versions, plugin version, host, cache layers, the health check output (replace your real domain names), and steps to reproduce. Do not post credentials or private URLs.
