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
	 * Whether a path is admin-post.php.
	 *
	 * @param string $path URL path.
	 * @return bool
	 */
	public function is_admin_post_path( $path ) {
		$site = wp_parse_url( (string) get_option( 'siteurl' ), PHP_URL_PATH );
		$post = rtrim( is_string( $site ) ? $site : '', '/' ) . '/wp-admin/admin-post.php';
		return strtolower( (string) $path ) === strtolower( $post );
	}

	/**
	 * Actions of admin-post.php forms that site code has opted in to keep on the alias.
	 *
	 * Empty by default: admin-post.php forms post to the primary domain unless
	 * their action is listed here.
	 *
	 * @return string[]
	 */
	public function admin_post_actions() {
		/**
		 * Filters the admin-post.php actions whose frontend forms submit on the
		 * alias the visitor is using (instead of the primary domain), and whose
		 * redirects return to that alias.
		 *
		 * List only public (nopriv) form handlers you have tested on an alias.
		 * Logged-in sessions belong to the primary domain, so handlers that need a
		 * logged-in user will not see one on an alias.
		 *
		 * @param string[] $actions Action names (the form's "action" field). Default empty.
		 */
		$actions = apply_filters( 'wp_domain_alias_admin_post_actions', array() );
		$clean   = array();
		foreach ( is_array( $actions ) ? $actions : array() as $action ) {
			if ( is_string( $action ) && '' !== $action && sanitize_key( $action ) === $action ) {
				$clean[] = $action;
			}
		}
		return array_values( array_unique( $clean ) );
	}

	/**
	 * Whether this request is an opted-in admin-post.php action on an alias.
	 *
	 * @return bool
	 */
	public function is_alias_admin_post() {
		if ( '' === $this->domains->current_alias() || ! $this->is_admin_post_path( $this->request_path ) ) {
			return false;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Only compared with an allowlist; the handler verifies its own nonce.
		$action = isset( $_REQUEST['action'] ) && is_string( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( $_REQUEST['action'] ) ) : '';
		return '' !== $action && in_array( $action, $this->admin_post_actions(), true );
	}

	/**
	 * Moves an own-site URL onto the current request alias.
	 *
	 * @param mixed $url              URL.
	 * @param bool  $allow_ajax       Allow /wp-admin/admin-ajax.php to move.
	 * @param bool  $allow_admin_post Allow /wp-admin/admin-post.php to move (opted-in forms only).
	 * @return mixed The rewritten URL, or the input unchanged.
	 */
	public function to_alias( $url, $allow_ajax = false, $allow_admin_post = false ) {
		$alias = $this->domains->current_alias();
		if ( '' === $alias ) {
			return $url;
		}
		$scheme = is_ssl() ? 'https' : $this->domains->primary_scheme();
		return $this->move( $url, $this->domains->own_hosts(), $scheme, $alias, $allow_ajax, $allow_admin_post );
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
	 * @param bool     $allow_admin_post Allow admin-post.php to move.
	 * @return mixed
	 */
	public function move( $url, array $from, $scheme, $to, $allow_ajax = false, $allow_admin_post = false ) {
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
		if ( $this->is_protected_path( $path ) && ! ( $allow_ajax && $this->is_ajax_path( $path ) ) && ! ( $allow_admin_post && $this->is_admin_post_path( $path ) ) ) {
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
