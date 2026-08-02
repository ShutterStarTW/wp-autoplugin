<?php

namespace WP_Autoplugin\V2\Domain\AI;

/**
 * Security boundary for administrator-configured OpenAI-compatible endpoints.
 */
final class Custom_Endpoint_Security {
	public const MAX_URL_BYTES         = 2048;
	public const MAX_HEADERS           = 64;
	public const MAX_HEADER_LINE_BYTES = 2048;
	public const MAX_HEADERS_BYTES     = 8192;
	public const MAX_RESPONSE_BYTES    = Direct_Transport::MAX_RESPONSE_BYTES;

	private const FORBIDDEN_HEADERS = [
		'connection',
		'content-length',
		'cookie',
		'host',
		'proxy-authenticate',
		'proxy-authorization',
		'set-cookie',
		'te',
		'trailer',
		'transfer-encoding',
		'upgrade',
	];

	/**
	 * Require a public-safe HTTPS endpoint without embedded credentials.
	 *
	 * @return string|\WP_Error
	 */
	public static function validate_url( string $url ) {
		$url = trim( $url );
		if ( '' === $url || strlen( $url ) > self::MAX_URL_BYTES || preg_match( '/[\x00-\x20\x7F]/', $url ) ) {
			return new \WP_Error( 'custom_endpoint_url_invalid', __( 'Custom model endpoints must use a valid public HTTPS URL.', 'wp-autoplugin' ) );
		}

		$url   = esc_url_raw( $url, [ 'https' ] );
		$parts = wp_parse_url( $url );
		if (
			'' === $url
			|| ! is_array( $parts )
			|| 'https' !== strtolower( (string) ( $parts['scheme'] ?? '' ) )
			|| '' === (string) ( $parts['host'] ?? '' )
			|| isset( $parts['user'] )
			|| isset( $parts['pass'] )
			|| isset( $parts['fragment'] )
			|| false === wp_http_validate_url( $url )
		) {
			return new \WP_Error( 'custom_endpoint_url_invalid', __( 'Custom model endpoints must use a valid public HTTPS URL.', 'wp-autoplugin' ) );
		}

		return $url;
	}

	/**
	 * Normalize one `Name=Value` header line and reject request-routing headers.
	 *
	 * @return string|\WP_Error
	 */
	public static function normalize_header_line( string $line ) {
		$line = trim( $line );
		if ( '' === $line || strlen( $line ) > self::MAX_HEADER_LINE_BYTES || 1 !== preg_match( '//u', $line ) || preg_match( '/[\r\n\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $line ) || ! str_contains( $line, '=' ) ) {
			return new \WP_Error( 'custom_endpoint_header_invalid', __( 'Custom model headers must use the format “Name=Value” without control characters.', 'wp-autoplugin' ) );
		}

		[ $name, $value ] = array_map( 'trim', explode( '=', $line, 2 ) );
		if (
			'' === $name
			|| '' === $value
			|| ! preg_match( "/^[!#$%&'*+.^_`|~0-9A-Za-z-]+$/D", $name )
			|| in_array( strtolower( $name ), self::FORBIDDEN_HEADERS, true )
		) {
			return new \WP_Error( 'custom_endpoint_header_invalid', __( 'A custom model header is malformed or attempts to override HTTP request routing.', 'wp-autoplugin' ) );
		}

		return $name . '=' . $value;
	}

	/** @param array<int, string> $lines @return array<string, string> */
	public static function headers( array $lines ): array {
		$headers = [];
		$bytes   = 0;
		$count   = 0;
		foreach ( $lines as $line ) {
			if ( ! is_string( $line ) ) {
				continue;
			}
			$normalized = self::normalize_header_line( $line );
			if ( is_wp_error( $normalized ) ) {
				continue;
			}
			++$count;
			$bytes += strlen( $normalized );
			if ( $count > self::MAX_HEADERS || $bytes > self::MAX_HEADERS_BYTES ) {
				break;
			}
			[ $name, $value ] = array_map( 'trim', explode( '=', $normalized, 2 ) );
			$headers[ $name ] = $value;
		}
		return $headers;
	}
}
