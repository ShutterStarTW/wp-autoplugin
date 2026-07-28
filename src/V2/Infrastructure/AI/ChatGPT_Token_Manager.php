<?php

namespace WP_Autoplugin\V2\Infrastructure\AI;

/** Supplies current OAuth credentials and serializes refreshes. */
final class ChatGPT_Token_Manager {
	public const LOCK_OPTION   = '_wp_autoplugin_chatgpt_oauth_lock';
	private const REFRESH_SKEW = 120;

	public function __construct( private ?ChatGPT_Token_Store $store = null, private ?ChatGPT_OAuth_Client $client = null ) {
		$this->store  ??= new ChatGPT_Token_Store();
		$this->client ??= new ChatGPT_OAuth_Client();
	}

	/** @return array<string, mixed>|\WP_Error */
	public function current() {
		$tokens = $this->store->get();
		if ( is_wp_error( $tokens ) ) {
			return $tokens;
		}
		if ( ! is_array( $tokens ) ) {
			return new \WP_Error(
				'chatgpt_oauth_missing',
				__( 'Connect a ChatGPT subscription before using this model.', 'wp-autoplugin' ),
				[
					'status'             => 401,
					'reconnect_required' => true,
				]
			);
		}
		return $this->needs_refresh( $tokens ) ? $this->refresh() : $tokens;
	}

	/** @return array<string, mixed>|null|\WP_Error */
	public function stored() {
		return $this->store->get(); }

	/** @param array<string, mixed> $tokens */
	public function needs_refresh( array $tokens ): bool {
		return (int) ( $tokens['expires_at'] ?? 0 ) <= time() + self::REFRESH_SKEW; }

	/** @return true|\WP_Error */
	public function save( array $tokens ) {
		return $this->store->save( $tokens ); }

	public function delete(): void {
		$this->store->delete(); }

	public function lock(): ChatGPT_Option_Lock {
		return new ChatGPT_Option_Lock( self::LOCK_OPTION, 30 ); }

	/** @return array<string, mixed>|\WP_Error */
	private function refresh() {
		$lock  = $this->lock();
		$owner = $lock->acquire();
		if ( is_wp_error( $owner ) ) {
			for ( $attempt = 0; $attempt < 20; $attempt++ ) {
				$tokens = $this->store->get();
				if ( is_array( $tokens ) && (int) $tokens['expires_at'] > time() + 5 ) {
					return $tokens;
				}
				usleep( 250000 );
			}
			return $owner;
		}
		try {
			$tokens = $this->store->get();
			if ( is_wp_error( $tokens ) || ! is_array( $tokens ) ) {
				return is_wp_error( $tokens ) ? $tokens : new \WP_Error( 'chatgpt_oauth_missing', __( 'Reconnect the ChatGPT account.', 'wp-autoplugin' ), [ 'reconnect_required' => true ] );
			}
			if ( ! $this->needs_refresh( $tokens ) ) {
				return $tokens;
			}
			$refreshed = $this->client->refresh( (string) $tokens['refresh_token'] );
			if ( is_wp_error( $refreshed ) ) {
				$retryable = (bool) ( $refreshed->get_error_data()['retryable'] ?? false );
				return $retryable && (int) $tokens['expires_at'] > time() + 5 ? $tokens : $refreshed;
			}
			if ( '' === (string) ( $refreshed['account_id'] ?? '' ) && '' !== (string) ( $tokens['account_id'] ?? '' ) ) {
				$refreshed['account_id'] = (string) $tokens['account_id'];
			}
			if ( in_array( (string) ( $refreshed['account_label'] ?? '' ), [ '', __( 'OpenAI account', 'wp-autoplugin' ) ], true ) && '' !== (string) ( $tokens['account_label'] ?? '' ) ) {
				$refreshed['account_label'] = (string) $tokens['account_label'];
			}
			$saved = $this->store->save( $refreshed );
			return is_wp_error( $saved ) ? $saved : $refreshed;
		} finally {
			$lock->release( $owner );
		}
	}

	/** @param array<string, mixed> $tokens @return array<string, string> */
	public static function headers( array $tokens ): array {
		$headers = [
			'Authorization' => 'Bearer ' . (string) $tokens['access_token'],
			'originator'    => 'codex_cli_rs',
			'User-Agent'    => 'codex_cli_rs/0.0.0 (WP-Autoplugin ' . WP_AUTOPLUGIN_VERSION . '; WordPress)',
		];
		if ( '' !== (string) ( $tokens['account_id'] ?? '' ) ) {
			$headers['ChatGPT-Account-ID'] = (string) $tokens['account_id'];
		}
		return $headers;
	}
}
