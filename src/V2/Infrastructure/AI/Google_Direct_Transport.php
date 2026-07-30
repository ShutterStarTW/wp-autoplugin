<?php

namespace WP_Autoplugin\V2\Infrastructure\AI;

use WP_Autoplugin\V2\Domain\AI\Agent_Transport;
use WP_Autoplugin\V2\Domain\AI\Direct_Transport;
use WP_Autoplugin\V2\Domain\AI\Model_Output_Limits;

/** Google Gemini generateContent transport for direct and native tool-use tasks. */
final class Google_Direct_Transport implements Agent_Transport, Direct_Transport {
	public function __construct( private string $api_key, private string $selected_model ) {}
	public function provider(): string {
		return 'google'; }
	public function model(): string {
		return $this->selected_model; }
	public function effort(): string {
		return ''; }

	public function complete( string $instructions, string $input, array $options = [] ) {
		$options['json'] = true;
		return $this->send(
			$instructions,
			[
				[
					'role'          => 'user',
					'content'       => $input,
					'prompt_images' => (array) ( $options['prompt_images'] ?? [] ),
				],
			],
			[],
			$options
		);
	}

	public function send( string $instructions, array $transcript, array $tools, array $options = [] ) {
		$url               = 'https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode( $this->selected_model ) . ':generateContent?key=' . rawurlencode( $this->api_key );
		$has_images        = false;
		$contents          = $this->contents( $transcript, $has_images );
		$generation_config = [
			'maxOutputTokens' => Model_Output_Limits::request_limit( 'google', $this->selected_model, $options, 8192 ),
		];
		if ( ! empty( $options['json'] ) ) {
			$generation_config['responseMimeType'] = 'application/json';
		}
		if ( ! in_array( $this->selected_model, [ 'gemini-3.6-flash', 'gemini-3.5-flash-lite' ], true ) ) {
			$generation_config['temperature'] = 0.2;
		}
		$body = [
			'systemInstruction' => [ 'parts' => [ [ 'text' => $instructions ] ] ],
			'contents'          => $contents,
			'generationConfig'  => $generation_config,
			'safetySettings'    => [
				[
					'category'  => 'HARM_CATEGORY_DANGEROUS_CONTENT',
					'threshold' => 'BLOCK_ONLY_HIGH',
				],
			],
		];
		if ( $tools ) {
			$body['tools'] = [
				[
					'functionDeclarations' => array_map(
						static fn( array $tool ): array => [
							'name'        => (string) $tool['name'],
							'description' => (string) $tool['description'],
							'parameters'  => $tool['parameters'],
						],
						$tools
					),
				],
			];
			$body['toolConfig'] = [
				'functionCallingConfig' => [ 'mode' => 'AUTO' ],
			];
		}
		$response = wp_safe_remote_post(
			$url,
			[
				'timeout'             => Direct_Transport::REQUEST_TIMEOUT,
				'redirection'         => 0,
				'limit_response_size' => Direct_Transport::MAX_RESPONSE_BYTES,
				'headers'             => [ 'Content-Type' => 'application/json' ],
				'body'                => wp_json_encode( $body ),
			]
		);
		if ( is_wp_error( $response ) ) {
			$raw_message = $response->get_error_message();
			$message     = $has_images ? __( 'The Google Gemini image request failed.', 'wp-autoplugin' ) : __( 'The Google Gemini request could not be completed.', 'wp-autoplugin' );
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
			$message = $has_images ? __( 'The Google Gemini image request failed.', 'wp-autoplugin' ) : ( is_array( $data ) ? (string) ( $data['error']['message'] ?? __( 'The Google Gemini request failed.', 'wp-autoplugin' ) ) : __( 'The Google Gemini request failed.', 'wp-autoplugin' ) );
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
			'input_tokens'  => (int) ( $data['usageMetadata']['promptTokenCount'] ?? 0 ),
			'output_tokens' => (int) ( $data['usageMetadata']['candidatesTokenCount'] ?? 0 ),
		];
		if ( 'MAX_TOKENS' === ( $data['candidates'][0]['finishReason'] ?? '' ) ) {
			return new \WP_Error(
				'agent_response_incomplete',
				__( 'The model reached its output limit before completing the Gemini turn.', 'wp-autoplugin' ),
				[
					'retryable'        => false,
					'ambiguous'        => false,
					'incomplete_reason' => 'max_tokens',
					'usage'            => $usage,
					'request_id'       => sanitize_text_field( (string) ( $data['responseId'] ?? '' ) ),
				]
			);
		}

		$content      = '';
		$calls        = [];
		$replay_parts = [];
		foreach ( (array) ( $data['candidates'][0]['content']['parts'] ?? [] ) as $part ) {
			$replay = [];
			if ( is_string( $part['text'] ?? null ) ) {
				$content .= $part['text'];
				$replay['text'] = $part['text'];
			}
			$signature = $part['thoughtSignature'] ?? $part['thought_signature'] ?? null;
			if ( is_string( $signature ) && '' !== $signature ) {
				$replay['thoughtSignature'] = $signature;
			}
			if ( is_array( $part['functionCall'] ?? null ) ) {
				$function  = $part['functionCall'];
				$id        = (string) ( $function['id'] ?? '' );
				$name      = (string) ( $function['name'] ?? '' );
				$arguments = $function['args'] ?? null;
				if ( '' === $id || '' === $name || ! is_array( $arguments ) ) {
					return new \WP_Error( 'agent_tool_arguments_invalid', __( 'Google Gemini returned a source-tool request without a valid ID, name, or arguments.', 'wp-autoplugin' ) );
				}
				$call = [
					'id'        => $id,
					'name'      => $name,
					'arguments' => $arguments,
				];
				if ( isset( $replay['thoughtSignature'] ) ) {
					$call['thought_signature'] = $replay['thoughtSignature'];
				}
				$calls[]                 = $call;
				$replay['functionCall'] = [
					'id'   => $id,
					'name' => $name,
					'args' => $arguments ?: new \stdClass(),
				];
			}
			if ( $replay ) {
				$replay_parts[] = $replay;
			}
		}
		$request_id = sanitize_text_field( (string) ( $data['responseId'] ?? '' ) );
		if ( $calls ) {
			return [
				'type'           => 'tool_calls',
				'tool_calls'     => $calls,
				'text'           => trim( $content ),
				'usage'          => $usage,
				'request_id'     => $request_id,
				'provider_parts' => $replay_parts,
			];
		}
		if ( '' === trim( $content ) ) {
			return new \WP_Error(
				'direct_provider_empty',
				__( 'Google Gemini returned an empty response.', 'wp-autoplugin' ),
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
			'request_id' => $request_id,
		];
	}

	/**
	 * Convert the durable provider-neutral transcript to Gemini contents.
	 *
	 * Gemini 3 requires every function-call thought signature to be replayed
	 * byte-for-byte in its original part on later turns.
	 *
	 * @param array<int, array<string, mixed>> $transcript
	 * @return array<int, array<string, mixed>>
	 */
	private function contents( array $transcript, bool &$has_images ): array {
		$contents = [];
		foreach ( $transcript as $item ) {
			$role = (string) ( $item['role'] ?? '' );
			if ( 'user' === $role ) {
				$parts = [];
				foreach ( (array) ( $item['prompt_images'] ?? [] ) as $image ) {
					if ( empty( $image['content'] ) || empty( $image['mime_type'] ) ) {
						continue;
					}
					$has_images = true;
					$parts[]    = [
						'inline_data' => [
							'mime_type' => (string) $image['mime_type'],
							'data'      => base64_encode( (string) $image['content'] ),
						],
					]; // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Provider-required private inline data.
				}
				if ( '' !== (string) ( $item['content'] ?? '' ) ) {
					$parts[] = [ 'text' => (string) $item['content'] ];
				}
				$contents[] = [
					'role'  => 'user',
					'parts' => $parts ?: [ [ 'text' => '' ] ],
				];
				continue;
			}

			if ( 'assistant' === $role ) {
				$parts          = [];
				$provider_parts = $item['provider_parts'] ?? null;
				if ( is_array( $provider_parts ) && $provider_parts ) {
					$parts = array_values( $provider_parts );
				}
				if ( $parts ) {
					$contents[] = [
						'role'  => 'model',
						'parts' => $parts,
					];
					continue;
				}
				if ( '' !== (string) ( $item['content'] ?? '' ) ) {
					$parts[] = [ 'text' => (string) $item['content'] ];
				}
				foreach ( (array) ( $item['tool_calls'] ?? [] ) as $call ) {
					$arguments = (array) ( $call['arguments'] ?? [] );
					$part      = [
						'functionCall' => [
							'id'   => (string) ( $call['id'] ?? '' ),
							'name' => (string) ( $call['name'] ?? '' ),
							'args' => $arguments ?: new \stdClass(),
						],
					];
					if ( is_string( $call['thought_signature'] ?? null ) && '' !== $call['thought_signature'] ) {
						$part['thoughtSignature'] = $call['thought_signature'];
					}
					$parts[] = $part;
				}
				if ( $parts ) {
					$contents[] = [
						'role'  => 'model',
						'parts' => $parts,
					];
				}
				continue;
			}

			if ( 'tool' !== $role ) {
				continue;
			}
			$part = [
				'functionResponse' => [
					'id'       => (string) ( $item['call_id'] ?? '' ),
					'name'     => (string) ( $item['name'] ?? '' ),
					'response' => [ 'content' => (string) ( $item['content'] ?? '' ) ],
				],
			];
			$last = array_key_last( $contents );
			if ( null !== $last && 'user' === ( $contents[ $last ]['role'] ?? '' ) && 'functionResponse' === array_key_first( (array) ( $contents[ $last ]['parts'][0] ?? [] ) ) ) {
				$contents[ $last ]['parts'][] = $part;
			} else {
				$contents[] = [
					'role'  => 'user',
					'parts' => [ $part ],
				];
			}
		}

		return $contents;
	}
}
