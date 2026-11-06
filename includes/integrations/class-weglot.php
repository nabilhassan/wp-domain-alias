<?php
/**
 * Weglot compatibility.
 *
 * @package WP_Domain_Alias
 */

namespace WP_Domain_Alias\Integrations;

use WP_Domain_Alias\Link_Handler;
use WP_Domain_Alias\Seo_Handler;
use WP_Domain_Alias\Url_Handler;

defined( 'ABSPATH' ) || exit;

/**
 * Weglot translates the page in its own output pass, after this plugin's
 * buffer has run, and does not translate a canonical that sits on a host it
 * was not configured for. This integration re-runs the HTML pass on Weglot's
 * output and keeps the translated canonical path.
 *
 * Status: unit tested against stubs; not yet verified with a live Weglot
 * project (it requires a Weglot account).
 */
class Weglot {

	/**
	 * Registers the integration when Weglot is present.
	 *
	 * @param Link_Handler $links Link handler.
	 * @param Seo_Handler  $seo   SEO handler.
	 * @param Url_Handler  $urls  URL handler.
	 * @return void
	 */
	public static function register( Link_Handler $links, Seo_Handler $seo, Url_Handler $urls ) {
		add_filter( 'weglot_render_dom', array( $links, 'rewrite_html' ), PHP_INT_MAX );
		$seo->set_language_path_provider(
			static function () use ( $urls ) {
				return self::language_path( $urls->request_path() );
			}
		);
	}

	/**
	 * The raw request path when it is a translated-language URL, else ''.
	 *
	 * @param string $request_path Raw request path captured at load time.
	 * @return string
	 */
	public static function language_path( $request_path ) {
		if ( ! function_exists( 'weglot_get_current_language' ) || ! function_exists( 'weglot_get_original_language' ) ) {
			return '';
		}
		$current  = (string) weglot_get_current_language();
		$original = (string) weglot_get_original_language();
		if ( '' === $current || $current === $original || ! preg_match( '/^[a-z0-9-]+$/iD', $current ) ) {
			return '';
		}
		$prefix = '/' . strtolower( $current );
		$path   = (string) $request_path;
		if ( strtolower( $path ) === $prefix || 0 === stripos( $path, $prefix . '/' ) ) {
			return $path;
		}
		return '';
	}
}
