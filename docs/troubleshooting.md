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
- Forms that post to `admin-post.php` post to the primary domain by default. They work, but the visitor ends up on the primary domain afterwards and any cookie the handler sets belongs to the primary domain. If the handler doesn't need primary-domain cookies, keep that form on the alias by listing its action:
  ```php
  add_filter( 'wp_domain_alias_admin_post_actions', function () {
  	return array( 'my_form_action' );
  } );
  ```
  Details: [review-0.1.1-beta.md](review-0.1.1-beta.md#3-admin-postphp-forms).
- Forms with security tokens bound to a session or cookie may fail across domains. Test each form on each alias.

## "WP Domain Alias is installed twice"

The plugin exists both as a Must-Use plugin and as a regular plugin. Only the first copy loaded runs. Delete one of them; see [must-use.md](must-use.md).

## The health check says "Not checked"

- *Outgoing requests are blocked*: `WP_HTTP_BLOCK_EXTERNAL` is set in `wp-config.php`. Allow your own domains with `WP_ACCESSIBLE_HOSTS`, or check the domains in a browser.
- *The domain does not resolve*: there is no DNS record for it yet, or this server can't resolve it.
- *The domain resolves to a private or reserved network address*: the health check refuses private and reserved IP addresses on purpose. This is common in local development and behind some load balancers. Check in a browser instead. (The primary domain is exempt.)
- *Please wait*: a new check can be started once every 20 seconds.

## The health check stops part-way

Without JavaScript, each click checks up to three domains; use **Check the next domains** to continue. With JavaScript, the page checks one domain per request and shows each result as it arrives. If it stops ("The health check stopped"), reload the page: results so far are kept for 10 minutes, and the continue button resumes the run. A domain that doesn't answer is reported as timed out after 5 seconds.

## Logged in on the primary, logged out on the alias

Expected. Login cookies belong to one domain, and admin and login stay on the primary domain.

## Search engines index the alias

Check that alias pages have a single canonical tag pointing to the primary domain (view source, search for `rel="canonical"`). Canonical tags are hints; it can take weeks for search engines to consolidate. See [seo.md](seo.md).

## "The \"point canonical URLs to the alias\" setting was removed" notice

Shown once after upgrading from 0.1.0-beta when canonical URLs were set to point to the alias. That option was removed; canonical URLs now always point to the primary domain. Dismiss the notice. See [seo.md](seo.md#why-there-is-no-alias-canonical-mode).

## Reporting a bug

Open an issue with: WordPress and PHP versions, plugin version, host, cache layers, the health check output (replace your real domain names), and steps to reproduce. Do not post credentials or private URLs.
