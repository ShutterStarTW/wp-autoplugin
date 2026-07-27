<?php

namespace WP_Autoplugin\V2\Infrastructure\AI;

use WP_Autoplugin\V2\Domain\AI\Direct_Transport;

/** Google Gemini generateContent transport for direct v2 tasks. */
final class Google_Direct_Transport implements Direct_Transport {
	public function __construct( private string $api_key, private string $selected_model ) {}
	public function provider(): string { return 'google'; }
	public function model(): string { return $this->selected_model; }
	public function effort(): string { return ''; }

	public function complete( string $instructions, string $input, array $options = [] ) {
		$url        = 'https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode( $this->selected_model ) . ':generateContent?key=' . rawurlencode( $this->api_key );
		$parts      = [];
		$has_images = ! empty( $options['prompt_images'] );
		foreach ( (array) ( $options['prompt_images'] ?? [] ) as $image ) {
			if ( empty( $image['content'] ) || empty( $image['mime_type'] ) ) {
				continue;
			}
			$parts[] = [ 'inline_data' => [ 'mime_type' => (string) $image['mime_type'], 'data' => base64_encode( (string) $image['content'] ) ] ]; // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Provider-required private inline data.
		}
		if ( '' !== $input ) {
			$parts[] = [ 'text' => $input ];
		}
		$generation_config = [
			'maxOutputTokens'  => min( 16384, max( 1, (int) ( $options['max_output_tokens'] ?? 8192 ) ) ),
			'responseMimeType' => 'application/json',
		];
		if ( ! in_array( $this->selected_model, [ 'gemini-3.6-flash', 'gemini-3.5-flash-lite' ], true ) ) {
			$generation_config['temperature'] = 0.2;
		}
		$response = wp_remote_post(
			$url,
			[
				'timeout' => Direct_Transport::REQUEST_TIMEOUT,
				'headers' => [ 'Content-Type' => 'application/json' ],
				'body'    => wp_json_encode(
					[
						'systemInstruction' => [ 'parts' => [ [ 'text' => $instructions ] ] ],
						'contents'          => [ [ 'role' => 'user', 'parts' => $parts ] ],
						'generationConfig'  => $generation_config,
						'safetySettings'    => [ [ 'category' => 'HARM_CATEGORY_DANGEROUS_CONTENT', 'threshold' => 'BLOCK_ONLY_HIGH' ] ],
					]
				),
			]
		);
		if ( is_wp_error( $response ) ) {
			$raw_message = $response->get_error_message();
			$message     = $has_images ? __( 'The Google Gemini image request failed.', 'wp-autoplugin' ) : $raw_message;
			$ambiguous   = false !== stripos( $raw_message, 'timed out' ) || false !== stripos( $raw_message, 'timeout' );
			return new \WP_Error( 'direct_provider_network', $message, [ 'retryable' => ! $ambiguous, 'ambiguous' => $ambiguous ] );
		}

		$status = wp_remote_retrieve_response_code( $response );
		$data   = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( $status < 200 || $status >= 300 || ! is_array( $data ) ) {
			$message = $has_images ? __( 'The Google Gemini image request failed.', 'wp-autoplugin' ) : ( is_array( $data ) ? (string) ( $data['error']['message'] ?? __( 'The Google Gemini request failed.', 'wp-autoplugin' ) ) : __( 'The Google Gemini request failed.', 'wp-autoplugin' ) );
			return new \WP_Error( 'direct_provider_http', $message, [ 'status' => $status, 'retryable' => 429 === $status || $status >= 500, 'ambiguous' => false ] );
		}

		$content = '';
		foreach ( (array) ( $data['candidates'][0]['content']['parts'] ?? [] ) as $part ) {
			if ( is_string( $part['text'] ?? null ) ) {
				$content .= $part['text'];
			}
		}
		if ( '' === trim( $content ) ) {
			return new \WP_Error( 'direct_provider_empty', __( 'Google Gemini returned an empty response.', 'wp-autoplugin' ), [ 'retryable' => true, 'ambiguous' => false ] );
		}

		return [
			'type'       => 'final',
			'content'    => trim( $content ),
			'usage'      => [
				'input_tokens'  => (int) ( $data['usageMetadata']['promptTokenCount'] ?? 0 ),
				'output_tokens' => (int) ( $data['usageMetadata']['candidatesTokenCount'] ?? 0 ),
			],
			'request_id' => '',
		];
	}
}
