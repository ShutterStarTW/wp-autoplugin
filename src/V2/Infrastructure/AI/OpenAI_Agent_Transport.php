<?php

namespace WP_Autoplugin\V2\Infrastructure\AI;

use WP_Autoplugin\V2\Domain\AI\Agent_Transport;

/** OpenAI Responses API native function-call transport. */
final class OpenAI_Agent_Transport implements Agent_Transport {
	public function __construct( private string $api_key, private string $selected_model, private string $selected_effort = '' ) {}
	public function provider(): string { return 'openai'; }
	public function model(): string { return $this->selected_model; }
	public function effort(): string { return $this->selected_effort; }

	public function send( string $instructions, array $transcript, array $tools ) {
		$input = [];
		foreach ( $transcript as $item ) {
			if ( 'user' === ( $item['role'] ?? '' ) ) {
				$input[] = [ 'role' => 'user', 'content' => [ [ 'type' => 'input_text', 'text' => (string) $item['content'] ] ] ];
			} elseif ( 'assistant' === ( $item['role'] ?? '' ) ) {
				if ( ! empty( $item['content'] ) ) {
					$input[] = [ 'role' => 'assistant', 'content' => (string) $item['content'] ];
				}
				foreach ( (array) ( $item['tool_calls'] ?? [] ) as $call ) {
					$input[] = [ 'type' => 'function_call', 'call_id' => (string) $call['id'], 'name' => (string) $call['name'], 'arguments' => wp_json_encode( $call['arguments'] ) ];
				}
			} elseif ( 'tool' === ( $item['role'] ?? '' ) ) {
				$input[] = [ 'type' => 'function_call_output', 'call_id' => (string) $item['call_id'], 'output' => (string) $item['content'] ];
			}
		}
		$native_tools = array_map(
			static fn( array $tool ): array => [ 'type' => 'function', 'name' => $tool['name'], 'description' => $tool['description'], 'parameters' => $tool['parameters'], 'strict' => false ],
			$tools
		);
		$body = [ 'model' => $this->selected_model, 'instructions' => $instructions, 'input' => $input, 'tools' => $native_tools, 'tool_choice' => 'auto', 'parallel_tool_calls' => true, 'max_output_tokens' => 4096, 'store' => false ];
		if ( '' !== $this->selected_effort ) {
			$body['reasoning'] = [ 'effort' => $this->selected_effort ];
		}
		$response = wp_remote_post(
			'https://api.openai.com/v1/responses',
			[
				'timeout' => 25,
				'headers' => [ 'Authorization' => 'Bearer ' . $this->api_key, 'Content-Type' => 'application/json' ],
				'body'    => wp_json_encode( $body ),
			]
		);
		if ( is_wp_error( $response ) ) {
			return $this->network_error( $response );
		}
		$status = wp_remote_retrieve_response_code( $response );
		$data   = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( $status < 200 || $status >= 300 || ! is_array( $data ) ) {
			return $this->http_error( $status, $data );
		}
		if ( 'incomplete' === ( $data['status'] ?? '' ) ) {
			return new \WP_Error( 'agent_response_incomplete', __( 'The model reached its output limit before completing the agent turn.', 'wp-autoplugin' ) );
		}

		$text = '';
		$calls = [];
		foreach ( (array) ( $data['output'] ?? [] ) as $output ) {
			if ( 'function_call' === ( $output['type'] ?? '' ) ) {
				$arguments = json_decode( (string) ( $output['arguments'] ?? '' ), true );
				if ( ! is_array( $arguments ) ) {
					return new \WP_Error( 'agent_tool_arguments_invalid', __( 'The model returned malformed tool arguments.', 'wp-autoplugin' ) );
				}
				$calls[] = [ 'id' => (string) ( $output['call_id'] ?? $output['id'] ?? '' ), 'name' => (string) ( $output['name'] ?? '' ), 'arguments' => $arguments ];
			}
			foreach ( (array) ( $output['content'] ?? [] ) as $content ) {
				if ( 'output_text' === ( $content['type'] ?? '' ) ) {
					$text .= (string) ( $content['text'] ?? '' );
				}
			}
		}
		$usage = [ 'input_tokens' => (int) ( $data['usage']['input_tokens'] ?? 0 ), 'output_tokens' => (int) ( $data['usage']['output_tokens'] ?? 0 ) ];
		if ( $calls ) {
			return [ 'type' => 'tool_calls', 'tool_calls' => $calls, 'text' => trim( $text ), 'usage' => $usage, 'request_id' => (string) ( $data['id'] ?? '' ) ];
		}
		if ( '' === trim( $text ) ) {
			return new \WP_Error( 'agent_response_empty', __( 'The model returned neither an answer nor a tool request.', 'wp-autoplugin' ) );
		}
		return [ 'type' => 'final', 'content' => trim( $text ), 'usage' => $usage, 'request_id' => (string) ( $data['id'] ?? '' ) ];
	}

	private function network_error( \WP_Error $error ): \WP_Error {
		$message   = $error->get_error_message();
		$ambiguous = false !== stripos( $message, 'timed out' ) || false !== stripos( $message, 'timeout' );
		return new \WP_Error( 'agent_provider_network', $message, [ 'retryable' => ! $ambiguous, 'ambiguous' => $ambiguous ] );
	}

	private function http_error( int $status, $data ): \WP_Error {
		$message = is_array( $data ) ? (string) ( $data['error']['message'] ?? __( 'The OpenAI request failed.', 'wp-autoplugin' ) ) : __( 'The OpenAI request failed.', 'wp-autoplugin' );
		return new \WP_Error( 'agent_provider_http', $message, [ 'retryable' => 429 === $status || $status >= 500, 'ambiguous' => false, 'status' => $status ] );
	}
}
