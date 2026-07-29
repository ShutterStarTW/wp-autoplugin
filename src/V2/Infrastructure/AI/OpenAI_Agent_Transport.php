<?php

namespace WP_Autoplugin\V2\Infrastructure\AI;

use WP_Autoplugin\V2\Domain\AI\Agent_Transport;
use WP_Autoplugin\V2\Domain\AI\Direct_Transport;
use WP_Autoplugin\V2\Domain\AI\Model_Output_Limits;

/** OpenAI Responses API native function-call transport. */
final class OpenAI_Agent_Transport implements Agent_Transport, Direct_Transport {
	public function __construct( private string $api_key, private string $selected_model, private string $selected_effort = '' ) {}
	public function provider(): string {
		return 'openai'; }
	public function model(): string {
		return $this->selected_model; }
	public function effort(): string {
		return $this->selected_effort; }

	public function complete( string $instructions, string $input, array $options = [] ) {
		if ( ! empty( $options['json'] ) ) {
			$input = "Return exactly one valid JSON object.\n\n" . $input;
		}
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
		$input = [];
		foreach ( $transcript as $item ) {
			if ( 'user' === ( $item['role'] ?? '' ) ) {
				$content = [];
				if ( '' !== (string) ( $item['content'] ?? '' ) ) {
					$content[] = [
						'type' => 'input_text',
						'text' => (string) $item['content'],
					];
				}
				foreach ( (array) ( $item['prompt_images'] ?? [] ) as $image ) {
					if ( empty( $image['content'] ) || empty( $image['mime_type'] ) ) {
						continue;
					}
					$content[] = [
						'type'      => 'input_image',
						'image_url' => 'data:' . $image['mime_type'] . ';base64,' . base64_encode( (string) $image['content'] ),
						'detail'    => 'auto',
					]; // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Provider-required private image data URI.
				}
				$input[] = [
					'role'    => 'user',
					'content' => $content ?: [
						[
							'type' => 'input_text',
							'text' => '',
						],
					],
				];
			} elseif ( 'assistant' === ( $item['role'] ?? '' ) ) {
				if ( ! empty( $item['content'] ) ) {
					$input[] = [
						'role'    => 'assistant',
						'content' => (string) $item['content'],
					];
				}
				foreach ( (array) ( $item['tool_calls'] ?? [] ) as $call ) {
					$input[] = [
						'type'      => 'function_call',
						'call_id'   => (string) $call['id'],
						'name'      => (string) $call['name'],
						'arguments' => wp_json_encode( $call['arguments'] ),
					];
				}
			} elseif ( 'tool' === ( $item['role'] ?? '' ) ) {
				$input[] = [
					'type'    => 'function_call_output',
					'call_id' => (string) $item['call_id'],
					'output'  => (string) $item['content'],
				];
			}
		}
		$native_tools = array_map(
			static fn( array $tool ): array => [
				'type'        => 'function',
				'name'        => $tool['name'],
				'description' => $tool['description'],
				'parameters'  => $tool['parameters'],
				'strict'      => false,
			],
			$tools
		);
		$body         = [
			'model'             => $this->selected_model,
			'instructions'      => $instructions,
			'input'             => $input,
			'max_output_tokens' => Model_Output_Limits::request_limit( 'openai', $this->selected_model, $options, 4096 ),
			'store'             => false,
		];
		if ( ! empty( $options['json'] ) ) {
			$body['text'] = [ 'format' => [ 'type' => 'json_object' ] ];
		}
		if ( $native_tools ) {
			$body['tools']               = $native_tools;
			$body['tool_choice']         = 'auto';
			$body['parallel_tool_calls'] = true;
		}
		if ( '' !== $this->selected_effort ) {
			$body['reasoning'] = [ 'effort' => $this->selected_effort ];
		}
		$response   = wp_remote_post(
			'https://api.openai.com/v1/responses',
			[
				'timeout' => Direct_Transport::REQUEST_TIMEOUT,
				'headers' => [
					'Authorization' => 'Bearer ' . $this->api_key,
					'Content-Type'  => 'application/json',
				],
				'body'    => wp_json_encode( $body ),
			]
		);
		$has_images = $this->has_prompt_images( $transcript );
		if ( is_wp_error( $response ) ) {
			return $this->network_error( $response, $has_images );
		}
		$status = wp_remote_retrieve_response_code( $response );
		$data   = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( $status < 200 || $status >= 300 || ! is_array( $data ) ) {
			return $this->http_error( $status, $data, $has_images );
		}
		$usage = [
			'input_tokens'  => (int) ( $data['usage']['input_tokens'] ?? 0 ),
			'output_tokens' => (int) ( $data['usage']['output_tokens'] ?? 0 ),
		];
		if ( 'incomplete' === ( $data['status'] ?? '' ) ) {
			$reason  = sanitize_key( (string) ( $data['incomplete_details']['reason'] ?? 'unknown' ) );
			$message = in_array( $reason, [ 'max_tokens', 'max_output_tokens' ], true )
				? __( 'The model reached its output limit before completing the agent turn.', 'wp-autoplugin' )
				: __( 'The provider stopped before completing the agent turn.', 'wp-autoplugin' );
			return new \WP_Error(
				'agent_response_incomplete',
				$message,
				[
					'retryable'        => false,
					'ambiguous'        => false,
					'incomplete_reason' => $reason,
					'usage'            => $usage,
					'request_id'       => sanitize_text_field( (string) ( $data['id'] ?? '' ) ),
				]
			);
		}

		$text  = '';
		$calls = [];
		foreach ( (array) ( $data['output'] ?? [] ) as $output ) {
			if ( 'function_call' === ( $output['type'] ?? '' ) ) {
				$arguments = json_decode( (string) ( $output['arguments'] ?? '' ), true );
				if ( ! is_array( $arguments ) ) {
					return new \WP_Error( 'agent_tool_arguments_invalid', __( 'The model returned malformed tool arguments.', 'wp-autoplugin' ) );
				}
				$calls[] = [
					'id'        => (string) ( $output['call_id'] ?? $output['id'] ?? '' ),
					'name'      => (string) ( $output['name'] ?? '' ),
					'arguments' => $arguments,
				];
			}
			foreach ( (array) ( $output['content'] ?? [] ) as $content ) {
				if ( 'output_text' === ( $content['type'] ?? '' ) ) {
					$text .= (string) ( $content['text'] ?? '' );
				}
			}
		}
		if ( $calls ) {
			return [
				'type'       => 'tool_calls',
				'tool_calls' => $calls,
				'text'       => trim( $text ),
				'usage'      => $usage,
				'request_id' => (string) ( $data['id'] ?? '' ),
			];
		}
		if ( '' === trim( $text ) ) {
			return new \WP_Error( 'agent_response_empty', __( 'The model returned neither an answer nor a tool request.', 'wp-autoplugin' ) );
		}
		return [
			'type'       => 'final',
			'content'    => trim( $text ),
			'usage'      => $usage,
			'request_id' => (string) ( $data['id'] ?? '' ),
		];
	}

	private function network_error( \WP_Error $error, bool $has_images ): \WP_Error {
		$raw_message = $error->get_error_message();
		$message     = $has_images ? __( 'The OpenAI image request failed.', 'wp-autoplugin' ) : $raw_message;
		$ambiguous   = false !== stripos( $raw_message, 'timed out' ) || false !== stripos( $raw_message, 'timeout' );
		return new \WP_Error(
			'agent_provider_network',
			$message,
			[
				'retryable' => ! $ambiguous,
				'ambiguous' => $ambiguous,
			]
		);
	}

	private function http_error( int $status, $data, bool $has_images ): \WP_Error {
		$message = $has_images ? __( 'The OpenAI image request failed.', 'wp-autoplugin' ) : ( is_array( $data ) ? (string) ( $data['error']['message'] ?? __( 'The OpenAI request failed.', 'wp-autoplugin' ) ) : __( 'The OpenAI request failed.', 'wp-autoplugin' ) );
		return new \WP_Error(
			'agent_provider_http',
			$message,
			[
				'retryable' => 429 === $status || $status >= 500,
				'ambiguous' => false,
				'status'    => $status,
			]
		);
	}

	/** @param array<int, array<string, mixed>> $transcript */
	private function has_prompt_images( array $transcript ): bool {
		foreach ( $transcript as $item ) {
			if ( ! empty( $item['prompt_images'] ) ) {
				return true;
			}
		}
		return false;
	}
}
