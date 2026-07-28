<?php

namespace WP_Autoplugin\V2\Release;

/** Deterministically transforms only the root style.css theme headers used by Release. */
final class Theme_Header_Transformer {
	private const HEADER_BYTES = 8192;

	/**
	 * @return array{content:string,transforms:array<string,string>}
	 */
	public function transform( string $source, string $mode, string $slug = '', string $name = '' ): array {
		if ( ! in_array( $mode, [ 'replacement', 'copy', 'direct' ], true ) ) {
			throw new \InvalidArgumentException( __( 'The requested theme header transformation is invalid.', 'wp-autoplugin' ) );
		}

		$theme_name = $this->header_value( $source, 'Theme Name' );
		if ( '' === $theme_name ) {
			throw new \InvalidArgumentException( __( 'The theme stylesheet does not contain a Theme Name header.', 'wp-autoplugin' ) );
		}

		$next_version = $this->next_version( $source );
		$source       = $this->set_or_insert_header( $source, 'Version', $next_version );
		$transforms   = [ 'version' => $next_version ];

		if ( 'copy' === $mode ) {
			$base_name                = trim( $name );
			$copy_name                = ( '' !== $base_name ? $base_name : $theme_name ) . ' — WP-Autoplugin Copy';
			$uri                      = 'https://wp-autoplugin.local/theme-copy/' . rawurlencode( $slug );
			$source                   = $this->replace_required_header( $source, 'Theme Name', $copy_name );
			$source                   = $this->set_or_insert_header( $source, 'Update URI', $uri );
			$transforms['theme_name'] = $copy_name;
			$transforms['update_uri'] = $uri;
		}

		return [
			'content'    => $source,
			'transforms' => $transforms,
		];
	}

	private function next_version( string $source ): string {
		$header = substr( $source, 0, self::HEADER_BYTES );
		if ( ! preg_match( $this->header_pattern( 'Version' ), $header, $match ) ) {
			return '0.0.1';
		}

		$current = trim( (string) $match[2] );
		if ( ! preg_match( '/^(\d+(?:\.\d+){0,2})(?:-[0-9A-Za-z.-]+)?(?:\+[0-9A-Za-z.-]+)?$/', $current, $version ) ) {
			throw new \InvalidArgumentException( __( 'The theme Version header must contain a numeric dotted version.', 'wp-autoplugin' ) );
		}
		$parts = array_map( 'intval', explode( '.', $version[1] ) );
		while ( count( $parts ) < 3 ) {
			$parts[] = 0;
		}
		++$parts[2];
		return implode( '.', array_slice( $parts, 0, 3 ) );
	}

	private function header_value( string $source, string $header_name ): string {
		$header = substr( $source, 0, self::HEADER_BYTES );
		return preg_match( $this->header_pattern( $header_name ), $header, $match )
			? trim( (string) $match[2] )
			: '';
	}

	private function replace_required_header( string $source, string $header_name, string $value ): string {
		$header = substr( $source, 0, self::HEADER_BYTES );
		if ( ! preg_match( $this->header_pattern( $header_name ), $header, $match, PREG_OFFSET_CAPTURE ) ) {
			throw new \InvalidArgumentException( sprintf( __( 'The theme stylesheet does not contain a %s header.', 'wp-autoplugin' ), $header_name ) );
		}
		$offset = $match[2][1];
		return substr( $source, 0, $offset ) . $value . substr( $source, $offset + strlen( $match[2][0] ) );
	}

	private function set_or_insert_header( string $source, string $header_name, string $value ): string {
		$header = substr( $source, 0, self::HEADER_BYTES );
		if ( preg_match( $this->header_pattern( $header_name ), $header, $match, PREG_OFFSET_CAPTURE ) ) {
			$offset = $match[2][1];
			return substr( $source, 0, $offset ) . $value . substr( $source, $offset + strlen( $match[2][0] ) );
		}

		if ( ! preg_match( $this->header_pattern( 'Theme Name' ), $header, $match, PREG_OFFSET_CAPTURE ) ) {
			throw new \InvalidArgumentException( __( 'The theme stylesheet does not contain a Theme Name header.', 'wp-autoplugin' ) );
		}
		$line     = $match[0][0];
		$line_end = $match[0][1] + strlen( $line );
		$prefix   = preg_match( '/^([ \t\/*#@]*)Theme Name/i', $line, $prefix_match ) ? $prefix_match[1] : ' * ';
		$newline  = str_contains( $header, "\r\n" ) ? "\r\n" : "\n";
		$insert   = str_contains( $match[1][0], '/*' ) && str_contains( $match[3][0], '*/' )
			? $newline . '/* ' . $header_name . ': ' . $value . ' */'
			: $newline . $prefix . $header_name . ': ' . $value;
		return substr( $source, 0, $line_end ) . $insert . substr( $source, $line_end );
	}

	private function header_pattern( string $header_name ): string {
		return '/^([ \t\/*#@]*' . preg_quote( $header_name, '/' ) . '\s*:\s*)(.*?)([ \t]*(?:\*\/)?[ \t]*)$/mi';
	}
}
