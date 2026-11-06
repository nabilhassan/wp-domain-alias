# Caching

## The rule

Every cache in front of WordPress must store **a separate copy of each page per hostname**. The HTML of `alias-example.com/about/` contains alias links; the HTML of `primary-example.com/about/` contains primary links. A cache that keys only on the path will serve one domain's copy on the other.

Most caches already key on the host. The automated tests show both cases with a real Nginx FastCGI cache and a small proxy:

- a cache keyed on host + URL keeps every domain isolated (supported);
- a cache keyed on the path only serves primary links on the alias (not supported; reconfigure it).

## After changing aliases

Purge every cache layer (plugin, host, CDN) after adding or removing an alias, or after changing settings.

## How to check

1. Enable **Diagnostics** in the settings. Alias pages get an `X-WP-Domain-Alias: <alias>` response header and an HTML comment such as `<!-- WP Domain Alias: 42 URL(s) adjusted for alias-example.com -->`.
2. Load the same page on the primary domain and on the alias, twice each (the second request is usually a cache hit).
3. On the alias, links must still use the alias. The health check does this automatically for the homepage ("Hostname retained").
4. Disable Diagnostics when finished.

## Status by cache

"Verified" means the automated tests in this repository passed in the environments named; nothing here was tested on a hosting provider. See [testing.md](testing.md).

| Cache | Status | Environments |
|---|---|---|
| Cache Enabler 1.8.17 | **Verified**, with a built-in integration | PHP built-in server, Nginx 1.31.8 + PHP-FPM, Apache 2.4.67 + PHP-FPM (HTTPS) |
| Nginx FastCGI cache keyed on `$scheme$request_method$host$request_uri` | **Verified** | Nginx 1.31.8 + PHP-FPM 8.4 (HTTPS) |
| Nginx FastCGI cache keyed on the path only | **Verified unsafe**: serves one host's HTML on another | Same; kept as a negative control |
| Reverse proxy keyed on host + URL / path only | Simulated (small Node proxy), same results as above | PHP built-in server |
| WP Super Cache 3.1.5 | **Not verified.** Writes a separate folder per host (`cache/supercache/<host>/`), but in the local harness it never served a cached page, so isolation and purging could not be observed | — |
| WP Rocket, W3 Total Cache, LiteSpeed Cache, SiteGround Optimizer, Breeze, NitroPack, WP Fastest Cache | Not tested | — |
| Kinsta, WP Engine and other host edge caches | Not tested | — |
| Cloudflare and other CDNs | Not tested | — |

No integration is shipped for an untested cache.

## Scenarios covered by the tests

Every scenario checks the critical property: **a hostname never receives another hostname's HTML.**

| Scenario | Cache Enabler | Nginx FastCGI (host key) |
|---|---|---|
| Cold, then warm, on every host | Pass | Pass (`MISS` then `HIT`) |
| Primary first, then alias | Pass | Pass |
| Alias first, then primary | Pass | Pass |
| Four hosts warm at once | Pass | Pass |
| Content edit | Alias and primary copies of the page refreshed | Every host keeps its old copy until the cache entry expires, then refreshes. Nginx has no purge here |
| Menu (block navigation) edit | Alias copies refreshed. The primary copy stays old: Cache Enabler's default clears only the saved post's page | Same as content edit |
| Plugin settings changed / alias removed | Alias copies cleared; a removed alias shows primary links | Old copies stay until expiry or a manual purge |
| Full purge | Pass | Pass (cache directory deleted) |
| Expiry | Expired copies regenerated per host; other hosts unaffected | Pass |
| Logged-in visitors, query strings | Bypassed by Cache Enabler | Bypassed by the configuration below |

## Cache Enabler

- Cache Enabler stores one copy per hostname, so domains are isolated.
- Its own purge actions only clear the primary hostname's copies. WP Domain Alias repeats every Cache Enabler purge for each alias. It also clears every alias copy, once per request, when posts, comments, terms, menus, the theme, plugins or its own settings change. Autosaves and revisions don't trigger this.
- Alias copies are therefore never older than the primary copy. After a menu change, the primary copy may still be the old one, because Cache Enabler only clears the saved post's page by default. Clear the whole cache after menu changes, or enable **Clear the site cache if any post type has been published, updated, or trashed** in Cache Enabler.
- Removing an alias also clears that alias's cached pages.
- Known gap: a manual "Clear cache" when no primary-domain copy is cached may not reach alias copies. Cache Enabler only reports a purge when it deleted something on the primary host. Enable **Expiration** in the Cache Enabler settings as a safety net; the configuration status card reminds you when it is off.

## Nginx FastCGI cache

The key must include the host. This configuration was tested:

```nginx
fastcgi_cache_path /var/cache/nginx/wp levels=1:2 keys_zone=wp:10m inactive=60m;

map $http_cookie $wp_skip_cookie {
    default 0;
    ~*(wordpress_logged_in_|comment_author_|wp-postpass_) 1;
}
map $request_uri $wp_skip_uri {
    default 0;
    ~*(/wp-admin/|/wp-json/|wp-login\.php|xmlrpc\.php|wp-cron\.php|sitemap) 1;
}

server {
    # ...
    set $wp_skip 0;
    if ($request_method = POST) { set $wp_skip 1; }
    if ($query_string != "") { set $wp_skip 1; }

    location ~ \.php$ {
        # ... fastcgi_pass ...
        fastcgi_cache wp;
        fastcgi_cache_key "$scheme$request_method$host$request_uri";
        fastcgi_cache_valid 200 301 302 10m;
        fastcgi_cache_bypass $wp_skip $wp_skip_cookie $wp_skip_uri;
        fastcgi_no_cache     $wp_skip $wp_skip_cookie $wp_skip_uri;
    }
}
```

Open-source Nginx has no purge command. Content, menu and alias changes appear when entries expire, so choose a short `fastcgi_cache_valid` or clear the cache directory after changes. **After removing an alias**, clear the cache; otherwise its old copies keep alias links until they expire. A purge module or a helper plugin can automate this; none was tested.

## Other caches

| Cache | What to check |
|---|---|
| WP Rocket, WP Super Cache | Per-host cache folders are expected. Check that editing a page refreshes its alias copies too; if not, set a short cache lifespan |
| W3 Total Cache | Depends on the page cache method; check the key includes the host |
| LiteSpeed Cache / LiteSpeed server | The key normally includes the host; check with Diagnostics and purge all after changes |
| Varnish | The default `vcl_hash` includes `req.http.host`; keep it |
| CDNs | Most include the hostname by default; look for "ignore host", "custom cache key" or "cache by path" options that remove it |

If you can't confirm the host is part of the key, don't use aliases with that cache.

## Fonts and CORS

Browsers block web fonts and JavaScript modules loaded from another domain unless that domain sends CORS headers. That is why the plugin serves scripts, stylesheets and theme fonts from the alias itself on alias pages.

If an optimisation plugin or CDN rewrites asset URLs to a fixed domain (for example `cdn.example.com`), fonts may fail on aliases. Two fixes:

- exclude font files from that rewrite, or
- make the asset domain send `Access-Control-Allow-Origin` for font files:

Apache (`.htaccess`):

```apache
<IfModule mod_headers.c>
  <FilesMatch "\.(woff2?|ttf|otf|eot)$">
    Header set Access-Control-Allow-Origin "*"
  </FilesMatch>
</IfModule>
```

Nginx:

```nginx
location ~* \.(woff2?|ttf|otf|eot)$ {
    add_header Access-Control-Allow-Origin "*";
}
```

Font files are public, so `*` is acceptable for them. Do not add this header to HTML or API responses.

On WordPress below 6.7, font URLs that other plugins print inline in `<style>` tags are not adjusted. Theme and Font Library fonts are. Use the CORS header above, or update WordPress.
