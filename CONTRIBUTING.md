# Contributing

Thanks for helping. Bug reports, compatibility reports and pull requests are welcome.

## Before you start

- **Security issues**: follow [SECURITY.md](SECURITY.md), not a public issue.
- **Use placeholder domains** (`example.com`, `primary-example.com`, `alias-example.com`, `.test`) in issues, code, tests and screenshots. Never post real client domains, server addresses, credentials or private URLs.
- For larger changes, open an issue first to agree on the approach.

## Principles

1. WordPress keeps generating primary-domain URLs. Do not filter `home_url`, `site_url`, `home` or `siteurl`.
2. Change URLs only on approved alias frontend requests, and only where navigation or same-origin loading needs it.
3. Admin, login and other protected endpoints never move to an alias.
4. No telemetry, external services or background network requests.
5. Do not claim compatibility you have not tested; update `docs/compatibility.md` with versions.

## Development setup

See [docs/testing.md](docs/testing.md). In short:

```sh
npm ci && npx playwright install chromium
composer install
npm run env:setup && npm run env:start
php tests/unit/run.php
npm run test:integration && npm run test:browser
vendor/bin/phpcs
```

No Docker or MySQL is needed.

## Branches and pull requests

- `main` is releasable. `develop` collects work for the next release.
- Create `feature/<short-name>` or `fix/<short-name>` from `develop`, and open the pull request against `develop`.
- Keep pull requests focused. Include tests for behaviour changes, and update docs and `CHANGELOG.md` (under *Unreleased*).
- CI must pass: PHP syntax 7.4–8.4, PHPCS, unit, integration and browser tests.

## Coding standards

- WordPress Coding Standards (`phpcs.xml.dist`), PHP 7.4 compatible, WordPress 6.4+.
- Escape all output, sanitise and validate all input, check capabilities and nonces.
- Translatable strings use the `wp-domain-alias` text domain. Regenerate the POT with `wp i18n make-pot . languages/wp-domain-alias.pot --exclude=tests,node_modules,vendor,dist`.

## Releases

Maintainers only: update the version in `wp-domain-alias.php` (header and `WP_DOMAIN_ALIAS_VERSION`), `mu-loader/wp-domain-alias-loader.php`, `readme.txt` (Stable tag) and `CHANGELOG.md`, merge to `main`, then push a `vX.Y.Z` tag. The release workflow builds the ZIP and checksum and attaches them to a GitHub release. This project is not published to WordPress.org.

## License

By contributing you agree that your contributions are licensed under GPL-2.0-or-later.
