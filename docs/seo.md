# SEO

## Behaviour

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

## Why there is no "alias" canonical mode

0.1.0-beta had an option to point canonical URLs at the alias being viewed. In that mode, sitemaps, `robots.txt`, JSON-LD and feeds still listed primary URLs, so search engines received two different preferred URLs for one page. Making it consistent would need per-domain sitemaps, structured data and hreflang sets, which is a separate SEO product. The option was removed in 0.1.1-beta; the reasoning is in [review-0.1.1-beta.md](review-0.1.1-beta.md#2-canonical-modes-option-a-chosen).

If each domain must rank in its own right, use separate sites (for example WordPress Multisite with domain mapping) instead of aliases.

**Upgrading from 0.1.0-beta.** A stored "alias" setting is removed on the first admin page load after the update, and administrators see a one-time notice that can be dismissed. Front-end pages ignore the old value immediately. WordPress's own `home` and `siteurl` settings are never changed.

## Multilingual sites

- **Polylang** (tested, directory mode `/es/`): language switcher links keep the alias. Canonical and hreflang point to the primary domain with the translated slug (for example `/es/sobre-nosotros/`).
- **Weglot**: integration code exists (keeps the translated canonical path and re-runs the link pass after Weglot renders) but has not been tested against a live Weglot project.
- **WPML**: not tested. WPML's "different domain per language" mode is a different feature and conflicts with aliasing; do not combine them.

## Recommendations

- Submit only the primary domain's sitemap in Google Search Console and Bing Webmaster Tools. Verifying alias domains there is useful for monitoring, but submitting their sitemaps is not.
- Avoid mixing this plugin with a redirect plugin rule for the same alias.
- After adding an alias, check a few alias pages' source: one canonical tag, pointing where you expect.
