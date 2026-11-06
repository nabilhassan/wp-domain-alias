# Security policy

## Supported versions

| Version | Supported |
|---|---|
| 0.1.1 | Yes |
| Earlier beta versions | No (upgrade to 0.1.1) |

## Reporting a vulnerability

Please **do not** open a public issue for security problems.

Report privately through GitHub: **Security → Report a vulnerability** on this repository (GitHub private vulnerability reporting). Include:

- the plugin version, WordPress and PHP versions,
- a description of the issue and its impact,
- steps to reproduce or a proof of concept, using placeholder domains (`example.com`, `.test`), not real sites,
- whether you would like to be credited.

You should get an acknowledgement within 7 days. Fixes are released as soon as practical, and the advisory is published after a fixed version is available.

## Scope

In scope: the code in this repository and the release ZIPs published on its Releases page.

Out of scope: hosting, DNS, CDN or cache misconfiguration (see `docs/caching.md`), issues requiring administrator access unless they cross a security boundary, and vulnerabilities in WordPress core or other plugins (report those upstream).

The threat model and review notes are in [docs/security-review.md](docs/security-review.md).
