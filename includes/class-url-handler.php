<?php
/**
 * URL mapping between the primary host and the request alias.
 *
 * @package WP_Domain_Alias
 */

namespace WP_Domain_Alias;

defined( 'ABSPATH' ) || exit;

/**
 * Decides whether a URL belongs to this site and moves it to another
 * approved host. Never touches external URLs or protected endpoints.
 */
class Url_Handler {

	/**
	 * Domain manager.
	 *
	 * @var Domain_Manager
	 */
	private $domains;

	/**
	 * Request path captured before other plugins rewrite REQUEST_URI.
	 *
	 * @var string
	 */
	private $request_path = '/';

	/**
	 * Constructor.
	 *
	 * @param Domain_Manager $domains Domain manager.
	 */
	public function __construct( Domain_Manager $domains ) {
		$this->domains = $domains;
	}

	/**
	 * Records the raw request path. Called as early as possible at load.
	 *
	 * @param string $request_uri Raw REQUEST_URI.
	 * @return void
	 */
	public function capture_request_path( $request_uri ) {
		// A leading "//" would otherwise be parsed as a protocol-relative host.
		$path               = wp_parse_url( preg_replace( '~^/{2,}~', '/', (string) $request_uri ), PHP_URL_PATH );
		$this->request_path = is_string( $path ) && '' !== $path ? $path : '/';
	}

	/**
	 * Raw request path captured at load time.
	 *
	 * @return string
	 */
	public function request_path() {
		return $this->request_path;
	}

	/**
	 * Whether this is a frontend page request on an approved alias.
	 *
	 * False for the primary host, admin, AJAX, REST, cron, WP-CLI and any
	 * protected path.
	 *
	 * @return bool
	 */
	public function is_alias_frontend() {
		if ( '' === $this->domains->current_alias() ) {
			return false;
		}
		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() ) {
			return false;
		}
		if ( ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			return false;
		}
		if ( function_exists( 'is_login' ) && is_login() ) {
			// The login screen reached through an unusual path spelling.
			return false;
		}
		return ! $this->is_protected_path( $this->request_path );
	}

	/**
	 * Paths that always stay on the primary domain: admin, login, sign-up,
	 * cron and XML-RPC.
	 *
	 * @param string $path URL path.
	 * @return bool
	 */
	public function is_protected_path( $path ) {
		// Match what the web server will execute: decoded, with repeated
		// slashes collapsed, so /wp%2Dlogin.php or //wp-admin/ still count.
		$path     = preg_replace( '~/{2,}~', '/', rawurldecode( (string) $path ) );
		$prefixes = array( '' );
		$site     = wp_parse_url( (string) get_option( 'siteurl' ), PHP_URL_PATH );
		if ( is_string( $site ) && '' !== trim( $site, '/' ) ) {
			// WordPress installed in a subdirectory, for example /wp/wp-admin/.
			$prefixes[] = '/' . trim( $site, '/' );
		}
		foreach ( $prefixes as $prefix ) {
			$pattern = '~^' . preg_quote( $prefix, '~' ) . '/(?:wp-admin(?:/|$)|wp-login\.php|wp-signup\.php|wp-activate\.php|wp-cron\.php|xmlrpc\.php)~i';
			if ( preg_match( $pattern, $path ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Whether a path is the frontend AJAX endpoint.
	 *
	 * @param string $path URL path.
	 * @return bool
	 */
	public function is_ajax_path( $path ) {
		// Built from the stored option, not admin_url(): this plugin filters
		// admin_url, and calling it here would recurse.
		$site = wp_parse_url( (string) get_option( 'siteurl' ), PHP_URL_PATH );
		$ajax = rtrim( is_string( $site ) ? $site : '', '/' ) . '/wp-admin/admin-ajax.php';
		return strtolower( (string) $path ) === strtolower( $ajax );
	}

	/**
	 * Moves an own-site URL onto the current request alias.
	 *
	 * @param mixed $url        URL.
	 * @param bool  $allow_ajax Allow /wp-admin/admin-ajax.php to move.
	 * @return mixed The rewritten URL, or the input unchanged.
	 */
	public function to_alias( $url, $allow_ajax = false ) {
		$alias = $this->domains->current_alias();
		if ( '' === $alias ) {
			return $url;
		}
		$scheme = is_ssl() ? 'https' : $this->domains->primary_scheme();
		return $this->move( $url, $this->domains->own_hosts(), $scheme, $alias, $allow_ajax );
	}

	/**
	 * Moves a URL whose host is in $from to $scheme://$to.
	 *
	 * Only absolute (http/https) and protocol-relative URLs with an exact
	 * host match are changed. Authorities containing userinfo or a port do not
	 * match and are left alone. Path, query and fragment are preserved.
	 *
	 * @param mixed    $url        URL.
	 * @param string[] $from       Hosts to move from.
	 * @param string   $scheme     Target scheme.
	 * @param string   $to         Target host.
	 * @param bool     $allow_ajax Allow the frontend AJAX endpoint to move.
	 * @return mixed
	 */
	public function move( $url, array $from, $scheme, $to, $allow_ajax = false ) {
		if ( ! is_string( $url ) || '' === $url || ! preg_match( '~^(?:(https?):)?//([^/?#]*)(.*)$~is', $url, $m ) ) {
			return $url;
		}
		$host = strtolower( $m[2] );
		if ( $host === $to && '' !== $m[1] && strtolower( $m[1] ) === $scheme ) {
			return $url;
		}
		if ( ! in_array( $host, $from, true ) && $host !== $to ) {
			return $url;
		}
		$path = wp_parse_url( 'http://placeholder' . $m[3], PHP_URL_PATH );
		$path = is_string( $path ) ? $path : '/';
		if ( $this->is_protected_path( $path ) && ! ( $allow_ajax && $this->is_ajax_path( $path ) ) ) {
			return $url;
		}
		/**
		 * Filters whether an own-site URL may be moved to another approved host.
		 *
		 * Return false to keep a specific URL (for example a checkout or
		 * payment callback) on its original host.
		 *
		 * @param bool   $allowed Whether the URL may move.
		 * @param string $url     Original URL.
		 * @param string $to      Target host.
		 */
		if ( ! apply_filters( 'wp_domain_alias_rewrite_url', true, $url, $to ) ) {
			return $url;
		}
		return $scheme . '://' . $to . $m[3];
	}
}
