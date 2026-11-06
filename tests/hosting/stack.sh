#!/usr/bin/env bash
# Local server stacks for hosting tests: Nginx + PHP-FPM and Apache + PHP-FPM,
# with HTTPS from a throwaway test CA. Nothing is installed system-wide and
# no trust store is changed; everything lives in .wp-test-hosting/.
#
#   tests/hosting/stack.sh setup          build the HTTPS test site + certificates
#   tests/hosting/stack.sh start          copy the plugin in, start PHP-FPM, Nginx and Apache
#   tests/hosting/stack.sh sync           copy the working tree's plugin files in again
#   tests/hosting/stack.sh stop
#   tests/hosting/stack.sh env nginx|apache   print the variables for the test suites
#   tests/hosting/stack.sh versions       print exact server software versions
#
# Needs: nginx, PHP-FPM and an Apache httpd with mod_proxy_fcgi and mod_ssl.
# Override with NGINX, PHP_FPM, HTTPD, OPENSSL.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
RUN="$ROOT/.wp-test-hosting"
WP_HTTPS="$ROOT/.wp-test-https/wordpress"
NGINX="${NGINX:-$(command -v nginx || echo /opt/homebrew/bin/nginx)}"
PHP_FPM="${PHP_FPM:-$(command -v php-fpm || echo /opt/homebrew/sbin/php-fpm)}"
HTTPD="${HTTPD:-$(command -v httpd || command -v apache2 || echo /usr/sbin/httpd)}"
APACHE_MODULES="${APACHE_MODULES:-$( [ -d /usr/libexec/apache2 ] && echo /usr/libexec/apache2 || echo /usr/lib/apache2/modules )}"
APACHE_MIME="${APACHE_MIME:-$( [ -f /private/etc/apache2/mime.types ] && echo /private/etc/apache2/mime.types || echo /etc/mime.types )}"
OPENSSL="${OPENSSL:-$(ls /opt/homebrew/opt/openssl@3/bin/openssl 2>/dev/null || command -v openssl)}"
NGINX_ETC="$(dirname "$("$NGINX" -V 2>&1 | sed -n 's/.*--conf-path=\([^ ]*\).*/\1/p')")"
TTL="${WPDA_FCGI_TTL:-8}"
HOSTS=(primary.test www.primary.test alias-one.test alias-two.test www.alias-two.test)

render() { # template output
	sed -e "s#@RUN@#$RUN#g" -e "s#@ROOT@#$ROOT#g" -e "s#@WP_HTTPS@#$WP_HTTPS#g" \
		-e "s#@NGINX_ETC@#$NGINX_ETC#g" -e "s#@APACHE_MODULES@#$APACHE_MODULES#g" -e "s#@APACHE_MIME@#$APACHE_MIME#g" -e "s#@TTL@#$TTL#g" "$1" > "$2"
}

htaccess() { # WordPress's standard rewrite block, as written on Apache hosts
	cat > "$1/.htaccess" <<'HT'
# BEGIN WordPress
<IfModule mod_rewrite.c>
RewriteEngine On
RewriteRule .* - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]
RewriteBase /
RewriteRule ^index\.php$ - [L]
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteRule . /index.php [L]
</IfModule>
# END WordPress
HT
}

certs() {
	local d="$RUN/tls"
	mkdir -p "$d"
	[ -s "$d/leaf.pem" ] && return
	"$OPENSSL" req -x509 -newkey rsa:2048 -nodes -days 30 -subj "/CN=WP Domain Alias throwaway test CA" \
		-addext "basicConstraints=critical,CA:TRUE" -addext "keyUsage=critical,keyCertSign,cRLSign" \
		-keyout "$d/ca.key" -out "$d/ca.pem" 2>/dev/null
	local san
	san="$(printf 'DNS:%s,' "${HOSTS[@]}")"
	"$OPENSSL" req -newkey rsa:2048 -nodes -subj "/CN=primary.test" -keyout "$d/leaf.key" -out "$d/leaf.csr" 2>/dev/null
	printf 'subjectAltName=%s\nbasicConstraints=CA:FALSE\nkeyUsage=digitalSignature,keyEncipherment\nextendedKeyUsage=serverAuth\n' "${san%,}" > "$d/leaf.ext"
	"$OPENSSL" x509 -req -in "$d/leaf.csr" -CA "$d/ca.pem" -CAkey "$d/ca.key" -CAcreateserial -days 30 \
		-extfile "$d/leaf.ext" -out "$d/leaf.pem" 2>/dev/null
	rm -f "$d/ca.key" "$d/leaf.csr"  # the CA can never sign anything else
}

# A copy, as on a real site. (A symlink to the repository would put this
# WordPress inside the plugin's real path and break other plugins' asset URLs.)
sync_plugin() {
	local dest="$WP_HTTPS/wp-content/plugins/wp-domain-alias"
	[ -L "$dest" ] && rm "$dest"
	mkdir -p "$dest"
	(cd "$ROOT" && git ls-files -co --exclude-standard -- wp-domain-alias.php uninstall.php includes admin languages mu-loader readme.txt LICENSE) \
		| rsync -a --delete --files-from=- "$ROOT/" "$dest/"
}

spki() { "$OPENSSL" x509 -in "$RUN/tls/leaf.pem" -pubkey -noout | "$OPENSSL" pkey -pubin -outform der | "$OPENSSL" dgst -sha256 -binary | base64; }

case "${1:-}" in
	setup)
		mkdir -p "$RUN"
		certs
		if [ ! -f "$WP_HTTPS/wp-config.php" ]; then
			WPDA_DIR="$ROOT/.wp-test-https" WPDA_SCHEME=https "$ROOT/tests/e2e/setup-wp.sh" latest \
				polylang contact-form-7 elementor wordpress-seo seo-by-rank-math cache-enabler
		fi
		# Health checks from this site connect to the HTTPS Nginx port and verify the test CA.
		grep -q WPDA_TEST_CONNECT_PORT "$WP_HTTPS/wp-config.php" || php -r '
			$f = $argv[1]; $c = file_get_contents($f);
			$add = "define( \"WPDA_TEST_CONNECT_PORT\", 8443 );\ndefine( \"WPDA_TEST_CA\", \"" . $argv[2] . "\" );\n";
			file_put_contents($f, str_replace("/* That\x27s all, stop editing!", $add . "/* That\x27s all, stop editing!", $c));
		' "$WP_HTTPS/wp-config.php" "$RUN/tls/ca.pem"
		htaccess "$WP_HTTPS"
		sync_plugin
		echo "HTTPS site ready in $WP_HTTPS" ;;
	start)
		[ -s "$RUN/tls/leaf.pem" ] || { echo "run setup first"; exit 1; }
		mkdir -p "$RUN/nginx-tmp"
		sync_plugin
		for t in php-fpm nginx httpd; do render "$ROOT/tests/hosting/$t.conf.in" "$RUN/$t.conf"; done
		[ -f "$RUN/php-fpm.pid" ] && kill -0 "$(cat "$RUN/php-fpm.pid")" 2>/dev/null || "$PHP_FPM" -y "$RUN/php-fpm.conf" -c "$(php -r 'echo php_ini_loaded_file();')"
		[ -f "$RUN/nginx.pid" ] && kill -0 "$(cat "$RUN/nginx.pid")" 2>/dev/null || "$NGINX" -c "$RUN/nginx.conf" -e "$RUN/nginx-error.log"
		[ -f "$RUN/httpd.pid" ] && kill -0 "$(cat "$RUN/httpd.pid")" 2>/dev/null || "$HTTPD" -f "$RUN/httpd.conf" -k start
		for port in 8443 8444 8445 8446; do
			for _ in $(seq 1 50); do nc -z 127.0.0.1 "$port" 2>/dev/null && break; sleep 0.2; done
		done
		echo "stacks started" ;;
	sync) sync_plugin; echo synced ;;
	stop)
		[ -f "$RUN/httpd.pid" ] && "$HTTPD" -f "$RUN/httpd.conf" -k stop 2>/dev/null || true
		[ -f "$RUN/nginx.pid" ] && "$NGINX" -c "$RUN/nginx.conf" -e "$RUN/nginx-error.log" -s stop 2>/dev/null || true
		[ -f "$RUN/php-fpm.pid" ] && kill "$(cat "$RUN/php-fpm.pid")" 2>/dev/null || true
		rm -f "$RUN/php-fpm.pid"
		echo stopped ;;
	env)
		case "${2:-}" in
			nginx) https=8443 ;;
			apache) https=8444 ;;
			*) echo "usage: env nginx|apache"; exit 1 ;;
		esac
		echo "WPDA_DIR=$ROOT/.wp-test-https WPDA_SCHEME=https WPDA_PORT=$https WPDA_CA=$RUN/tls/ca.pem WPDA_TLS_SPKI=$(spki)" ;;
	versions)
		"$NGINX" -v 2>&1
		"$HTTPD" -v | head -1
		"$PHP_FPM" -v | head -1
		"$OPENSSL" version
		php "$ROOT/.wp-test-https/wp-cli.phar" --path="$WP_HTTPS" core version 2>/dev/null | sed 's/^/WordPress /'
		php "$ROOT/.wp-test-https/wp-cli.phar" --path="$WP_HTTPS" plugin list --fields=name,version,status --format=csv 2>/dev/null ;;
	*) sed -n '2,13p' "$0"; exit 1 ;;
esac
