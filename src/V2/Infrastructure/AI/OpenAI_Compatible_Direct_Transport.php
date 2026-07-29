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

	public function provider(): string {
		return $this->selected_provider; }
	public function model(): string {
		return $this->selected_model; }
	public function effort(): string {
		return ''; }

	public function complete( string $instructions, string $input, array $options = [] ) {
		$user_content = $input;
		$has_images   = ! empty( $options['prompt_images'] );
		$max_tokens   = 'xai' === $this->selected_provider
			? null
			: max( 1, (int) ( $options['max_output_tokens'] ?? 8192 ) );
		if ( $has_images ) {
			$user_content = [];
			foreach ( (array) $options['prompt_images'] as $image ) {
				if ( empty( $image['content'] ) || empty( $image['mime_type'] ) ) {
					continue;
				}
				$user_content[] = [
					'type'      => 'image_url',
					'image_url' => [
						'url'    => 'data:' . $image['mime_type'] . ';base64,' . base64_encode( (string) $image['content'] ),
						'detail' => 'auto',
					],
				]; // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Provider-required private image data URI.
			}
			if ( '' !== $input ) {
				$user_content[] = [
					'type' => 'text',
					'text' => $input,
				];
			}
		}
		$response = wp_remote_post(
			$this->endpoint,
			[
				'timeout' => Direct_Transport::REQUEST_TIMEOUT,
				'headers' => array_merge(
					[
						'Authorization' => 'Bearer ' . $this->api_key,
						'Content-Type'  => 'application/json',
					],
					$this->extra_headers
				),
				'body'    => wp_json_encode(
					array_filter(
						[
							'model'           => $this->selected_model,
							'messages'        => [
								[
									'role'    => 'system',
									'content' => $instructions,
								],
								[
									'role'    => 'user',
									'content' => $user_content,
								],
							],
							'temperature'     => 0.2,
							'max_tokens'      => $max_tokens,
							'response_format' => ! empty( $options['json'] ) ? [ 'type' => 'json_object' ] : null,
						],
						static fn( $value ): bool => null !== $value
					)
				),
			]
		);
		if ( is_wp_error( $response ) ) {
			$raw_message = $response->get_error_message();
			$message     = $has_images ? __( 'The image request to the selected provider failed.', 'wp-autoplugin' ) : $raw_message;
			$ambiguous   = false !== stripos( $raw_message, 'timed out' ) || false !== stripos( $raw_message, 'timeout' );
			return new \WP_Error(
				'direct_provider_network',
				$message,
				[
					'retryable' => ! $ambiguous,
					'ambiguous' => $ambiguous,
				]
			);
		}

		$status = wp_remote_retrieve_response_code( $response );
		$data   = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( $status < 200 || $status >= 300 || ! is_array( $data ) ) {
			$message = $has_images ? __( 'The image request to the selected provider failed.', 'wp-autoplugin' ) : ( is_array( $data ) ? (string) ( $data['error']['message'] ?? __( 'The direct provider request failed.', 'wp-autoplugin' ) ) : __( 'The direct provider request failed.', 'wp-autoplugin' ) );
			return new \WP_Error(
				'direct_provider_http',
				$message,
				[
					'status'    => $status,
					'retryable' => 429 === $status || $status >= 500,
					'ambiguous' => false,
				]
			);
		}

		$usage = [
			'input_tokens'  => (int) ( $data['usage']['prompt_tokens'] ?? $data['usage']['input_tokens'] ?? 0 ),
			'output_tokens' => (int) ( $data['usage']['completion_tokens'] ?? $data['usage']['output_tokens'] ?? 0 ),
		];
		if ( 'length' === ( $data['choices'][0]['finish_reason'] ?? '' ) ) {
			return new \WP_Error(
				'direct_response_incomplete',
				__( 'The model reached its output limit before completing the response.', 'wp-autoplugin' ),
				[
					'retryable'        => false,
					'ambiguous'        => false,
					'incomplete_reason' => 'max_tokens',
					'usage'            => $usage,
					'request_id'       => sanitize_text_field( (string) ( $data['id'] ?? '' ) ),
				]
			);
		}

		$content = $data['choices'][0]['message']['content'] ?? '';
		if ( ! is_string( $content ) || '' === trim( $content ) ) {
			return new \WP_Error(
				'direct_provider_empty',
				__( 'The provider returned an empty response.', 'wp-autoplugin' ),
				[
					'retryable' => true,
					'ambiguous' => false,
				]
			);
		}

		return [
			'type'       => 'final',
			'content'    => trim( $content ),
			'usage'      => $usage,
			'request_id' => (string) ( $data['id'] ?? '' ),
		];
	}
}
