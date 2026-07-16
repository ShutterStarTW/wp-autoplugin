<?php

namespace WP_Autoplugin\V2\Infrastructure\AI;

use WP_Autoplugin\V2\Domain\AI\Direct_Transport;

/** Direct transport for xAI and custom OpenAI-compatible chat endpoints. */
final class OpenAI_Compatible_Direct_Transport implements Direct_Transport {
	/** @param array<string, string> $extra_headers */
	public function __construct(
		private string $selected_provider,
		private string $endpoint,
		private string $api_key,
		private string $selected_model,
		private array $extra_headers = []
	) {}

	public function provider(): string { return $this->selected_provider; }
	public function model(): string { return $this->selected_model; }
	public function effort(): string { return ''; }

	public function complete( string $instructions, string $input ) {
		$response = wp_remote_post(
			$this->endpoint,
			[
				'timeout' => 25,
				'headers' => array_merge(
					[ 'Authorization' => 'Bearer ' . $this->api_key, 'Content-Type' => 'application/json' ],
					$this->extra_headers
				),
				'body'    => wp_json_encode(
					[
						'model'       => $this->selected_model,
						'messages'    => [
							[ 'role' => 'system', 'content' => $instructions ],
							[ 'role' => 'user', 'content' => $input ],
						],
						'temperature' => 0.2,
						'max_tokens'  => 8192,
					]
				),
			]
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$status = wp_remote_retrieve_response_code( $response );
		$data   = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( $status < 200 || $status >= 300 || ! is_array( $data ) ) {
			$message = is_array( $data ) ? (string) ( $data['error']['message'] ?? __( 'The direct provider request failed.', 'wp-autoplugin' ) ) : __( 'The direct provider request failed.', 'wp-autoplugin' );
			return new \WP_Error( 'direct_provider_http', $message, [ 'status' => $status ] );
		}

		$content = $data['choices'][0]['message']['content'] ?? '';
		if ( ! is_string( $content ) || '' === trim( $content ) ) {
			return new \WP_Error( 'direct_provider_empty', __( 'The provider returned an empty Plan response.', 'wp-autoplugin' ) );
		}

		return [
			'type'       => 'final',
			'content'    => trim( $content ),
			'usage'      => [
				'input_tokens'  => (int) ( $data['usage']['prompt_tokens'] ?? $data['usage']['input_tokens'] ?? 0 ),
				'output_tokens' => (int) ( $data['usage']['completion_tokens'] ?? $data['usage']['output_tokens'] ?? 0 ),
			],
			'request_id' => (string) ( $data['id'] ?? '' ),
		];
	}
}
