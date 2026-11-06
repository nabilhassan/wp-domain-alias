<?php
/**
 * Settings storage and request-host resolution.
 *
 * @package WP_Domain_Alias
 */

namespace WP_Domain_Alias;

defined( 'ABSPATH' ) || exit;

/**
 * Knows the primary host, the approved aliases and which alias (if any)
 * the current request arrived on.
 */
class Domain_Manager {

	const OPTION      = 'wp_domain_alias_settings';
	const MAX_ALIASES = 25;

	const CANONICAL_PRIMARY = 'primary';
	const CANONICAL_ALIAS   = 'alias';

	/**
	 * Memoised settings for this request.
	 *
	 * @var array|null
	 */
	private $settings = null;

	/**
	 * Memoised alias lookup, keyed by the Host header it was computed for.
	 *
	 * @var array{0:string,1:string}|null
	 */
	private $alias_cache = null;

	/**
	 * Memoised alias list and own-host list for this request.
	 *
	 * @var array{aliases?:string[],own?:string[]}
	 */
	private $hosts_cache = array();

	/**
	 * Default settings. Safe for any site: no aliases, primary canonicals.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'enabled'        => true,
			'aliases'        => array(),
			'canonical_mode' => self::CANONICAL_PRIMARY,
			'diagnostics'    => false,
		);
	}

	/**
	 * Current settings, re-validated on every read.
	 *
	 * Stored values are never trusted blindly: an alias that no longer passes
	 * validation (for example a value written directly to the database) is
	 * dropped rather than allowed to widen the allowlist.
	 *
	 * @return array
	 */
	public function get_settings() {
		if ( null === $this->settings ) {
			$this->settings = $this->sanitize( get_option( self::OPTION, array() ) );
		}
		return $this->settings;
	}

	/**
	 * Normalises a settings array to the expected shape and allowed values.
	 *
	 * @param mixed $raw Raw settings.
	 * @return array
	 */
	public function sanitize( $raw ) {
		$raw      = is_array( $raw ) ? $raw : array();
		$settings = self::defaults();

		if ( array_key_exists( 'enabled', $raw ) ) {
			$settings['enabled'] = (bool) $raw['enabled'];
		}
		if ( array_key_exists( 'diagnostics', $raw ) ) {
			$settings['diagnostics'] = (bool) $raw['diagnostics'];
		}
		if ( isset( $raw['canonical_mode'] ) && self::CANONICAL_ALIAS === $raw['canonical_mode'] ) {
			$settings['canonical_mode'] = self::CANONICAL_ALIAS;
		}
		$settings['aliases'] = $this->clean_alias_list( isset( $raw['aliases'] ) ? $raw['aliases'] : array() );

		return $settings;
	}

	/**
	 * Saves settings after sanitising them.
	 *
	 * @param array $settings Settings.
	 * @return void
	 */
	public function save( array $settings ) {
		$clean = $this->sanitize( $settings );
		update_option( self::OPTION, $clean, true );
		$this->settings    = $clean;
		$this->alias_cache = null;
		$this->hosts_cache = array();
	}

	/**
	 * Drops memoised settings whenever the option changes by any route
	 * (another plugin, WP-CLI, an options import), not only through save().
	 *
	 * @return void
	 */
	public function register() {
		foreach ( array( 'add_option_', 'update_option_', 'delete_option_' ) as $prefix ) {
			add_action( $prefix . self::OPTION, array( $this, 'reset' ), 0, 0 );
		}
		// Re-read once plugins and the theme have added their filters.
		add_action( 'init', array( $this, 'reset' ), 0, 0 );
	}

	/**
	 * Clears memoised state (used after settings change and in tests).
	 *
	 * @return void
	 */
	public function reset() {
		$this->settings    = null;
		$this->alias_cache = null;
		$this->hosts_cache = array();
	}

	/**
	 * Whether hostname preservation is switched on.
	 *
	 * @return bool
	 */
	public function is_enabled() {
		$settings = $this->get_settings();
		return $settings['enabled'];
	}

	/**
	 * Canonical mode: 'primary' (default) or 'alias'.
	 *
	 * @return string
	 */
	public function canonical_mode() {
		$settings = $this->get_settings();
		return $settings['canonical_mode'];
	}

	/**
	 * Whether diagnostics output is enabled.
	 *
	 * @return bool
	 */
	public function diagnostics_enabled() {
		$settings = $this->get_settings();
		return $settings['diagnostics'];
	}

	/**
	 * Primary host, read from the WordPress "home" setting. Never stored by
	 * this plugin.
	 *
	 * @return string
	 */
	public function primary_host() {
		$host = wp_parse_url( (string) get_option( 'home' ), PHP_URL_HOST );
		return is_string( $host ) ? strtolower( $host ) : '';
	}

	/**
	 * Scheme of the primary site ("https" or "http").
	 *
	 * @return string
	 */
	public function primary_scheme() {
		$scheme = wp_parse_url( (string) get_option( 'home' ), PHP_URL_SCHEME );
		return 'http' === $scheme ? 'http' : 'https';
	}

	/**
	 * Approved alias hosts (lowercase punycode).
	 *
	 * @return string[]
	 */
	public function aliases() {
		if ( isset( $this->hosts_cache['aliases'] ) ) {
			return $this->hosts_cache['aliases'];
		}
		$settings = $this->get_settings();
		/**
		 * Filters the approved alias hosts.
		 *
		 * Returned values are validated again; invalid entries and the primary
		 * host are discarded. The result is cached for the request (refreshed
		 * on init and whenever the settings option changes).
		 *
		 * @param string[] $aliases Approved alias hosts.
		 */
		$filtered                     = apply_filters( 'wp_domain_alias_aliases', $settings['aliases'] );
		$this->hosts_cache['aliases'] = $this->clean_alias_list( $filtered );
		return $this->hosts_cache['aliases'];
	}

	/**
	 * Hosts whose URLs count as "this site" when rewriting links: the primary
	 * host, its www/non-www counterpart and every alias.
	 *
	 * @return string[]
	 */
	public function own_hosts() {
		if ( isset( $this->hosts_cache['own'] ) ) {
			return $this->hosts_cache['own'];
		}
		$primary = $this->primary_host();
		$hosts   = $this->aliases();
		if ( '' !== $primary ) {
			$hosts[] = $primary;
			$hosts[] = Host_Validator::www_counterpart( $primary );
		}
		$this->hosts_cache['own'] = array_values( array_unique( $hosts ) );
		return $this->hosts_cache['own'];
	}

	/**
	 * The approved alias this request arrived on, or '' for the primary host,
	 * an unknown host, or when the plugin is disabled.
	 *
	 * Only $_SERVER['HTTP_HOST'] is consulted. Forwarded headers are ignored
	 * because they are trivially spoofed unless a proxy strips them.
	 *
	 * @return string
	 */
	public function current_alias() {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- Validated by Host_Validator::request_host() and an exact allowlist match.
		$raw = isset( $_SERVER['HTTP_HOST'] ) ? (string) $_SERVER['HTTP_HOST'] : '';
		if ( null !== $this->alias_cache && $this->alias_cache[0] === $raw ) {
			return $this->alias_cache[1];
		}

		$alias = '';
		if ( $this->is_enabled() ) {
			$host = Host_Validator::request_host( $raw, is_ssl() );
			if ( '' !== $host && in_array( $host, $this->aliases(), true ) ) {
				$alias = $host;
			}
		}
		$this->alias_cache = array( $raw, $alias );
		return $alias;
	}

	/**
	 * Validates, de-duplicates and caps a list of alias hosts.
	 *
	 * @param mixed $hosts Candidate hosts.
	 * @return string[]
	 */
	private function clean_alias_list( $hosts ) {
		if ( ! is_array( $hosts ) ) {
			return array();
		}
		$primary = $this->primary_host();
		$clean   = array();
		foreach ( $hosts as $host ) {
			if ( Host_Validator::is_valid_host( $host ) && $host !== $primary && ! in_array( $host, $clean, true ) ) {
				$clean[] = $host;
			}
			if ( count( $clean ) >= self::MAX_ALIASES ) {
				break;
			}
		}
		return $clean;
	}
}
