<?php
/**
 * Frontend link handling for alias requests.
 *
 * @package WP_Domain_Alias
 */

namespace WP_Domain_Alias;

defined( 'ABSPATH' ) || exit;

/**
 * Keeps navigation, forms, frontend endpoints and CORS-sensitive assets on
 * the alias the visitor is using.
 *
 * WordPress keeps generating primary-domain URLs. This class changes only
 * selected attributes in the final HTML and a few endpoint URLs, so canonical
 * tags, sitemaps and structured data are not affected.
 */
class Link_Handler {

	/**
	 * Apply queued tag-processor edits after this many changes. The core
	 * processor slows down sharply as unapplied edits pile up (about 80 ms
	 * for 2,000 links without flushing, under 20 ms with it).
	 */
	const FLUSH_EVERY = 100;

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
	 * SEO handler, called for each tag during the HTML pass.
	 *
	 * @var Seo_Handler
	 */
	private $seo;

	/**
	 * Output collected across intermediate buffer flushes.
	 *
	 * @var string
	 */
	private $pending = '';

	/**
	 * Number of URLs changed in the last HTML pass (diagnostics).
	 *
	 * @var int
	 */
	private $rewrites = 0;

	/**
	 * Constructor.
	 *
	 * @param Domain_Manager $domains Domain manager.
	 * @param Url_Handler    $urls    URL handler.
	 * @param Seo_Handler    $seo     SEO handler.
	 */
	public function __construct( Domain_Manager $domains, Url_Handler $urls, Seo_Handler $seo ) {
		$this->domains = $domains;
		$this->urls    = $urls;
		$this->seo     = $seo;
	}

	/**
	 * Registers hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_filter( 'rest_url', array( $this, 'rest_url' ), 100 );
		add_filter( 'admin_url', array( $this, 'admin_url' ), 100, 2 );
		add_filter( 'script_module_loader_src', array( $this, 'asset_url' ), 100 );
		add_filter( 'theme_file_uri', array( $this, 'font_url' ), 100 );
		add_filter( 'font_dir', array( $this, 'font_dir' ), 100 );
		add_action( 'template_redirect', array( $this, 'start_buffer' ), -100 );
	}

	/**
	 * Same-origin REST API root for frontend scripts on an alias.
	 *
	 * @param string $url REST URL.
	 * @return string
	 */
	public function rest_url( $url ) {
		return $this->urls->is_alias_frontend() ? $this->urls->to_alias( $url ) : $url;
	}

	/**
	 * Same-origin admin-ajax.php for frontend scripts on an alias. Every
	 * other admin URL stays on the primary domain.
	 *
	 * @param string $url  Admin URL.
	 * @param string $path Requested path.
	 * @return string
	 */
	public function admin_url( $url, $path = '' ) {
		if ( ! $this->urls->is_alias_frontend() ) {
			return $url;
		}
		$path = ltrim( (string) $path, '/' );
		if ( 'admin-ajax.php' !== $path && 0 !== strpos( $path, 'admin-ajax.php?' ) ) {
			return $url;
		}
		return $this->urls->to_alias( $url, true );
	}

	/**
	 * Same-origin script module URLs (module scripts and the import map
	 * require CORS when loaded from another origin).
	 *
	 * @param string $url Asset URL.
	 * @return string
	 */
	public function asset_url( $url ) {
		return $this->assets_enabled() ? $this->urls->to_alias( $url ) : $url;
	}

	/**
	 * Same-origin theme font files. Browsers block cross-origin web fonts
	 * without CORS headers; theme.json fonts are resolved through
	 * get_theme_file_uri() before WordPress prints them.
	 *
	 * @param string $url Theme file URL.
	 * @return string
	 */
	public function font_url( $url ) {
		if ( ! is_string( $url ) || ! preg_match( '/\.(?:woff2?|ttf|otf|eot)(?:[?#]|$)/i', $url ) ) {
			return $url;
		}
		return $this->asset_url( $url );
	}

	/**
	 * Same-origin Font Library uploads (wp-content/fonts).
	 *
	 * @param mixed $dir Font directory data.
	 * @return mixed
	 */
	public function font_dir( $dir ) {
		if ( ! is_array( $dir ) || ! $this->assets_enabled() ) {
			return $dir;
		}
		foreach ( array( 'url', 'baseurl' ) as $key ) {
			if ( isset( $dir[ $key ] ) ) {
				$dir[ $key ] = $this->urls->to_alias( $dir[ $key ] );
			}
		}
		return $dir;
	}

	/**
	 * Starts the output buffer for alias page views only.
	 *
	 * @return void
	 */
	public function start_buffer() {
		if ( ! $this->urls->is_alias_frontend() || is_feed() || is_robots() || is_trackback() ) {
			return;
		}
		/**
		 * Filters whether the HTML pass runs for this alias request.
		 *
		 * @param bool $enabled Whether to buffer and rewrite the page.
		 */
		if ( ! apply_filters( 'wp_domain_alias_buffer_output', true ) ) {
			return;
		}
		if ( $this->domains->diagnostics_enabled() && ! headers_sent() ) {
			header( 'X-WP-Domain-Alias: ' . $this->domains->current_alias() );
		}
		$this->pending = '';
		ob_start( array( $this, 'buffer_callback' ) );
	}

	/**
	 * Output buffer callback.
	 *
	 * Intermediate flushes are held back and the page is processed once, so a
	 * tag is never split across two passes.
	 *
	 * @param string $buffer Buffer contents.
	 * @param int    $phase  PHP_OUTPUT_HANDLER_* flags.
	 * @return string
	 */
	public function buffer_callback( $buffer, $phase = PHP_OUTPUT_HANDLER_FINAL ) {
		if ( ! ( $phase & PHP_OUTPUT_HANDLER_CLEAN ) ) {
			$this->pending .= (string) $buffer;
		}
		if ( ! ( $phase & PHP_OUTPUT_HANDLER_FINAL ) ) {
			return '';
		}
		$html          = $this->pending;
		$this->pending = '';
		return $this->rewrite_html( $html );
	}

	/**
	 * Rewrites own-site URLs in selected attributes of an HTML document.
	 *
	 * Public so integrations (for example a translation plugin that renders
	 * after the buffer) can run the same pass on their output.
	 *
	 * @param mixed $html HTML.
	 * @return mixed
	 */
	public function rewrite_html( $html ) {
		if ( ! is_string( $html ) || '' === $html || ! $this->urls->is_alias_frontend() ) {
			return $html;
		}
		if ( ! class_exists( 'WP_HTML_Tag_Processor' ) || ! $this->is_html_response() ) {
			return $html;
		}
		if ( ! $this->mentions_own_host( $html ) ) {
			return $html;
		}

		$attributes = $this->url_attributes();
		$assets     = $this->assets_enabled();
		$text_edit  = method_exists( 'WP_HTML_Tag_Processor', 'set_modifiable_text' );
		$processor  = new \WP_HTML_Tag_Processor( $html );
		$count      = 0;
		$flushed_at = 0;
		$post_forms = $this->opted_in_admin_post_forms( $html );
		$form_index = -1;

		while ( $processor->next_tag() ) {
			$tag = $processor->get_tag();
			if ( 'FORM' === $tag ) {
				++$form_index;
			}

			$names = isset( $attributes[ $tag ] ) ? $attributes[ $tag ] : array();
			if ( isset( $attributes['*'] ) ) {
				$names = array_merge( $names, $attributes['*'] );
			}
			if ( $assets ) {
				$names = array_merge( $names, $this->asset_attributes( $processor, $tag ) );
			}
			foreach ( array_unique( $names ) as $name ) {
				$value = $processor->get_attribute( $name );
				if ( ! is_string( $value ) ) {
					continue;
				}
				$allow_post = 'FORM' === $tag && 'action' === $name && isset( $post_forms[ $form_index ] );
				$new        = $this->urls->to_alias( $value, 'href' !== $name, $allow_post );
				if ( $new !== $value ) {
					$processor->set_attribute( $name, $new );
					++$count;
				}
			}

			$count += $this->seo->process_tag( $processor, $tag );

			if ( $assets && $text_edit && ( 'STYLE' === $tag || 'SCRIPT' === $tag ) ) {
				$count += $this->rewrite_inline( $processor, $tag );
			}

			if ( $count - $flushed_at >= self::FLUSH_EVERY ) {
				$processor->get_updated_html();
				$flushed_at = $count;
			}
		}

		$this->rewrites = $count;
		$html           = $processor->get_updated_html();

		if ( $this->domains->diagnostics_enabled() ) {
			$html .= sprintf(
				"\n<!-- WP Domain Alias: %d URL(s) adjusted for %s -->",
				$count,
				esc_html( $this->domains->current_alias() )
			);
		}
		return $html;
	}

	/**
	 * Finds admin-post.php forms whose action is opted in through the
	 * wp_domain_alias_admin_post_actions filter.
	 *
	 * Read-only pre-scan; runs only when the filter lists actions and the
	 * page mentions admin-post.php. Forms are identified by their position
	 * among FORM tags, which the main pass counts the same way.
	 *
	 * @param string $html HTML.
	 * @return array<int,true> Positions of opted-in forms.
	 */
	private function opted_in_admin_post_forms( $html ) {
		$actions = $this->urls->admin_post_actions();
		if ( empty( $actions ) || false === stripos( $html, 'admin-post.php' ) ) {
			return array();
		}
		$processor = new \WP_HTML_Tag_Processor( $html );
		$index     = -1;
		$current   = null;
		$found     = array();
		while ( $processor->next_tag( array( 'tag_closers' => 'visit' ) ) ) {
			$tag = $processor->get_tag();
			if ( 'FORM' === $tag ) {
				$current = null;
				if ( $processor->is_tag_closer() ) {
					continue;
				}
				++$index;
				$target = $processor->get_attribute( 'action' );
				$path   = is_string( $target ) ? wp_parse_url( $target, PHP_URL_PATH ) : null;
				if ( is_string( $path ) && $this->urls->is_admin_post_path( $path ) ) {
					$current = $index;
					$query   = wp_parse_url( $target, PHP_URL_QUERY );
					$args    = array();
					wp_parse_str( is_string( $query ) ? html_entity_decode( $query ) : '', $args );
					if ( isset( $args['action'] ) && in_array( $args['action'], $actions, true ) ) {
						$found[ $index ] = true;
					}
				}
				continue;
			}
			if ( null !== $current && ( 'INPUT' === $tag || 'BUTTON' === $tag ) && ! $processor->is_tag_closer() && 'action' === $processor->get_attribute( 'name' ) ) {
				$value = $processor->get_attribute( 'value' );
				if ( is_string( $value ) && in_array( $value, $actions, true ) ) {
					$found[ $current ] = true;
				}
			}
		}
		return $found;
	}

	/**
	 * Number of URLs changed by the last HTML pass.
	 *
	 * @return int
	 */
	public function last_rewrite_count() {
		return $this->rewrites;
	}

	/**
	 * Navigation attributes to rewrite, keyed by uppercase tag name; '*'
	 * applies to every tag.
	 *
	 * @return array<string,string[]>
	 */
	private function url_attributes() {
		$defaults = array(
			'A'      => array( 'href' ),
			'AREA'   => array( 'href' ),
			'FORM'   => array( 'action' ),
			'BUTTON' => array( 'formaction' ),
			'INPUT'  => array( 'formaction' ),
			// Used by "clickable column" add-ons for Elementor.
			'*'      => array( 'data-column-clickable' ),
		);
		/**
		 * Filters the HTML attributes treated as navigation URLs.
		 *
		 * @param array<string,string[]> $attributes Attribute names keyed by uppercase tag name or '*'.
		 */
		$filtered = apply_filters( 'wp_domain_alias_url_attributes', $defaults );
		return is_array( $filtered ) ? $filtered : $defaults;
	}

	/**
	 * Asset attributes that need to be same-origin on this tag.
	 *
	 * Scripts and stylesheets are moved because module scripts and web fonts
	 * referenced from stylesheets are blocked cross-origin without CORS
	 * headers. Images and other media stay on the primary domain.
	 *
	 * @param \WP_HTML_Tag_Processor $processor Processor positioned on a tag.
	 * @param string                 $tag       Uppercase tag name.
	 * @return string[]
	 */
	private function asset_attributes( $processor, $tag ) {
		if ( 'SCRIPT' === $tag ) {
			return array( 'src' );
		}
		if ( 'LINK' === $tag ) {
			$rel = strtolower( (string) $processor->get_attribute( 'rel' ) );
			if ( preg_match( '/(?:^|\s)(?:stylesheet|preload|modulepreload)(?:\s|$)/', $rel ) ) {
				return array( 'href' );
			}
		}
		return array();
	}

	/**
	 * Rewrites own-site URLs inside inline STYLE url() values and the script
	 * module import map. Requires WordPress 6.7+.
	 *
	 * @param \WP_HTML_Tag_Processor $processor Processor positioned on STYLE or SCRIPT.
	 * @param string                 $tag       Uppercase tag name.
	 * @return int Number of URLs changed.
	 */
	private function rewrite_inline( $processor, $tag ) {
		$text = $processor->get_modifiable_text();
		if ( '' === $text || ! $this->mentions_own_host( $text ) ) {
			return 0;
		}
		$count = 0;

		if ( 'STYLE' === $tag ) {
			$new = preg_replace_callback(
				'~url\(\s*([\'"]?)((?:https?:)?//[^\'")\s]+)~i',
				function ( $m ) use ( &$count ) {
					$url = $this->urls->to_alias( $m[2] );
					if ( $url !== $m[2] ) {
						++$count;
					}
					return 'url(' . $m[1] . $url;
				},
				$text
			);
		} elseif ( 'importmap' === strtolower( (string) $processor->get_attribute( 'type' ) ) ) {
			$map = json_decode( $text, true );
			if ( ! is_array( $map ) ) {
				return 0;
			}
			array_walk_recursive(
				$map,
				function ( &$value ) use ( &$count ) {
					$url = $this->urls->to_alias( $value );
					if ( $url !== $value ) {
						$value = $url;
						++$count;
					}
				}
			);
			$new = wp_json_encode( $map, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_SLASHES );
		} else {
			return 0;
		}

		if ( $count > 0 && is_string( $new ) ) {
			$processor->set_modifiable_text( $new );
			return $count;
		}
		return 0;
	}

	/**
	 * Whether same-origin asset handling is on.
	 *
	 * @return bool
	 */
	private function assets_enabled() {
		/**
		 * Filters whether scripts, stylesheets and inline font URLs are served
		 * from the alias on alias page views.
		 *
		 * @param bool $enabled Default true.
		 */
		return $this->urls->is_alias_frontend() && (bool) apply_filters( 'wp_domain_alias_same_origin_assets', true );
	}

	/**
	 * Quick check that the document contains any own host at all.
	 *
	 * @param string $html HTML.
	 * @return bool
	 */
	private function mentions_own_host( $html ) {
		foreach ( $this->domains->own_hosts() as $host ) {
			if ( false !== stripos( $html, $host ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Whether the response being sent is HTML.
	 *
	 * @return bool
	 */
	private function is_html_response() {
		foreach ( headers_list() as $header ) {
			if ( 0 === stripos( $header, 'Content-Type:' ) ) {
				return false !== stripos( $header, 'text/html' ) || false !== stripos( $header, 'application/xhtml+xml' );
			}
		}
		return true;
	}
}
