#!/usr/bin/env bash
# Starts or stops the local test server.  Usage: tests/e2e/server.sh start|stop
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
DIR="${WPDA_DIR:-$ROOT/.wp-test}"
PORT="${WPDA_PORT:-8890}"
PIDFILE="$DIR/server.pid"
case "${1:-start}" in
	start)
		if [ -f "$PIDFILE" ] && kill -0 "$(cat "$PIDFILE")" 2>/dev/null; then echo "already running"; exit 0; fi
		WPDA_PORT="$PORT" PHP_CLI_SERVER_WORKERS=4 nohup php -d memory_limit=512M -S "127.0.0.1:$PORT" -t "$DIR/wordpress" "$ROOT/tests/e2e/router.php" >"$DIR/server.log" 2>&1 &
		echo $! > "$PIDFILE"
		for _ in $(seq 1 50); do curl -fs -o /dev/null -H 'Host: primary.test' "http://127.0.0.1:$PORT/wp-login.php" && { echo "server on $PORT"; exit 0; }; sleep 0.2; done
		echo "server failed to start"; cat "$DIR/server.log"; exit 1 ;;
	stop)
		[ -f "$PIDFILE" ] && kill "$(cat "$PIDFILE")" 2>/dev/null || true
		rm -f "$PIDFILE"; echo stopped ;;
esac
