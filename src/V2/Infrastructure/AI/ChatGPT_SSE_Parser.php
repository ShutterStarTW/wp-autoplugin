<?php

namespace WP_Autoplugin\V2\Infrastructure\AI;

/** Reconstructs a Responses object from the Codex backend SSE stream. */
final class ChatGPT_SSE_Parser {
	/** @return array<string, mixed>|\WP_Error */
	public static function parse( string $body ) {
		if ( '' === trim( $body ) ) {
			return new \WP_Error( 'chatgpt_stream_empty', __( 'The ChatGPT response stream was empty.', 'wp-autoplugin' ) );
		}
		$output        = [];
		$deltas        = [];
		$phase         = '';
		$saw_tool      = false;
		$terminal_type = '';
		$terminal      = [];
		foreach ( self::events( $body ) as $event ) {
			$type = is_string( $event['type'] ?? null ) ? $event['type'] : '';
			if ( 'error' === $type ) {
				return self::error( $event );
			}
			if ( 'response.output_item.added' === $type ) {
				$item     = is_array( $event['item'] ?? null ) ? $event['item'] : [];
				$phase    = 'message' === ( $item['type'] ?? '' ) ? self::phase( $item['phase'] ?? '' ) : '';
				$saw_tool = $saw_tool || 'function_call' === ( $item['type'] ?? '' );
				continue;
			}
			if ( str_contains( $type, 'function_call' ) ) {
				$saw_tool = true;
			}
			if ( 'response.output_text.delta' === $type ) {
				if ( ! in_array( $phase, [ 'analysis', 'commentary' ], true ) && is_string( $event['delta'] ?? null ) ) {
					$deltas[] = $event['delta'];
				}
				continue;
			}
			if ( 'response.output_item.done' === $type && is_array( $event['item'] ?? null ) ) {
				$item       = $event['item'];
				$item_type  = is_string( $item['type'] ?? null ) ? $item['type'] : '';
				$item_phase = self::phase( $item['phase'] ?? '' );
				$saw_tool   = $saw_tool || 'function_call' === $item_type;
				if ( in_array( $item_type, [ 'message', 'function_call' ], true ) && ! in_array( $item_phase, [ 'analysis', 'commentary' ], true ) ) {
					$output[] = $item;
				}
				continue;
			}
			if ( in_array( $type, [ 'response.completed', 'response.incomplete', 'response.failed' ], true ) ) {
				$terminal_type = $type;
				$terminal      = is_array( $event['response'] ?? null ) ? $event['response'] : [];
				if ( 'response.failed' === $type ) {
					return self::error( $terminal );
				}
				break;
			}
		}
		if ( '' === $terminal_type ) {
			return new \WP_Error( 'chatgpt_stream_truncated', __( 'The ChatGPT response stream ended before reporting completion.', 'wp-autoplugin' ), [ 'ambiguous' => true ] );
		}
		if ( ! $output && $deltas && ! $saw_tool ) {
			$output[] = [
				'type'    => 'message',
				'role'    => 'assistant',
				'status'  => 'completed',
				'content' => [
					[
						'type' => 'output_text',
						'text' => implode( '', $deltas ),
					],
				],
			];
		}
		$terminal['output']      = $output;
		$terminal['status']      = is_string( $terminal['status'] ?? null ) ? $terminal['status'] : ( 'response.incomplete' === $terminal_type ? 'incomplete' : 'completed' );
		$terminal['output_text'] = implode( '', $deltas );
		return $terminal;
	}

	/** @return array<int, array<string, mixed>> */
	private static function events( string $body ): array {
		$frames = preg_split( '/\n{2,}/', trim( str_replace( [ "\r\n", "\r" ], "\n", $body ) ) );
		$events = [];
		foreach ( is_array( $frames ) ? $frames : [] as $frame ) {
			$lines = [];
			foreach ( explode( "\n", $frame ) as $line ) {
				if ( str_starts_with( $line, 'data:' ) ) {
					$lines[] = ltrim( substr( $line, 5 ), ' ' );
				}
			}
			$json  = implode( "\n", $lines );
			$event = '[DONE]' !== $json ? json_decode( $json, true ) : null;
			if ( is_array( $event ) && ! array_is_list( $event ) ) {
				$events[] = $event;
			}
		}
		return $events;
	}

	/** @param mixed $value */
	private static function phase( $value ): string {
		return is_string( $value ) ? strtolower( trim( $value ) ) : '';
	}

	/** @param array<string, mixed> $data */
	private static function error( array $data ): \WP_Error {
		$error   = is_array( $data['error'] ?? null ) ? $data['error'] : $data;
		$message = is_string( $error['message'] ?? null ) ? trim( $error['message'] ) : '';
		return new \WP_Error( 'chatgpt_stream_failed', '' !== $message ? sanitize_text_field( $message ) : __( 'The ChatGPT response failed.', 'wp-autoplugin' ) );
	}
}
