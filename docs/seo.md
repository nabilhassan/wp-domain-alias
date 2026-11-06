# SEO

## Default behaviour (canonical mode: primary)

On every alias page:

| Signal | Value |
|---|---|
| `<link rel="canonical">` | Same page on the primary domain |
| `og:url`, `twitter:url` | Primary domain |
| `hreflang` alternates | Primary domain |
| XML sitemaps (core `wp-sitemap.xml`, Yoast SEO, Rank Math) | Primary URLs only, even when requested on an alias |
| `robots.txt` sitemap line | Primary domain |
| JSON-LD structured data | Primary domain (never changed) |
| RSS feeds | Primary domain (never changed) |
| Visible links, menus, forms | Alias (this is the point of the plugin) |

Search engines therefore get one consistent canonical domain. Exactly one canonical tag is printed; the plugin changes the existing tag and never adds another. Automated tests check this with core, Yoast SEO and Rank Math.

Canonical tags are hints. Search engines usually honour them but may pick a different canonical, especially if the alias content differs or many links point to the alias.

## Alias canonical mode

**Settings → WP Domain Alias → Canonical URLs → Point to the alias being viewed** makes canonical, hreflang, `og:url` and `twitter:url` on alias pages use the alias. Use it only if each alias should rank in its own right (for example a regional domain), and you accept that the same content is published under several canonical URLs.

In alias mode, sitemaps and JSON-LD still list primary URLs. If you need per-domain sitemaps, this plugin does not provide them.

## Multilingual sites

- **Polylang** (tested, directory mode `/es/`): language switcher links keep the alias. Canonical and hreflang follow the canonical setting. Translated slugs are kept.
- **Weglot**: integration code exists (keeps the translated canonical path and re-runs the link pass after Weglot renders) but has not been tested against a live Weglot project.
- **WPML**: not tested. WPML's "different domain per language" mode is a different feature and conflicts with aliasing; do not combine them.

## Recommendations

- Keep the default primary canonical mode unless you have a clear reason.
- Submit only the primary domain's sitemap in Google Search Console and Bing Webmaster Tools. Verifying alias domains there is useful for monitoring, but submitting their sitemaps is not.
- Avoid mixing this plugin with a redirect plugin rule for the same alias.
- After adding an alias, check a few alias pages' source: one canonical tag, pointing where you expect.
