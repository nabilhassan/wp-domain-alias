# LiteSpeed / OpenLiteSpeed

> **Not tested.** Based on LiteSpeed's documented behaviour.

- On shared hosting with LiteSpeed (often with cPanel), set up the alias as in [shared-hosting.md](shared-hosting.md).
- On OpenLiteSpeed or LiteSpeed Enterprise you manage, add the alias to the virtual host's domain list (listener *Domains* / virtual host mapping) with the same document root, and attach a certificate covering it.
- **LiteSpeed Cache plugin**: page cache entries are expected to vary by host in typical setups, but this has not been verified with WP Domain Alias. After adding an alias, *Purge All*, enable Diagnostics, and load the same page on the primary and on the alias twice each. Alias links must stay on the alias. If they do not, do not use aliases with this cache until it is fixed.
- **QUIC.cloud CDN**: check that the hostname is part of the cache key and purge after changes.
- Remove any `.htaccess` rule forcing the primary host (LiteSpeed reads Apache-style rewrite rules).
