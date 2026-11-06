# Audit of the original "Dual-Domain Compatibility" plugin (1.0.3)

This audit was done before any WP Domain Alias code was written. It reviewed a
local, read-only copy of the original plugin, its tests, maintenance README,
deployment report, rollback script and prior security review. Nothing on the
production site was accessed or changed.

The original was written for one client site. In this document its domains
are replaced with placeholders:

| Placeholder | Meaning |
|---|---|
| `PRIMARY` | The configured WordPress `home`/`siteurl` host |
| `ALIAS` / `www.ALIAS` | The two approved secondary hosts |

No client domain, server address, account name, path, tracking ID or credential
from the original project appears in this repository.

---

## 1. Current architecture

Two files, installed as a Must-Use plugin:

| File | Lines | Role |
|---|---|---|
| `mu-plugins/<loader>.php` | 25 | Defines a loader constant, `require_once`s the implementation if readable, otherwise shows a capability-gated admin notice instead of a fatal error. |
| `mu-plugins/<dir>/<impl>.php` | 203 | One frontend class (domain logic) and one admin class (read-only details page and health check). |

### Core idea (worth keeping)

The plugin **never changes how WordPress builds URLs**. `home`, `siteurl`,
`home_url()` and permalinks keep returning `PRIMARY`. That keeps canonical
tags, sitemaps, JSON-LD, Open Graph and feeds on the primary domain without any
SEO-specific code.

When a request arrives on an approved alias host, the plugin changes only
**where visitors go next**:

1. **Final HTML**: an output buffer passes the page through
   `WP_HTML_Tag_Processor` and rewrites only navigation attributes (`a/area[href]`,
   `form[action]`, `button/input[formaction]`, and one Elementor add-on attribute)
   whose host is one of the site's own hosts.
2. **Redirects**: `redirect_canonical` and `wp_redirect` filters keep the alias
   host on redirects WordPress or plugins issue.
3. **Script endpoints**: `rest_url` and `admin_url('admin-ajax.php')` are
   switched to the alias so frontend JavaScript stays same-origin.

### Function and class responsibilities

**Frontend class**

| Method | Responsibility |
|---|---|
| `host()` | Lowercases `HTTP_HOST`; returns it only if it exactly matches one of the two hard-coded aliases, otherwise `''`. Anything with a port, trailing dot, userinfo or CRLF is rejected. |
| `protected_path($path)` | True for `/wp-admin`, `/wp-login.php`, `/wp-cron.php`, `/xmlrpc.php`. |
| `frontend()` | True only for an alias request that is not admin, AJAX, REST or a protected path. |
| `navigation_url($url, $allow_ajax)` | Rewrites absolute or protocol-relative URLs whose authority is exactly one of four hard-coded hosts to `https://<request alias>`. Leaves external URLs, ambiguous authorities and protected paths alone. `admin-ajax.php` is allowed only when the caller asks. |
| `canonical_redirect()` | `redirect_canonical` filter at priority 100. Moves the redirect target to the alias; returns `false` when the only difference was the host, which prevents an alias-to-primary redirect while keeping path and slash normalisation. |
| `frontend_redirect()` | `wp_redirect` filter: keeps legacy/plugin redirects on the alias. |
| `rest_url()`, `admin_url()` | Same-origin REST and frontend AJAX endpoints on aliases. |
| `rewrite_html()` | The HTML pass described above, skipped for non-HTML `Content-Type`. Also contains a hard-coded multilingual fix: on `/es/` paths it forces the canonical to `https://PRIMARY` + the raw request path, because the translation service does not translate a canonical that sits on a different host. |
| `start_buffer()` | `template_redirect` at priority -100; skips feeds, robots and trackbacks. |
| `boot()` | Captures the raw request path before the translation plugin rewrites `REQUEST_URI`; registers all hooks; re-runs `rewrite_html` on `weglot_render_dom` at `PHP_INT_MAX`. |

**Admin class** (loaded only when `is_admin()`)

| Method | Responsibility |
|---|---|
| `row_meta()` | Adds "View details" to the plugin row for `activate_plugins` users. |
| `register_page()` | Hidden sub-page of Plugins. |
| `health_check()` | Fixed list of three hosts; `wp_safe_remote_get`, 4 s timeout, no redirects, TLS verify, `reject_unsafe_urls`, 1 KB response cap. Transport errors are not shown. |
| `render()` | Capability check, POST + nonce check before any network call, escaped metadata and diagnostics, read-only feature list. |

### Hooks and filters used

| Hook | Type | Priority |
|---|---|---|
| `redirect_canonical` | filter | 100 |
| `wp_redirect` | filter | 100 |
| `rest_url` | filter | 100 |
| `admin_url` | filter | 100 |
| `template_redirect` | action (starts buffer) | -100 |
| `weglot_render_dom` | filter | `PHP_INT_MAX` |
| `plugin_row_meta` | filter | 10 |
| `admin_menu` | action | 10 |
| `admin_notices` | action (loader failure only) | 10 |

---

## 2. Answers to the audit questions

1. **Reusable without significant changes:** the URL-authority matching and
   protected-path logic, the `redirect_canonical` approach, the `wp_redirect`,
   `rest_url` and `admin_url` filters, the attribute-scoped HTML pass with
   `WP_HTML_Tag_Processor`, the non-HTML skip, the loader's fail-soft pattern,
   the duplicate-declaration guards, and the health check's transport limits.
2. **Depends on client domains:** every host constant; the four-host source
   list inside `navigation_url()`; the hard-coded `/es` path and `PRIMARY`
   origin in the canonical fix; the health check targets; the admin feature
   text; all three test suites (they target the live hosts and pages).
3. **Depends on Kinsta:** no runtime code does. The rollback script calls
   `wp kinsta cache purge`, the HTTP tests assert `x-kinsta-cache` headers, and
   the admin text says "compatible with the verified Kinsta cache setup". A
   NitroPack purge is also in the rollback script.
4. **Hooks:** listed above.
5. **Canonical redirects:** filtered, not removed. Path/query normalisation
   survives; only a redirect that would change the host back to `PRIMARY` is
   cancelled.
6. **Internal links:** not generated differently. The final HTML is rewritten
   for the request alias, and only for attributes that navigate.
7. **Elementor:** Elementor buttons and menus are plain `<a href>`, handled by
   the general pass. Clickable columns from a third-party add-on use
   `data-column-clickable`, added explicitly. Elementor's inline JSON
   (`elementorFrontendConfig`) is inside `<script>`, which the tag processor does
   not enter; its AJAX URL is fixed at generation time by the `admin_url` filter.
8. **Frontend AJAX and REST:** `admin_url('admin-ajax.php')` and `rest_url()`
   are moved to the alias during frontend alias requests. Real AJAX/REST
   requests are excluded from rewriting.
9. **Canonical and structured data:** preserved by not touching URL
   generation. The HTML pass never visits `<link>`, `<meta>` or `<script>`
   except for the multilingual canonical fix.
10. **Multilingual:** Weglot-specific. The raw path is captured early, and the
    canonical is corrected after Weglot's own output pass. Only `/es/` is
    handled. Hreflang links on aliases were left on the alias host (whatever
    Weglot produced).
11. **Caching:** responses for aliases differ from primary responses, so every
    page cache must key on host. The original relied on Kinsta's
    host-inclusive cache key and verified warm/cold behaviour against
    production. There is no in-plugin protection against a path-only cache key.
12. **Issues:** see section 4.
13. **Reusable tests:** see section 6.
14. **Hard-coded client data found:** client domains (all files), the plugin
    author/agency name and URL (metadata and admin page), a health-check
    user-agent with the agency name, server IP/port/username/SSH key path and
    absolute server paths (rollback script, README, deployment report), a
    Google Tag Manager container ID (HTTP test), and real page slugs (tests,
    SEO baseline). **No passwords, API tokens or private keys** are in the
    files (the rollback script refers to a key path, not key material). None
    of this was copied into this repository.

---

## 3. Client-specific functionality (to be generalised or dropped)

| Item | Decision |
|---|---|
| Hard-coded host constants | Replace with an admin-managed, validated allowlist; primary host read from `home_url()`. |
| Forced `https://` on rewritten URLs | Use the primary site's scheme so `http` development sites work and an `https` site never downgrades. |
| `/es/` + `PRIMARY` canonical fix | Move into an optional Weglot integration that uses the current language, not a fixed prefix. |
| `data-column-clickable` | Keep as a default attribute, but make the attribute list filterable. |
| Agency branding, hidden admin page under Plugins | Replace with a standard **Settings → WP Domain Alias** page and neutral metadata. |
| Kinsta/NitroPack purge in rollback | Not part of the generic plugin. Cache guidance moves to docs. |
| Tests against the live site | Rebuild against a local WordPress. |

---

## 4. Risks and issues

None of these block the architecture. Severity is for the **generic** plugin,
where hosts become configurable.

| # | Area | Finding | Severity (generic) | Plan |
|---|---|---|---|---|
| R1 | Configuration trust | Hosts move from code to the database. A malformed saved value (or a value injected by another plugin with DB access) must not widen the allowlist. | High if unvalidated | Validate on save **and** re-validate on read; exact-match comparisons only; never derive anything from `HTTP_HOST` that is not in the stored list. |
| R2 | SSRF | The health check will now request admin-configured hosts. | Medium | Admin-only (`manage_options`), nonce, POST only, saved hosts only (never request input), `wp_safe_remote_get` with `reject_unsafe_urls`, no redirects, short timeout, response size cap, per-user rate limit, no persistent writes. DNS rebinding between WordPress's IP check and the HTTP request is a known core limitation and is documented. |
| R3 | Cache poisoning | Alias HTML is different from primary HTML; a path-only cache key would serve one host's HTML on another. | High (environmental) | Cannot be fixed inside a plugin. Health check detects host mismatches in returned HTML; docs list cache setups to check. |
| R4 | Host header | Original reads only `HTTP_HOST`, exact match. Ports are rejected. | Low | Keep. Accept `:80`/`:443` only when they match the scheme; ignore `X-Forwarded-Host`. |
| R5 | Open redirect | `wp_redirect` filter only rewrites URLs whose host is already the site's own; external targets untouched. | Low | Keep. Add aliases to `allowed_redirect_hosts` so `wp_safe_redirect()` can return visitors to an approved alias (approved hosts only). |
| R6 | Output buffering | Runs only on alias frontend requests; one extra pass of `WP_HTML_Tag_Processor` (two with Weglot). Memory equals page size. | Low | Keep scope. Skip feeds, robots, sitemaps, embeds, non-HTML, `HEAD`. Primary-domain requests pay nothing. |
| R7 | WordPress version | `WP_HTML_Tag_Processor` requires WP 6.2+. Original silently did nothing on older versions. | Low | Declare `Requires at least: 6.2` and show an admin notice instead of silently doing nothing. |
| R8 | Hreflang on aliases | Original left hreflang links on the alias while the canonical pointed to primary — mixed signals. | Low (SEO) | In primary-canonical mode, normalise canonical, hreflang and `og:url` to the primary host. |
| R9 | Multisite | No guard. | Medium | Refuse to activate on Multisite; show a notice. Out of scope for now. |
| R10 | Duplicate loading | Guarded with `class_exists`. | Low | Guard the whole plugin with a version constant so MU + regular copies cannot both boot. |
| R11 | Maintainability | Very compact code, one-line control structures, not WPCS formatted, untranslatable strings. | — | Split into small classes, follow WordPress Coding Standards, add i18n. |
| R12 | Testing | Integration and browser tests run against production. | — | Rebuild against a disposable local WordPress. |
| R13 | `admin-post.php` | Frontend forms posting to `admin-post.php` stay on primary (cross-host POST). | Low | Keep (safer). Document; allow opt-in by filter. |
| R14 | Authentication | Logged-in cookies are host-scoped; visitors on an alias appear logged out. | — | Intended. Document: admin and login stay on primary. |

### Verdict

The architecture is suitable. Its central choice — leave URL generation
alone, adjust only navigation output and redirects for allowlisted hosts — is
what makes SEO safe by default, and it generalises cleanly. No change of
direction is needed.

---

## 5. Recommended architectural changes

```
wp-domain-alias.php           Plugin header, constants, duplicate-load guard, bootstrap
uninstall.php                 Removes the plugin's option and transients
includes/
  class-plugin.php            Wiring, environment checks (WP version, Multisite)
  class-host-validator.php    Pure functions: normalise/validate admin input and request hosts
  class-domain-manager.php    Settings storage, primary host, current request alias
  class-url-handler.php       Maps own-site URLs to the request alias; protected paths
  class-redirect-handler.php  redirect_canonical, wp_redirect, allowed_redirect_hosts
  class-link-handler.php      rest_url/admin_url filters and the scoped HTML pass
  class-seo-handler.php       Canonical/hreflang/og:url policy, SEO plugin filters
  class-health-check.php      Admin-only, bounded, read-only checks
  class-admin.php             Settings → WP Domain Alias
  integrations/class-weglot.php
mu-loader/wp-domain-alias-loader.php
```

Settings (one autoloaded option): `enabled`, `aliases[]`,
`canonical_mode` (`primary` default, or `alias`), `diagnostics`.

---

## 6. Existing test coverage

| Suite | What it covers | Reuse |
|---|---|---|
| `security-unit.php` (50 assertions, WP stubs, no network) | Host allowlist incl. case, port, trailing dot, userinfo, CRLF; external/ambiguous authorities; protected endpoints; AJAX exception; canonical-redirect cancellation and slash normalisation; no expansion of external redirects; duplicate include; admin capability, missing/malformed/invalid nonce, no network on GET; fixed health-check targets and transport bounds; error privacy; loader missing-file behaviour. | **High.** Port the harness and every case, replacing fixed hosts with configured ones. |
| `http-regression.mjs` (427 checks, live) | Status/redirects, one primary canonical, robots, JSON-LD, navigation hosts, logo link, hreflang, forms/CAPTCHA markup, cold/warm cache, legacy redirect with UTM/gclid, REST/AJAX/login, admin stays primary, sitemap hosts, anonymous admin denial, SEO baseline diff. | **Medium.** Keep the checks and the tag parser; point them at a local site; drop host-specific cache headers. |
| `browser-regression.cua.js` (7 checks, live) | Real desktop navigation on each host, mobile menu, form + CAPTCHA initialisation, console errors. | **Low** as code (tied to a specific browser runtime and pages); **high** as a checklist. Rebuild with Playwright. |

## 7. Missing tests

- Admin input validation (IDN, wildcard, ports, IPs, duplicates, primary match, limits).
- Settings save/add/remove with capability and nonce checks.
- Health check with configured hosts, private-address blocking, rate limit.
- Activation, deactivation, uninstall, Multisite refusal, MU + regular duplicate load.
- Any test against a real WordPress outside production: 404s, pagination,
  archives, sitemaps, REST, AJAX, SEO plugins, a free multilingual plugin,
  Contact Form 7, Elementor.
- Cache-isolation tests with a page-cache plugin.
- PHP version matrix and coding standards in CI.

## 8. Migration strategy (for the original site, later, by the owner)

WP Domain Alias is a separate plugin with different class names, option name
and constants, so it cannot collide with the original at the PHP level. A
future switch-over would be:

1. Test WP Domain Alias on a staging copy of the site.
2. Add the two aliases in the settings; enable the Weglot integration.
3. Compare canonicals, hreflang and navigation against the original's SEO baseline.
4. Replace the two MU files with WP Domain Alias in one step (never run both:
   both would rewrite the same output).
5. Keep the original files for rollback.

**This project will not perform that switch-over.** The live site and its
plugin are left unchanged.
