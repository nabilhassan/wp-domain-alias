# Caching

## The rule

Every cache in front of WordPress must store **a separate copy of each page per hostname**. The HTML of `alias-example.com/about/` contains alias links; the HTML of `primary-example.com/about/` contains primary links. A cache that keys only on the path will serve one domain's copy on the other.

Most caches already key on the host. The automated tests demonstrate both cases with a small proxy:

- a cache keyed on host + URL keeps every domain isolated (supported);
- a cache keyed on the path only serves primary links on the alias (not supported; reconfigure it).

## After changing aliases

Purge every cache layer (plugin, host, CDN) after adding or removing an alias, or after changing settings.

## How to check

1. Enable **Diagnostics** in the settings. Alias pages get an `X-WP-Domain-Alias: <alias>` response header and an HTML comment such as `<!-- WP Domain Alias: 42 URL(s) adjusted for alias-example.com -->`.
2. Load the same page on the primary domain and on the alias, twice each (the second request is usually a cache hit).
3. On the alias, links must still use the alias. The health check does this automatically for the homepage ("Hostname retained").
4. Disable Diagnostics when finished.

## Cache Enabler (tested: 1.8.17)

- Cache Enabler stores one copy per hostname, so domains are isolated.
- Its own purge actions only clear the primary hostname's copies. WP Domain Alias repeats every Cache Enabler purge for each alias. It also clears all alias copies once per request when posts, comments, terms, menus, the theme, plugins or its own settings change. Autosaves and revisions do not trigger this.
- Removing an alias also clears that alias's cached pages.
- One known gap: a manual "Clear cache" when no primary-domain copy is cached may not reach alias copies, because Cache Enabler only reports a purge when it deleted something on the primary host. Enable **Cache Expiry** in Cache Enabler as a safety net. The configuration status card reminds you if expiry is off.

## Other page cache plugins (not tested)

| Plugin | Expected behaviour | What to check |
|---|---|---|
| WP Rocket | Stores cache in per-host folders | Purge after changes; check alias copies are cleared too, or set a cache lifespan |
| WP Super Cache | Per-host folders | Same as above |
| W3 Total Cache | Depends on page cache method | Check that the cache key includes the host |
| LiteSpeed Cache | Server-level cache; varies by host in typical setups | Check with Diagnostics; purge all after changes |
| SiteGround Optimizer, Breeze, NitroPack, WP Fastest Cache | Unknown | Check with Diagnostics and the health check |

These are expectations, not test results. Check your setup as described above.

## Host and CDN caches (not tested)

Nginx FastCGI cache, Varnish, host edge caches and CDNs must include the host in the cache key. Typical safe keys:

- Nginx: `fastcgi_cache_key "$scheme$request_method$host$request_uri";`
- Varnish: the default `vcl_hash` hashes `req.http.host` (or the server IP) together with the URL.
- CDNs: most include the hostname by default. Check for "ignore host", "custom cache key" or "cache by path" options that would remove it.

If you cannot confirm the host is part of the key, do not use aliases with that cache.

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
