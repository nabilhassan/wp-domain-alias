# Architecture

WP Domain Alias is built on one rule: **WordPress keeps generating primary-domain URLs.** The plugin never filters `home_url`, `site_url`, `home` or `siteurl`. Anything that is generated from those values (canonical tags, sitemaps, feeds, JSON-LD, REST data, emails) therefore stays on the primary domain automatically. On approved alias page views, only the URLs a visitor clicks or a browser must load same-origin are changed. This happens in the final HTML or through narrow endpoint filters.

## Request flow

```
Request ──► HTTP_HOST in allowlist? ──no──► nothing changes (primary behaviour)
                     │yes
                     ▼
        protected path / admin / AJAX / REST / cron / WP-CLI? ──yes──► nothing changes
                     │no  (frontend page view on an alias)
                     ▼
  template_redirect (-100): start output buffer
  filters: rest_url, admin_url (admin-ajax only), script_module_loader_src,
           theme_file_uri (fonts), font_dir, redirect_canonical, wp_redirect,
           SEO canonical filters
                     ▼
  final HTML ──► WP_HTML_Tag_Processor pass ──► response
```

## Components

| File | Class | Responsibility |
|---|---|---|
| `wp-domain-alias.php` | — | Header, constants, duplicate-copy guard, activation hooks, boot. |
| `includes/class-host-validator.php` | `Host_Validator` | Validates admin input and the request `Host`. Rejects wildcards, IPs, ports, paths, userinfo and control characters. Converts IDNs to punycode. |
| `includes/class-domain-manager.php` | `Domain_Manager` | Settings (one option, re-validated on every read), primary host from `home`, approved aliases, current request alias. |
| `includes/class-url-handler.php` | `Url_Handler` | Decides if a request is an alias frontend view, or an opted-in `admin-post.php` action on an alias. Protected paths. Moves an own-site URL to another approved host (exact host match only). |
| `includes/class-link-handler.php` | `Link_Handler` | Output buffer and HTML pass (including opted-in `admin-post.php` form targets). REST and AJAX endpoint filters. Same-origin scripts, styles, import map and fonts. |
| `includes/class-redirect-handler.php` | `Redirect_Handler` | Keeps `redirect_canonical` and `wp_redirect` on the alias. Adds aliases to `allowed_redirect_hosts`. |
| `includes/class-seo-handler.php` | `Seo_Handler` | Keeps canonical URLs on the primary domain for core, Yoast SEO and Rank Math filters, and for `<link rel=canonical>`, hreflang alternates, `og:url`, `twitter:url` in the HTML pass. |
| `includes/class-health-check.php` | `Health_Check` | Configuration checks, and the admin-started remote checks: per-user runs checked a few hosts at a time, DNS validation and connection pinning. |
| `includes/class-admin.php` | `Admin` | Settings → WP Domain Alias screen, its `admin-post.php` handlers, the health-check AJAX endpoints, and the one-time migration of 0.1.0-beta settings. |
| `admin/admin.js` | — | Progressive health check: one domain per AJAX request, inserts server-rendered results. Optional; everything works without it. |
| `includes/class-plugin.php` | `Plugin` | Wires components, Multisite refusal, admin notices, integrations. |
| `includes/integrations/class-cache-enabler.php` | — | Repeats Cache Enabler purges for each alias. |
| `includes/integrations/class-weglot.php` | — | Re-runs the HTML pass after Weglot renders (untested against real Weglot). |
| `mu-loader/wp-domain-alias-loader.php` | — | Optional Must-Use loader. |
| `uninstall.php` | — | Deletes the option and the plugin's transients. |

## Host resolution

- Only `$_SERVER['HTTP_HOST']` is read. `X-Forwarded-Host` and similar headers are ignored because they are trivially spoofed unless a proxy strips them.
- The value is lower-cased, and a port is accepted only if it is the default for the scheme (`:443` on HTTPS, `:80` on HTTP). It must then be a valid hostname and match an approved alias exactly. Anything else (a trailing dot, another port, an IP address) is treated as not an alias.
- Primary host = host of the `home` option. Its `www`/non-`www` counterpart is treated as "this site" when recognising links, but is never an alias unless added.

## What is changed on an alias page view

| Item | Changed to alias? | Why |
|---|---|---|
| `a`/`area` `href`, `form action`, `button`/`input formaction`, `data-column-clickable` | Yes | Navigation keeps the hostname. |
| `<script src>`, `<link rel=stylesheet|preload|modulepreload href>` | Yes | Module scripts, import maps and fonts referenced by CSS are blocked cross-origin without CORS. |
| Import map JSON, `url()` in inline `<style>` | Yes (WordPress 6.7+) | Same CORS reason; uses `set_modifiable_text()`. |
| Theme font files (`theme_file_uri`), Font Library (`font_dir`) | Yes | Fonts printed by core from `theme.json`. |
| REST root (`rest_url`), `admin-ajax.php` | Yes | Same-origin fetch for forms and frontend scripts. |
| Images, media, `srcset` | No | Not CORS-sensitive; stays cacheable on one host. |
| Canonical, hreflang, `og:url`, `twitter:url` | Per setting (primary by default) | SEO policy. |
| JSON-LD, feeds, sitemaps, `robots.txt`, REST response data, emails | No | Generated from `home`; one canonical source. |
| Admin, login, sign-up, activate, cron, XML-RPC, `admin-post.php` | Never | Security and session scope. |

URLs are only moved when the scheme is `http`, `https` or protocol-relative and the host exactly matches the primary host, its www counterpart or an approved alias. Hosts with userinfo, a port, or a look-alike suffix are left alone. Relative URLs need no change.

## Redirects

- `redirect_canonical` (priority 100): WordPress still normalises trailing slashes, `?p=` and `?page_id=` URLs, and so on. The proposed redirect is moved to the alias. It is cancelled only when its sole effect would be switching host, which also prevents loops.
- `wp_redirect`: own-site destinations are moved to the alias on frontend alias requests. External destinations are never changed.
- `allowed_redirect_hosts`: approved aliases are added so `wp_safe_redirect()` works on them.

## HTML pass

- The buffer starts at `template_redirect` priority -100. It is skipped for feeds, `robots.txt` and trackbacks, and for responses whose `Content-Type` is not HTML.
- Intermediate flushes are held back so a tag is never split between two passes. Cleaned buffers are discarded.
- The pass returns early if the document does not mention any own host.
- Queued edits are applied every 100 changes, because the core tag processor slows down as unapplied edits accumulate.
- Measured locally: a normal page adds about 3 ms; a page with 2,000 internal links adds about 35 ms.

## Settings storage

One autoloaded option, `wp_domain_alias_settings`:

```php
array(
  'enabled'        => true,
  'aliases'        => array(),       // lowercase punycode hosts, max 25
  'diagnostics'    => false,
)
```

Every read re-validates the stored value, so an invalid host written directly to the database is ignored. A `canonical_mode` key left by 0.1.0-beta is removed on the first admin request; if it was `alias`, the option `wp_domain_alias_canonical_notice` is set until an administrator dismisses the notice.

Per-user transients:

| Transient | Lifetime | Purpose |
|---|---|---|
| `wpda_notices_<user>` | short | Admin notices after a form post |
| `wpda_health_<user>` | 10 min | Health-check run: host snapshot and results so far |
| `wpda_hc_lock_<user>` | 20 s | Rate limit for starting a run |
| `wpda_hc_step_<user>` | seconds | Prevents two steps of one run at the same time |

## Integrations

- **Yoast SEO / Rank Math**: canonical and `og:url` filters (`wpseo_canonical`, `wpseo_opengraph_url`, `rank_math/frontend/canonical`, `rank_math/opengraph/url`), plus the HTML pass as a safety net.
- **Polylang**: no integration code needed; language switcher links are ordinary links.
- **Cache Enabler**: purges are repeated for each alias; content, menu, theme, plugin and settings changes clear alias caches once per request.
- **Weglot**: the HTML pass runs again on `weglot_render_dom`, and a language-aware canonical path is used. Not yet verified against Weglot itself.

## Multisite

Not supported. Activation is refused on Multisite. If the plugin is loaded on a network anyway, it registers no behaviour and shows a notice.

## Health check

1. **Start** (`wpda_health_check` form post, or `wpda_health_start` AJAX). Checks the capability, the nonce and the 20-second rate limit, then stores a run: an id, the primary host and a snapshot of the hosts to check.
2. **Step** (`wpda_health_continue` form post: up to 3 hosts; `wpda_health_step` AJAX: 1 host). Takes the per-user step lock, checks the next hosts and appends their results. AJAX steps must send the current run id; the comparison uses `hash_equals`.
3. **Each host**:
   - Resolve its addresses. Fail if there are none.
   - Refuse private or reserved addresses, unless it is the primary host or core's `http_request_host_is_external` filter allows it.
   - Pin cURL to the first validated address (`CURLOPT_RESOLVE` via `http_api_curl`).
   - `wp_safe_remote_get` with no redirects, a 5 s timeout and a 1 MB limit.
   - Read the status, the redirect target, the canonical URL and the hosts used by navigation links.
4. Results are rendered server-side and shown on the page; the AJAX endpoint returns the same HTML.

## `admin-post.php` opt-in

Sites can list `admin-post.php` actions with the `wp_domain_alias_admin_post_actions` filter (default: none). On an alias, the HTML pass first scans the page for forms that post to `admin-post.php` with a listed action, then moves only those forms' `action` attribute to the alias. During such a request on an alias, `wp_redirect` keeps own-host redirects on the alias. Nothing else about `admin-post.php`, CORS or the protected paths changes.
