<?php

namespace WP_Autoplugin\V2\Infrastructure\AI;

/** Stores the one active, short-lived site-wide device authorization session. */
final class ChatGPT_Session_Store {
	public const TRANSIENT = 'wp_autoplugin_chatgpt_oauth_session';
	public const TTL       = 900;

	/** @param array<string, mixed> $device @return array<string, mixed>|\WP_Error */
	public function create( int $user_id, array $device ) {
		if ( $user_id < 1 || ! ChatGPT_Config::is_verification_url( (string) ( $device['verification_url'] ?? '' ) ) ) {
			return new \WP_Error( 'chatgpt_oauth_session_invalid', __( 'The device authorization session could not be stored.', 'wp-autoplugin' ), [ 'status' => 502 ] );
		}
		$now = time();
		try {
			$session_id = bin2hex( random_bytes( 24 ) );
		} catch ( \Throwable $error ) {
			return new \WP_Error( 'chatgpt_oauth_session_invalid', __( 'Secure random data is unavailable for device authorization.', 'wp-autoplugin' ), [ 'status' => 500 ] );
		}
		$session = [
			'session_id'       => $session_id,
			'user_id'          => $user_id,
			'status'           => 'pending',
			'device_auth_id'   => (string) $device['device_auth_id'],
			'user_code'        => (string) $device['user_code'],
			'verification_url' => (string) $device['verification_url'],
			'interval'         => (int) $device['interval'],
			'expires_at'       => $now + self::TTL,
			'next_poll_at'     => $now + (int) $device['interval'],
			'cancelled'        => false,
		];
		set_transient( self::TRANSIENT, $session, self::TTL );
		return $this->safe( $session );
	}

	/** @return array<string, mixed>|\WP_Error */
	public function begin_poll( int $user_id, string $session_id ) {
		$session = $this->owned( $user_id, $session_id );
		if ( is_wp_error( $session ) ) {
			return $session;
		}
		if ( 'pending' !== $session['status'] || ! empty( $session['cancelled'] ) ) {
			return new \WP_Error( 'chatgpt_oauth_session_state', __( 'The device authorization session is no longer pending.', 'wp-autoplugin' ), [ 'status' => 409 ] );
		}
		if ( (int) $session['next_poll_at'] > time() ) {
			return new \WP_Error(
				'chatgpt_oauth_poll_early',
				__( 'Wait before checking authorization again.', 'wp-autoplugin' ),
				[
					'status'      => 429,
					'retry_after' => (int) $session['next_poll_at'] - time(),
				]
			);
		}
		$session['next_poll_at'] = time() + (int) $session['interval'];
		$this->save( $session );
		return $session;
	}

	/** @param array<int, string> $statuses @return array<string, mixed>|\WP_Error */
	public function assert_active( int $user_id, string $session_id, array $statuses = [ 'pending' ] ) {
		$session = $this->owned( $user_id, $session_id );
		if ( is_wp_error( $session ) || ! empty( $session['cancelled'] ) || ! in_array( $session['status'], $statuses, true ) ) {
			return is_wp_error( $session ) ? $session : new \WP_Error( 'chatgpt_oauth_session_state', __( 'The device authorization session changed before completion.', 'wp-autoplugin' ), [ 'status' => 409 ] );
		}
		return $session;
	}

	public function mark( int $user_id, string $session_id, string $status ): bool {
		$session = $this->owned( $user_id, $session_id );
		if ( is_wp_error( $session ) ) {
			return false;
		}
		$session['status'] = $status;
		if ( in_array( $status, [ 'approved', 'cancelled', 'error' ], true ) ) {
			$session['device_auth_id'] = '';
			$session['user_code']      = '';
		}
		$session['cancelled'] = 'cancelled' === $status;
		$this->save( $session );
		return true;
	}

	/** @return array<string, mixed>|\WP_Error */
	public function cancel( int $user_id, string $session_id ) {
		return $this->mark( $user_id, $session_id, 'cancelled' ) ? [
			'status'     => 'cancelled',
			'session_id' => $session_id,
		] : new \WP_Error( 'chatgpt_oauth_session_missing', __( 'The device authorization session is unavailable.', 'wp-autoplugin' ), [ 'status' => 404 ] );
	}

	public function clear(): void {
		delete_transient( self::TRANSIENT ); }

	/** @return array<string, mixed>|null */
	public function status( int $user_id ): ?array {
		$session = get_transient( self::TRANSIENT );
		return is_array( $session ) && (int) ( $session['user_id'] ?? 0 ) === $user_id && (int) ( $session['expires_at'] ?? 0 ) > time() ? $this->safe( $session ) : null;
	}

	/** @return array<string, mixed>|\WP_Error */
	private function owned( int $user_id, string $session_id ) {
		$session = get_transient( self::TRANSIENT );
		if ( ! is_array( $session ) || (int) ( $session['expires_at'] ?? 0 ) <= time() ) {
			$this->clear();
			return new \WP_Error( 'chatgpt_oauth_session_missing', __( 'The device authorization session expired or is unavailable.', 'wp-autoplugin' ), [ 'status' => 410 ] );
		}
		if ( (int) ( $session['user_id'] ?? 0 ) !== $user_id || ! is_string( $session['session_id'] ?? null ) || ! hash_equals( $session['session_id'], $session_id ) ) {
			return new \WP_Error( 'chatgpt_oauth_session_forbidden', __( 'The device authorization session does not belong to this administrator.', 'wp-autoplugin' ), [ 'status' => 403 ] );
		}
		return $session;
	}

	/** @param array<string, mixed> $session */
	private function save( array $session ): void {
		set_transient( self::TRANSIENT, $session, max( 1, (int) $session['expires_at'] - time() ) ); }

	/** @param array<string, mixed> $session @return array<string, mixed> */
	private function safe( array $session ): array {
		return [
			'session_id'       => (string) $session['session_id'],
			'status'           => (string) $session['status'],
			'user_code'        => (string) $session['user_code'],
			'verification_url' => (string) $session['verification_url'],
			'interval'         => (int) $session['interval'],
			'expires_at'       => (int) $session['expires_at'],
			'retry_after'      => max( 0, (int) $session['next_poll_at'] - time() ),
		];
	}
}
