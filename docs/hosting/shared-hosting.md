# Generic shared hosting (no SSH)

> **Not tested.** Based on how common control panels (cPanel, Plesk, DirectAdmin) work. Labels differ between hosts.

No SSH, WP-CLI or file access is needed.

1. **Attach the alias to the same site.** In the control panel, look for *Domains → Create a New Domain* with "share document root" ticked (cPanel), *Aliases* or *Parked Domains* (older cPanel), *Add Domain Alias* (Plesk), or *Domain Pointers* (DirectAdmin). The alias must use the **same document root** as the primary domain. Do not choose any "redirect" option.
2. **DNS.** If the domain is registered elsewhere, point its nameservers or A record to the host as instructed.
3. **HTTPS.** Run AutoSSL / Let's Encrypt for the alias (usually automatic once DNS resolves). Wait until `https://alias-example.com/` shows a valid certificate.
4. **Check.** `https://alias-example.com/` shows your site without redirecting.
5. **Install** the plugin through *Plugins → Add New → Upload Plugin*, add the alias, run the health check.
6. **Caching.** If the host has its own cache toggle, purge it after adding the alias and check with Diagnostics (see [../caching.md](../caching.md)).

Common problems:

- *The alias shows the host's default page*: it was created with its own document root. Recreate it sharing the primary's root, or ask support.
- *The alias redirects*: the domain was added as a redirect, or `.htaccess` contains a rule forcing the primary host (look for `RewriteCond %{HTTP_HOST}`). Remove that rule for the alias.
