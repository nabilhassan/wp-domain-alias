# Apache / cPanel

> **Not tested.** Based on standard Apache and cPanel behaviour.

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
- **Fonts via a CDN or another host** need CORS headers; see [../caching.md](../caching.md#fonts-and-cors).
