<?php

namespace WP_Autoplugin\V2\Infrastructure\AI;

/** Discovers and caches the curated ChatGPT subscription model entitlement set. */
final class ChatGPT_Model_Service {
	public const OPTION      = 'wp_autoplugin_chatgpt_model_cache';
	public const LOCK_OPTION = '_wp_autoplugin_chatgpt_models_lock';
	private const MAX_AGE    = DAY_IN_SECONDS;
	private const EFFORTS    = [ 'minimal', 'low', 'medium', 'high', 'xhigh', 'max', 'ultra' ];

	public function __construct( private ?ChatGPT_Token_Manager $tokens = null ) {
		$this->tokens ??= new ChatGPT_Token_Manager(); }

	/** @return array<string, mixed> */
	public function state(): array {
		$cache           = get_option( self::OPTION, [] );
		$cache           = is_array( $cache ) ? $cache : [];
		$tokens          = $this->tokens->stored();
		$account_matches = is_array( $tokens ) && '' !== (string) ( $cache['account'] ?? '' ) && hash_equals( (string) $cache['account'], $this->account_hash( $tokens ) );
		$models          = [];
		foreach ( $account_matches ? (array) ( $cache['models'] ?? [] ) : [] as $slug => $metadata ) {
			if ( ! is_string( $slug ) || ! isset( ChatGPT_Config::models()[ $slug ] ) || ! is_array( $metadata ) ) {
				continue;
			}
			$levels          = array_values( array_filter( array_map( 'sanitize_key', (array) ( $metadata['levels'] ?? [] ) ), static fn( string $level ): bool => in_array( $level, self::EFFORTS, true ) ) );
			$default         = sanitize_key( (string) ( $metadata['default'] ?? '' ) );
			$models[ $slug ] = [
				'label'   => sanitize_text_field( (string) ( $metadata['label'] ?? ChatGPT_Config::models()[ $slug ]['label'] ) ),
				'levels'  => array_values( array_unique( $levels ) ),
				'default' => in_array( $default, $levels, true ) ? $default : '',
			];
		}
		return [
			'last_synced_at'     => $account_matches ? (int) ( $cache['fetched_at'] ?? 0 ) : 0,
			'last_attempt_at'    => $account_matches ? (int) ( $cache['attempted_at'] ?? 0 ) : 0,
			'stale'              => ! $account_matches || (int) ( $cache['fetched_at'] ?? 0 ) < time() - self::MAX_AGE,
			'error'              => $account_matches ? sanitize_text_field( (string) ( $cache['error'] ?? '' ) ) : '',
			'reconnect_required' => $account_matches && ! empty( $cache['reconnect_required'] ),
			'models'             => $models,
		];
	}

	public function connected_account_matches(): bool {
		$tokens = $this->tokens->stored();
		$cache  = get_option( self::OPTION, [] );
		return is_array( $tokens ) && is_array( $cache ) && hash_equals( (string) ( $cache['account'] ?? '' ), $this->account_hash( $tokens ) );
	}

	/** @return array<string, mixed>|\WP_Error */
	public function refresh( ?array $tokens = null ) {
		$tokens ??= $this->tokens->current();
		if ( is_wp_error( $tokens ) ) {
			return $tokens;
		}
		$lock  = $this->lock();
		$owner = $lock->acquire();
		if ( is_wp_error( $owner ) ) {
			return $owner;
		}
		try {
			$url = add_query_arg( 'client_version', WP_AUTOPLUGIN_VERSION, ChatGPT_Config::API_BASE_URL . '/models' );
			if ( ! ChatGPT_Config::is_api_url( $url ) ) {
				return new \WP_Error( 'chatgpt_models_url', __( 'The ChatGPT model endpoint is invalid.', 'wp-autoplugin' ) );
			}
			$response = wp_safe_remote_get(
				$url,
				[
					'timeout'     => 30,
					'redirection' => 0,
					'headers'     => array_merge( ChatGPT_Token_Manager::headers( $tokens ), [ 'Accept' => 'application/json' ] ),
				]
			);
			if ( is_wp_error( $response ) ) {
				return $this->record_error( __( 'The ChatGPT model catalog could not be reached.', 'wp-autoplugin' ), $tokens );
			}
			$status = (int) wp_remote_retrieve_response_code( $response );
			$data   = json_decode( (string) wp_remote_retrieve_body( $response ), true );
			if ( $status < 200 || $status >= 300 || ! is_array( $data['models'] ?? null ) ) {
				return $this->record_error( 401 === $status || 403 === $status ? __( 'Reconnect ChatGPT to refresh model access.', 'wp-autoplugin' ) : __( 'OpenAI returned an invalid ChatGPT model catalog.', 'wp-autoplugin' ), $tokens, in_array( $status, [ 401, 403 ], true ) );
			}
			$models = [];
			foreach ( $data['models'] as $remote ) {
				if ( ! is_array( $remote ) || ! is_string( $remote['slug'] ?? null ) || ! isset( ChatGPT_Config::models()[ $remote['slug'] ] ) ) {
					continue;
				}
				$visibility = strtolower( (string) ( $remote['visibility'] ?? '' ) );
				if ( in_array( $visibility, [ 'hide', 'hidden' ], true ) ) {
					continue;
				}
				$slug     = $remote['slug'];
				$fallback = ChatGPT_Config::models()[ $slug ];
				$levels   = [];
				foreach ( (array) ( $remote['supported_reasoning_levels'] ?? [] ) as $level ) {
					$effort = is_array( $level ) ? sanitize_key( (string) ( $level['effort'] ?? '' ) ) : sanitize_key( (string) $level );
					if ( in_array( $effort, self::EFFORTS, true ) ) {
						$levels[] = $effort;
					}
				}
				$levels  = array_values( array_unique( $levels ) ) ?: $fallback['levels'];
				$default = sanitize_key( (string) ( $remote['default_reasoning_level'] ?? '' ) );
				if ( ! in_array( $default, $levels, true ) ) {
					$default = $fallback['default'];
				}
				$models[ $slug ] = [
					'label'   => sanitize_text_field( (string) ( $remote['display_name'] ?? $fallback['label'] ) ),
					'levels'  => $levels,
					'default' => $default,
				];
			}
			$cache = [
				'account'            => $this->account_hash( $tokens ),
				'fetched_at'         => time(),
				'attempted_at'       => time(),
				'error'              => '',
				'reconnect_required' => false,
				'models'             => $models,
			];
			update_option( self::OPTION, $cache, false );
			return $this->state();
		} finally {
			$lock->release( $owner );
		}
	}

	public function lock(): ChatGPT_Option_Lock {
		return new ChatGPT_Option_Lock( self::LOCK_OPTION, 45 ); }

	public function delete(): void {
		delete_option( self::OPTION ); }

	/** @return array<string, array<string, mixed>> */
	public function verified_models(): array {
		return $this->connected_account_matches() ? (array) $this->state()['models'] : [];
	}

	/** @param array<string, mixed> $tokens @return \WP_Error */
	private function record_error( string $message, array $tokens, bool $reconnect = false ): \WP_Error {
		$previous                    = get_option( self::OPTION, [] );
		$cache                       = is_array( $previous ) && hash_equals( (string) ( $previous['account'] ?? '' ), $this->account_hash( $tokens ) ) ? $previous : [
			'account'    => $this->account_hash( $tokens ),
			'fetched_at' => 0,
			'models'     => [],
		];
		$cache['attempted_at']       = time();
		$cache['error']              = $message;
		$cache['reconnect_required'] = $reconnect;
		update_option( self::OPTION, $cache, false );
		return new \WP_Error(
			'chatgpt_models_failed',
			$message,
			[
				'status'             => 502,
				'reconnect_required' => $reconnect,
			]
		);
	}

	/** @param array<string, mixed> $tokens */
	private function account_hash( array $tokens ): string {
		$identity = (string) ( $tokens['account_id'] ?? $tokens['refresh_token'] ?? $tokens['access_token'] ?? '' );
		return hash_hmac( 'sha256', $identity, wp_salt( 'auth' ) );
	}
}
