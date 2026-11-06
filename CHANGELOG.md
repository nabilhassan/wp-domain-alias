# Changelog

All notable changes to this project are documented here. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the project uses [Semantic Versioning](https://semver.org/).

## [Unreleased]

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

[Unreleased]: https://github.com/nabilhassan/wp-domain-alias/compare/v0.1.0-beta...HEAD
[0.1.0-beta]: https://github.com/nabilhassan/wp-domain-alias/releases/tag/v0.1.0-beta
