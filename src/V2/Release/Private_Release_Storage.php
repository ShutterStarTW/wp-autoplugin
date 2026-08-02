<?php

namespace WP_Autoplugin\V2\Release;

/**
 * Resolves and verifies the private filesystem boundary for release archives.
 */
final class Private_Release_Storage {
	private const DIRECTORY       = 'wp-autoplugin-v2-release';
	private const ARCHIVE_PATTERN = '/^package-[A-Za-z0-9]+\.zip$/D';

	/** @return string|\WP_Error */
	public function root() {
		$base = wp_normalize_path( sys_get_temp_dir() . '/' . self::DIRECTORY );
		if ( is_link( $base ) || ( file_exists( $base ) && ! is_dir( $base ) ) ) {
			return $this->unavailable();
		}
		if ( ! is_dir( $base ) && ! wp_mkdir_p( $base ) ) {
			return $this->unavailable();
		}

		$real   = realpath( $base );
		$public = realpath( ABSPATH );
		if ( false === $real || false === $public || is_link( $base ) ) {
			return $this->unavailable();
		}
		$real   = untrailingslashit( wp_normalize_path( $real ) );
		$public = trailingslashit( wp_normalize_path( $public ) );
		if ( str_starts_with( trailingslashit( $real ), $public ) ) {
			return $this->unavailable();
		}

		@chmod( $real, 0700 );
		$permissions = fileperms( $real );
		$private     = '\\' === DIRECTORY_SEPARATOR || ( false !== $permissions && 0 === ( $permissions & 0077 ) );
		return $private && is_readable( $real ) && is_writable( $real ) ? $real : $this->unavailable();
	}

	/**
	 * Verify that a stored archive still names the exact private regular file.
	 *
	 * @return string|null
	 */
	public function verified_archive( string $candidate, ?string $expected_hash = null, ?int $expected_size = null ): ?string {
		$root = $this->root();
		if ( is_wp_error( $root ) || '' === $candidate || is_link( $candidate ) ) {
			return null;
		}

		$real = realpath( $candidate );
		if ( false === $real ) {
			return null;
		}
		$real = wp_normalize_path( $real );
		if ( dirname( $real ) !== $root || ! preg_match( self::ARCHIVE_PATTERN, basename( $real ) ) || ! is_file( $real ) ) {
			return null;
		}

		$size = filesize( $real );
		if ( false === $size || ( null !== $expected_size && $size !== $expected_size ) ) {
			return null;
		}
		if ( null !== $expected_hash ) {
			if ( ! preg_match( '/^[a-f0-9]{64}$/D', $expected_hash ) ) {
				return null;
			}
			$actual_hash = hash_file( 'sha256', $real );
			if ( false === $actual_hash || ! hash_equals( $expected_hash, $actual_hash ) ) {
				return null;
			}
		}

		return $real;
	}

	private function unavailable(): \WP_Error {
		return new \WP_Error( 'release_private_temp', __( 'A private temporary release directory is unavailable.', 'wp-autoplugin' ) );
	}
}
