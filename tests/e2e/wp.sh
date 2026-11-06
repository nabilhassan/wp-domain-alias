#!/usr/bin/env bash
# WP-CLI against the local test site.  Usage: tests/e2e/wp.sh <command...>
ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
DIR="${WPDA_DIR:-$ROOT/.wp-test}"
exec php -d memory_limit=512M "$DIR/wp-cli.phar" --path="$DIR/wordpress" --url=http://primary.test "$@" 2> >(grep -v "^$\|Constant WP_DEBUG" >&2)
