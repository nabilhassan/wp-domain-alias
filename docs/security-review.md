# Security and performance review — 0.1.0-beta

Review date: 2026-11-06. Scope: every runtime file shipped in the release ZIP (`wp-domain-alias.php`, `uninstall.php`, `includes/`, `admin/`, `mu-loader/`). Method: manual code review against the threat model below, automated tests for each control, and PHPCS (WordPress Coding Standards security sniffs). This is a self-review by the author, not an independent audit.

## Threat model

| Actor | Can control | Goal |
|---|---|---|
| Anonymous visitor | `Host` and every other request header, path, query, body | Make the site link, redirect or post to a host they control (host-header poisoning, open redirect); poison a shared cache; reach admin or login through an alias; inject markup |
| Logged-in non-admin | The above, plus a session on the primary domain | Change settings; trigger outbound requests (SSRF) |
| Administrator | Plugin settings | Trusted. Still, input is validated so mistakes cannot widen the allowlist |
| Other plugins | Filters, option values, output | Out of scope, but stored values are re-validated |

## Controls

| Risk | Control | Verified by |
|---|---|---|
| Host-header poisoning | Only `HTTP_HOST` is read; it must exactly match an approved alias after lowercasing and default-port removal. `X-Forwarded-Host` and similar headers are ignored. Unknown hosts get unmodified primary output. | Unit tests; integration "host header safety" (spoofed, IP, port, look-alike, uppercase, trailing dot, `X-Forwarded-Host`) |
| Allowlist widening | Admin input rejects wildcards, IPs, ports, paths, queries, `@`, backslashes, control characters, non-http schemes, and over-long names. IDNs are converted to punycode. Stored and filtered values are re-validated on every read. Maximum 25 aliases. | Unit tests (host validator, corrupted option, filtered aliases) |
| Open redirect | Redirect filters only move URLs whose host is already this site's; external destinations are never changed. `allowed_redirect_hosts` gains only approved aliases. | Unit and integration tests |
| Admin/login on an alias | Admin, login, sign-up, activate, cron, XML-RPC and `admin-post.php` are never moved to an alias. Requests to them are not treated as alias views, including encoded (`/wp%2Dlogin.php`) and doubled-slash (`//wp-admin/`) paths and anything `is_login()` reports. | Unit tests; integration (admin → primary login, login form posts to primary) |
| Markup injection | URLs are changed only through `WP_HTML_Tag_Processor::set_attribute()` (escaped by core). Import maps are re-encoded with `JSON_HEX_TAG`. Diagnostics output is escaped. All admin output uses `esc_html`/`esc_attr`/`esc_url`. | Unit test with `"><script>` payloads; PHPCS escaping sniffs |
| CSRF / privilege | Every admin action posts to `admin-post.php` with a per-action nonce and checks `manage_options`. Settings page requires `manage_options`. | Unit (handlers refuse without capability or nonce); integration (forged request refused, anonymous → login) |
| SSRF via health check | Admin-only, nonce-protected, POST. Destinations are only the saved primary and alias hosts, never request input. `wp_safe_remote_get` with `reject_unsafe_urls` refuses private and reserved addresses. No redirects followed, 5 s timeout, 1 MB limit, no cookies, TLS verification on. Rate-limited to once per 20 s per user. Error messages are mapped to fixed text, so no remote content or internal detail is shown. | Unit (error mapping, rate limit); integration (runs only with nonce; request input not used as a destination) |
| Cache poisoning | Output differs only by approved host, never by an unkeyed header. Caches keyed on host are safe; caches keyed on path only are documented as unsupported and detected by the health check. | Cache suite (host-keyed proxy isolated; path-keyed proxy failure demonstrated) |
| Data handling | No telemetry, external API, licensing or background request. One option and short-lived per-user transients; all removed on uninstall. No files are written. | Code review; lifecycle uninstall test |
| Multisite confusion | Activation refused; no behaviour registered on a network. | Unit tests |

## Findings fixed during this review

| # | Severity | Finding | Fix |
|---|---|---|---|
| 1 | Low | A request for `/wp%2Dlogin.php` or `//wp-login.php` on an alias runs the login screen but was treated as an alias page view, so the HTML pass and redirect filters ran on it. Form targets still pointed to the primary domain, so there was no credential exposure, but the protected-path rule was bypassable. | Paths are decoded and repeated slashes collapsed before matching; a leading `//` no longer parses as a host; `is_login()` is checked. Unit and live checks added. |
| 2 | Low (integrity) | Removing an alias left Cache Enabler's cached copies for that host. They kept serving alias links indefinitely when cache expiry was off. | The purge now includes aliases removed in the same request. Test added, with a negative control. |
| 3 | Low (robustness) | Settings were cached for the whole request and not refreshed when the option changed outside the plugin (WP-CLI, imports, other plugins). | Cache cleared on `add/update/delete_option_wp_domain_alias_settings` and on `init`. |
| 4 | Performance | Cache Enabler integration purged every alias on autosaves and revisions. | Revisions, autosaves and auto-drafts are ignored. Test added. |
| 5 | Performance | Large pages were slow on aliases: about 150 ms extra for 2,000 links, caused by core's tag processor slowing down as unapplied edits pile up, plus per-URL re-validation of the alias list. | Edits applied every 100 changes; alias and own-host lists cached per request. Now about 35 ms for 2,000 links, about 3 ms for a typical page. Test covers correctness across flushes. |

Earlier development fixes with security relevance: NUL bytes are rejected before trimming (they used to be silently stripped), and `is_ajax_path()` no longer calls the filtered `admin_url()`, which could recurse and exhaust memory.

## Residual risks (accepted, documented)

- **DNS rebinding / TOCTOU in the health check.** Core validates the destination by resolving the hostname, then the HTTP library resolves it again. A hostile DNS server for a saved alias could answer differently the second time. Mitigations: only administrators can trigger it, destinations are limited to domains the administrator saved, no redirects are followed and the response is not shown. A full fix needs IP pinning in WordPress core's HTTP API.
- **Shared caches keyed on path only** would serve one domain's HTML on another. This cannot be prevented from inside WordPress. It is documented and detected by the health check (homepage only).
- **Third-party output after the buffer** (for example a plugin that prints after `shutdown`, or a JavaScript-built link) is not adjusted. Impact: links leave the alias. No security impact.
- **`wp_domain_alias_rewrite_url` and the other filters** let site code widen what is rewritten. Code with that access is already trusted.
- **Transients with a persistent object cache** are not in the options table, so uninstall cannot delete them. They expire within 5 minutes.
- **GitHub Actions** use version tags (`@v4`), not commit SHAs. Dependabot keeps them updated. The workflows use no secrets beyond the automatic `GITHUB_TOKEN`.

## Performance summary

Measured locally (PHP 8.4, built-in server, WordPress 7.1.4, Twenty Twenty-Five), averages of 10 warm requests:

| Page | Primary | Alias | Overhead |
|---|---|---|---|
| Homepage | 46 ms | 49 ms | ~3 ms |
| About page | 39 ms | 41 ms | ~2 ms |
| Synthetic page with 2,000 internal links, 25 aliases | 47 ms | 83 ms | ~36 ms |

- Primary-domain requests do no HTML work: every hook returns early when the host is not an alias.
- The alias HTML pass is one linear scan with `WP_HTML_Tag_Processor`. It is skipped when the page does not mention any own host or is not HTML.
- Memory: the page is buffered once, so peak memory grows by roughly the size of the HTML.
- With a page cache, the pass runs once per page per alias; cached hits cost nothing.
- Settings are one autoloaded option. No database queries are added to front-end requests.
- The admin screen makes no outgoing requests unless the health check button is pressed.
