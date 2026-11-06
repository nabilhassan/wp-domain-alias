<?php
/**
 * Administrator-triggered health check.
 *
 * @package WP_Domain_Alias
 */

namespace WP_Domain_Alias;

defined( 'ABSPATH' ) || exit;

/**
 * Read-only checks of the WordPress configuration and of each configured
 * domain's homepage.
 *
 * Safety rules:
 * - Called only from authenticated, nonce-checked admin requests.
 * - Requests go only to the saved primary host and saved aliases, snapshotted
 *   when a run starts; nothing from the request is used as a destination.
 * - Each host is resolved once, every address must be public (unless core's
 *   http_request_host_is_external filter allows the host), and the request is
 *   pinned to the validated address so a second DNS answer cannot redirect it
 *   (DNS rebinding). wp_safe_remote_get's own check stays as a second layer.
 * - No redirects are followed, timeouts are short and responses are capped.
 * - A run is processed in small steps (at most BATCH_SIZE hosts per request)
 *   so no single PHP request runs long, whatever the number of aliases.
 * - Only short-lived per-user transients are written.
 */
class Health_Check {

	const TIMEOUT        = 5;
	const MAX_BYTES      = 1048576;
	const RATE_LIMIT_TTL = 20;
	const BATCH_SIZE     = 3;
	const RUN_TTL        = 600;

	const PASS = 'pass';
	const WARN = 'warn';
	const FAIL = 'fail';
	const INFO = 'info';

	/**
	 * Domain manager.
	 *
	 * @var Domain_Manager
	 */
	private $domains;

	/**
	 * Constructor.
	 *
	 * @param Domain_Manager $domains Domain manager.
	 */
	public function __construct( Domain_Manager $domains ) {
		$this->domains = $domains;
	}

	/**
	 * Configuration checks that need no network access.
	 *
	 * @return array<int,array{status:string,label:string,message:string}>
	 */
	public function configuration_checks() {
		global $wp_version;
		$checks  = array();
		$primary = $this->domains->primary_host();
		$aliases = $this->domains->aliases();

		$checks[] = $this->item(
			version_compare( $wp_version, '6.4', '>=' ) && class_exists( 'WP_HTML_Tag_Processor' ) ? self::PASS : self::FAIL,
			__( 'WordPress version', 'wp-domain-alias' ),
			/* translators: %s: WordPress version. */
			sprintf( __( 'WordPress %s. Version 6.4 or newer is required.', 'wp-domain-alias' ), $wp_version )
		);

		if ( ! method_exists( 'WP_HTML_Tag_Processor', 'set_modifiable_text' ) ) {
			$checks[] = $this->item(
				self::INFO,
				__( 'Inline font URLs', 'wp-domain-alias' ),
				__( 'Theme and Font Library fonts are served from the alias. Fonts that other plugins print inline with a full primary-domain URL are only adjusted on WordPress 6.7 or newer; on older versions they may need a CORS header on the primary domain.', 'wp-domain-alias' )
			);
		}

		$checks[] = $this->item(
			$this->domains->is_enabled() ? self::PASS : self::WARN,
			__( 'Hostname preservation', 'wp-domain-alias' ),
			$this->domains->is_enabled()
				? __( 'Enabled.', 'wp-domain-alias' )
				: __( 'Disabled. Aliases show the site with primary-domain links.', 'wp-domain-alias' )
		);

		$checks[] = $this->item(
			'https' === $this->domains->primary_scheme() ? self::PASS : self::WARN,
			__( 'HTTPS', 'wp-domain-alias' ),
			'https' === $this->domains->primary_scheme()
				? __( 'The site address uses HTTPS. Every alias needs its own valid certificate.', 'wp-domain-alias' )
				: __( 'The site address uses HTTP. HTTPS is strongly recommended for the primary domain and every alias.', 'wp-domain-alias' )
		);

		$site_host = strtolower( (string) wp_parse_url( (string) get_option( 'siteurl' ), PHP_URL_HOST ) );
		if ( '' !== $site_host && $site_host !== $primary ) {
			$checks[] = $this->item(
				self::INFO,
				__( 'Admin address', 'wp-domain-alias' ),
				/* translators: %s: WordPress address host. */
				sprintf( __( 'The WordPress address uses %s. Admin and login stay there.', 'wp-domain-alias' ), $site_host )
			);
		}

		if ( empty( $aliases ) ) {
			$checks[] = $this->item( self::INFO, __( 'Aliases', 'wp-domain-alias' ), __( 'No aliases are configured yet.', 'wp-domain-alias' ) );
		}

		$www_primary = Host_Validator::www_counterpart( $primary );
		if ( in_array( $www_primary, $aliases, true ) ) {
			$checks[] = $this->item(
				self::WARN,
				__( 'Redirect conflict', 'wp-domain-alias' ),
				/* translators: %s: hostname. */
				sprintf( __( '%s is the www/non-www form of the primary domain. Most hosts redirect it at server level, which this plugin cannot override. Run the health check to confirm.', 'wp-domain-alias' ), $www_primary )
			);
		}

		foreach ( $aliases as $alias ) {
			$pair = Host_Validator::www_counterpart( $alias );
			if ( $pair !== $primary && ! in_array( $pair, $aliases, true ) ) {
				$checks[] = $this->item(
					self::INFO,
					__( 'www variant', 'wp-domain-alias' ),
					/* translators: 1: configured alias, 2: its www/non-www counterpart. */
					sprintf( __( '%1$s is configured but %2$s is not. Add it too if visitors may use it.', 'wp-domain-alias' ), Host_Validator::to_display( $alias ), Host_Validator::to_display( $pair ) )
				);
			}
		}

		if ( defined( 'COOKIE_DOMAIN' ) && COOKIE_DOMAIN ) {
			$checks[] = $this->item(
				self::WARN,
				__( 'Cookie domain', 'wp-domain-alias' ),
				__( 'COOKIE_DOMAIN is set in wp-config.php. Cookies set on alias pages will be ignored by browsers unless that constant is removed.', 'wp-domain-alias' )
			);
		}

		$caches = $this->detect_cache_plugins();
		if ( $caches ) {
			$checks[] = $this->item(
				self::WARN,
				__( 'Page cache', 'wp-domain-alias' ),
				/* translators: %s: comma-separated plugin names. */
				sprintf( __( 'Detected: %s. Make sure cached pages are stored separately per domain, then purge the cache after changing aliases. Run the health check to look for cross-domain cache mix-ups.', 'wp-domain-alias' ), implode( ', ', $caches ) )
			);
		}

		if ( class_exists( 'Cache_Enabler_Engine', false ) && isset( \Cache_Enabler_Engine::$settings['cache_expires'] ) && ! \Cache_Enabler_Engine::$settings['cache_expires'] ) {
			$checks[] = $this->item(
				self::INFO,
				__( 'Cache Enabler', 'wp-domain-alias' ),
				__( 'Alias copies are cleared whenever content changes. As a safety net, enable "Cache Expiry" in Cache Enabler so alias pages can never stay stale indefinitely.', 'wp-domain-alias' )
			);
		}

		return $checks;
	}

	/**
	 * Hosts a run checks: the primary host, then each saved alias.
	 *
	 * @return string[]
	 */
	public function hosts_to_check() {
		$hosts = array();
		foreach ( array_merge( array( $this->domains->primary_host() ), $this->domains->aliases() ) as $host ) {
			if ( Host_Validator::is_valid_host( $host ) && ! in_array( $host, $hosts, true ) ) {
				$hosts[] = $host;
			}
		}
		return $hosts;
	}

	/**
	 * Starts a new run for a user. Nothing is requested yet.
	 *
	 * @param int $user_id User ID.
	 * @return array|false Run state, or false when rate-limited.
	 */
	public function start_run( $user_id ) {
		if ( ! $this->acquire_rate_limit( $user_id ) ) {
			return false;
		}
		$run = array(
			'id'      => md5( uniqid( (string) $user_id, true ) ),
			'started' => time(),
			'primary' => $this->domains->primary_host(),
			'hosts'   => $this->hosts_to_check(),
			'results' => array(),
		);
		set_transient( $this->run_key( $user_id ), $run, self::RUN_TTL );
		return $run;
	}

	/**
	 * The user's current run, or null.
	 *
	 * @param int $user_id User ID.
	 * @return array|null
	 */
	public function get_run( $user_id ) {
		$run = get_transient( $this->run_key( $user_id ) );
		if ( ! is_array( $run ) || ! isset( $run['id'], $run['hosts'], $run['results'] ) || ! is_array( $run['hosts'] ) || ! is_array( $run['results'] ) ) {
			return null;
		}
		return $run;
	}

	/**
	 * Checks the next hosts of the user's run, at most $max_hosts of them.
	 *
	 * Concurrent steps for the same user are refused, so repeated or parallel
	 * requests cannot multiply outgoing traffic.
	 *
	 * @param int    $user_id   User ID.
	 * @param int    $max_hosts Hosts to check in this request (1 to BATCH_SIZE).
	 * @param string $run_id    Optional run ID the caller expects.
	 * @return array|\WP_Error Updated run state.
	 */
	public function run_step( $user_id, $max_hosts = self::BATCH_SIZE, $run_id = '' ) {
		$run = $this->get_run( $user_id );
		if ( null === $run || ( '' !== $run_id && ! hash_equals( $run['id'], (string) $run_id ) ) ) {
			return new \WP_Error( 'wpda_no_run', __( 'This health check has expired. Start a new one.', 'wp-domain-alias' ) );
		}
		$lock = 'wpda_hc_step_' . (int) $user_id;
		if ( get_transient( $lock ) ) {
			return new \WP_Error( 'wpda_busy', __( 'A health check step is already running. Please wait.', 'wp-domain-alias' ) );
		}
		$max_hosts = max( 1, min( self::BATCH_SIZE, (int) $max_hosts ) );
		set_transient( $lock, 1, self::TIMEOUT * $max_hosts + 15 );

		$done = count( $run['results'] );
		$todo = array_slice( $run['hosts'], $done, $max_hosts );
		foreach ( $todo as $host ) {
			$run['results'][] = array(
				'host'    => $host,
				'display' => Host_Validator::to_display( $host ),
				'checks'  => $this->check_host( $host, $host === $run['primary'] ),
			);
		}
		set_transient( $this->run_key( $user_id ), $run, self::RUN_TTL );
		delete_transient( $lock );
		return $run;
	}

	/**
	 * Whether every host of a run has been checked.
	 *
	 * @param array $run Run state.
	 * @return bool
	 */
	public static function is_complete( array $run ) {
		return count( $run['results'] ) >= count( $run['hosts'] );
	}

	/**
	 * Transient key of a user's run.
	 *
	 * @param int $user_id User ID.
	 * @return string
	 */
	private function run_key( $user_id ) {
		return 'wpda_health_' . (int) $user_id;
	}

	/**
	 * Per-user rate limit. Returns false when a check ran very recently.
	 *
	 * @param int $user_id User ID.
	 * @return bool
	 */
	public function acquire_rate_limit( $user_id ) {
		$key = 'wpda_hc_lock_' . (int) $user_id;
		if ( get_transient( $key ) ) {
			return false;
		}
		set_transient( $key, 1, self::RATE_LIMIT_TTL );
		return true;
	}

	/**
	 * Checks a single host.
	 *
	 * @param string $host       Hostname from the saved configuration.
	 * @param bool   $is_primary Whether this is the primary host.
	 * @return array
	 */
	private function check_host( $host, $is_primary ) {
		$scheme = $this->domains->primary_scheme();
		$url    = $scheme . '://' . $host . '/';
		$checks = array();

		$ips = $this->resolve( $host );
		if ( empty( $ips ) ) {
			$checks[] = $this->item( self::FAIL, __( 'Reachable', 'wp-domain-alias' ), __( 'The domain does not resolve. Check its DNS records.', 'wp-domain-alias' ) );
			return $checks;
		}
		// Like core's wp_http_validate_url(), the site's own host may resolve
		// internally (intranets, containers, NAT); aliases may not, unless core's
		// http_request_host_is_external filter allows them.
		/** This filter is documented in wp-includes/http.php */
		$allow_internal = $is_primary || (bool) apply_filters( 'http_request_host_is_external', false, $host, $url ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core's hook, applied with core's semantics.
		foreach ( $ips as $ip ) {
			if ( ! $allow_internal && ! self::is_public_ip( $ip ) ) {
				$checks[] = $this->item( self::FAIL, __( 'Reachable', 'wp-domain-alias' ), __( 'Not checked: the domain resolves to a private or reserved network address. Requests to internal addresses are blocked.', 'wp-domain-alias' ) );
				return $checks;
			}
		}
		$pin = $this->pin( $host, 'https' === $scheme ? 443 : 80, $ips[0], $url );

		$response = wp_safe_remote_get(
			$url,
			array(
				'timeout'             => self::TIMEOUT,
				'redirection'         => 0,
				'sslverify'           => true,
				'reject_unsafe_urls'  => true,
				'limit_response_size' => self::MAX_BYTES,
				'user-agent'          => 'WP-Domain-Alias-Health-Check/' . WP_DOMAIN_ALIAS_VERSION,
				'headers'             => array( 'Accept' => 'text/html' ),
				'cookies'             => array(),
			)
		);
		remove_action( 'http_api_curl', $pin, 10 );

		if ( is_wp_error( $response ) ) {
			$checks[] = $this->item(
				self::FAIL,
				__( 'Reachable', 'wp-domain-alias' ),
				$this->describe_error( $response )
			);
			return $checks;
		}

		$status   = (int) wp_remote_retrieve_response_code( $response );
		$location = (string) wp_remote_retrieve_header( $response, 'location' );
		$body     = (string) wp_remote_retrieve_body( $response );

		$checks[] = $this->item(
			200 === $status ? self::PASS : self::FAIL,
			__( 'Reachable', 'wp-domain-alias' ),
			/* translators: %d: HTTP status code. */
			sprintf( __( 'HTTP %d', 'wp-domain-alias' ), $status )
		);

		if ( 'https' === $scheme ) {
			$checks[] = $this->item( self::PASS, __( 'HTTPS', 'wp-domain-alias' ), __( 'Valid certificate.', 'wp-domain-alias' ) );
		}

		if ( '' !== $location ) {
			$target   = strtolower( (string) wp_parse_url( $location, PHP_URL_HOST ) );
			$checks[] = $this->item(
				( '' === $target || $target === $host ) ? self::WARN : self::FAIL,
				__( 'Redirects', 'wp-domain-alias' ),
				( '' === $target || $target === $host )
					/* translators: %s: redirect location. */
					? sprintf( __( 'Redirects within the same domain to %s.', 'wp-domain-alias' ), $this->short( $location ) )
					/* translators: %s: redirect location. */
					: sprintf( __( 'Redirects to another domain: %s. This is usually a hosting, CDN or redirect-plugin rule that must be removed for this alias.', 'wp-domain-alias' ), $this->short( $location ) )
			);
		} else {
			$checks[] = $this->item( self::PASS, __( 'Redirects', 'wp-domain-alias' ), __( 'No redirect.', 'wp-domain-alias' ) );
		}

		if ( 200 !== $status || '' === $body ) {
			return $checks;
		}

		$scan = $this->scan_html( $body );

		if ( ! $is_primary ) {
			$own     = $scan['link_hosts'];
			$foreign = array_diff( $own, array( $host ) );
			if ( empty( $own ) ) {
				$checks[] = $this->item( self::WARN, __( 'Hostname retained', 'wp-domain-alias' ), __( 'No internal links were found on the homepage to verify.', 'wp-domain-alias' ) );
			} elseif ( empty( $foreign ) ) {
				$checks[] = $this->item( self::PASS, __( 'Hostname retained', 'wp-domain-alias' ), __( 'Internal links stay on this domain.', 'wp-domain-alias' ) );
			} else {
				$checks[] = $this->item(
					self::FAIL,
					__( 'Hostname retained', 'wp-domain-alias' ),
					/* translators: %s: list of hosts. */
					sprintf( __( 'Some internal links point to %s. If hostname preservation is enabled, a page cache may be serving another domain\'s copy of this page.', 'wp-domain-alias' ), implode( ', ', array_map( array( Host_Validator::class, 'to_display' ), $foreign ) ) )
				);
			}
		}

		$expected = $this->domains->primary_host();
		if ( 0 === count( $scan['canonicals'] ) ) {
			$checks[] = $this->item( self::INFO, __( 'Canonical', 'wp-domain-alias' ), __( 'The homepage has no canonical tag.', 'wp-domain-alias' ) );
		} elseif ( count( $scan['canonicals'] ) > 1 ) {
			$checks[] = $this->item( self::FAIL, __( 'Canonical', 'wp-domain-alias' ), __( 'More than one canonical tag was found.', 'wp-domain-alias' ) );
		} else {
			$canonical_host = strtolower( (string) wp_parse_url( $scan['canonicals'][0], PHP_URL_HOST ) );
			$checks[]       = $this->item(
				$canonical_host === $expected ? self::PASS : self::FAIL,
				__( 'Canonical', 'wp-domain-alias' ),
				/* translators: %s: canonical URL. */
				sprintf( __( 'Canonical: %s', 'wp-domain-alias' ), $this->short( $scan['canonicals'][0] ) )
			);
		}

		return $checks;
	}

	/**
	 * Resolves a host to its IP addresses (IPv4, else IPv6).
	 *
	 * @param string $host Hostname.
	 * @return string[]
	 */
	private function resolve( $host ) {
		/**
		 * Short-circuits DNS resolution for the health check, for example on
		 * split-horizon DNS or in tests. Return an array of IP addresses, or
		 * null to resolve normally. Returned addresses are still validated.
		 *
		 * @param string[]|null $ips  Addresses, or null.
		 * @param string        $host Hostname.
		 */
		$ips = apply_filters( 'wp_domain_alias_health_check_ips', null, $host );
		if ( null === $ips ) {
			$ips = gethostbynamel( $host );
			$ips = is_array( $ips ) ? $ips : array();
			if ( empty( $ips ) && function_exists( 'dns_get_record' ) ) {
				$records = @dns_get_record( $host, DNS_AAAA ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Lookup failures are reported as "does not resolve".
				foreach ( is_array( $records ) ? $records : array() as $record ) {
					if ( isset( $record['ipv6'] ) ) {
						$ips[] = $record['ipv6'];
					}
				}
			}
		}
		$valid = array();
		foreach ( (array) $ips as $ip ) {
			if ( is_string( $ip ) && false !== filter_var( $ip, FILTER_VALIDATE_IP ) ) {
				$valid[] = $ip;
			}
		}
		return array_values( array_unique( $valid ) );
	}

	/**
	 * Whether an IP address is publicly routable.
	 *
	 * @param string $ip IP address.
	 * @return bool
	 */
	public static function is_public_ip( $ip ) {
		$ip = strtolower( (string) $ip );
		if ( 0 === strpos( $ip, '::ffff:' ) && false !== filter_var( substr( $ip, 7 ), FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) {
			$ip = substr( $ip, 7 ); // IPv4-mapped IPv6.
		}
		if ( false === filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
			return false;
		}
		$bin = inet_pton( $ip );
		if ( false === $bin ) {
			return false;
		}
		if ( 4 === strlen( $bin ) ) {
			$n = unpack( 'N', $bin );
			$n = $n[1];
			// Ranges PHP's flags miss on some versions: 0/8, 100.64/10 (CGNAT), 192.0.0/24, 198.18/15, 224/4 and above.
			$blocked = array( array( 0x00000000, 8 ), array( 0x64400000, 10 ), array( 0xC0000000, 24 ), array( 0xC6120000, 15 ), array( 0xE0000000, 3 ) );
			foreach ( $blocked as $range ) {
				$mask = ( 0xFFFFFFFF << ( 32 - $range[1] ) ) & 0xFFFFFFFF;
				if ( ( $n & $mask ) === $range[0] ) {
					return false;
				}
			}
			return true;
		}
		$first  = ord( $bin[0] );
		$second = ord( $bin[1] );
		// fc00::/7 unique local, fe80::/10 link local, ff00::/8 multicast, ::/8 (loopback, unspecified, mapped).
		return ! ( 0xfc === ( $first & 0xfe ) || ( 0xfe === $first && 0x80 === ( $second & 0xc0 ) ) || 0xff === $first || 0x00 === $first );
	}

	/**
	 * Pins the next request to $url to a validated address (cURL only).
	 *
	 * @param string $host Hostname.
	 * @param int    $port Port.
	 * @param string $ip   Validated address.
	 * @param string $url  Exact URL of the request to pin.
	 * @return callable The hook callback, to remove after the request.
	 */
	private function pin( $host, $port, $ip, $url ) {
		$entry    = $host . ':' . $port . ':' . ( false !== strpos( $ip, ':' ) ? '[' . $ip . ']' : $ip );
		$callback = static function ( $handle, $args, $request_url ) use ( $entry, $url ) {
			if ( $request_url === $url && defined( 'CURLOPT_RESOLVE' ) ) {
				curl_setopt( $handle, CURLOPT_RESOLVE, array( $entry ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_setopt -- Pinning needs the cURL handle that core exposes.
			}
		};
		add_action( 'http_api_curl', $callback, 10, 3 );
		return $callback;
	}

	/**
	 * Finds canonical URLs and the own hosts used by navigation links.
	 *
	 * @param string $html HTML.
	 * @return array{canonicals:string[],link_hosts:string[]}
	 */
	private function scan_html( $html ) {
		$own        = $this->domains->own_hosts();
		$canonicals = array();
		$hosts      = array();
		$processor  = new \WP_HTML_Tag_Processor( $html );
		while ( $processor->next_tag() ) {
			$tag = $processor->get_tag();
			if ( 'LINK' === $tag && false !== strpos( ' ' . strtolower( (string) $processor->get_attribute( 'rel' ) ) . ' ', ' canonical ' ) ) {
				$canonicals[] = (string) $processor->get_attribute( 'href' );
			} elseif ( 'A' === $tag ) {
				$href = $processor->get_attribute( 'href' );
				$host = is_string( $href ) ? strtolower( (string) wp_parse_url( $href, PHP_URL_HOST ) ) : '';
				$path = is_string( $href ) ? (string) wp_parse_url( $href, PHP_URL_PATH ) : '';
				if ( '' !== $host && in_array( $host, $own, true ) && ! preg_match( '~/wp-(?:admin|login\.php)~', $path ) ) {
					$hosts[ $host ] = true;
				}
			}
		}
		return array(
			'canonicals' => $canonicals,
			'link_hosts' => array_keys( $hosts ),
		);
	}

	/**
	 * Converts a transport error into a message without internal details.
	 *
	 * @param \WP_Error $error  Error.
	 * @return string
	 */
	private function describe_error( $error ) {
		if ( 'http_request_not_executed' === $error->get_error_code() ) {
			return __( 'Not checked: outgoing requests are blocked by this site\'s configuration (WP_HTTP_BLOCK_EXTERNAL).', 'wp-domain-alias' );
		}
		// wp_http_validate_url() refused the destination (core's own message).
		// phpcs:ignore WordPress.WP.I18n.MissingArgDomain -- Must match WordPress core's string.
		if ( __( 'A valid URL was not provided.' ) === $error->get_error_message() ) {
			return __( 'Not checked: the domain does not resolve, or it resolves to a private or reserved network address. Requests to internal addresses are blocked.', 'wp-domain-alias' );
		}
		$message = strtolower( $error->get_error_message() );
		if ( false !== strpos( $message, 'ssl' ) || false !== strpos( $message, 'certificate' ) ) {
			return __( 'The HTTPS certificate could not be verified for this domain.', 'wp-domain-alias' );
		}
		if ( false !== strpos( $message, 'timed out' ) || false !== strpos( $message, 'timeout' ) ) {
			return __( 'The request timed out.', 'wp-domain-alias' );
		}
		if ( false !== strpos( $message, 'resolve' ) ) {
			return __( 'The domain does not resolve. Check its DNS records.', 'wp-domain-alias' );
		}
		return __( 'The request did not complete. Check DNS, the hosting domain mapping and HTTPS.', 'wp-domain-alias' );
	}

	/**
	 * Detects common page-cache plugins.
	 *
	 * @return string[]
	 */
	private function detect_cache_plugins() {
		$known = array(
			'WP_ROCKET_VERSION'    => 'WP Rocket',
			'W3TC'                 => 'W3 Total Cache',
			'WPCACHEHOME'          => 'WP Super Cache',
			'LSCWP_V'              => 'LiteSpeed Cache',
			'WPFC_WP_CONTENT_URL'  => 'WP Fastest Cache',
			'NITROPACK_VERSION'    => 'NitroPack',
			'SiteGround_Optimizer' => 'SiteGround Optimizer',
			'BREEZE_VERSION'       => 'Breeze',
			'CE_VERSION'           => 'Cache Enabler',
		);
		$found = array();
		foreach ( $known as $constant => $name ) {
			if ( defined( $constant ) || class_exists( $constant, false ) ) {
				$found[] = $name;
			}
		}
		if ( defined( 'WP_CACHE' ) && WP_CACHE && empty( $found ) ) {
			$found[] = __( 'an advanced-cache drop-in (WP_CACHE is on)', 'wp-domain-alias' );
		}
		return $found;
	}

	/**
	 * Shortens a URL for display.
	 *
	 * @param string $url URL.
	 * @return string
	 */
	private function short( $url ) {
		return strlen( $url ) > 120 ? substr( $url, 0, 117 ) . '...' : $url;
	}

	/**
	 * Builds a result row.
	 *
	 * @param string $status  Status.
	 * @param string $label   Label.
	 * @param string $message Message.
	 * @return array{status:string,label:string,message:string}
	 */
	private function item( $status, $label, $message ) {
		return array(
			'status'  => $status,
			'label'   => $label,
			'message' => $message,
		);
	}
}
