<?php

namespace WP_Autoplugin\V2\Infrastructure\AI;

use WP_Autoplugin\V2\Domain\AI\Agent_Transport;
use WP_Autoplugin\V2\Domain\AI\Direct_Transport;

/** ChatGPT subscription transport for direct and native-agent v2 tasks. */
final class ChatGPT_Transport implements Agent_Transport, Direct_Transport {
	public function __construct( private string $catalog_model, private string $remote_model, private string $selected_effort = '', private ?ChatGPT_Token_Manager $tokens = null ) {
		$this->tokens ??= new ChatGPT_Token_Manager();
	}

	public function provider(): string {
		return 'chatgpt'; }
	public function model(): string {
		return $this->catalog_model; }
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
		if ( '' === $this->remote_model || ! isset( ChatGPT_Config::models()[ $this->remote_model ] ) ) {
			return new \WP_Error( 'chatgpt_model_invalid', __( 'The ChatGPT model identifier is invalid.', 'wp-autoplugin' ) );
		}
		$tokens = $this->tokens->current();
		if ( is_wp_error( $tokens ) ) {
			return $tokens;
		}
		$input        = $this->input( $transcript );
		$native_tools = [];
		foreach ( $tools as $tool ) {
			$native_tools[] = [
				'type'        => 'function',
				'name'        => (string) $tool['name'],
				'description' => (string) $tool['description'],
				'parameters'  => (array) $tool['parameters'],
				'strict'      => false,
			];
		}
		$native_tools = ChatGPT_Tool_Schema::sanitize( $native_tools );
		$body         = [
			'model'        => $this->remote_model,
			'instructions' => $instructions,
			'input'        => $input,
			'store'        => false,
			'stream'       => true,
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
		$url = ChatGPT_Config::API_BASE_URL . '/responses';
		if ( ! ChatGPT_Config::is_api_url( $url ) ) {
			return new \WP_Error( 'chatgpt_endpoint_invalid', __( 'The ChatGPT endpoint is invalid.', 'wp-autoplugin' ) );
		}
		$response   = wp_safe_remote_post(
			$url,
			[
				'timeout'             => Direct_Transport::REQUEST_TIMEOUT,
				'redirection'         => 0,
				'limit_response_size' => Direct_Transport::MAX_RESPONSE_BYTES,
				'headers'             => array_merge(
					ChatGPT_Token_Manager::headers( $tokens ),
					[
						'Content-Type' => 'application/json',
						'Accept'       => 'text/event-stream',
					]
				),
				'body'                => wp_json_encode( $body ),
			]
		);
		$has_images = $this->has_prompt_images( $transcript );
		if ( is_wp_error( $response ) ) {
			return $this->network_error( $response, $has_images );
		}
		$status = (int) wp_remote_retrieve_response_code( $response );
		if ( $status < 200 || $status >= 300 ) {
			return $this->http_error( $status, (string) wp_remote_retrieve_body( $response ), $has_images );
		}
		$data = ChatGPT_SSE_Parser::parse( (string) wp_remote_retrieve_body( $response ) );
		if ( is_wp_error( $data ) ) {
			if ( $has_images ) {
				return new \WP_Error( $data->get_error_code(), __( 'The ChatGPT image request failed.', 'wp-autoplugin' ), $data->get_error_data() );
			}
			return $data;
		}
		if ( 'incomplete' === ( $data['status'] ?? '' ) ) {
			return new \WP_Error( 'agent_response_incomplete', __( 'The model stopped before completing the ChatGPT turn.', 'wp-autoplugin' ) );
		}
		return $this->normalize( $data );
	}

	/** @param array<int, array<string, mixed>> $transcript @return array<int, array<string, mixed>> */
	private function input( array $transcript ): array {
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
						'content' => [
							[
								'type' => 'output_text',
								'text' => (string) $item['content'],
							],
						],
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
		return $input;
	}

	/** @param array<string, mixed> $data @return array<string, mixed>|\WP_Error */
	private function normalize( array $data ) {
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
		if ( '' === $text && is_string( $data['output_text'] ?? null ) ) {
			$text = $data['output_text'];
		}
		$usage = [
			'input_tokens'  => (int) ( $data['usage']['input_tokens'] ?? 0 ),
			'output_tokens' => (int) ( $data['usage']['output_tokens'] ?? 0 ),
		];
		if ( $calls ) {
			return [
				'type'       => 'tool_calls',
				'tool_calls' => $calls,
				'text'       => trim( $text ),
				'usage'      => $usage,
				'request_id' => (string) ( $data['id'] ?? '' ),
			];
		}
		return '' !== trim( $text )
			? [
				'type'       => 'final',
				'content'    => trim( $text ),
				'usage'      => $usage,
				'request_id' => (string) ( $data['id'] ?? '' ),
			]
			: new \WP_Error( 'agent_response_empty', __( 'The model returned neither an answer nor a tool request.', 'wp-autoplugin' ) );
	}

	private function network_error( \WP_Error $error, bool $has_images ): \WP_Error {
		$raw       = $error->get_error_message();
		$ambiguous = false !== stripos( $raw, 'timed out' ) || false !== stripos( $raw, 'timeout' );
		return new \WP_Error(
			'agent_provider_network',
			$has_images ? __( 'The ChatGPT image request failed.', 'wp-autoplugin' ) : $raw,
			[
				'retryable' => ! $ambiguous,
				'ambiguous' => $ambiguous,
			]
		);
	}

	private function http_error( int $status, string $body, bool $has_images ): \WP_Error {
		$data    = json_decode( $body, true );
		$message = $has_images ? __( 'The ChatGPT image request failed.', 'wp-autoplugin' ) : ( is_array( $data ) ? sanitize_text_field( (string) ( $data['error']['message'] ?? __( 'The ChatGPT request failed.', 'wp-autoplugin' ) ) ) : __( 'The ChatGPT request failed.', 'wp-autoplugin' ) );
		return new \WP_Error(
			'agent_provider_http',
			$message,
			[
				'retryable'          => 429 === $status || $status >= 500,
				'ambiguous'          => false,
				'status'             => $status,
				'reconnect_required' => in_array( $status, [ 401, 403 ], true ),
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
