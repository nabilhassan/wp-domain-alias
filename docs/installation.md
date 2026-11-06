# Installation

## Before you start

The plugin only works once each alias already reaches this WordPress installation. Check this first:

1. **DNS**: the alias's A/AAAA or CNAME records point to your host, as your host instructs.
2. **Hosting domain mapping**: the alias is added to the same site at your host (sometimes called "add domain", "alias", "parked domain" or "server alias"). Do **not** set it as the primary domain, and do **not** create a redirect for it.
3. **HTTPS**: the alias has a valid certificate. Most hosts issue one automatically once DNS points to them.
4. **Test**: open `https://alias-example.com/`. You should see your site. At this stage its links still go to the primary domain. If you are redirected to the primary domain, remove that redirect at the host, CDN or redirect plugin first.

Provider-specific notes: [hosting/README.md](hosting/README.md).

Always try this on a staging copy first.

## Install as a regular plugin (recommended)

1. Download `wp-domain-alias-<version>.zip` from the GitHub Releases page. Optionally verify it against the published `.sha256` file:
   ```sh
   shasum -a 256 -c wp-domain-alias-<version>.zip.sha256
   ```
2. In WordPress: **Plugins → Add New → Upload Plugin**, choose the ZIP, **Install Now**, then **Activate**.
3. Go to **Settings → WP Domain Alias**.
4. Add each alias, one at a time. `www.alias-example.com` and `alias-example.com` are separate entries; add both if both are used.
5. Click **Run health check** and review each domain.
6. Purge every cache layer (plugin, host, CDN).
7. Visit each alias in a private window. Click through menus, the logo, pagination, search, forms and any language switcher, on desktop and mobile.

### Shared hosting without SSH

Nothing in the steps above needs SSH, WP-CLI or file access. The plugin is uploaded through the WordPress admin, and the domain is set up in your hosting control panel.

## Install as a Must-Use plugin

See [must-use.md](must-use.md).

## Updating

This plugin is not distributed through WordPress.org, so WordPress does not update it automatically. To update:

1. Download the new ZIP from GitHub Releases.
2. **Plugins → Add New → Upload Plugin**, choose the ZIP. WordPress offers to replace the installed version; confirm.
3. Settings are kept.

## Deactivating and removing

- **Deactivate**: aliases still load but their links point to the primary domain. Settings are kept.
- **Delete** (from the Plugins screen): also deletes the settings and the plugin's temporary data.
- Purge caches afterwards so alias pages built while the plugin was active are not served.
- Remove the domain mapping at your host if the alias should stop working, or replace it with a 301 redirect to the primary domain.

## Rolling back

Deactivate the plugin. Nothing else needs undoing: the database, WordPress addresses and server configuration were never changed by the plugin. Purge caches.
