=== WP Domain Alias ===
Contributors: nabilhassan
Tags: domains, domain alias, multiple domains, canonical, hostname
Requires at least: 6.4
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.1.1
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Show one WordPress site on additional domains while keeping visitors on the domain they typed. Canonical URLs stay on the primary domain.

== Description ==

WP Domain Alias lets one WordPress installation answer on extra domains, for example `alias-example.com` next to `primary-example.com`, without redirecting visitors away and without duplicating content.

* Visitors stay on the domain they entered: menus, buttons, content links, pagination, forms and language switchers keep that hostname.
* WordPress keeps generating primary-domain URLs, so canonical tags, XML sitemaps and structured data keep pointing at the primary domain.
* Admin, login, cron and XML-RPC always stay on the primary domain.
* Only domains an administrator approves are used. Unknown Host headers are ignored.
* A read-only health check shows whether each domain answers, redirects, keeps its hostname and has the expected canonical URL. It checks a few domains at a time, so it never runs long.
* No database URL replacement, no change to the WordPress Address or Site Address, no external services, no telemetry.

This is domain aliasing for one site. It is not WordPress Multisite and does not support Multisite networks.

= What you must set up outside WordPress =

Configure every domain to route to the same WordPress installation before installing. For A records, point each root domain to the same hosting-assigned IP address; for providers requiring CNAMEs or managed DNS targets, follow their instructions instead. Include any www hostnames you intend to use, add all domains at your host and configure HTTPS. The plugin cannot create DNS records, hosting domain mappings, virtual hosts or SSL certificates. See the hosting guides in the `docs/` folder of the project repository.

= Tested with =

WordPress 6.4 and 7.1, Twenty Twenty-Four and Twenty Twenty-Five, Yoast SEO, Rank Math, Polylang, Elementor, Contact Form 7 and Cache Enabler, locally on PHP's built-in server and on Nginx and Apache with PHP-FPM over HTTPS. See the compatibility matrix in the repository for exact versions and for what has not been tested. Hosting providers have not been tested yet.

= Not supported =

Checkout and payment flows, membership or single sign-on logins and other cross-domain authentication on alias domains have not been tested and are not supported. Logged-in sessions do not carry over between domains.

== Installation ==

1. Point every domain to the same WordPress installation (the same A-record IP where supported, or the provider-required CNAME/target), add the domains at your host, and verify HTTPS.
2. Go to Plugins → Add New → Upload Plugin, choose `wp-domain-alias-0.1.1.zip` and activate it.
3. Go to Settings → WP Domain Alias and add the alias domain.
4. Run the health check.
5. Purge any page cache, then visit the alias and click through the menus and forms.

A Must-Use installation is also available for advanced users; see `docs/must-use.md`.

== Frequently Asked Questions ==

= Will this hurt SEO? =

Every alias page has a canonical tag pointing at the same page on the primary domain, and sitemaps list only primary URLs. Canonical tags are hints that search engines usually follow, not guarantees.

= Does it work with my page cache? =

The cache must store pages separately per domain. Most hosting caches and CDNs do. A cache that ignores the hostname would show one domain's links on another; the health check reports this. See `docs/caching.md`.

= Can I use a wildcard such as *.example.com? =

No. Add every domain separately, including the www and non-www forms you want to accept.

== Changelog ==

= 0.1.1 =
* First non-beta release. No functional changes from 0.1.1-beta; see the compatibility matrix for tested environments.

= 0.1.1-beta =
* Canonical URLs always point to the primary domain; the alias canonical option was removed and stored values are migrated with a one-time notice.
* The health check runs a few domains at a time (one per request with JavaScript), validates every resolved address and pins the connection to it.
* New filter `wp_domain_alias_admin_post_actions` keeps chosen `admin-post.php` forms on the alias.
* Tested on Nginx and Apache with PHP-FPM over HTTPS (locally).

= 0.1.0-beta =
* First public beta, generalised from a private single-site plugin.

== Upgrade Notice ==

= 0.1.1 =
Use the current 0.1.1 release and follow the DNS/HTTPS requirements in the README.

= 0.1.1-beta =
Canonical URLs on alias pages now always point to the primary domain. If you had chosen alias canonicals, that setting is removed. Test on a staging site first.

= 0.1.0-beta =
Beta release. Test on a staging site first.
