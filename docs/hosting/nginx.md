# Nginx (self-managed)

> **Verified locally, not on a provider.** Nginx 1.31.8 + PHP-FPM 8.4.13 on macOS, HTTPS with a test certificate, WordPress 7.1.4, 2026-11-06. The configuration used is [`tests/hosting/nginx.conf.in`](../../tests/hosting/nginx.conf.in); results are in [README.md](README.md#what-was-verified-on-each-local-stack). A real server adds DNS, a public certificate and possibly a proxy in front, none of which were tested.

Add every alias to `server_name` of the WordPress server block, with a certificate covering all names:

```nginx
server {
    listen 443 ssl;
    http2 on;
    server_name primary-example.com www.primary-example.com alias-example.com www.alias-example.com;
    ssl_certificate     /etc/ssl/site/fullchain.pem;   # must cover every name
    ssl_certificate_key /etc/ssl/site/privkey.pem;
    root /var/www/wordpress;
    index index.php;

    location / { try_files $uri $uri/ /index.php?$args; }
    location ~ /\.(?!well-known) { deny all; }
    location ~ \.php$ {
        try_files $uri =404;
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        fastcgi_pass unix:/run/php/php-fpm.sock;
    }
}
```

`fastcgi_params` passes the request's `Host` header to PHP as `HTTP_HOST` and sets `HTTPS=on` on TLS connections; both are required.

Things to check:

- **Redirect server blocks.** A common pattern redirects every non-primary name: `return 301 https://primary-example.com$request_uri;`. Make sure the aliases are not caught by it.
- **Host header**: don't override `HTTP_HOST` with a fixed name.
- **FastCGI cache key** must include the host. A tested configuration and its invalidation behaviour are in [../caching.md](../caching.md#nginx-fastcgi-cache).
- **Reverse proxy in front of PHP** (`proxy_pass`): forward the host with `proxy_set_header Host $host;`. Otherwise PHP sees the upstream name instead of the alias. Not tested.
- **Fonts on another host** need CORS headers; see [../caching.md](../caching.md#fonts-and-cors).
- Nginx removes a trailing dot from `Host` (`alias-example.com.` becomes `alias-example.com`). This is harmless.
