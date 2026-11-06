<?php
/**
 * Redirect handling for alias requests.
 *
 * @package WP_Domain_Alias
 */

namespace WP_Domain_Alias;

defined( 'ABSPATH' ) || exit;

/**
 * Keeps redirects on the alias host without removing WordPress's own URL
 * normalisation.
 */
class Redirect_Handler {

	/**
	 * Domain manager.
	 *
	 * @var Domain_Manager
	 */
	private $domains;

	/**
	 * URL handler.
	 *
	 * @var Url_Handler
	 */
	private $urls;

	/**
	 * Constructor.
	 *
	 * @param Domain_Manager $domains Domain manager.
	 * @param Url_Handler    $urls    URL handler.
	 */
	public function __construct( Domain_Manager $domains, Url_Handler $urls ) {
		$this->domains = $domains;
		$this->urls    = $urls;
	}

	/**
	 * Registers hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_filter( 'redirect_canonical', array( $this, 'redirect_canonical' ), 100, 2 );
		add_filter( 'wp_redirect', array( $this, 'wp_redirect' ), 100 );
		add_filter( 'allowed_redirect_hosts', array( $this, 'allowed_redirect_hosts' ) );
	}

	/**
	 * Keeps canonical redirects on the alias.
	 *
	 * WordPress still normalises paths, slashes and query strings. Only a
	 * redirect whose sole effect would be to switch the alias to another host
	 * is cancelled, which also prevents redirect loops.
	 *
	 * @param string|false $redirect  Proposed redirect URL.
	 * @param string       $requested Requested URL.
	 * @return string|false
	 */
	public function redirect_canonical( $redirect, $requested ) {
		if ( ! $redirect || ! is_string( $redirect ) || ! $this->urls->is_alias_frontend() ) {
			return $redirect;
		}
		$same_host = $this->urls->to_alias( $redirect );
		if ( $this->same_url( $same_host, $requested ) ) {
			return false;
		}
		return $same_host;
	}

	/**
	 * Keeps frontend redirects to this site on the alias.
	 *
	 * External destinations are never changed.
	 *
	 * @param string $location Redirect location.
	 * @return string
	 */
	public function wp_redirect( $location ) {
		return $this->urls->is_alias_frontend() ? $this->urls->to_alias( $location ) : $location;
	}

	/**
	 * Allows wp_safe_redirect() to approved aliases.
	 *
	 * @param string[] $hosts Allowed hosts.
	 * @return string[]
	 */
	public function allowed_redirect_hosts( $hosts ) {
		if ( ! is_array( $hosts ) ) {
			$hosts = array();
		}
		return array_values( array_unique( array_merge( $hosts, $this->domains->aliases() ) ) );
	}

	/**
	 * Compares two URLs ignoring host case.
	 *
	 * @param mixed $a URL.
	 * @param mixed $b URL.
	 * @return bool
	 */
	private function same_url( $a, $b ) {
		if ( ! is_string( $a ) || ! is_string( $b ) ) {
			return false;
		}
		$pattern = '~^(https?://)([^/?#]*)~i';
		$a       = preg_replace_callback( $pattern, array( $this, 'lower_authority' ), $a );
		$b       = preg_replace_callback( $pattern, array( $this, 'lower_authority' ), $b );
		return $a === $b;
	}

	/**
	 * Lowercases scheme and host.
	 *
	 * @param string[] $m Matches.
	 * @return string
	 */
	private function lower_authority( $m ) {
		return strtolower( $m[1] . $m[2] );
	}
}
