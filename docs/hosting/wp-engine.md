# WP Engine

> **Not tested.** Based on WP Engine's public documentation of its domain features. Confirm with WP Engine support before relying on it.

1. In the WP Engine User Portal open the environment → **Domains** → **Add domain**, and add the alias (and its `www` form if used). Do not set it as primary, and do not choose the "redirect to" option.
2. Point DNS as instructed, then add an SSL certificate for the alias under **SSL** (Let's Encrypt is available).
3. Check **Redirect rules** (Web Rules / redirect manager) for anything sending the alias to the primary domain.
4. Install the plugin, add the alias, run the health check.
5. **Caching.** WP Engine's page cache is expected to key on the hostname, but this is not verified. Purge all caches from the User Portal or the WP Engine plugin after adding an alias. Then check with Diagnostics and by loading pages on both domains twice each.
6. If the environment uses WP Engine's CDN or Global Edge Security, check that the alias is covered and that cached pages stay per host.

Notes:

- WP Engine may block or restrict some plugins; WP Domain Alias makes no outgoing requests except the admin-started health check, and writes no files.
