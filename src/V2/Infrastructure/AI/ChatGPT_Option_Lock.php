<?php

namespace WP_Autoplugin\V2\Infrastructure\AI;

/** Small non-autoloaded option lock for OAuth credential operations. */
final class ChatGPT_Option_Lock {
	public function __construct( private string $option, private int $ttl = 30 ) {}

	/** @return string|\WP_Error */
	public function acquire() {
		$owner = wp_generate_uuid4();
		$value = [
			'owner'      => $owner,
			'expires_at' => time() + max( 3, min( 60, $this->ttl ) ),
		];
		if ( add_option( $this->option, $value, '', false ) ) {
			return $owner;
		}
		$current = get_option( $this->option, null );
		if ( is_array( $current ) && (int) ( $current['expires_at'] ?? 0 ) <= time() ) {
			delete_option( $this->option );
			if ( add_option( $this->option, $value, '', false ) ) {
				return $owner;
			}
		}
		return new \WP_Error(
			'chatgpt_oauth_locked',
			__( 'Another ChatGPT authentication operation is in progress.', 'wp-autoplugin' ),
			[
				'status'      => 409,
				'retry_after' => 1,
			]
		);
	}

	public function release( string $owner ): void {
		$current = get_option( $this->option, null );
		if ( is_array( $current ) && is_string( $current['owner'] ?? null ) && hash_equals( $current['owner'], $owner ) ) {
			delete_option( $this->option );
		}
	}
}
