#!/usr/bin/env bash
# Builds the installable plugin ZIP: dist/wp-domain-alias-<version>.zip
# Only runtime files are included; no Composer, npm or build step is needed
# to install the result.
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
VERSION="$(sed -n 's/^ \* Version: *//p' "$ROOT/wp-domain-alias.php" | head -1 | tr -d '[:space:]')"
OUT="$ROOT/dist"
STAGE="$OUT/wp-domain-alias"

rm -rf "$STAGE" "$OUT/wp-domain-alias-$VERSION.zip"
mkdir -p "$STAGE"
cp "$ROOT/wp-domain-alias.php" "$ROOT/uninstall.php" "$ROOT/readme.txt" "$ROOT/LICENSE" "$STAGE/"
cp -R "$ROOT/includes" "$ROOT/admin" "$ROOT/mu-loader" "$ROOT/languages" "$STAGE/"
find "$STAGE" -name ".gitkeep" -delete
find "$STAGE" -name '.DS_Store' -delete

# Every shipped PHP file must parse.
find "$STAGE" -name '*.php' -print0 | xargs -0 -n1 php -l >/dev/null

(cd "$OUT" && zip -qr -X "wp-domain-alias-$VERSION.zip" wp-domain-alias)
(cd "$OUT" && shasum -a 256 "wp-domain-alias-$VERSION.zip" > "wp-domain-alias-$VERSION.zip.sha256")
echo "$OUT/wp-domain-alias-$VERSION.zip"
