<?php

namespace WP_Autoplugin\V2\Domain\AI;

use WP_Autoplugin\V2\Domain\Revision\Code_Validator;

/** Strictly parses and normalizes the Code follow-up analysis contract. */
final class Code_Follow_Up_Response {
	private const MAX_CONTENT_BYTES      = 32768;
	private const MAX_INSTRUCTION_BYTES  = 4096;
	private const MAX_INSTRUCTIONS_BYTES = 32768;

	/**
	 * @param array<string, mixed> $base_manifest Current revision manifest.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function parse( string $response, array $base_manifest ) {
		if ( str_contains( $response, '```' ) ) {
			return $this->error( 'code_follow_up_fence', __( 'The Code follow-up response must be JSON without Markdown fences.', 'wp-autoplugin' ) );
		}
		$decoded = json_decode( Json_Response::strip_fence( $response ), true );
		if ( ! is_array( $decoded ) ) {
			return $this->error( 'code_follow_up_json', __( 'The Code follow-up analysis must be a valid JSON object.', 'wp-autoplugin' ) );
		}

		$outcome = sanitize_key( (string) ( $decoded['outcome'] ?? '' ) );
		$content = is_string( $decoded['content'] ?? null ) ? trim( $decoded['content'] ) : '';
		if ( '' === $content || strlen( $content ) > self::MAX_CONTENT_BYTES ) {
			return $this->error( 'code_follow_up_content', __( 'The Code follow-up response requires bounded non-empty Markdown content.', 'wp-autoplugin' ) );
		}
		if ( 'answer' === $outcome ) {
			return [ 'outcome' => 'answer', 'content' => $content ];
		}
		if ( 'changes' !== $outcome || ! is_array( $decoded['manifest'] ?? null ) || ! is_array( $decoded['changes'] ?? null ) ) {
			return $this->error( 'code_follow_up_shape', __( 'The Code follow-up response must classify as answer or provide a complete changes manifest.', 'wp-autoplugin' ) );
		}

		$validator = new Code_Validator();
		$manifest  = $validator->manifest( $decoded['manifest'] );
		if ( is_wp_error( $manifest ) ) {
			return $this->error( 'code_follow_up_manifest', $manifest->get_error_message() );
		}
		$base = $validator->manifest( $base_manifest );
		if ( is_wp_error( $base ) ) {
			return new \WP_Error( 'code_follow_up_base_manifest', __( 'The current revision manifest is invalid.', 'wp-autoplugin' ), [ 'retryable' => false ] );
		}

		$base_paths   = array_column( $base['files'], null, 'path' );
		$target_paths = array_column( $manifest['files'], null, 'path' );
		$instructions = [];
		$total_bytes  = 0;
		foreach ( $decoded['changes'] as $change ) {
			$path        = is_array( $change ) ? trim( (string) ( $change['path'] ?? '' ) ) : '';
			$instruction = is_array( $change ) ? trim( (string) ( $change['instruction'] ?? '' ) ) : '';
			if ( '' === $path || ! isset( $target_paths[ $path ] ) || isset( $instructions[ $path ] ) || '' === $instruction || strlen( $instruction ) > self::MAX_INSTRUCTION_BYTES ) {
				return $this->error( 'code_follow_up_instruction', __( 'Every change instruction must be bounded, unique, and identify a desired file.', 'wp-autoplugin' ) );
			}
			$total_bytes += strlen( $instruction );
			$instructions[ $path ] = $instruction;
		}
		if ( $total_bytes > self::MAX_INSTRUCTIONS_BYTES ) {
			return $this->error( 'code_follow_up_instructions_large', __( 'The Code follow-up instructions exceed the safe limit.', 'wp-autoplugin' ) );
		}

		$added   = array_values( array_diff( array_keys( $target_paths ), array_keys( $base_paths ) ) );
		$deleted = array_values( array_diff( array_keys( $base_paths ), array_keys( $target_paths ) ) );
		foreach ( $added as $path ) {
			if ( ! isset( $instructions[ $path ] ) ) {
				return $this->error( 'code_follow_up_new_file_instruction', sprintf( __( 'The new file %s requires a generation instruction.', 'wp-autoplugin' ), $path ) );
			}
		}
		$updated = array_values( array_intersect( array_keys( $instructions ), array_keys( $base_paths ) ) );
		if ( ! $added && ! $deleted && ! $updated ) {
			return $this->error( 'code_follow_up_noop', __( 'The proposed Code follow-up does not contain a material change.', 'wp-autoplugin' ) );
		}

		if ( $base['main_file'] !== $manifest['main_file'] ) {
			$role_changes = [ $manifest['main_file'] ];
			if ( isset( $target_paths[ $base['main_file'] ] ) ) {
				$role_changes[] = $base['main_file'];
			}
			foreach ( array_unique( $role_changes ) as $path ) {
				if ( ! isset( $instructions[ $path ] ) ) {
					return $this->error( 'code_follow_up_main_role', __( 'Every retained PHP file whose plugin-header role changes must be regenerated.', 'wp-autoplugin' ) );
				}
			}
		}

		$files = [];
		foreach ( $manifest['files'] as $file ) {
			if ( ! isset( $instructions[ $file['path'] ] ) ) {
				continue;
			}
			$files[] = [
				'path'        => $file['path'],
				'type'        => $file['type'],
				'operation'   => isset( $base_paths[ $file['path'] ] ) ? 'update' : 'add',
				'instruction' => $instructions[ $file['path'] ],
			];
		}
		if ( isset( $instructions[ $manifest['main_file'] ] ) ) {
			$main  = $files[ array_search( $manifest['main_file'], array_column( $files, 'path' ), true ) ];
			$files = array_values( array_filter( $files, static fn( array $file ): bool => $manifest['main_file'] !== $file['path'] ) );
			$files[] = $main;
		}

		return [
			'outcome'       => 'changes',
			'content'       => $content,
			'manifest'      => $manifest,
			'files'         => $files,
			'change_set'    => [
				'added_paths'   => $added,
				'updated_paths' => $updated,
				'deleted_paths' => $deleted,
			],
		];
	}

	private function error( string $code, string $message ): \WP_Error {
		return new \WP_Error( $code, $message, [ 'retryable' => true, 'ambiguous' => false ] );
	}
}
