<?php

namespace WP_Autoplugin\V2\Infrastructure\AI;

use WP_Autoplugin\V2\Domain\AI\Direct_Transport;

/** Google Gemini generateContent transport for direct v2 tasks. */
final class Google_Direct_Transport implements Direct_Transport {
	public function __construct( private string $api_key, private string $selected_model ) {}
	public function provider(): string { return 'google'; }
	public function model(): string { return $this->selected_model; }
	public function effort(): string { return ''; }

	public function complete( string $instructions, string $input ) {
		$url      = 'https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode( $this->selected_model ) . ':generateContent?key=' . rawurlencode( $this->api_key );
		$response = wp_remote_post(
			$url,
			[
				'timeout' => 25,
				'headers' => [ 'Content-Type' => 'application/json' ],
				'body'    => wp_json_encode(
					[
						'systemInstruction' => [ 'parts' => [ [ 'text' => $instructions ] ] ],
						'contents'          => [ [ 'role' => 'user', 'parts' => [ [ 'text' => $input ] ] ] ],
						'generationConfig'  => [ 'temperature' => 0.2, 'maxOutputTokens' => 8192, 'responseMimeType' => 'application/json' ],
						'safetySettings'    => [ [ 'category' => 'HARM_CATEGORY_DANGEROUS_CONTENT', 'threshold' => 'BLOCK_ONLY_HIGH' ] ],
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
			$message = is_array( $data ) ? (string) ( $data['error']['message'] ?? __( 'The Google Gemini request failed.', 'wp-autoplugin' ) ) : __( 'The Google Gemini request failed.', 'wp-autoplugin' );
			return new \WP_Error( 'direct_provider_http', $message, [ 'status' => $status ] );
		}

		$content = '';
		foreach ( (array) ( $data['candidates'][0]['content']['parts'] ?? [] ) as $part ) {
			if ( is_string( $part['text'] ?? null ) ) {
				$content .= $part['text'];
			}
		}
		if ( '' === trim( $content ) ) {
			return new \WP_Error( 'direct_provider_empty', __( 'Google Gemini returned an empty Plan response.', 'wp-autoplugin' ) );
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
