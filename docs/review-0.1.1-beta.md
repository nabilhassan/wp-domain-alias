# Review findings addressed in 0.1.1-beta

Date: 2026-11-06. Each finding was reproduced before it was fixed. "Verified" below means an automated test that fails without the fix and passes with it, run in the environments listed in [testing.md](testing.md).

| # | Finding | Severity | Status |
|---|---|---|---|
| 1 | Health check could run for about 130 seconds in one request | High (availability of the admin screen) | Fixed |
| 2 | "Alias" canonical mode sent contradictory signals | Medium (SEO) | Fixed: mode removed (Option A), stored values migrated |
| 3 | `admin-post.php` forms move visitors to the primary domain | Medium (usability) | Documented default; narrow opt-in added |
| 4 | DNS rebinding window in the health check | Low (security) | Fixed when WordPress uses cURL |
| 5 | CI failed on `main` after the 0.1.0-beta merge | Low (release process) | Fixed (test harness) |
| 6 | Nginx strips a trailing dot from `Host` | Informational | Test adjusted; no plugin change |

---

## 1. Health-check timeout

**Root cause.** `run_remote_checks()` requested every saved domain one after another inside a single `admin-post.php` request. Each request may take up to the 5-second timeout. With the maximum of 25 aliases plus the primary, the worst case is 26 × 5 s = 130 s (computed from the code, not measured), well past typical PHP (30 s) and proxy (60 s) limits. The page then fails with a gateway timeout and no results.

**Affected code.** `includes/class-health-check.php` (`run_remote_checks`), `includes/class-admin.php` (`handle_health_check`, `render_health`).

**Solution.**

- A run is a per-user state record (transient `wpda_health_<user>`, 10 minutes) holding a snapshot of the hosts and the results so far.
- **Without JavaScript**, each form post checks at most 3 domains (worst case about 15 s). The page shows the results so far, "Checked N of M domains" and a **Check the next domains** button.
- **With JavaScript** (`admin/admin.js`), each AJAX request checks one domain (worst case about 5 s), and results appear as they arrive. The markup is rendered and escaped on the server.
- A per-user step lock (`wpda_hc_step_<user>`) prevents two steps running in parallel. Starting a new run is still limited to once per 20 seconds.
- Unchanged: `manage_options` and a nonce on every endpoint (`wpda_health_check`, `wpda_health_continue`, `wpda_health_start`, `wpda_health_step`), destinations only from saved settings, no redirects followed, 1 MB response limit, TLS verification.
- No background jobs, cron or new dependencies.

**Possible regressions.** A run left half-finished expires after 10 minutes. A stale or replayed run id is refused (HTTP 410). Concurrent steps are refused (HTTP 409).

**Measured** (local PHP built-in server, 2026-11-06):

| Scenario | Result |
|---|---|
| 8 domains, no JavaScript (one answers after 8 s) | 3 requests: 190 ms, 5,089 ms, 47 ms |
| 25 aliases + primary, AJAX | 26 requests, longest 116 ms, total about 1 s |
| Worst case per request | 3 × 5 s (no JavaScript); 1 × 5 s (AJAX); plus DNS resolution time, which the 5 s timeout does not cover |

**Tests.** `tests/unit/test-admin-health.php` covers batching for 1, 5 and 25 aliases (1, 2 and 9 steps), the AJAX endpoints, rate limits, stale runs, and capability and nonce refusal. `tests/e2e/health.test.mjs` exercises the same against real HTTP, with:

- slow, redirecting, unresolvable and bad-certificate domains;
- repeated requests, a non-admin user, and invalid or missing nonces.

The browser suite runs the JavaScript path in Chrome.

## 2. Canonical modes: Option A chosen

**Root cause.** In "alias" mode, canonical, hreflang and `og:url` pointed at the alias. Sitemaps, `robots.txt`, JSON-LD and feeds still listed primary URLs, because they are generated from `home`. Search engines therefore received two different preferred URLs for the same page. Making alias mode consistent (Option B) would mean per-host sitemaps, structured data and hreflang clusters. In practice that is a second SEO plugin, which conflicts with the plugin's purpose: keep visitors on the domain they typed, and keep one canonical domain.

**Decision.** Option A: canonical URLs always point to the primary domain. Per-domain indexing is out of scope; a multi-site or multilingual-domain setup is the right tool for it.

**Affected code.**

- `includes/class-domain-manager.php`: setting removed, migration added.
- `includes/class-seo-handler.php`: the policy is primary-only.
- `includes/class-admin.php`: settings row, notice.
- `uninstall.php`.

**Migration.** On the first admin request after upgrading, `migrate_legacy_settings()` removes the stored `canonical_mode` key. If it was `alias`, a dismissible notice tells administrators about the change. The notice is dismissed through `admin-post.php` with a nonce, and the flag is deleted on uninstall. Until that first admin request, front-end requests already ignore the old value. The WordPress `home` and `siteurl` options are never touched.

**Possible regressions.** Sites that relied on alias canonicals will see search engines consolidate to the primary domain. This is the intended behaviour, and the notice explains it.

**Tests.** Unit tests cover migration and the notice, and one primary canonical tag, hreflang and `og:url` on every host. Integration covers a stored 0.1.0-beta `alias` value and the dismissible notice. With Yoast SEO and Rank Math, each page has exactly one canonical tag. `og:url` points to the primary, JSON-LD and sitemaps are unchanged, and Polylang translated URLs keep their translated slug on the primary domain.

## 3. `admin-post.php` forms

**Investigation** (real Chrome, `tests/e2e/browser.test.mjs`):

- A front-end form on an alias posts to `https://primary/wp-admin/admin-post.php`, because `admin-post.php` is a protected endpoint that is never moved.
- Nonces created for logged-out visitors validate on the primary. The handler runs.
- `wp_safe_redirect( wp_get_referer() )` uses `_wp_http_referer`, which is a path. The visitor therefore lands on the **primary** domain after submitting.
- Cookies set by the handler belong to the primary domain.
- `fetch()` from an alias page to the primary's `admin-post.php` cannot read the response. WordPress's `send_origin_headers()` only allows the primary origin.

**Decision.** Do not move `admin-post.php` globally: it serves logged-in actions, and keeping it on the primary domain is part of the protected-endpoint rule.

Do not add aliases to `allowed_http_origins` either. That would answer credentialed cross-origin requests from aliases for every AJAX and `admin-post.php` action, a much wider change than the problem needs.

**Solution (opt-in, default off).** A site can list specific actions:

```php
add_filter( 'wp_domain_alias_admin_post_actions', function () {
	return array( 'my_newsletter_signup' );
} );
```

For a listed action only:

- forms on an alias whose `action` field (or `?action=` query) matches post to the alias's `admin-post.php`;
- WordPress redirects issued while handling that request stay on the alias.

Action names must equal their `sanitize_key()` form. Unlisted actions, logged-in admin actions and all other protected endpoints are unchanged.

**Possible regressions.** None with the default (empty list). With an action listed, the handler must not depend on primary-domain cookies.

**Tests.** Unit tests cover form detection (hidden input, button, query string, unlisted actions, invalid names) and redirects. In the browser:

- by default the visitor lands on the primary (documented);
- with the action listed, the visitor and the cookie stay on the alias;
- a cross-origin `fetch` cannot read the response.

## 4. DNS rebinding in the health check

**Root cause.** Core's `wp_http_validate_url()` resolves the host to check for private addresses. The HTTP library then resolves it again to connect, so a hostile DNS server could answer differently the second time.

**Solution.**

- The health check resolves each host itself (`gethostbynamel`, with IPv6 as a fallback).
- It rejects the host if any address is private, reserved, loopback, link-local, CGNAT, benchmark, multicast or IPv4-mapped.
- It then pins the connection to a validated address with `CURLOPT_RESOLVE`, through the `http_api_curl` hook. The hook is removed straight after the request.

The primary host is exempt from the private-address rule, as in core's same-host behaviour. Aliases can be allowed only through core's `http_request_host_is_external` filter. Core's own check stays as a second layer.

**Limitations.** Pinning applies only when WordPress uses the cURL transport, which is the default whenever the cURL extension is loaded. With the streams transport, the original window remains. DNS resolution time is not bounded by the 5-second timeout.

**Tests.** Unit tests cover address classification and pinning. `tests/e2e/fixtures/pin-check.php` proves pinning end to end. The validated address is set to 127.0.0.1 while cURL would resolve the real address; the pinned request connects to 127.0.0.1. A control without the pin reaches the real host (HTTP 200).

## 5. CI failure on `main`

**Root cause.** On a fresh runner the cache suite runs first. On a freshly activated site, Cache Enabler skipped caching for 10 or more back-to-back requests, with or without WP Domain Alias active (measured locally). The suite's warm-up gave up after 6 requests, so CI failed while the PR run, on a different timing, passed.

**Solution.** The warm-up spaces requests 500 ms apart for up to 15 s and reports how many requests it needed. A fresh-site run in CI order passed 126 of 126 tests. The test summary also reports cancelled tests, which `fail 0` alone hides.

## 6. Nginx and a trailing-dot `Host`

Nginx removes a trailing dot from the `Host` header before PHP sees it. Apache and PHP's built-in server pass it through. The plugin rejects `alias.example.` itself. Behind Nginx, the request arrives as `alias.example` and is treated as that alias, which is correct for the same DNS name. The integration test now checks that no dotted hostname reaches the output, instead of assuming all servers behave alike.
