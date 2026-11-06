<?php
/**
 * Hostname validation and normalisation.
 *
 * Pure functions with no WordPress dependencies, so they can be unit tested
 * in isolation. Every hostname the plugin trusts passes through here.
 *
 * @package WP_Domain_Alias
 */

namespace WP_Domain_Alias;

defined( 'ABSPATH' ) || exit;

/**
 * Validates administrator input and request Host headers.
 */
final class Host_Validator {

	/**
	 * Strict check for a lowercase ASCII (punycode) DNS hostname.
	 *
	 * Rejects IP addresses, single-label names, wildcards, ports, trailing
	 * dots and anything outside letters, digits, hyphens and dots.
	 *
	 * @param mixed $host Candidate hostname.
	 * @return bool
	 */
	public static function is_valid_host( $host ) {
		if ( ! is_string( $host ) || '' === $host || strlen( $host ) > 253 ) {
			return false;
		}
		if ( strtolower( $host ) !== $host ) {
			return false;
		}
		$labels = explode( '.', $host );
		if ( count( $labels ) < 2 ) {
			return false;
		}
		foreach ( $labels as $label ) {
			if ( ! preg_match( '/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/D', $label ) ) {
				return false;
			}
		}
		// A numeric top-level label means an IPv4 address, not a domain.
		return ! preg_match( '/^[0-9]+$/D', end( $labels ) );
	}

	/**
	 * Normalises a domain typed by an administrator.
	 *
	 * Accepts "example.com", "Example.com", "https://example.com/" and
	 * internationalised names (converted to punycode when the intl extension is
	 * available). Returns either a normalised host or an error message.
	 *
	 * @param mixed $raw Raw input.
	 * @return array{host:string,error:string,warnings:string[]}
	 */
	public static function normalize_input( $raw ) {
		$result = array(
			'host'     => '',
			'error'    => '',
			'warnings' => array(),
		);

		if ( ! is_string( $raw ) ) {
			$result['error'] = __( 'Enter a domain name.', 'wp-domain-alias' );
			return $result;
		}

		// Control characters (other than surrounding whitespace that trim()
		// removes) are rejected before trimming, so "\0" cannot be stripped away.
		if ( preg_match( '/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', $raw ) ) {
			$result['error'] = __( 'Domain names cannot contain spaces or control characters.', 'wp-domain-alias' );
			return $result;
		}

		$value = trim( $raw );
		if ( '' === $value ) {
			$result['error'] = __( 'Enter a domain name.', 'wp-domain-alias' );
			return $result;
		}
		if ( strlen( $value ) > 300 ) {
			$result['error'] = __( 'That domain name is too long.', 'wp-domain-alias' );
			return $result;
		}
		if ( preg_match( '/[\x00-\x20\x7f]/', $value ) ) {
			$result['error'] = __( 'Domain names cannot contain spaces or control characters.', 'wp-domain-alias' );
			return $result;
		}

		if ( preg_match( '~^([a-z][a-z0-9+.-]*)://~i', $value, $m ) ) {
			$scheme = strtolower( $m[1] );
			if ( 'http' !== $scheme && 'https' !== $scheme ) {
				$result['error'] = __( 'Only http:// or https:// addresses can be used.', 'wp-domain-alias' );
				return $result;
			}
			if ( 'http' === $scheme ) {
				$result['warnings'][] = __( 'The http:// prefix was ignored. Aliases use the same scheme as the site; HTTPS is strongly recommended.', 'wp-domain-alias' );
			}
			$value = substr( $value, strlen( $m[0] ) );
		}

		// Allow one trailing slash ("example.com/"), nothing else after the host.
		if ( '/' === substr( $value, -1 ) ) {
			$value = substr( $value, 0, -1 );
		}
		if ( strpbrk( $value, '/?#\\' ) !== false ) {
			$result['error'] = __( 'Enter only the domain name, without a path, query string or fragment.', 'wp-domain-alias' );
			return $result;
		}
		if ( false !== strpos( $value, '@' ) ) {
			$result['error'] = __( 'Domain names cannot contain "@".', 'wp-domain-alias' );
			return $result;
		}
		if ( false !== strpos( $value, '*' ) ) {
			$result['error'] = __( 'Wildcard domains are not supported. Add each domain separately.', 'wp-domain-alias' );
			return $result;
		}
		if ( '[' === substr( $value, 0, 1 ) || preg_match( '/^[0-9.]+$/D', $value ) ) {
			$result['error'] = __( 'IP addresses cannot be used as aliases. Use a domain name.', 'wp-domain-alias' );
			return $result;
		}
		if ( false !== strpos( $value, ':' ) ) {
			$result['error'] = __( 'Do not include a port number. Aliases must be served on the standard HTTP/HTTPS ports.', 'wp-domain-alias' );
			return $result;
		}

		// A single trailing dot is the fully-qualified form of the same name.
		if ( '.' === substr( $value, -1 ) ) {
			$value = substr( $value, 0, -1 );
		}

		if ( preg_match( '/[^\x00-\x7f]/', $value ) ) {
			$ascii = self::idn_to_ascii( $value );
			if ( '' === $ascii ) {
				$result['error'] = function_exists( 'idn_to_ascii' )
					? __( 'That internationalised domain name is not valid.', 'wp-domain-alias' )
					: __( 'This server cannot convert internationalised domain names. Enter the punycode form (starting with "xn--").', 'wp-domain-alias' );
				return $result;
			}
			$value = $ascii;
		}

		$value = strtolower( $value );
		if ( ! self::is_valid_host( $value ) ) {
			$result['error'] = __( 'That is not a valid domain name. Use letters, numbers, hyphens and dots, for example "example.com".', 'wp-domain-alias' );
			return $result;
		}

		$result['host'] = $value;
		return $result;
	}

	/**
	 * Extracts the hostname from a request Host header value.
	 *
	 * Only the exact hostname, or the hostname followed by the default port of
	 * the current scheme, is accepted. Returns '' for anything else.
	 *
	 * @param mixed $http_host Value of $_SERVER['HTTP_HOST'].
	 * @param bool  $is_ssl    Whether the request arrived over HTTPS.
	 * @return string
	 */
	public static function request_host( $http_host, $is_ssl ) {
		if ( ! is_string( $http_host ) || '' === $http_host || strlen( $http_host ) > 260 ) {
			return '';
		}
		$host = strtolower( $http_host );
		if ( preg_match( '/^(.+):([0-9]+)$/D', $host, $m ) ) {
			$default = $is_ssl ? '443' : '80';
			if ( $m[2] !== $default ) {
				return '';
			}
			$host = $m[1];
		}
		return self::is_valid_host( $host ) ? $host : '';
	}

	/**
	 * Returns the www / non-www counterpart of a hostname.
	 *
	 * @param string $host Hostname.
	 * @return string
	 */
	public static function www_counterpart( $host ) {
		return 0 === strpos( $host, 'www.' ) ? substr( $host, 4 ) : 'www.' . $host;
	}

	/**
	 * Human-readable (Unicode) form of a punycode hostname, for display only.
	 *
	 * @param string $host Hostname.
	 * @return string
	 */
	public static function to_display( $host ) {
		if ( false === strpos( $host, 'xn--' ) || ! function_exists( 'idn_to_utf8' ) ) {
			return $host;
		}
		$unicode = idn_to_utf8( $host, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46 );
		return is_string( $unicode ) && '' !== $unicode ? $unicode : $host;
	}

	/**
	 * Converts an internationalised name to punycode, or returns ''.
	 *
	 * @param string $value Unicode hostname.
	 * @return string
	 */
	private static function idn_to_ascii( $value ) {
		if ( ! function_exists( 'idn_to_ascii' ) ) {
			return '';
		}
		$ascii = idn_to_ascii( $value, IDNA_NONTRANSITIONAL_TO_ASCII, INTL_IDNA_VARIANT_UTS46 );
		return is_string( $ascii ) ? $ascii : '';
	}
}
