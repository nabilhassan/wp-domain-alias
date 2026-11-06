# Nginx (self-managed)

> **Not tested.** Based on standard Nginx configuration.

Add every alias to `server_name` of the WordPress server block, with a certificate covering all names:

```nginx
server {
    listen 443 ssl;
    http2 on;
    server_name primary-example.com www.primary-example.com alias-example.com www.alias-example.com;
    ssl_certificate     /etc/ssl/site/fullchain.pem;   # must cover every name
    ssl_certificate_key /etc/ssl/site/privkey.pem;
    root /var/www/wordpress;
    ...
    location ~ \.php$ {
        include fastcgi_params;
        ...
    }
}
```

Things to check:

- **Redirect server blocks.** A common pattern redirects every non-primary name: `return 301 https://primary-example.com$request_uri;`. Make sure the aliases are not caught by it.
- **Host header**: Nginx passes the request's `Host` header to PHP as `HTTP_HOST` by default; do not override it with a fixed name.
- **FastCGI cache key** must include the host: `fastcgi_cache_key "$scheme$request_method$host$request_uri";`
- **Reverse proxy in front of PHP** (`proxy_pass`): forward the host with `proxy_set_header Host $host;`. Otherwise PHP sees the upstream name instead of the alias.
- **Fonts on another host** need CORS headers; see [../caching.md](../caching.md#fonts-and-cors).
