<?php
/**
 * Canonical and SEO URL policy for alias requests.
 *
 * @package WP_Domain_Alias
 */

namespace WP_Domain_Alias;

defined( 'ABSPATH' ) || exit;

/**
 * Keeps search-engine URLs consistent on alias page views.
 *
 * Policy (single, since 0.1.1-beta): canonical, hreflang alternates, og:url
 * and twitter:url point at the primary domain, consistent with sitemaps and
 * structured data, which WordPress already generates on the primary domain.
 * An "alias canonical" mode existed in 0.1.0-beta and was removed because
 * sitemaps and JSON-LD could not follow it consistently.
 */
class Seo_Handler {

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
	 * Returns the untranslated-host language path to force on the canonical,
	 * or ''. Set by a translation integration.
	 *
	 * @var callable|null
	 */
	private $language_path_provider = null;

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
	 * Registers filters provided by WordPress core and SEO plugins.
	 *
	 * @return void
	 */
	public function register() {
		add_filter( 'get_canonical_url', array( $this, 'filter_url' ), 100 );
		// Yoast SEO.
		add_filter( 'wpseo_canonical', array( $this, 'filter_url' ), 100 );
		add_filter( 'wpseo_opengraph_url', array( $this, 'filter_url' ), 100 );
		// Rank Math.
		add_filter( 'rank_math/frontend/canonical', array( $this, 'filter_url' ), 100 );
		add_filter( 'rank_math/opengraph/url', array( $this, 'filter_url' ), 100 );
	}

	/**
	 * Lets a translation integration supply the request's language path.
	 *
	 * @param callable $provider Returns a path such as "/es/about/" or ''.
	 * @return void
	 */
	public function set_language_path_provider( callable $provider ) {
		$this->language_path_provider = $provider;
	}

	/**
	 * Applies the canonical policy to a URL produced by core or an SEO plugin.
	 *
	 * @param mixed $url URL.
	 * @return mixed
	 */
	public function filter_url( $url ) {
		if ( ! is_string( $url ) || ! $this->urls->is_alias_frontend() ) {
			return $url;
		}
		return $this->apply_policy( $url );
	}

	/**
	 * Moves an own-site URL (primary, its www form, or any alias) to the
	 * primary domain.
	 *
	 * @param string $url URL.
	 * @return string
	 */
	public function apply_policy( $url ) {
		return $this->urls->move(
			$url,
			$this->domains->own_hosts(),
			$this->domains->primary_scheme(),
			$this->domains->primary_host()
		);
	}

	/**
	 * Called by the HTML pass for every tag on an alias page view.
	 *
	 * @param \WP_HTML_Tag_Processor $processor Processor positioned on a tag.
	 * @param string                 $tag       Uppercase tag name.
	 * @return int Number of URLs changed.
	 */
	public function process_tag( $processor, $tag ) {
		$attribute = '';
		$canonical = false;

		if ( 'LINK' === $tag ) {
			$rel = ' ' . strtolower( (string) $processor->get_attribute( 'rel' ) ) . ' ';
			if ( false !== strpos( $rel, ' canonical ' ) ) {
				$attribute = 'href';
				$canonical = true;
			} elseif ( false !== strpos( $rel, ' alternate ' ) && null !== $processor->get_attribute( 'hreflang' ) ) {
				$attribute = 'href';
			}
		} elseif ( 'META' === $tag ) {
			$property = strtolower( (string) $processor->get_attribute( 'property' ) . (string) $processor->get_attribute( 'name' ) );
			if ( 'og:url' === $property || 'twitter:url' === $property ) {
				$attribute = 'content';
			}
		}
		if ( '' === $attribute ) {
			return 0;
		}

		$value = $processor->get_attribute( $attribute );
		if ( ! is_string( $value ) ) {
			return 0;
		}

		$new = $this->apply_policy( $value );
		if ( $canonical ) {
			$new = $this->language_canonical( $new );
		}
		if ( $new === $value ) {
			return 0;
		}
		$processor->set_attribute( $attribute, $new );
		return 1;
	}

	/**
	 * Corrects a canonical that a translation plugin left untranslated
	 * because the page was served on a different host.
	 *
	 * @param string $canonical Canonical URL after the host policy.
	 * @return string
	 */
	private function language_canonical( $canonical ) {
		if ( null === $this->language_path_provider ) {
			return $canonical;
		}
		$path = (string) call_user_func( $this->language_path_provider );
		if ( '' === $path || '/' !== $path[0] ) {
			return $canonical;
		}
		$parts = wp_parse_url( $canonical );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) || ! in_array( strtolower( $parts['host'] ), $this->domains->own_hosts(), true ) ) {
			// A canonical pointing at another site was set deliberately.
			return $canonical;
		}
		$scheme = isset( $parts['scheme'] ) ? $parts['scheme'] : $this->domains->primary_scheme();
		return $scheme . '://' . $parts['host'] . $path;
	}
}
