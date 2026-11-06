#!/usr/bin/env bash
# Builds a disposable local WordPress for integration and browser tests.
# No Docker or MySQL: PHP's built-in server + the SQLite Database Integration plugin.
#
# Usage: tests/e2e/setup-wp.sh [wp-version] [extra plugin slugs...]
#   WPDA_NO_LINK=1  do not link the working tree as a plugin (lifecycle tests)
#   WP_VERSION   default "latest"
#   WPDA_PORT    default 8890
#   WPDA_DIR     default <repo>/.wp-test
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
WP_VERSION="${1:-${WP_VERSION:-latest}}"
shift || true
PLUGINS=("$@")
DIR="${WPDA_DIR:-$ROOT/.wp-test}"
PORT="${WPDA_PORT:-8890}"
WP="$DIR/wordpress"
CLI="$DIR/wp-cli.phar"

mkdir -p "$DIR/cache"
fetch() { # url dest
	[ -s "$2" ] || curl -fsSL --retry 3 -o "$2.part" "$1" && { [ -s "$2" ] || mv "$2.part" "$2"; }
}

fetch "https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar" "$CLI"
wp() { php -d memory_limit=512M "$CLI" --path="$WP" --url="http://primary.test" "$@"; }

if [ "$WP_VERSION" = "latest" ]; then
	zip="$DIR/cache/wordpress-latest.zip"
	fetch "https://wordpress.org/latest.zip" "$zip"
else
	zip="$DIR/cache/wordpress-$WP_VERSION.zip"
	fetch "https://wordpress.org/wordpress-$WP_VERSION.zip" "$zip"
fi

rm -rf "$WP"
unzip -q "$zip" -d "$DIR"

sqlite_zip="$DIR/cache/sqlite-database-integration.zip"
fetch "https://downloads.wordpress.org/plugin/sqlite-database-integration.latest-stable.zip" "$sqlite_zip"
unzip -q "$sqlite_zip" -d "$WP/wp-content/plugins"
sed "s#{SQLITE_IMPLEMENTATION_FOLDER_PATH}#$WP/wp-content/plugins/sqlite-database-integration#; s#{SQLITE_PLUGIN}#sqlite-database-integration/load.php#" \
	"$WP/wp-content/plugins/sqlite-database-integration/db.copy" > "$WP/wp-content/db.php"

cp "$WP/wp-config-sample.php" "$WP/wp-config.php"
php -r '
$f = $argv[1];
$c = file_get_contents($f);
$extra = "define( \"DB_DIR\", __DIR__ . \"/wp-content/database\" );\n"
	. "define( \"WP_DEBUG\", true );\ndefine( \"WP_DEBUG_LOG\", __DIR__ . \"/wp-content/debug.log\" );\ndefine( \"WP_DEBUG_DISPLAY\", false );\n"
	. "define( \"DISABLE_WP_CRON\", true );\ndefine( \"AUTOMATIC_UPDATER_DISABLED\", true );\n";
$c = str_replace("define( \x27WP_DEBUG\x27, false );", "", $c);
$c = str_replace("/* That\x27s all, stop editing!", $extra . "/* That\x27s all, stop editing!", $c);
file_put_contents($f, $c);
' "$WP/wp-config.php"

wp core install --title="WP Domain Alias Test" --admin_user=admin --admin_password=password \
	--admin_email=admin@primary.test --skip-email
wp option update home 'http://primary.test' >/dev/null
wp option update siteurl 'http://primary.test' >/dev/null
wp rewrite structure '/%postname%/' >/dev/null
wp option update blogdescription "Local test site" >/dev/null

# The plugin under test, linked from the working tree. Lifecycle tests use
# WPDA_NO_LINK=1 and install the built ZIP instead, because uninstalling a
# linked copy would delete the working tree.
if [ "${WPDA_NO_LINK:-0}" != "1" ]; then
	ln -s "$ROOT" "$WP/wp-content/plugins/wp-domain-alias"
fi

# Test-only MU plugin: lets the health check reach 127.0.0.1 and maps test hosts
# to the local server. Never shipped.
mkdir -p "$WP/wp-content/mu-plugins"
cp "$ROOT/tests/e2e/fixtures/wpda-test-env.php" "$WP/wp-content/mu-plugins/"

for slug in ${PLUGINS[@]+"${PLUGINS[@]}"}; do
	pzip="$DIR/cache/$slug.zip"
	fetch "https://downloads.wordpress.org/plugin/$slug.latest-stable.zip" "$pzip"
	unzip -q -o "$pzip" -d "$WP/wp-content/plugins"
done

wp eval-file "$ROOT/tests/e2e/fixtures/seed.php"
echo "WordPress $(wp core version) ready in $WP (port $PORT)"
