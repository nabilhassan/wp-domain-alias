# Changelog

All notable changes to this project are documented here. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the project uses [Semantic Versioning](https://semver.org/).

## [Unreleased]

### Documentation

- README and installation guide list each DNS and hosting prerequisite separately: same installation, A/AAAA or provider CNAME targets, `www` and non-`www`, hosting-dashboard mapping, HTTPS, no redirects to the primary, WordPress addresses unchanged.
- Issue templates use 0.1.1 as the example version.

## [0.1.1] - 2026-11-06

First non-beta release. No runtime behavior changes relative to 0.1.1-beta. The current documentation now explains DNS and hosting prerequisites explicitly. Previous prerelease entries are retained below for historical accuracy.

## [0.1.1-beta] - 2026-11-06

Fixes the findings of the 0.1.0-beta review and extends testing to real web servers. Details: [docs/review-0.1.1-beta.md](docs/review-0.1.1-beta.md).

### Changed
- **Canonical URLs always point to the primary domain.** The "point canonical URLs to the alias" option is removed because it contradicted sitemaps and structured data. A stored 0.1.0-beta value is removed on the first admin page load, with a one-time notice that can be dismissed. WordPress's `home` and `siteurl` are never changed.
- **The health check no longer runs as one long request.** With JavaScript it checks one domain per request and shows results as they arrive. Without JavaScript it checks up to three domains per click and offers **Check the next domains**. Worst case per request is 5 s and 15 s respectively, down from about 130 s with 25 aliases. Results are kept for 10 minutes.
- The Cache Enabler reminder now uses Cache Enabler's own label ("Expiration").

### Added
- `wp_domain_alias_admin_post_actions` filter (default: none). It keeps listed `admin-post.php` frontend forms, and the redirect after them, on the alias.
- `wp_domain_alias_health_check_ips` filter to supply the addresses the health check uses for a host. They are still validated.
- Local Nginx + PHP-FPM and Apache + PHP-FPM test stacks over HTTPS (`tests/hosting/`). The integration, compatibility, cache and browser suites now also run over HTTPS.
- Nginx FastCGI cache tests, health-check end-to-end tests, a performance benchmark (`tests/perf/`), and more Cache Enabler scenarios (request order, menu edits, expiry).

### Security
- The health check validates every address a domain resolves to (IPv4 and IPv6, including CGNAT, benchmark, multicast and IPv4-mapped ranges), then pins the connection to the checked address with cURL. This closes the DNS-rebinding window between validation and connection.
- Health-check endpoints: capability and nonce checks on each of the four endpoints. Stale or concurrent steps are refused.
- GitHub Actions are pinned to commit SHAs.

### Fixed
- CI failed on a fresh runner because the Cache Enabler test warm-up gave up too early.

## [0.1.0-beta] - 2026-11-06

First beta, generalised from a private single-site plugin.

### Added
- Settings → WP Domain Alias screen. The primary domain is shown read-only; aliases can be added and removed with validation (punycode for internationalised domains, max 25). Settings for hostname preservation, canonical mode and diagnostics. Configuration status and version information.
- Hostname preservation on approved aliases: navigation links, forms, `formaction`, pagination, language switchers, REST root and `admin-ajax.php` stay on the alias. Scripts, stylesheets, the script-module import map and theme fonts are served same-origin.
- Canonical policy: primary domain by default (canonical, hreflang, `og:url`, `twitter:url`), optional alias mode. Works with core, Yoast SEO and Rank Math filters.
- Canonical redirects stay on the alias without redirect loops.
- Admin-only health check with SSRF protections: status, HTTPS, redirects, retained hostname and canonical per domain.
- Cache Enabler integration: purges are repeated for each alias.
- Weglot integration (experimental, untested against Weglot itself).
- Optional Must-Use loader with duplicate-copy protection.
- Uninstall cleanup.
- Developer filters: `wp_domain_alias_aliases`, `wp_domain_alias_rewrite_url`, `wp_domain_alias_url_attributes`, `wp_domain_alias_same_origin_assets`, `wp_domain_alias_buffer_output`.
- Unit, integration, compatibility, cache, browser and lifecycle test suites; GitHub Actions CI and release workflows.

### Security
- Only `HTTP_HOST` is trusted, with an exact allowlist; forwarded-host headers are ignored.
- Admin, login, sign-up, cron, XML-RPC and `admin-post.php` always stay on the primary domain, including encoded path spellings.

[Unreleased]: https://github.com/nabilhassan/wp-domain-alias/compare/v0.1.1...HEAD
[0.1.1]: https://github.com/nabilhassan/wp-domain-alias/compare/v0.1.1-beta...v0.1.1
[0.1.1-beta]: https://github.com/nabilhassan/wp-domain-alias/compare/v0.1.0-beta...v0.1.1-beta
[0.1.0-beta]: https://github.com/nabilhassan/wp-domain-alias/releases/tag/v0.1.0-beta
