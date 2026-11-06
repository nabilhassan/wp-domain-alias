# Kinsta

> **Not tested.** Based on Kinsta's public documentation of its domain features. Confirm with Kinsta support before relying on it.

1. In MyKinsta open the site → **Domains** → **Add domain**, and add the alias (and its `www` form if used) to the **same environment**. Do not make it the primary domain.
2. Complete domain verification and point DNS as MyKinsta instructs. Kinsta issues the certificate automatically once DNS resolves.
3. Make sure no **redirect rule** (MyKinsta → *Redirects*) sends the alias to the primary domain.
4. Install the plugin, add the alias, run the health check.
5. **Caching.** Kinsta's server-level page cache and edge caching are expected to key on the hostname, but this is not verified. After adding an alias, clear the site cache in MyKinsta (or with the Kinsta MU plugin's *Clear cache*), enable Diagnostics, and load the same page on the primary and the alias twice each. Alias links must stay on the alias.
6. **Staging first.** Use a staging environment and a test alias before production.

Notes:

- Kinsta's built-in "redirect www to non-www" (or the reverse) applies to the primary domain. Adding the primary's other form as an alias usually has no effect because it is redirected first.
- The plugin does not use or need Kinsta's API, SSH or any credentials.
