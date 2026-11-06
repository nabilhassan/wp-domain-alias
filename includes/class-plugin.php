<?php
/**
 * Plugin bootstrap.
 *
 * @package WP_Domain_Alias
 */

namespace WP_Domain_Alias;

defined( 'ABSPATH' ) || exit;

/**
 * Creates the components and registers their hooks.
 */
final class Plugin {

	/**
	 * Single instance.
	 *
	 * @var Plugin|null
	 */
	private static $instance = null;

	/**
	 * Domain manager.
	 *
	 * @var Domain_Manager
	 */
	public $domains;

	/**
	 * URL handler.
	 *
	 * @var Url_Handler
	 */
	public $urls;

	/**
	 * SEO handler.
	 *
	 * @var Seo_Handler
	 */
	public $seo;

	/**
	 * Link handler.
	 *
	 * @var Link_Handler
	 */
	public $links;

	/**
	 * Redirect handler.
	 *
	 * @var Redirect_Handler
	 */
	public $redirects;

	/**
	 * Returns the plugin instance.
	 *
	 * @return Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Builds the components.
	 */
	private function __construct() {
		$this->domains   = new Domain_Manager();
		$this->urls      = new Url_Handler( $this->domains );
		$this->seo       = new Seo_Handler( $this->domains, $this->urls );
		$this->links     = new Link_Handler( $this->domains, $this->urls, $this->seo );
		$this->redirects = new Redirect_Handler( $this->domains, $this->urls );
	}

	/**
	 * Registers hooks. Runs once, when the plugin file is loaded.
	 *
	 * @return void
	 */
	public function boot() {
		// Capture the path before translation plugins normalise REQUEST_URI.
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- Only the parsed path is kept, and only compared.
		$this->urls->capture_request_path( isset( $_SERVER['REQUEST_URI'] ) ? (string) $_SERVER['REQUEST_URI'] : '/' );

		add_action( 'init', array( $this, 'load_textdomain' ) );
		add_action( 'admin_notices', array( $this, 'environment_notices' ) );

		if ( is_multisite() ) {
			// Multisite is out of scope; do nothing beyond the notice.
			return;
		}

		$this->domains->register();
		$this->redirects->register();
		$this->links->register();
		$this->seo->register();
		add_action( 'plugins_loaded', array( $this, 'register_integrations' ) );

		if ( is_admin() ) {
			$admin = new Admin( $this->domains, new Health_Check( $this->domains ) );
			$admin->register();
		}
	}

	/**
	 * Registers optional third-party integrations.
	 *
	 * @return void
	 */
	public function register_integrations() {
		if ( defined( 'WEGLOT_VERSION' ) || function_exists( 'weglot_get_current_language' ) ) {
			Integrations\Weglot::register( $this->links, $this->seo, $this->urls );
		}
		Integrations\Cache_Enabler::register( $this->domains );
	}

	/**
	 * Loads translations.
	 *
	 * @return void
	 */
	public function load_textdomain() {
		load_plugin_textdomain( 'wp-domain-alias', false, dirname( plugin_basename( WP_DOMAIN_ALIAS_FILE ) ) . '/languages' );
	}

	/**
	 * Admin notices about the environment.
	 *
	 * @return void
	 */
	public function environment_notices() {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}
		if ( is_multisite() ) {
			echo '<div class="notice notice-warning"><p>' . esc_html__( 'WP Domain Alias does not support Multisite and is not doing anything on this network.', 'wp-domain-alias' ) . '</p></div>';
		}
		if ( ! class_exists( 'WP_HTML_Tag_Processor' ) ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'WP Domain Alias needs WordPress 6.4 or newer. Links on alias domains are not being adjusted.', 'wp-domain-alias' ) . '</p></div>';
		}
		if ( defined( 'WP_DOMAIN_ALIAS_DUPLICATE' ) ) {
			echo '<div class="notice notice-warning"><p>' . esc_html__( 'WP Domain Alias is installed twice (for example as a Must-Use plugin and as a regular plugin). Only the first copy is running. Remove the other copy.', 'wp-domain-alias' ) . '</p></div>';
		}
	}

	/**
	 * Activation: refuses Multisite network activation and stores defaults.
	 *
	 * @param bool $network_wide Whether activated network-wide.
	 * @return void
	 */
	public static function activate( $network_wide = false ) {
		if ( is_multisite() ) {
			deactivate_plugins( plugin_basename( WP_DOMAIN_ALIAS_FILE ), true, $network_wide );
			wp_die(
				esc_html__( 'WP Domain Alias does not support WordPress Multisite.', 'wp-domain-alias' ),
				esc_html__( 'Plugin not activated', 'wp-domain-alias' ),
				array( 'back_link' => true )
			);
		}
		add_option( Domain_Manager::OPTION, Domain_Manager::defaults(), '', true );
	}

	/**
	 * Deactivation: settings are kept so reactivation restores them.
	 * Removing the plugin through the Plugins screen deletes them (uninstall.php).
	 *
	 * @return void
	 */
	public static function deactivate() {
		delete_transient( 'wpda_notices_' . get_current_user_id() );
	}
}
