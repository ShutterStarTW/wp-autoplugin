<?php
/**
 * Built-in provider API-key connection testing.
 *
 * @package WP_Autoplugin
 */

namespace WP_Autoplugin\V2\Infrastructure\AI;

/** Tests built-in provider API keys without persisting or returning them. */
final class API_Key_Tester {
	/**
	 * Test a built-in provider key against its model-list endpoint.
	 *
	 * @param string $provider Provider identifier.
	 * @param string $api_key  API key to test.
	 * @return true|\WP_Error
	 */
	public function test( string $provider, string $api_key ) {
		$provider = sanitize_key( $provider );
		$api_key  = preg_replace( '/[\x00-\x1F\x7F]/', '', trim( $api_key ) ) ?? '';

		$validation = $this->validate_key_format( $provider, $api_key );
		if ( is_wp_error( $validation ) ) {
			return $validation;
		}

		$url     = '';
		$headers = [ 'Accept' => 'application/json' ];
		switch ( $provider ) {
			case 'openai':
				$url                      = 'https://api.openai.com/v1/models';
				$headers['Authorization'] = 'Bearer ' . $api_key;
				break;
			case 'anthropic':
				$url                          = 'https://api.anthropic.com/v1/models';
				$headers['x-api-key']         = $api_key;
				$headers['anthropic-version'] = '2023-06-01';
				break;
			case 'google':
				$url = add_query_arg( 'key', $api_key, 'https://generativelanguage.googleapis.com/v1beta/models' );
				break;
			case 'xai':
				$url                      = 'https://api.x.ai/v1/models';
				$headers['Authorization'] = 'Bearer ' . $api_key;
				break;
			default:
				return new \WP_Error( 'wp_autoplugin_api_key_provider_invalid', __( 'The selected API provider is invalid.', 'wp-autoplugin' ), [ 'status' => 400 ] );
		}

		$response = wp_safe_remote_get(
			$url,
			[
				'timeout'             => 15,
				'redirection'         => 0,
				'limit_response_size' => 1024,
				'headers'             => $headers,
			]
		);
		if ( is_wp_error( $response ) ) {
			return new \WP_Error( 'wp_autoplugin_api_key_connection_failed', __( 'WP-Autoplugin could not connect to the API provider.', 'wp-autoplugin' ), [ 'status' => 502 ] );
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 === $status ) {
			return true;
		}

		if ( in_array( $status, [ 401, 403 ], true ) ) {
			return new \WP_Error( 'wp_autoplugin_api_key_rejected', __( 'The API provider rejected this key.', 'wp-autoplugin' ), [ 'status' => 400 ] );
		}

		return new \WP_Error(
			'wp_autoplugin_api_key_test_failed',
			sprintf(
				/* translators: %d: HTTP response status code. */
				__( 'The API provider could not validate this key (HTTP %d).', 'wp-autoplugin' ),
				$status
			),
			[ 'status' => 502 ]
		);
	}

	/**
	 * Reject empty or obviously malformed keys before making a request.
	 *
	 * @param string $provider Provider identifier.
	 * @param string $api_key  API key to validate.
	 * @return true|\WP_Error
	 */
	private function validate_key_format( string $provider, string $api_key ) {
		if ( '' === $api_key ) {
			return new \WP_Error( 'wp_autoplugin_api_key_empty', __( 'Enter an API key before testing it.', 'wp-autoplugin' ), [ 'status' => 400 ] );
		}
		if ( strlen( $api_key ) > 4096 ) {
			return new \WP_Error( 'wp_autoplugin_api_key_format_invalid', __( 'The API key is too long.', 'wp-autoplugin' ), [ 'status' => 400 ] );
		}

		switch ( $provider ) {
			case 'openai':
				if ( ! str_starts_with( $api_key, 'sk-' ) ) {
					return new \WP_Error( 'wp_autoplugin_api_key_format_invalid', __( 'OpenAI API keys should start with "sk-".', 'wp-autoplugin' ), [ 'status' => 400 ] );
				}
				break;
			case 'anthropic':
				if ( ! str_starts_with( $api_key, 'sk-ant-' ) ) {
					return new \WP_Error( 'wp_autoplugin_api_key_format_invalid', __( 'Anthropic API keys should start with "sk-ant-".', 'wp-autoplugin' ), [ 'status' => 400 ] );
				}
				break;
			case 'google':
				if ( strlen( $api_key ) < 20 ) {
					return new \WP_Error( 'wp_autoplugin_api_key_format_invalid', __( 'The Google API key appears to be too short.', 'wp-autoplugin' ), [ 'status' => 400 ] );
				}
				break;
			case 'xai':
				if ( ! str_starts_with( $api_key, 'xai-' ) ) {
					return new \WP_Error( 'wp_autoplugin_api_key_format_invalid', __( 'xAI API keys should start with "xai-".', 'wp-autoplugin' ), [ 'status' => 400 ] );
				}
				break;
			default:
				return new \WP_Error( 'wp_autoplugin_api_key_provider_invalid', __( 'The selected API provider is invalid.', 'wp-autoplugin' ), [ 'status' => 400 ] );
		}

		return true;
	}
}
