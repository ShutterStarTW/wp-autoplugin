<?php

namespace WP_Autoplugin\V2\Domain\AI;

use WP_Autoplugin\AI_Utils;

/** Validates a native Plan agent's terminal response. */
final class Plan_Response {
	/**
	 * @return array<string, mixed>|\WP_Error
	 */
	public function parse( string $response, bool $follow_up = false, int $parent_job_id = 0 ) {
		$decoded = json_decode( AI_Utils::strip_code_fences( trim( $response ), 'json' ), true );
		$outcome = is_array( $decoded ) ? (string) ( $decoded['outcome'] ?? '' ) : '';
		$content = is_array( $decoded ) && is_string( $decoded['content'] ?? null ) ? trim( $decoded['content'] ) : '';

		if ( ! in_array( $outcome, [ 'answer', 'artifact' ], true ) || '' === $content || ( ! $follow_up && 'artifact' !== $outcome ) ) {
			return new \WP_Error( 'plan_agent_response_invalid', __( 'The provider returned an invalid Plan response. No Plan artifact was changed.', 'wp-autoplugin' ) );
		}
		if ( 'answer' === $outcome ) {
			return [ 'content' => $content, 'outcome' => 'answer' ];
		}

		$structure = $this->structure( $decoded['structured']['project_structure'] ?? null );
		if ( is_wp_error( $structure ) ) {
			return $structure;
		}

		$result = [
			'content'    => $content,
			'outcome'    => 'artifact',
			'structured' => [ 'project_structure' => $structure ],
		];
		if ( $follow_up ) {
			$result['artifact'] = [
				'type'          => 'plan',
				'content'       => $content,
				'parent_job_id' => $parent_job_id,
			];
		}

		return $result;
	}

	/**
	 * @param mixed $value Raw project structure.
	 * @return array<string, mixed>|\WP_Error
	 */
	private function structure( $value ) {
		if ( ! is_array( $value ) || ! is_array( $value['directories'] ?? null ) || ! is_array( $value['files'] ?? null ) ) {
			return new \WP_Error( 'plan_agent_structure_invalid', __( 'The provider returned an invalid Plan file map. No Plan artifact was changed.', 'wp-autoplugin' ) );
		}

		$directories = [];
		foreach ( $value['directories'] as $directory ) {
			$normalized = is_string( $directory ) ? $this->relative_path( $directory, true ) : '';
			if ( '' === $normalized ) {
				return new \WP_Error( 'plan_agent_structure_invalid', __( 'The provider returned an invalid Plan file map. No Plan artifact was changed.', 'wp-autoplugin' ) );
			}
			$directories[] = $normalized;
		}

		$files = [];
		$paths = [];
		foreach ( $value['files'] as $file ) {
			if ( ! is_array( $file ) ) {
				return new \WP_Error( 'plan_agent_structure_invalid', __( 'The provider returned an invalid Plan file map. No Plan artifact was changed.', 'wp-autoplugin' ) );
			}
			$path        = is_string( $file['path'] ?? null ) ? $this->relative_path( $file['path'] ) : '';
			$type        = is_string( $file['type'] ?? null ) ? sanitize_key( $file['type'] ) : '';
			$action      = is_string( $file['action'] ?? null ) ? sanitize_key( $file['action'] ) : '';
			$description = is_string( $file['description'] ?? null ) ? trim( $file['description'] ) : '';
			if ( '' === $path || isset( $paths[ $path ] ) || ! in_array( $type, [ 'php', 'js', 'css' ], true ) || ! in_array( $action, [ 'add', 'update', 'delete' ], true ) || '' === $description ) {
				return new \WP_Error( 'plan_agent_structure_invalid', __( 'The provider returned an invalid Plan file map. No Plan artifact was changed.', 'wp-autoplugin' ) );
			}
			$paths[ $path ] = true;
			$files[] = compact( 'path', 'type', 'description', 'action' );
		}

		return [
			'directories' => array_values( array_unique( $directories ) ),
			'files'       => $files,
		];
	}

	private function relative_path( string $path, bool $directory = false ): string {
		$path = wp_normalize_path( trim( $path ) );
		if ( '' === $path || str_starts_with( $path, '/' ) || str_contains( $path, "\0" ) || preg_match( '#(^|/)\.\.(/|$)#', $path ) || preg_match( '#^[A-Za-z]:/#', $path ) ) {
			return '';
		}
		$path = trim( $path, '/' );
		return $directory && '' !== $path ? $path . '/' : $path;
	}
}
