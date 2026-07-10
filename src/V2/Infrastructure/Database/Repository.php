<?php

namespace WP_Autoplugin\V2\Infrastructure\Database;

/**
 * Shared JSON and timestamp helpers for repositories.
 */
abstract class Repository {
	protected \wpdb $wpdb;

	public function __construct( ?\wpdb $wpdb = null ) {
		if ( null === $wpdb ) {
			global $wpdb;
		}

		$this->wpdb = $wpdb;
	}

	protected function now(): string {
		return current_time( 'mysql', true );
	}

	/**
	 * @param mixed $value Value to encode.
	 */
	protected function json( $value ): string {
		return wp_json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) ?: '{}';
	}

	/**
	 * @return array<string, mixed>
	 */
	protected function decode( ?string $value ): array {
		$decoded = json_decode( (string) $value, true );

		return is_array( $decoded ) ? $decoded : [];
	}
}
