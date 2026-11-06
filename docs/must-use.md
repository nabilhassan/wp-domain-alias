# Must-Use installation

A Must-Use (MU) plugin is loaded on every request and cannot be deactivated from the Plugins screen. Use it only if you need the plugin to stay on regardless of who manages plugins, and you can access the server's files (SFTP or SSH).

The regular plugin installation is recommended for most sites.

## Install

1. Unzip `wp-domain-alias-<version>.zip`. You get a folder named `wp-domain-alias/`.
2. Upload that folder to `wp-content/mu-plugins/wp-domain-alias/`.
3. Copy `wp-content/mu-plugins/wp-domain-alias/mu-loader/wp-domain-alias-loader.php` to `wp-content/mu-plugins/wp-domain-alias-loader.php`. WordPress loads only PHP files placed directly in `mu-plugins/`, not files in subfolders, so the loader is needed.
4. Check **Plugins → Must-Use**: "WP Domain Alias (Must-Use loader)" is listed.
5. Go to **Settings → WP Domain Alias**. The About card shows "Installed as: Must-Use plugin".

Resulting layout:

```
wp-content/mu-plugins/
├── wp-domain-alias-loader.php
└── wp-domain-alias/
    ├── wp-domain-alias.php
    ├── includes/
    └── ...
```

Activation hooks do not run for MU plugins. Default settings are used until you save settings or add an alias.

## If both copies are installed

If the plugin is installed as an MU plugin **and** as a regular plugin, only the MU copy runs. The second copy does nothing, and an admin notice asks you to remove it. Deactivate and delete the regular copy.

## If the folder is missing

If `wp-content/mu-plugins/wp-domain-alias/` is missing but the loader file is present, the site keeps working, aliases show primary-domain links, and an admin notice says so. Restore the folder or delete the loader.

## Update

Replace the contents of `wp-content/mu-plugins/wp-domain-alias/` with the new version's folder. If the new version's `mu-loader/wp-domain-alias-loader.php` has changed, copy it over the old loader too.

## Remove

MU plugins cannot be removed from the WordPress admin. Using SFTP, SSH or your host's file manager:

1. Delete `wp-content/mu-plugins/wp-domain-alias-loader.php`.
2. Delete the folder `wp-content/mu-plugins/wp-domain-alias/`.
3. Purge all caches.

The settings option `wp_domain_alias_settings` stays in the database (uninstall cleanup only runs for regular plugins deleted from the Plugins screen). It is harmless. To remove it, run `wp option delete wp_domain_alias_settings`, or install the plugin as a regular plugin and delete it from the Plugins screen.

## Tested

Install, the About card, the duplicate-copy notice and the missing-folder notice are covered by the automated lifecycle tests (`tests/e2e/lifecycle.test.mjs`) in the local test environment.
