<?php

namespace WP_Autoplugin\V2\Infrastructure\AI;

/** Coordinates the site-wide ChatGPT device authorization lifecycle. */
final class ChatGPT_OAuth_Service {
	private const POLL_LOCK_OPTION = '_wp_autoplugin_chatgpt_oauth_poll_lock';

	public function __construct(
		private ?ChatGPT_OAuth_Client $client = null,
		private ?ChatGPT_Session_Store $sessions = null,
		private ?ChatGPT_Token_Manager $tokens = null,
		private ?ChatGPT_Model_Service $models = null,
		private ?ChatGPT_Token_Store $store = null
	) {
		$this->client   ??= new ChatGPT_OAuth_Client();
		$this->sessions ??= new ChatGPT_Session_Store();
		$this->tokens   ??= new ChatGPT_Token_Manager();
		$this->models   ??= new ChatGPT_Model_Service( $this->tokens );
		$this->store    ??= new ChatGPT_Token_Store();
	}

	/** @return array<string, mixed>|\WP_Error */
	public function start( int $user_id ) {
		if ( ! $this->store->available() ) {
			return new \WP_Error( 'chatgpt_oauth_encryption_unavailable', __( 'OpenSSL AES-256-GCM support is required to connect ChatGPT.', 'wp-autoplugin' ), [ 'status' => 500 ] );
		}
		$lock  = new ChatGPT_Option_Lock( self::POLL_LOCK_OPTION, 20 );
		$owner = $lock->acquire();
		if ( is_wp_error( $owner ) ) {
			return $owner;
		}
		try {
			$device = $this->client->start();
			return is_wp_error( $device ) ? $device : $this->sessions->create( $user_id, $device );
		} finally {
			$lock->release( $owner );
		}
	}

	/** @return array<string, mixed>|\WP_Error */
	public function poll( int $user_id, string $session_id ) {
		$poll_lock  = new ChatGPT_Option_Lock( self::POLL_LOCK_OPTION, 20 );
		$poll_owner = $poll_lock->acquire();
		if ( is_wp_error( $poll_owner ) ) {
			return $poll_owner;
		}

		try {
			$session = $this->sessions->begin_poll( $user_id, $session_id );
			if ( is_wp_error( $session ) ) {
				return $session;
			}
			$result = $this->client->poll( (string) $session['device_auth_id'], (string) $session['user_code'] );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
			if ( 'pending' === (string) ( $result['status'] ?? '' ) ) {
				return [
					'status'      => 'pending',
					'session_id'  => $session_id,
					'retry_after' => max( 1, (int) $session['interval'] ),
				];
			}

			$active = $this->sessions->assert_active( $user_id, $session_id );
			if ( is_wp_error( $active ) ) {
				return $active;
			}
			$this->sessions->mark( $user_id, $session_id, 'exchanging' );

			$credential_lock  = $this->tokens->lock();
			$credential_owner = $credential_lock->acquire();
			if ( is_wp_error( $credential_owner ) ) {
				$this->sessions->mark( $user_id, $session_id, 'pending' );
				return $credential_owner;
			}
			try {
				$active = $this->sessions->assert_active( $user_id, $session_id, [ 'exchanging' ] );
				if ( is_wp_error( $active ) ) {
					return $active;
				}
				$approved = $this->client->exchange( (string) $result['authorization_code'], (string) $result['code_verifier'] );
				if ( is_wp_error( $approved ) ) {
					$this->sessions->mark( $user_id, $session_id, 'error' );
					return $approved;
				}
				$active = $this->sessions->assert_active( $user_id, $session_id, [ 'exchanging' ] );
				if ( is_wp_error( $active ) ) {
					return $active;
				}
				$saved = $this->tokens->save( $approved );
				if ( is_wp_error( $saved ) ) {
					$this->sessions->mark( $user_id, $session_id, 'error' );
					return $saved;
				}
				$this->models->delete();
				$model_state = $this->models->refresh( $approved );
				$this->sessions->mark( $user_id, $session_id, 'approved' );
				return [
					'status'        => 'approved',
					'connected'     => true,
					'account_label' => sanitize_text_field( (string) ( $approved['account_label'] ?? '' ) ),
					'models'        => is_wp_error( $model_state ) ? $this->models->state() : $model_state,
					'notice'        => is_wp_error( $model_state ) ? $model_state->get_error_message() : '',
				];
			} finally {
				$credential_lock->release( $credential_owner );
			}
		} finally {
			$poll_lock->release( $poll_owner );
		}
	}

	/** @return array<string, mixed>|\WP_Error */
	public function cancel( int $user_id, string $session_id ) {
		$lock  = new ChatGPT_Option_Lock( self::POLL_LOCK_OPTION, 20 );
		$owner = $lock->acquire();
		if ( is_wp_error( $owner ) ) {
			return $owner;
		}
		try {
			return $this->sessions->cancel( $user_id, $session_id );
		} finally {
			$lock->release( $owner );
		}
	}

	/** @return array<string, mixed>|\WP_Error */
	public function disconnect() {
		$lock  = $this->tokens->lock();
		$owner = $lock->acquire();
		if ( is_wp_error( $owner ) ) {
			return $owner;
		}
		try {
			$model_lock  = $this->models->lock();
			$model_owner = $model_lock->acquire();
			if ( is_wp_error( $model_owner ) ) {
				return $model_owner;
			}
			try {
				$this->tokens->delete();
				$this->models->delete();
				$this->sessions->clear();
				return [
					'connected' => false,
					'status'    => 'disconnected',
				];
			} finally {
				$model_lock->release( $model_owner );
			}
		} finally {
			$lock->release( $owner );
		}
	}

	/** @return array<string, mixed>|\WP_Error */
	public function refresh_models() {
		return $this->models->refresh();
	}

	/** @return array<string, mixed> */
	public function status( int $user_id, bool $refresh_stale = false ): array {
		$stored      = $this->tokens->stored();
		$connected   = is_array( $stored );
		$error       = is_wp_error( $stored ) ? $stored->get_error_message() : '';
		$error_data  = is_wp_error( $stored ) ? (array) $stored->get_error_data() : [];
		$reconnect   = ! empty( $error_data['reconnect_required'] );
		$model_state = $this->models->state();
		if ( $connected && $refresh_stale && ! empty( $model_state['stale'] ) ) {
			$refreshed   = $this->models->refresh();
			$model_state = is_wp_error( $refreshed ) ? $this->models->state() : $refreshed;
		}
		$reconnect = $reconnect || ! empty( $model_state['reconnect_required'] );
		return [
			'provider'             => 'chatgpt',
			'experimental'         => true,
			'encryption_available' => $this->store->available(),
			'connected'            => $connected,
			'account_label'        => $connected ? sanitize_text_field( (string) ( $stored['account_label'] ?? __( 'OpenAI account', 'wp-autoplugin' ) ) ) : '',
			'reconnect_required'   => $reconnect,
			'error'                => $error,
			'session'              => $this->sessions->status( $user_id ),
			'models'               => $model_state,
		];
	}
}
