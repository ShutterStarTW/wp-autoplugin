<?php

namespace WP_Autoplugin\V2\Infrastructure\AI;

use WP_Autoplugin\V2\Domain\AI\Agent_Transport;

/** Anthropic Messages API native tool-use transport. */
final class Anthropic_Agent_Transport implements Agent_Transport {
	public function __construct( private string $api_key, private string $selected_model ) {}
	public function provider(): string { return 'anthropic'; }
	public function model(): string { return $this->selected_model; }

	public function send( string $instructions, array $transcript, array $tools ) {
		$messages = [];
		foreach ( $transcript as $item ) {
			$role = (string) ( $item['role'] ?? '' );
			if ( 'user' === $role ) {
				$messages[] = [ 'role' => 'user', 'content' => [ [ 'type' => 'text', 'text' => (string) $item['content'] ] ] ];
			} elseif ( 'assistant' === $role ) {
				$content = [];
				if ( ! empty( $item['content'] ) ) {
					$content[] = [ 'type' => 'text', 'text' => (string) $item['content'] ];
				}
				foreach ( (array) ( $item['tool_calls'] ?? [] ) as $call ) {
					$content[] = [ 'type' => 'tool_use', 'id' => (string) $call['id'], 'name' => (string) $call['name'], 'input' => (array) $call['arguments'] ];
				}
				$messages[] = [ 'role' => 'assistant', 'content' => $content ];
			} elseif ( 'tool' === $role ) {
				$block = [ 'type' => 'tool_result', 'tool_use_id' => (string) $item['call_id'], 'content' => (string) $item['content'] ];
				$last  = array_key_last( $messages );
				if ( null !== $last && 'user' === $messages[ $last ]['role'] && isset( $messages[ $last ]['content'][0]['type'] ) && 'tool_result' === $messages[ $last ]['content'][0]['type'] ) {
					$messages[ $last ]['content'][] = $block;
				} else {
					$messages[] = [ 'role' => 'user', 'content' => [ $block ] ];
				}
			}
		}
		$native_tools = array_map( static fn( array $tool ): array => [ 'name' => $tool['name'], 'description' => $tool['description'], 'input_schema' => $tool['parameters'] ], $tools );
		$response = wp_remote_post(
			'https://api.anthropic.com/v1/messages',
			[
				'timeout' => 25,
				'headers' => [ 'x-api-key' => $this->api_key, 'anthropic-version' => '2023-06-01', 'content-type' => 'application/json' ],
				'body'    => wp_json_encode( [ 'model' => $this->selected_model, 'system' => $instructions, 'messages' => $messages, 'tools' => $native_tools, 'tool_choice' => [ 'type' => 'auto' ], 'max_tokens' => 4096 ] ),
			]
		);
		if ( is_wp_error( $response ) ) {
			$message   = $response->get_error_message();
			$ambiguous = false !== stripos( $message, 'timed out' ) || false !== stripos( $message, 'timeout' );
			return new \WP_Error( 'agent_provider_network', $message, [ 'retryable' => ! $ambiguous, 'ambiguous' => $ambiguous ] );
		}
		$status = wp_remote_retrieve_response_code( $response );
		$data   = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( $status < 200 || $status >= 300 || ! is_array( $data ) ) {
			$message = is_array( $data ) ? (string) ( $data['error']['message'] ?? __( 'The Anthropic request failed.', 'wp-autoplugin' ) ) : __( 'The Anthropic request failed.', 'wp-autoplugin' );
			return new \WP_Error( 'agent_provider_http', $message, [ 'retryable' => 429 === $status || $status >= 500, 'ambiguous' => false, 'status' => $status ] );
		}
		if ( 'max_tokens' === ( $data['stop_reason'] ?? '' ) ) {
			return new \WP_Error( 'agent_response_incomplete', __( 'The model reached its output limit before completing the Explain turn.', 'wp-autoplugin' ) );
		}

		$text = '';
		$calls = [];
		foreach ( (array) ( $data['content'] ?? [] ) as $content ) {
			if ( 'text' === ( $content['type'] ?? '' ) ) {
				$text .= (string) ( $content['text'] ?? '' );
			} elseif ( 'tool_use' === ( $content['type'] ?? '' ) ) {
				$calls[] = [ 'id' => (string) ( $content['id'] ?? '' ), 'name' => (string) ( $content['name'] ?? '' ), 'arguments' => (array) ( $content['input'] ?? [] ) ];
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
}
