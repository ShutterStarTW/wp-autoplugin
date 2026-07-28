<?php

namespace WP_Autoplugin\V2\Infrastructure\AI;

/** WordPress HTTP client for OpenAI's Codex device authorization flow. */
final class ChatGPT_OAuth_Client {
	/** @return array<string, mixed>|\WP_Error */
	public function start() {
		$response = $this->post_json( ChatGPT_Config::DEVICE_START_URL, [ 'client_id' => ChatGPT_Config::CLIENT_ID ] );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		if ( 200 !== $response['status'] ) {
			return $this->status_error( 'chatgpt_oauth_start_failed', __( 'OpenAI rejected the device authorization request.', 'wp-autoplugin' ), $response );
		}
		$data = $this->decode( $response );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		$user_code = $this->bounded( $data['user_code'] ?? null, 128 );
		$device_id = $this->bounded( $data['device_auth_id'] ?? null, 1024 );
		$url       = ChatGPT_Config::VERIFICATION_URL;
		$candidate = $this->bounded( $data['verification_uri_complete'] ?? $data['verification_uri'] ?? $data['verification_url'] ?? null, 2048 );
		if ( '' !== $candidate && ChatGPT_Config::is_verification_url( $candidate ) ) {
			$url = $candidate;
		}
		if ( '' === $user_code || '' === $device_id ) {
			return new \WP_Error( 'chatgpt_oauth_start_invalid', __( 'OpenAI returned an incomplete device authorization response.', 'wp-autoplugin' ), [ 'status' => 502 ] );
		}
		return [
			'device_auth_id'   => $device_id,
			'user_code'        => $user_code,
			'verification_url' => $url,
			'interval'         => max( 3, min( 30, (int) ( $data['interval'] ?? 5 ) ) ),
		];
	}

	/** @return array<string, mixed>|\WP_Error */
	public function poll( string $device_id, string $user_code ) {
		$response = $this->post_json(
			ChatGPT_Config::DEVICE_POLL_URL,
			[
				'device_auth_id' => $device_id,
				'user_code'      => $user_code,
			]
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		if ( in_array( $response['status'], [ 202, 403, 404 ], true ) ) {
			return [ 'status' => 'pending' ];
		}
		if ( 200 !== $response['status'] ) {
			return $this->status_error( 'chatgpt_oauth_poll_failed', __( 'OpenAI rejected the device authorization check.', 'wp-autoplugin' ), $response );
		}
		$data = $this->decode( $response );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		$code     = $this->bounded( $data['authorization_code'] ?? null, 4096 );
		$verifier = $this->bounded( $data['code_verifier'] ?? null, 4096 );
		return '' !== $code && '' !== $verifier
			? [
				'status'             => 'authorized',
				'authorization_code' => $code,
				'code_verifier'      => $verifier,
			]
			: new \WP_Error( 'chatgpt_oauth_poll_invalid', __( 'OpenAI returned an incomplete authorization approval.', 'wp-autoplugin' ), [ 'status' => 502 ] );
	}

	/** @return array<string, mixed>|\WP_Error */
	public function exchange( string $code, string $verifier ) {
		return $this->token_request(
			[
				'grant_type'    => 'authorization_code',
				'code'          => $code,
				'redirect_uri'  => ChatGPT_Config::REDIRECT_URL,
				'client_id'     => ChatGPT_Config::CLIENT_ID,
				'code_verifier' => $verifier,
			]
		);
	}

	/** @return array<string, mixed>|\WP_Error */
	public function refresh( string $refresh_token ) {
		return $this->token_request(
			[
				'grant_type'    => 'refresh_token',
				'refresh_token' => $refresh_token,
				'client_id'     => ChatGPT_Config::CLIENT_ID,
			],
			$refresh_token
		);
	}

	/** @param array<string, string> $fields @return array<string, mixed>|\WP_Error */
	private function token_request( array $fields, string $existing_refresh = '' ) {
		$response = $this->post_form( ChatGPT_Config::TOKEN_URL, $fields );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		if ( 200 !== $response['status'] ) {
			$reconnect = in_array( $response['status'], [ 400, 401, 403 ], true );
			return $this->status_error( 'chatgpt_oauth_token_failed', $reconnect ? __( 'The ChatGPT connection expired. Reconnect the account.', 'wp-autoplugin' ) : __( 'OpenAI rejected the OAuth token request.', 'wp-autoplugin' ), $response, $reconnect );
		}
		$data = $this->decode( $response );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		$access  = $this->bounded( $data['access_token'] ?? null, 32768 );
		$refresh = $this->bounded( $data['refresh_token'] ?? null, 32768 ) ?: $existing_refresh;
		if ( '' === $access || '' === $refresh ) {
			return new \WP_Error( 'chatgpt_oauth_token_invalid', __( 'OpenAI did not return a usable token pair.', 'wp-autoplugin' ), [ 'status' => 502 ] );
		}
		$claims  = self::claims( $access );
		$expires = isset( $claims['exp'] ) && is_numeric( $claims['exp'] ) ? (int) $claims['exp'] : time() + max( 60, (int) ( $data['expires_in'] ?? 3600 ) );
		$auth    = is_array( $claims['https://api.openai.com/auth'] ?? null ) ? $claims['https://api.openai.com/auth'] : [];
		$profile = is_array( $claims['https://api.openai.com/profile'] ?? null ) ? $claims['https://api.openai.com/profile'] : [];
		return [
			'access_token'  => $access,
			'refresh_token' => $refresh,
			'expires_at'    => $expires,
			'obtained_at'   => time(),
			'account_id'    => $this->bounded( $auth['chatgpt_account_id'] ?? null, 512 ),
			'account_label' => $this->bounded( $profile['email'] ?? $claims['email'] ?? null, 320 ) ?: __( 'OpenAI account', 'wp-autoplugin' ),
		];
	}

	/** @return array<string, mixed> */
	public static function claims( string $token ): array {
		$parts = explode( '.', $token );
		if ( count( $parts ) < 2 ) {
			return [];
		}
		$encoded  = strtr( $parts[1], '-_', '+/' );
		$encoded .= str_repeat( '=', ( 4 - strlen( $encoded ) % 4 ) % 4 );
		$data     = json_decode( (string) base64_decode( $encoded, true ), true );
		return is_array( $data ) ? $data : [];
	}

	/** @param array<string, string> $body @return array<string, mixed>|\WP_Error */
	private function post_json( string $url, array $body ) {
		return $this->post(
			$url,
			[
				'Accept'       => 'application/json',
				'Content-Type' => 'application/json',
			],
			(string) wp_json_encode( $body )
		);
	}

	/** @param array<string, string> $body @return array<string, mixed>|\WP_Error */
	private function post_form( string $url, array $body ) {
		return $this->post(
			$url,
			[
				'Accept'       => 'application/json',
				'Content-Type' => 'application/x-www-form-urlencoded',
			],
			http_build_query( $body, '', '&', PHP_QUERY_RFC3986 )
		);
	}

	/** @param array<string, string> $headers @return array<string, mixed>|\WP_Error */
	private function post( string $url, array $headers, string $body ) {
		$response = wp_safe_remote_post(
			$url,
			[
				'timeout'     => 15,
				'redirection' => 0,
				'headers'     => $headers,
				'body'        => $body,
			]
		);
		if ( is_wp_error( $response ) ) {
			return new \WP_Error(
				'chatgpt_oauth_network',
				__( 'The OpenAI authentication server could not be reached.', 'wp-autoplugin' ),
				[
					'status'    => 502,
					'retryable' => true,
				]
			);
		}
		return [
			'status'      => (int) wp_remote_retrieve_response_code( $response ),
			'body'        => (string) wp_remote_retrieve_body( $response ),
			'retry_after' => wp_remote_retrieve_header( $response, 'retry-after' ),
		];
	}

	/** @param array<string, mixed> $response @return array<string, mixed>|\WP_Error */
	private function decode( array $response ) {
		$data = json_decode( (string) $response['body'], true );
		return is_array( $data ) ? $data : new \WP_Error(
			'chatgpt_oauth_json',
			__( 'OpenAI returned an invalid authentication response.', 'wp-autoplugin' ),
			[
				'status'    => 502,
				'retryable' => true,
			]
		);
	}

	/** @param array<string, mixed> $response */
	private function status_error( string $code, string $message, array $response, bool $reconnect = false ): \WP_Error {
		$status = (int) $response['status'];
		$data   = [
			'status'             => 429 === $status ? 429 : 502,
			'upstream_status'    => $status,
			'retryable'          => 429 === $status || $status >= 500,
			'reconnect_required' => $reconnect,
		];
		if ( 429 === $status ) {
			$data['retry_after'] = max( 1, min( 300, is_numeric( $response['retry_after'] ) ? (int) $response['retry_after'] : 60 ) );
		}
		return new \WP_Error( $code, $message, $data );
	}

	private function bounded( $value, int $max ): string {
		return is_string( $value ) && strlen( trim( $value ) ) <= $max ? trim( $value ) : '';
	}
}
