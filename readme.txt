=== WP Domain Alias ===
Contributors: nabilhassan
Tags: domains, domain alias, multiple domains, canonical, hostname
Requires at least: 6.4
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.1.0-beta
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Show one WordPress site on additional domains while keeping visitors on the domain they typed. Canonical URLs stay on the primary domain.

== Description ==

WP Domain Alias lets one WordPress installation answer on extra domains, for example `alias-example.com` next to `primary-example.com`, without redirecting visitors away and without duplicating content.

* Visitors stay on the domain they entered: menus, buttons, content links, pagination, forms and language switchers keep that hostname.
* WordPress keeps generating primary-domain URLs, so canonical tags, XML sitemaps and structured data keep pointing at the primary domain (recommended for SEO). An optional mode points canonicals at the alias instead.
* Admin, login, cron and XML-RPC always stay on the primary domain.
* Only domains an administrator approves are used. Unknown Host headers are ignored.
* A read-only health check shows whether each domain answers, redirects, keeps its hostname and has the expected canonical URL.
* No database URL replacement, no change to the WordPress Address or Site Address, no external services, no telemetry.

This is domain aliasing for one site. It is not WordPress Multisite and does not support Multisite networks.

= What you must set up outside WordPress =

The plugin cannot create DNS records, hosting domain mappings, virtual hosts or SSL certificates. Each alias must already reach this WordPress installation over HTTPS before you add it. See the hosting guides in the `docs/` folder of the project repository.

= Tested with =

WordPress 6.4 and 7.1, Twenty Twenty-Four and Twenty Twenty-Five, Yoast SEO, Rank Math, Polylang, Elementor, Contact Form 7 and Cache Enabler, on a local test server. See the compatibility matrix in the repository for exact versions and for what has not been tested. Hosting providers have not been tested yet.

= Not supported =

Checkout and payment flows, membership or single sign-on logins and other cross-domain authentication on alias domains have not been tested and are not supported. Logged-in sessions do not carry over between domains.

== Installation ==

1. Make sure the alias domain already points to this site and has HTTPS (see the hosting guides).
2. Go to Plugins → Add New → Upload Plugin, choose `wp-domain-alias-0.1.0-beta.zip` and activate it.
3. Go to Settings → WP Domain Alias and add the alias domain.
4. Run the health check.
5. Purge any page cache, then visit the alias and click through the menus and forms.

A Must-Use installation is also available for advanced users; see `docs/must-use.md`.

== Frequently Asked Questions ==

= Will this hurt SEO? =

By default every alias page has a canonical tag pointing at the same page on the primary domain, and sitemaps list only primary URLs. Canonical tags are hints that search engines usually follow, not guarantees.

= Does it work with my page cache? =

The cache must store pages separately per domain. Most hosting caches and CDNs do. A cache that ignores the hostname would show one domain's links on another; the health check reports this. See `docs/caching.md`.

= Can I use a wildcard such as *.example.com? =

No. Add every domain separately, including the www and non-www forms you want to accept.

== Changelog ==

= 0.1.0-beta =
* First public beta, generalised from a private single-site plugin.

== Upgrade Notice ==

= 0.1.0-beta =
Beta release. Test on a staging site first.
