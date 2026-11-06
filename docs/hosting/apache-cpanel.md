# Apache / cPanel

> **Apache verified locally; cPanel not tested.** Apache 2.4.67 with mod_proxy_fcgi, mod_rewrite (WordPress's standard `.htaccess`) and mod_ssl, PHP-FPM 8.4.13, HTTPS with a test certificate, WordPress 7.1.4, 2026-11-06. This is the shape of a typical cPanel account using PHP-FPM, but cPanel, EasyApache, AutoSSL and LSAPI were not tested. The configuration used is [`tests/hosting/httpd.conf.in`](../../tests/hosting/httpd.conf.in); results are in [README.md](README.md#what-was-verified-on-each-local-stack).

## cPanel

Follow [shared-hosting.md](shared-hosting.md): add the alias under *Domains* with the primary's document root (or as an *Alias*), issue AutoSSL, then install the plugin.

## Self-managed Apache

Add the alias to the existing virtual host, for both HTTP and HTTPS:

```apache
<VirtualHost *:443>
    ServerName  primary-example.com
    ServerAlias www.primary-example.com alias-example.com www.alias-example.com
    DocumentRoot /var/www/wordpress
    # Certificates must cover every name above, e.g. one SAN certificate
    # or separate vhosts per certificate with the same DocumentRoot.
    ...
</VirtualHost>
```

Things to check:

- **Redirect rules.** Remove or narrow any rule that forces the primary host, for example:
  ```apache
  RewriteCond %{HTTP_HOST} !^primary-example\.com$ [NC]
  RewriteRule ^ https://primary-example.com%{REQUEST_URI} [R=301,L]
  ```
  Such a rule must list every alias as an exception, or be removed.
- **`UseCanonicalName Off`** (the default) so `HTTP_HOST` carries the requested name.
- **`AllowOverride All`** (or at least `FileInfo`) on the document root, so WordPress's `.htaccess` rewrites work. Pretty permalinks and the trailing-slash redirects were tested this way.
- **PHP-FPM through mod_proxy_fcgi** (`SetHandler "proxy:fcgi://..."`) passes `HTTPS=on` and the request's `Host` to PHP. Tested.
- **Fonts via a CDN or another host** need CORS headers; see [../caching.md](../caching.md#fonts-and-cors).
