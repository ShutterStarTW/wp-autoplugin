<?php

namespace WP_Autoplugin\V2\Domain\AI;

use WP_Autoplugin\V2\Domain\Revision\Code_Validator;
use WP_Autoplugin\V2\Domain\Revision\Project_Root_Normalizer;

/** Validates a native Plan agent's terminal response. */
final class Plan_Response {
	/**
	 * @return array<string, mixed>|\WP_Error
	 */
	public function parse( string $response, bool $follow_up = false, int $parent_job_id = 0, string $operation = '' ) {
		$decoded = json_decode( Json_Response::strip_fence( $response ), true );
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
		$raw_structured = is_array( $decoded['structured'] ?? null ) ? $decoded['structured'] : [];
		if ( in_array( $operation, [ 'create', 'hook_extension' ], true ) ) {
			$has_explicit_main = is_string( $raw_structured['main_file'] ?? null ) && '' !== trim( $raw_structured['main_file'] );
			$root      = ( new Project_Root_Normalizer() )->normalize(
				$structure,
				$has_explicit_main ? $raw_structured['main_file'] : ''
			);
			$structure = $root['structure'];
			if ( 'hook_extension' === $operation || $has_explicit_main ) {
				$raw_structured['main_file'] = $root['main_file'];
			}
		}

		$structured = [ 'project_structure' => $structure ];
		if ( 'create' === $operation ) {
			$creation = $this->creation( $raw_structured, $structure );
			if ( is_wp_error( $creation ) ) {
				return $creation;
			}
			$structured = array_merge( $creation, $structured );
		} elseif ( 'hook_extension' === $operation ) {
			$extension = $this->extension( $raw_structured, $structure );
			if ( is_wp_error( $extension ) ) {
				return $extension;
			}
			$structured = array_merge( $extension, $structured );
		}

		$result = [
			'content'    => $content,
			'outcome'    => 'artifact',
			'structured' => $structured,
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
	 * Validate the new-plugin contract independently of existing-target Plans.
	 *
	 * @param mixed                $value     Raw structured response.
	 * @param array<string, mixed> $structure Normalized project structure.
	 * @return array<string, string>|\WP_Error
	 */
	private function creation( $value, array $structure ) {
		$plugin_name = is_array( $value ) && is_string( $value['plugin_name'] ?? null ) ? trim( $value['plugin_name'] ) : '';
		$main_file   = is_array( $value ) && is_string( $value['main_file'] ?? null ) ? $this->relative_path( $value['main_file'] ) : '';
		$has_php     = false;
		if ( '' === $plugin_name || '' === $main_file || str_contains( $main_file, '/' ) || 'php' !== strtolower( (string) pathinfo( $main_file, PATHINFO_EXTENSION ) ) || ! $structure['files'] ) {
			return $this->creation_error();
		}
		$main_found = false;
		foreach ( $structure['files'] as $file ) {
			if ( 'add' !== $file['action'] ) {
				return $this->creation_error();
			}
			$has_php = $has_php || 'php' === $file['type'];
			$main_found = $main_found || ( $main_file === $file['path'] && 'php' === $file['type'] );
		}

		return $has_php && $main_found ? [ 'plugin_name' => $plugin_name, 'main_file' => $main_file ] : $this->creation_error();
	}

	private function creation_error(): \WP_Error {
		return new \WP_Error( 'plan_agent_creation_invalid', __( 'The provider returned an invalid new-plugin Plan. No Plan artifact was changed.', 'wp-autoplugin' ) );
	}

	/**
	 * Validate the separate-plugin contract for hook-extension Plans.
	 *
	 * @param mixed                $value     Raw structured response.
	 * @param array<string, mixed> $structure Normalized project structure.
	 * @return array<string, mixed>|\WP_Error
	 */
	private function extension( $value, array $structure ) {
		if ( ! is_array( $value ) || ! is_bool( $value['technically_feasible'] ?? null ) ) {
			return $this->extension_error();
		}

		$feasible   = $value['technically_feasible'];
		$plugin_name = is_string( $value['plugin_name'] ?? null ) ? trim( $value['plugin_name'] ) : '';
		$main_file   = is_string( $value['main_file'] ?? null ) ? $this->relative_path( $value['main_file'] ) : '';
		$hooks       = [];
		if ( is_array( $value['hooks'] ?? null ) ) {
			foreach ( $value['hooks'] as $hook ) {
				if ( ! is_string( $hook ) || '' === trim( $hook ) ) {
					return $this->extension_error();
				}
				$hooks[] = trim( $hook );
			}
		} else {
			return $this->extension_error();
		}

		if ( $feasible ) {
			if ( '' === $plugin_name || ! $hooks || ! $structure['files'] || '' === $main_file || str_contains( $main_file, '/' ) || 'php' !== strtolower( (string) pathinfo( $main_file, PATHINFO_EXTENSION ) ) ) {
				return $this->extension_error();
			}
			$main_found = false;
			foreach ( $structure['files'] as $file ) {
				if ( 'add' !== $file['action'] ) {
					return $this->extension_error();
				}
				$main_found = $main_found || ( $main_file === $file['path'] && 'php' === $file['type'] );
			}
			if ( ! $main_found ) {
				return $this->extension_error();
			}
		} elseif ( $structure['directories'] || $structure['files'] || '' !== $main_file ) {
			return $this->extension_error();
		}

		return [
			'technically_feasible' => $feasible,
			'plugin_name'           => $plugin_name,
			'main_file'             => $main_file,
			'hooks'                 => array_values( array_unique( $hooks ) ),
		];
	}

	private function extension_error(): \WP_Error {
		return new \WP_Error( 'plan_agent_extension_invalid', __( 'The provider returned an invalid extension Plan. No Plan artifact was changed.', 'wp-autoplugin' ) );
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
			if ( '' === $path || isset( $paths[ $path ] ) || ! in_array( $type, Code_Validator::GENERATED_TYPES, true ) || ! in_array( $action, [ 'add', 'update', 'delete' ], true ) || '' === $description ) {
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
		if ( '' === $path || str_starts_with( $path, '/' ) || str_starts_with( strtolower( $path ), 'parent_theme:' ) || str_contains( $path, "\0" ) || preg_match( '#(^|/)\.\.(/|$)#', $path ) || preg_match( '#^[A-Za-z]:/#', $path ) ) {
			return '';
		}
		$path = trim( $path, '/' );
		return $directory && '' !== $path ? $path . '/' : $path;
	}
}
