<?php

namespace WP_Autoplugin\V2\Infrastructure\AI;

use WP_Autoplugin\V2\Domain\AI\Agent_Transport;
use WP_Autoplugin\V2\Domain\AI\Direct_Transport;

/** Anthropic Messages API native tool-use transport. */
final class Anthropic_Agent_Transport implements Agent_Transport, Direct_Transport {
	public function __construct( private string $api_key, private string $selected_model, private string $selected_effort = '' ) {}
	public function provider(): string { return 'anthropic'; }
	public function model(): string { return $this->selected_model; }
	public function effort(): string { return $this->selected_effort; }

	public function complete( string $instructions, string $input, array $options = [] ) {
		return $this->send( $instructions, [ [ 'role' => 'user', 'content' => $input, 'prompt_images' => (array) ( $options['prompt_images'] ?? [] ) ] ], [], $options );
	}

	public function send( string $instructions, array $transcript, array $tools, array $options = [] ) {
		$messages   = [];
		$has_images = false;
		foreach ( $transcript as $item ) {
			$role = (string) ( $item['role'] ?? '' );
			if ( 'user' === $role ) {
				$content = [];
				foreach ( (array) ( $item['prompt_images'] ?? [] ) as $image ) {
					if ( empty( $image['content'] ) || empty( $image['mime_type'] ) ) {
						continue;
					}
					$has_images = true;
					$content[] = [ 'type' => 'image', 'source' => [ 'type' => 'base64', 'media_type' => (string) $image['mime_type'], 'data' => base64_encode( (string) $image['content'] ) ] ]; // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Provider-required private image block.
				}
				if ( '' !== (string) ( $item['content'] ?? '' ) ) {
					$content[] = [ 'type' => 'text', 'text' => (string) $item['content'] ];
				}
				if ( ! $content ) {
					$content[] = [ 'type' => 'text', 'text' => '' ];
				}
				$messages[] = [ 'role' => 'user', 'content' => $content ];
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
		$body = [ 'model' => $this->selected_model, 'system' => $instructions, 'messages' => $messages, 'max_tokens' => min( 16384, max( 1, (int) ( $options['max_output_tokens'] ?? 4096 ) ) ) ];
		if ( $native_tools ) {
			$body['tools']       = $native_tools;
			$body['tool_choice'] = [ 'type' => 'auto' ];
		}
		if ( '' !== $this->selected_effort ) {
			$body['output_config'] = [ 'effort' => $this->selected_effort ];
		}
		$response = wp_remote_post(
			'https://api.anthropic.com/v1/messages',
			[
				'timeout' => Direct_Transport::REQUEST_TIMEOUT,
				'headers' => [ 'x-api-key' => $this->api_key, 'anthropic-version' => '2023-06-01', 'content-type' => 'application/json' ],
				'body'    => wp_json_encode( $body ),
			]
		);
		if ( is_wp_error( $response ) ) {
			$raw_message = $response->get_error_message();
			$message     = $has_images ? __( 'The Anthropic image request failed.', 'wp-autoplugin' ) : $raw_message;
			$ambiguous   = false !== stripos( $raw_message, 'timed out' ) || false !== stripos( $raw_message, 'timeout' );
			return new \WP_Error( 'agent_provider_network', $message, [ 'retryable' => ! $ambiguous, 'ambiguous' => $ambiguous ] );
		}
		$status = wp_remote_retrieve_response_code( $response );
		$data   = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( $status < 200 || $status >= 300 || ! is_array( $data ) ) {
			$message = $has_images ? __( 'The Anthropic image request failed.', 'wp-autoplugin' ) : ( is_array( $data ) ? (string) ( $data['error']['message'] ?? __( 'The Anthropic request failed.', 'wp-autoplugin' ) ) : __( 'The Anthropic request failed.', 'wp-autoplugin' ) );
			return new \WP_Error( 'agent_provider_http', $message, [ 'retryable' => 429 === $status || $status >= 500, 'ambiguous' => false, 'status' => $status ] );
		}
		if ( 'max_tokens' === ( $data['stop_reason'] ?? '' ) ) {
			return new \WP_Error( 'agent_response_incomplete', __( 'The model reached its output limit before completing the agent turn.', 'wp-autoplugin' ) );
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
