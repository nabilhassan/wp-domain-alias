<?php
/**
 * Settings → WP Domain Alias.
 *
 * @package WP_Domain_Alias
 */

namespace WP_Domain_Alias;

defined( 'ABSPATH' ) || exit;

/**
 * Administration screen and its form handlers.
 *
 * Every state-changing request is a POST to admin-post.php that requires the
 * manage_options capability and a per-action nonce.
 */
class Admin {

	const SLUG       = 'wp-domain-alias';
	const CAPABILITY = 'manage_options';

	/**
	 * Domain manager.
	 *
	 * @var Domain_Manager
	 */
	private $domains;

	/**
	 * Health check.
	 *
	 * @var Health_Check
	 */
	private $health;

	/**
	 * Constructor.
	 *
	 * @param Domain_Manager $domains Domain manager.
	 * @param Health_Check   $health  Health check.
	 */
	public function __construct( Domain_Manager $domains, Health_Check $health ) {
		$this->domains = $domains;
		$this->health  = $health;
	}

	/**
	 * Registers admin hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'admin_menu', array( $this, 'add_page' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( WP_DOMAIN_ALIAS_FILE ), array( $this, 'action_links' ) );
		add_action( 'admin_init', array( $this->domains, 'migrate_legacy_settings' ) );
		add_action( 'admin_notices', array( $this, 'canonical_notice' ) );
		foreach ( array( 'save_settings', 'add_alias', 'remove_alias', 'health_check', 'health_continue', 'dismiss_canonical_notice' ) as $action ) {
			add_action( 'admin_post_wpda_' . $action, array( $this, 'handle_' . $action ) );
		}
		// Progressive health check for browsers with JavaScript (one host per request).
		add_action( 'wp_ajax_wpda_health_start', array( $this, 'ajax_health_start' ) );
		add_action( 'wp_ajax_wpda_health_step', array( $this, 'ajax_health_step' ) );
	}

	/**
	 * Adds the settings page.
	 *
	 * @return void
	 */
	public function add_page() {
		add_options_page(
			__( 'WP Domain Alias', 'wp-domain-alias' ),
			__( 'WP Domain Alias', 'wp-domain-alias' ),
			self::CAPABILITY,
			self::SLUG,
			array( $this, 'render' )
		);
	}

	/**
	 * Loads the stylesheet on this plugin's screen only.
	 *
	 * @param string $hook_suffix Current admin page.
	 * @return void
	 */
	public function enqueue( $hook_suffix ) {
		if ( 'settings_page_' . self::SLUG !== $hook_suffix ) {
			return;
		}
		wp_enqueue_style( 'wp-domain-alias-admin', plugins_url( 'admin/admin.css', WP_DOMAIN_ALIAS_FILE ), array(), WP_DOMAIN_ALIAS_VERSION );
		wp_enqueue_script( 'wp-domain-alias-admin', plugins_url( 'admin/admin.js', WP_DOMAIN_ALIAS_FILE ), array(), WP_DOMAIN_ALIAS_VERSION, true );
		wp_localize_script(
			'wp-domain-alias-admin',
			'wpdaHealth',
			array(
				'ajaxUrl'    => admin_url( 'admin-ajax.php' ),
				'startNonce' => wp_create_nonce( 'wpda_health_start' ),
				'stepNonce'  => wp_create_nonce( 'wpda_health_step' ),
				'i18n'       => array(
					/* translators: 1: number of domains checked, 2: total number of domains. */
					'progress' => __( 'Checked %1$d of %2$d domains…', 'wp-domain-alias' ),
					'done'     => __( 'Health check complete.', 'wp-domain-alias' ),
					'failed'   => __( 'The health check stopped. Reload the page to see the results so far.', 'wp-domain-alias' ),
				),
			)
		);
	}

	/**
	 * Adds a Settings link on the Plugins screen.
	 *
	 * @param string[] $links Links.
	 * @return string[]
	 */
	public function action_links( $links ) {
		if ( current_user_can( self::CAPABILITY ) ) {
			array_unshift( $links, '<a href="' . esc_url( $this->page_url() ) . '">' . esc_html__( 'Settings', 'wp-domain-alias' ) . '</a>' );
		}
		return $links;
	}

	/**
	 * Saves the general settings.
	 *
	 * @return void
	 */
	public function handle_save_settings() {
		$this->authorize( 'save_settings' );
		$settings = $this->domains->get_settings();
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Verified in authorize().
		$settings['enabled']     = ! empty( $_POST['wpda_enabled'] );
		$settings['diagnostics'] = ! empty( $_POST['wpda_diagnostics'] );
		// phpcs:enable
		$this->domains->save( $settings );
		$this->add_notice( 'success', __( 'Settings saved. If you use a page cache, purge it so every domain gets fresh pages.', 'wp-domain-alias' ) );
		$this->back();
	}

	/**
	 * Adds an alias.
	 *
	 * @return void
	 */
	public function handle_add_alias() {
		$this->authorize( 'add_alias' );
		// phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce verified in authorize(); value is validated by Host_Validator.
		$raw    = isset( $_POST['wpda_alias'] ) ? wp_unslash( $_POST['wpda_alias'] ) : '';
		$result = Host_Validator::normalize_input( is_string( $raw ) ? $raw : '' );

		if ( '' !== $result['error'] ) {
			$this->add_notice( 'error', $result['error'] );
			$this->back();
		}

		$host     = $result['host'];
		$settings = $this->domains->get_settings();
		$primary  = $this->domains->primary_host();

		if ( $host === $primary ) {
			$this->add_notice( 'error', __( 'That is the primary domain. It is managed under Settings → General and does not need to be added.', 'wp-domain-alias' ) );
		} elseif ( in_array( $host, $settings['aliases'], true ) ) {
			/* translators: %s: hostname. */
			$this->add_notice( 'error', sprintf( __( '%s is already an alias.', 'wp-domain-alias' ), Host_Validator::to_display( $host ) ) );
		} elseif ( count( $settings['aliases'] ) >= Domain_Manager::MAX_ALIASES ) {
			/* translators: %d: maximum number of aliases. */
			$this->add_notice( 'error', sprintf( __( 'You can add up to %d aliases.', 'wp-domain-alias' ), Domain_Manager::MAX_ALIASES ) );
		} else {
			$settings['aliases'][] = $host;
			$this->domains->save( $settings );
			foreach ( $result['warnings'] as $warning ) {
				$this->add_notice( 'warning', $warning );
			}
			if ( Host_Validator::www_counterpart( $host ) === $primary ) {
				$this->add_notice( 'warning', __( 'This is the www/non-www form of the primary domain. Many hosts redirect it at server level, which this plugin cannot override.', 'wp-domain-alias' ) );
			}
			$this->add_notice(
				'success',
				/* translators: %s: hostname. */
				sprintf( __( '%s was added. Make sure the domain points to this site and has HTTPS, then run the health check.', 'wp-domain-alias' ), Host_Validator::to_display( $host ) )
			);
		}
		$this->back();
	}

	/**
	 * Removes an alias.
	 *
	 * @return void
	 */
	public function handle_remove_alias() {
		$this->authorize( 'remove_alias' );
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified in authorize().
		$host     = isset( $_POST['wpda_host'] ) ? sanitize_text_field( wp_unslash( $_POST['wpda_host'] ) ) : '';
		$settings = $this->domains->get_settings();
		$index    = array_search( $host, $settings['aliases'], true );
		if ( false === $index ) {
			$this->add_notice( 'error', __( 'That alias was not found.', 'wp-domain-alias' ) );
		} else {
			unset( $settings['aliases'][ $index ] );
			$settings['aliases'] = array_values( $settings['aliases'] );
			$this->domains->save( $settings );
			/* translators: %s: hostname. */
			$this->add_notice( 'success', sprintf( __( '%s was removed. Requests on that domain now show primary-domain links.', 'wp-domain-alias' ), Host_Validator::to_display( $host ) ) );
		}
		$this->back();
	}

	/**
	 * Runs the health check.
	 *
	 * @return void
	 */
	public function handle_health_check() {
		$this->authorize( 'health_check' );
		$user = get_current_user_id();
		if ( false === $this->health->start_run( $user ) ) {
			$this->add_notice( 'warning', __( 'A health check ran a few seconds ago. Please wait before running it again.', 'wp-domain-alias' ) );
			$this->back();
		}
		$this->step_and_return( $user );
	}

	/**
	 * One-time notice after a 0.1.0-beta "alias canonical" setting was migrated.
	 *
	 * @return void
	 */
	public function canonical_notice() {
		if ( ! current_user_can( self::CAPABILITY ) || ! get_option( Domain_Manager::CANONICAL_NOTICE_OPTION ) ) {
			return;
		}
		echo '<div class="notice notice-warning"><p><strong>' . esc_html__( 'WP Domain Alias:', 'wp-domain-alias' ) . '</strong> ';
		echo esc_html__( 'The "point canonical URLs to the alias" setting was removed in 0.1.1-beta. Canonical URLs on alias pages now point to the primary domain, matching the sitemaps and structured data. Review your search engine setup if you relied on alias canonicals.', 'wp-domain-alias' ) . '</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="margin:0 0 8px">';
		wp_nonce_field( 'wpda_dismiss_canonical_notice' );
		echo '<input type="hidden" name="action" value="wpda_dismiss_canonical_notice">';
		submit_button( __( 'Dismiss', 'wp-domain-alias' ), 'secondary small', 'submit', false );
		echo '</form></div>';
	}

	/**
	 * Dismisses the canonical migration notice.
	 *
	 * @return void
	 */
	public function handle_dismiss_canonical_notice() {
		$this->authorize( 'dismiss_canonical_notice' );
		delete_option( Domain_Manager::CANONICAL_NOTICE_OPTION );
		$this->back();
	}

	/**
	 * Checks the next batch of an existing run (no-JavaScript path).
	 *
	 * @return void
	 */
	public function handle_health_continue() {
		$this->authorize( 'health_continue' );
		$this->step_and_return( get_current_user_id() );
	}

	/**
	 * Runs one batch of the user's health check and returns to the page.
	 *
	 * @param int $user User ID.
	 * @return void
	 */
	private function step_and_return( $user ) {
		$run = $this->health->run_step( $user, Health_Check::BATCH_SIZE );
		if ( is_wp_error( $run ) ) {
			$this->add_notice( 'warning', $run->get_error_message() );
		}
		$this->back( '#wpda-health' );
	}

	/**
	 * AJAX: starts a run and returns its size.
	 *
	 * @return void
	 */
	public function ajax_health_start() {
		$this->authorize_ajax( 'wpda_health_start' );
		$run = $this->health->start_run( get_current_user_id() );
		if ( false === $run ) {
			wp_send_json_error( array( 'message' => __( 'A health check ran a few seconds ago. Please wait before running it again.', 'wp-domain-alias' ) ), 429 );
		}
		wp_send_json_success(
			array(
				'run'   => $run['id'],
				'total' => count( $run['hosts'] ),
			)
		);
	}

	/**
	 * AJAX: checks the next host of a run and returns its rendered result.
	 *
	 * @return void
	 */
	public function ajax_health_step() {
		$this->authorize_ajax( 'wpda_health_step' );
		$run_id = isset( $_POST['run'] ) ? sanitize_key( wp_unslash( $_POST['run'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified in authorize_ajax().
		$before = $this->health->get_run( get_current_user_id() );
		$run    = $this->health->run_step( get_current_user_id(), 1, $run_id );
		if ( is_wp_error( $run ) ) {
			wp_send_json_error( array( 'message' => $run->get_error_message() ), 'wpda_busy' === $run->get_error_code() ? 409 : 410 );
		}
		$new = array_slice( $run['results'], null === $before ? 0 : count( $before['results'] ) );
		ob_start();
		foreach ( $new as $result ) {
			$this->render_host_result( $result );
		}
		wp_send_json_success(
			array(
				'html'    => ob_get_clean(),
				'checked' => count( $run['results'] ),
				'total'   => count( $run['hosts'] ),
				'done'    => Health_Check::is_complete( $run ),
			)
		);
	}

	/**
	 * Capability and nonce check for AJAX handlers.
	 *
	 * @param string $action Nonce action.
	 * @return void
	 */
	private function authorize_ajax( $action ) {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_send_json_error( array( 'message' => __( 'Sorry, you are not allowed to do that.', 'wp-domain-alias' ) ), 403 );
		}
		if ( ! check_ajax_referer( $action, 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'The link you followed has expired. Reload the page and try again.', 'wp-domain-alias' ) ), 403 );
		}
	}

	/**
	 * Renders the settings page.
	 *
	 * @return void
	 */
	public function render() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to access this page.', 'wp-domain-alias' ), '', array( 'response' => 403 ) );
		}
		$settings = $this->domains->get_settings();
		$primary  = $this->domains->primary_host();
		$user     = get_current_user_id();
		$health   = $this->health->get_run( $user );

		echo '<div class="wrap wpda">';
		echo '<h1>' . esc_html__( 'WP Domain Alias', 'wp-domain-alias' ) . '</h1>';
		echo '<p class="wpda-intro">' . esc_html__( 'Show this site on additional domains while keeping visitors on the domain they typed. Content, database and the WordPress address stay the same.', 'wp-domain-alias' ) . '</p>';
		$this->render_notices( $user );

		$this->render_domains( $settings, $primary );
		$this->render_settings( $settings );
		$this->render_status();
		$this->render_health( $health );
		$this->render_about();
		echo '</div>';
	}

	/**
	 * Domains card.
	 *
	 * @param array  $settings Settings.
	 * @param string $primary  Primary host.
	 * @return void
	 */
	private function render_domains( array $settings, $primary ) {
		echo '<div class="wpda-card"><h2>' . esc_html__( 'Domains', 'wp-domain-alias' ) . '</h2>';
		echo '<table class="widefat striped wpda-domains"><thead><tr><th>' . esc_html__( 'Domain', 'wp-domain-alias' ) . '</th><th>' . esc_html__( 'Role', 'wp-domain-alias' ) . '</th><th><span class="screen-reader-text">' . esc_html__( 'Actions', 'wp-domain-alias' ) . '</span></th></tr></thead><tbody>';
		echo '<tr><td><strong>' . esc_html( Host_Validator::to_display( $primary ) ) . '</strong></td><td>' . esc_html__( 'Primary (from Settings → General, read-only)', 'wp-domain-alias' ) . '</td><td></td></tr>';
		foreach ( $settings['aliases'] as $alias ) {
			$display = Host_Validator::to_display( $alias );
			echo '<tr><td>' . esc_html( $display );
			if ( $display !== $alias ) {
				echo ' <code>' . esc_html( $alias ) . '</code>';
			}
			echo '</td><td>' . esc_html__( 'Alias', 'wp-domain-alias' ) . '</td><td class="wpda-actions">';
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
			wp_nonce_field( 'wpda_remove_alias' );
			echo '<input type="hidden" name="action" value="wpda_remove_alias"><input type="hidden" name="wpda_host" value="' . esc_attr( $alias ) . '">';
			/* translators: %s: hostname. */
			echo '<button type="submit" class="button button-link-delete" aria-label="' . esc_attr( sprintf( __( 'Remove %s', 'wp-domain-alias' ), $display ) ) . '">' . esc_html__( 'Remove', 'wp-domain-alias' ) . '</button></form></td></tr>';
		}
		if ( empty( $settings['aliases'] ) ) {
			echo '<tr><td colspan="3"><em>' . esc_html__( 'No aliases yet.', 'wp-domain-alias' ) . '</em></td></tr>';
		}
		echo '</tbody></table>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="wpda-add">';
		wp_nonce_field( 'wpda_add_alias' );
		echo '<input type="hidden" name="action" value="wpda_add_alias">';
		echo '<label for="wpda-alias">' . esc_html__( 'Add an alias', 'wp-domain-alias' ) . '</label> ';
		echo '<input type="text" id="wpda-alias" name="wpda_alias" class="regular-text" placeholder="alias-example.com" autocomplete="off" spellcheck="false" required> ';
		echo '<button type="submit" class="button button-primary">' . esc_html__( 'Add alias', 'wp-domain-alias' ) . '</button>';
		echo '<p class="description">' . esc_html__( 'Enter one domain, for example alias-example.com. www and non-www are separate domains: add each one visitors may use. Wildcards, IP addresses and ports are not accepted.', 'wp-domain-alias' ) . '</p>';
		echo '<p class="description">' . esc_html__( 'The domain must already point to this site at your host (domain mapping, DNS and an HTTPS certificate). This plugin cannot set that up.', 'wp-domain-alias' ) . '</p>';
		echo '</form></div>';
	}

	/**
	 * Settings card.
	 *
	 * @param array $settings Settings.
	 * @return void
	 */
	private function render_settings( array $settings ) {
		echo '<div class="wpda-card"><h2>' . esc_html__( 'Settings', 'wp-domain-alias' ) . '</h2>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'wpda_save_settings' );
		echo '<input type="hidden" name="action" value="wpda_save_settings">';
		echo '<table class="form-table" role="presentation"><tbody>';

		echo '<tr><th scope="row">' . esc_html__( 'Hostname preservation', 'wp-domain-alias' ) . '</th><td><label><input type="checkbox" name="wpda_enabled" value="1"' . checked( $settings['enabled'], true, false ) . '> ' . esc_html__( 'Keep visitors on the alias domain they used', 'wp-domain-alias' ) . '</label></td></tr>';

		echo '<tr><th scope="row">' . esc_html__( 'Canonical URLs', 'wp-domain-alias' ) . '</th><td>';
		echo '<p>' . esc_html__( 'Always the primary domain.', 'wp-domain-alias' ) . '</p>';
		echo '<p class="description">' . esc_html__( 'On alias pages, canonical tags, hreflang links, og:url and twitter:url point to the same page on the primary domain, matching the sitemaps and structured data. Canonical tags are hints to search engines, not guarantees.', 'wp-domain-alias' ) . '</p>';
		echo '</td></tr>';

		echo '<tr><th scope="row">' . esc_html__( 'Diagnostics', 'wp-domain-alias' ) . '</th><td><label><input type="checkbox" name="wpda_diagnostics" value="1"' . checked( $settings['diagnostics'], true, false ) . '> ' . esc_html__( 'Add an X-WP-Domain-Alias header and an HTML comment on alias pages', 'wp-domain-alias' ) . '</label><p class="description">' . esc_html__( 'Useful when checking cache behaviour. Turn off when finished.', 'wp-domain-alias' ) . '</p></td></tr>';

		echo '</tbody></table>';
		submit_button( __( 'Save settings', 'wp-domain-alias' ) );
		echo '</form></div>';
	}

	/**
	 * Configuration status card.
	 *
	 * @return void
	 */
	private function render_status() {
		echo '<div class="wpda-card"><h2>' . esc_html__( 'Configuration status', 'wp-domain-alias' ) . '</h2>';
		$this->render_check_table( $this->health->configuration_checks() );
		echo '</div>';
	}

	/**
	 * Health check card.
	 *
	 * @param mixed $health Stored results or false.
	 * @return void
	 */
	private function render_health( $health ) {
		echo '<div class="wpda-card" id="wpda-health"><h2>' . esc_html__( 'Health check', 'wp-domain-alias' ) . '</h2>';
		echo '<p>' . esc_html__( 'Requests the homepage of the primary domain and each alias from this server, without following redirects, and reports status, redirects, retained hostname and canonical URL. Domains are checked a few at a time so no request runs long. Nothing is changed.', 'wp-domain-alias' ) . '</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" id="wpda-health-start">';
		wp_nonce_field( 'wpda_health_check' );
		echo '<input type="hidden" name="action" value="wpda_health_check">';
		submit_button( __( 'Run health check', 'wp-domain-alias' ), 'secondary', 'submit', false );
		echo '</form>';
		echo '<p id="wpda-health-progress" class="wpda-progress" role="status" aria-live="polite">';
		if ( is_array( $health ) && ! Health_Check::is_complete( $health ) ) {
			/* translators: 1: number of domains checked, 2: total number of domains. */
			echo esc_html( sprintf( __( 'Checked %1$d of %2$d domains.', 'wp-domain-alias' ), count( $health['results'] ), count( $health['hosts'] ) ) );
		}
		echo '</p>';

		echo '<div id="wpda-health-results">';
		if ( is_array( $health ) ) {
			foreach ( $health['results'] as $result ) {
				$this->render_host_result( $result );
			}
		}
		echo '</div>';

		if ( is_array( $health ) && ! Health_Check::is_complete( $health ) ) {
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" id="wpda-health-continue">';
			wp_nonce_field( 'wpda_health_continue' );
			echo '<input type="hidden" name="action" value="wpda_health_continue">';
			$remaining = count( $health['hosts'] ) - count( $health['results'] );
			/* translators: %d: number of domains still to check. */
			submit_button( sprintf( _n( 'Check the remaining %d domain', 'Check the next domains (%d remaining)', $remaining, 'wp-domain-alias' ), $remaining ), 'primary', 'submit', false );
			echo '</form>';
		}
		if ( is_array( $health ) && ! empty( $health['results'] ) ) {
			echo '<p class="description">' . esc_html__( 'These are server-side HTTP checks. They do not run JavaScript, submit forms or test the site in a browser. A page cache may answer some requests.', 'wp-domain-alias' ) . '</p>';
		}
		echo '</div>';
	}

	/**
	 * One domain's health check results.
	 *
	 * @param array $result Result row.
	 * @return void
	 */
	private function render_host_result( $result ) {
		if ( ! is_array( $result ) || ! isset( $result['display'], $result['checks'] ) || ! is_array( $result['checks'] ) ) {
			return;
		}
		echo '<h3>' . esc_html( $result['display'] ) . '</h3>';
		$this->render_check_table( $result['checks'] );
	}

	/**
	 * About card.
	 *
	 * @return void
	 */
	private function render_about() {
		global $wp_version;
		$mu   = defined( 'WP_DOMAIN_ALIAS_MU' ) && WP_DOMAIN_ALIAS_MU;
		$rows = array(
			__( 'Plugin version', 'wp-domain-alias' ) => WP_DOMAIN_ALIAS_VERSION,
			__( 'Installed as', 'wp-domain-alias' )   => $mu ? __( 'Must-Use plugin (cannot be deactivated from the Plugins screen)', 'wp-domain-alias' ) : __( 'Regular plugin', 'wp-domain-alias' ),
			__( 'Requires', 'wp-domain-alias' )       => 'WordPress 6.4+, PHP 7.4+',
			__( 'This site', 'wp-domain-alias' )      => 'WordPress ' . $wp_version . ', PHP ' . PHP_VERSION,
			__( 'Multisite', 'wp-domain-alias' )      => __( 'Not supported', 'wp-domain-alias' ),
		);
		echo '<div class="wpda-card"><h2>' . esc_html__( 'About', 'wp-domain-alias' ) . '</h2><table class="widefat striped"><tbody>';
		foreach ( $rows as $label => $value ) {
			echo '<tr><th scope="row">' . esc_html( $label ) . '</th><td>' . esc_html( $value ) . '</td></tr>';
		}
		echo '</tbody></table>';
		echo '<p>' . esc_html__( 'Admin, login and other sensitive pages always stay on the primary domain. Checkout, membership and single sign-on flows on aliases are not supported.', 'wp-domain-alias' ) . '</p>';
		echo '</div>';
	}

	/**
	 * Renders a table of check results.
	 *
	 * @param array $checks Check rows.
	 * @return void
	 */
	private function render_check_table( array $checks ) {
		$labels = array(
			Health_Check::PASS => __( 'Pass', 'wp-domain-alias' ),
			Health_Check::WARN => __( 'Warning', 'wp-domain-alias' ),
			Health_Check::FAIL => __( 'Fail', 'wp-domain-alias' ),
			Health_Check::INFO => __( 'Info', 'wp-domain-alias' ),
		);
		echo '<table class="widefat striped wpda-checks"><tbody>';
		foreach ( $checks as $check ) {
			$status = isset( $labels[ $check['status'] ] ) ? $check['status'] : Health_Check::INFO;
			echo '<tr><td class="wpda-status"><span class="wpda-badge wpda-' . esc_attr( $status ) . '">' . esc_html( $labels[ $status ] ) . '</span></td><th scope="row">' . esc_html( $check['label'] ) . '</th><td>' . esc_html( $check['message'] ) . '</td></tr>';
		}
		echo '</tbody></table>';
	}

	/**
	 * Shows and clears queued notices.
	 *
	 * @param int $user User ID.
	 * @return void
	 */
	private function render_notices( $user ) {
		$notices = get_transient( 'wpda_notices_' . $user );
		if ( ! is_array( $notices ) ) {
			return;
		}
		delete_transient( 'wpda_notices_' . $user );
		foreach ( $notices as $notice ) {
			$type = in_array( $notice['type'], array( 'success', 'error', 'warning', 'info' ), true ) ? $notice['type'] : 'info';
			echo '<div class="notice notice-' . esc_attr( $type ) . '"><p>' . esc_html( $notice['message'] ) . '</p></div>';
		}
	}

	/**
	 * Queues a notice for the next page view.
	 *
	 * @param string $type    success|error|warning|info.
	 * @param string $message Message.
	 * @return void
	 */
	private function add_notice( $type, $message ) {
		$key       = 'wpda_notices_' . get_current_user_id();
		$notices   = get_transient( $key );
		$notices   = is_array( $notices ) ? $notices : array();
		$notices[] = array(
			'type'    => $type,
			'message' => $message,
		);
		set_transient( $key, $notices, MINUTE_IN_SECONDS );
	}

	/**
	 * Capability and nonce check for a form handler.
	 *
	 * @param string $action Action name without prefix.
	 * @return void
	 */
	private function authorize( $action ) {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'wp-domain-alias' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'wpda_' . $action );
	}

	/**
	 * Redirects back to the settings page and stops.
	 *
	 * @param string $fragment Optional #fragment.
	 * @return void
	 */
	private function back( $fragment = '' ) {
		wp_safe_redirect( $this->page_url() . $fragment );
		$this->terminate();
	}

	/**
	 * Ends the request. Separate so tests can intercept it.
	 *
	 * @return void
	 */
	protected function terminate() {
		exit;
	}

	/**
	 * Settings page URL.
	 *
	 * @return string
	 */
	private function page_url() {
		return admin_url( 'options-general.php?page=' . self::SLUG );
	}
}
