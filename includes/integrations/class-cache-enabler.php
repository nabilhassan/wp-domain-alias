<?php
/**
 * Cache Enabler compatibility.
 *
 * @package WP_Domain_Alias
 */

namespace WP_Domain_Alias\Integrations;

use WP_Domain_Alias\Domain_Manager;
use WP_Domain_Alias\Host_Validator;

defined( 'ABSPATH' ) || exit;

/**
 * Cache Enabler stores one copy of each page per hostname, which keeps
 * aliases isolated. Its purge functions, however, only clear the primary
 * hostname's copies, so alias copies would stay stale. This integration:
 *
 * - repeats each Cache Enabler purge for every alias (when Cache Enabler
 *   reports one), and
 * - clears every alias's whole cache, once per request, when content,
 *   menus, theme, plugins or this plugin's settings change. This covers the
 *   case where the primary copy was not cached, so Cache Enabler reports no
 *   purge at all.
 *
 * Verified against Cache Enabler 1.8 in the local test environment.
 */
class Cache_Enabler {

	/**
	 * Domain manager.
	 *
	 * @var Domain_Manager
	 */
	private $domains;

	/**
	 * Whether a full alias purge is scheduled for shutdown.
	 *
	 * @var bool
	 */
	private $scheduled = false;

	/**
	 * Re-entrancy guard.
	 *
	 * @var bool
	 */
	private $clearing = false;

	/**
	 * Aliases removed during this request, whose cached copies must go too.
	 *
	 * @var string[]
	 */
	private $removed = array();

	/**
	 * Constructor.
	 *
	 * @param Domain_Manager $domains Domain manager.
	 */
	public function __construct( Domain_Manager $domains ) {
		$this->domains = $domains;
	}

	/**
	 * Registers the hooks when Cache Enabler is active.
	 *
	 * @param Domain_Manager $domains Domain manager.
	 * @return void
	 */
	public static function register( Domain_Manager $domains ) {
		if ( ! class_exists( '\Cache_Enabler' ) || ! method_exists( '\Cache_Enabler', 'clear_page_cache_by_url' ) ) {
			return;
		}
		$self = new self( $domains );
		add_action( 'cache_enabler_page_cache_cleared', array( $self, 'page_cleared' ), 10, 1 );
		add_action( 'cache_enabler_site_cache_cleared', array( $self, 'site_cleared' ), 10, 0 );

		add_action( 'save_post', array( $self, 'post_saved' ), 10, 1 );
		add_action( 'update_option_' . Domain_Manager::OPTION, array( $self, 'settings_changed' ), 10, 2 );

		$events = array(
			'wp_trash_post',
			'deleted_post',
			'comment_post',
			'wp_set_comment_status',
			'edited_term',
			'delete_term',
			'wp_update_nav_menu',
			'switch_theme',
			'customize_save_after',
			'activated_plugin',
			'deactivated_plugin',
		);
		foreach ( $events as $event ) {
			add_action( $event, array( $self, 'schedule_purge' ), 10, 0 );
		}
	}

	/**
	 * A post was saved. Revisions, autosaves and auto-drafts are ignored so
	 * editing does not empty the alias caches every few seconds.
	 *
	 * @param int $post_id Post ID.
	 * @return void
	 */
	public function post_saved( $post_id ) {
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) || 'auto-draft' === get_post_status( $post_id ) ) {
			return;
		}
		$this->schedule_purge();
	}

	/**
	 * This plugin's settings changed: purge every alias, including any alias
	 * that was just removed (its cached pages would otherwise keep alias
	 * links indefinitely).
	 *
	 * @param mixed $old_value Previous settings.
	 * @param mixed $value     New settings.
	 * @return void
	 */
	public function settings_changed( $old_value, $value ) {
		$old = is_array( $old_value ) && isset( $old_value['aliases'] ) && is_array( $old_value['aliases'] ) ? $old_value['aliases'] : array();
		$new = is_array( $value ) && isset( $value['aliases'] ) && is_array( $value['aliases'] ) ? $value['aliases'] : array();
		foreach ( array_diff( $old, $new ) as $host ) {
			if ( Host_Validator::is_valid_host( $host ) && ! in_array( $host, $this->removed, true ) ) {
				$this->removed[] = $host;
			}
		}
		$this->schedule_purge();
	}

	/**
	 * Schedules one full alias purge at the end of the request.
	 *
	 * @return void
	 */
	public function schedule_purge() {
		if ( ! $this->scheduled ) {
			$this->scheduled = true;
			add_action( 'shutdown', array( $this, 'site_cleared' ), 0 );
		}
	}

	/**
	 * Clears the same page on every alias.
	 *
	 * @param string $url URL that Cache Enabler just cleared.
	 * @return void
	 */
	public function page_cleared( $url ) {
		$parts = wp_parse_url( (string) $url );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) || strtolower( $parts['host'] ) !== $this->domains->primary_host() ) {
			return;
		}
		$rest = ( isset( $parts['path'] ) ? $parts['path'] : '/' ) . ( isset( $parts['query'] ) ? '?' . $parts['query'] : '' );
		$this->clear_aliases( $rest, array() );
	}

	/**
	 * Clears every alias's whole cache.
	 *
	 * @return void
	 */
	public function site_cleared() {
		$this->clear_aliases( '/', array( 'subpages' => 1 ) );
	}

	/**
	 * Runs a Cache Enabler purge for each alias without firing its hooks again.
	 *
	 * @param string $rest Path and query.
	 * @param array  $args Cache Enabler iterator arguments.
	 * @return void
	 */
	private function clear_aliases( $rest, array $args ) {
		if ( $this->clearing ) {
			return;
		}
		$this->clearing = true;
		$args['hooks']  = array( 'include' => array() );
		$scheme         = $this->domains->primary_scheme();
		$hosts          = array_unique( array_merge( $this->domains->aliases(), $this->removed ) );
		foreach ( $hosts as $alias ) {
			\Cache_Enabler::clear_page_cache_by_url( $scheme . '://' . $alias . $rest, $args );
		}
		$this->clearing = false;
	}
}
