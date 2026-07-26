<?php

namespace WP_Autoplugin\V2\Domain\Revision;

/** Deterministic validation for complete plugin projects and target-relative change sets. */
final class Code_Validator {
	public const MAX_FILES             = 20;
	public const MAX_FILE_BYTES        = 65536;
	public const MAX_PROJECT_BYTES     = 262144;
	public const MAX_MANUAL_FILE_BYTES = 262144;
	public const GENERATED_TYPES       = [ 'php', 'js', 'css', 'json', 'html', 'md', 'txt' ];
	private const SUPPORTED_TYPES      = [ 'php', 'js', 'jsx', 'ts', 'tsx', 'css', 'scss', 'json', 'md', 'txt', 'xml', 'html' ];

	/**
	 * Normalize and validate structured Plan metadata before billable work.
	 *
	 * @param array<string, mixed> $structured Plan structured data.
	 * @param array<string, mixed> $context    Workspace and target context.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function plan( array $structured, array $context = [] ) {
		$operation = sanitize_key( (string) ( $context['operation'] ?? 'create' ) );
		if ( in_array( $operation, [ 'modify', 'fix' ], true ) ) {
			return $this->change_plan( $structured, $context, $operation );
		}

		if ( 'hook_extension' === $operation && false === ( $structured['technically_feasible'] ?? null ) ) {
			return new \WP_Error( 'code_extension_infeasible', __( 'This extension Plan is not technically feasible and cannot generate Code.', 'wp-autoplugin' ) );
		}

		$project = $structured['project_structure'] ?? null;
		$raw     = is_array( $project ) && is_array( $project['files'] ?? null ) ? $project['files'] : [];
		$root    = ( new Project_Root_Normalizer() )->normalize(
			[
				'directories' => is_array( $project ) && is_array( $project['directories'] ?? null ) ? $project['directories'] : [],
				'files'       => $raw,
			],
			(string) ( $structured['main_file'] ?? '' )
		);
		$raw       = $root['structure']['files'];
		$main_file = $root['main_file'];
		if ( ! $raw || count( $raw ) > self::MAX_FILES ) {
			return new \WP_Error( 'code_plan_files_invalid', sprintf( __( 'Code generation requires between 1 and %d planned files.', 'wp-autoplugin' ), self::MAX_FILES ) );
		}

		$files = [];
		foreach ( $raw as $file ) {
			$action = is_array( $file ) ? sanitize_key( (string) ( $file['action'] ?? '' ) ) : '';
			$type   = is_array( $file ) ? sanitize_key( (string) ( $file['type'] ?? '' ) ) : '';
			if ( 'add' !== $action || ! in_array( $type, self::GENERATED_TYPES, true ) ) {
				return new \WP_Error( 'code_plan_manifest_invalid', __( 'The Plan file map is invalid. Regenerate the Plan before generating Code.', 'wp-autoplugin' ) );
			}
			$files[] = [
				'path'        => is_array( $file ) ? $file['path'] ?? '' : '',
				'type'        => $type,
				'description' => is_array( $file ) ? $file['description'] ?? '' : '',
				'operation'   => 'add',
			];
		}

		if ( '' === $main_file ) {
			$root_php = array_values(
				array_filter(
					$files,
					static fn( array $file ): bool => 'php' === ( $file['type'] ?? '' ) && ! str_contains( (string) ( $file['path'] ?? '' ), '/' )
				)
			);
			if ( 1 !== count( $root_php ) ) {
				return new \WP_Error( 'code_plan_main_file_missing', __( 'This Plan does not identify its main plugin file. Regenerate the Plan before generating Code.', 'wp-autoplugin' ) );
			}
			$main_file = $root_php[0]['path'];
		}
		$manifest = $this->manifest(
			[
				'scope'         => 'project',
				'artifact_kind' => 'plugin',
				'operation'     => $operation,
				'plugin_name'   => $structured['plugin_name'] ?? '',
				'main_file'     => $main_file,
				'files'         => $files,
			]
		);
		if ( is_wp_error( $manifest ) ) {
			return new \WP_Error( 'code_plan_manifest_invalid', __( 'The Plan file map is invalid. Regenerate the Plan before generating Code.', 'wp-autoplugin' ), $manifest->get_error_data() );
		}
		$main = $manifest['files'][ array_search( $manifest['main_file'], array_column( $manifest['files'], 'path' ), true ) ];
		$manifest['files'] = array_values( array_filter( $manifest['files'], static fn( array $file ): bool => $manifest['main_file'] !== $file['path'] ) );
		$manifest['files'][] = $main;
		return $manifest;
	}

	/**
	 * Normalize a revision-owned project or change-set manifest.
	 *
	 * @param array<string, mixed> $manifest Raw manifest.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function manifest( array $manifest ) {
		$scope = sanitize_key( (string) ( $manifest['scope'] ?? 'project' ) );
		if ( 'changes' === $scope ) {
			return $this->change_manifest( $manifest );
		}

		$plugin_name = trim( (string) ( $manifest['plugin_name'] ?? '' ) );
		$raw_files   = is_array( $manifest['files'] ?? null ) ? $manifest['files'] : [];
		if ( '' === $plugin_name || ! $raw_files || count( $raw_files ) > self::MAX_FILES ) {
			return new \WP_Error( 'code_manifest_files_invalid', sprintf( __( 'The project manifest requires between 1 and %d files and a plugin name.', 'wp-autoplugin' ), self::MAX_FILES ) );
		}

		$files = [];
		$seen  = [];
		foreach ( $raw_files as $file ) {
			$path        = is_array( $file ) ? $this->path( (string) ( $file['path'] ?? '' ) ) : '';
			$type        = is_array( $file ) ? sanitize_key( (string) ( $file['type'] ?? '' ) ) : '';
			$description = is_array( $file ) ? trim( (string) ( $file['description'] ?? '' ) ) : '';
			$operation   = is_array( $file ) ? sanitize_key( (string) ( $file['operation'] ?? $file['action'] ?? 'add' ) ) : '';
			$extension   = strtolower( (string) pathinfo( $path, PATHINFO_EXTENSION ) );
			if ( '' === $path || isset( $seen[ $path ] ) || ! in_array( $type, self::SUPPORTED_TYPES, true ) || $extension !== $type || '' === $description || 'add' !== $operation ) {
				return new \WP_Error( 'code_manifest_invalid', __( 'The project manifest contains an unsafe, duplicate, unsupported, or undescribed file.', 'wp-autoplugin' ) );
			}
			$seen[ $path ] = true;
			$files[]       = compact( 'path', 'type', 'description', 'operation' );
		}

		$main_file  = $this->path( (string) ( $manifest['main_file'] ?? '' ) );
		$main_index = array_search( $main_file, array_column( $files, 'path' ), true );
		if ( '' === $main_file || str_contains( $main_file, '/' ) || false === $main_index || 'php' !== $files[ $main_index ]['type'] ) {
			return new \WP_Error( 'code_manifest_main_file_invalid', __( 'The project main_file must identify a root-level PHP file in the manifest.', 'wp-autoplugin' ) );
		}

		$result = [
			'scope'         => 'project',
			'artifact_kind' => 'plugin',
			'operation'     => sanitize_key( (string) ( $manifest['operation'] ?? 'create' ) ),
			'plugin_name'   => $plugin_name,
			'main_file'     => $main_file,
			'files'         => $files,
		];
		if ( 'hook_extension' === $result['operation'] ) {
			$integration_kind = sanitize_key( (string) ( $manifest['integration_target_kind'] ?? '' ) );
			$integration_ref  = trim( (string) ( $manifest['integration_target_ref'] ?? '' ) );
			$fingerprint      = (string) ( $manifest['integration_target_fingerprint'] ?? '' );
			if ( in_array( $integration_kind, [ 'plugin', 'theme' ], true ) && '' !== $integration_ref && preg_match( '/^[a-f0-9]{64}$/', $fingerprint ) ) {
				$result['integration_target_kind']        = $integration_kind;
				$result['integration_target_ref']         = $integration_ref;
				$result['integration_target_fingerprint'] = $fingerprint;
			}
		}
		return $result;
	}

	/**
	 * Parse and validate one provider response against its expected manifest row.
	 *
	 * @param array<string, mixed>        $expected Expected file.
	 * @param string|array<string, mixed> $policy   Legacy main path or normalized manifest.
	 * @return array<string, string>|\WP_Error
	 */
	public function response( string $response, array $expected, $policy ) {
		$decoded = json_decode( trim( $response ), true );
		if ( ! is_array( $decoded ) && str_contains( $response, '```' ) ) {
			return $this->error( $expected['path'], 0, 'markdown_fence', __( 'The response must be JSON without Markdown code fences.', 'wp-autoplugin' ) );
		}
		if ( ! is_array( $decoded ) || ! is_string( $decoded['path'] ?? null ) || ! is_string( $decoded['content'] ?? null ) ) {
			return $this->error( $expected['path'], 0, 'response_shape', __( 'The provider response must contain only path and complete content.', 'wp-autoplugin' ) );
		}
		if ( (string) $decoded['path'] !== (string) $expected['path'] ) {
			return $this->error( $expected['path'], 0, 'wrong_path', __( 'The provider returned a different file path than requested.', 'wp-autoplugin' ) );
		}

		$manifest = is_array( $policy )
			? $policy
			: [ 'scope' => 'project', 'artifact_kind' => 'plugin', 'main_file' => (string) $policy ];
		$issues = $this->file_issues(
			[
				'path'        => $decoded['path'],
				'type'        => $expected['type'],
				'change_type' => $expected['operation'] ?? 'add',
				'content'     => $decoded['content'],
			],
			$expected,
			$manifest
		);
		if ( $issues ) {
			return new \WP_Error( 'code_validation_failed', $issues[0]['message'], [ 'issues' => $issues, 'retryable' => true ] );
		}

		return [ 'path' => $decoded['path'], 'content' => $decoded['content'] ];
	}

	/**
	 * Validate and apply bounded, non-overlapping exact replacements to an original target file.
	 *
	 * @param array<string, mixed> $expected Expected Update manifest row.
	 * @param array<string, mixed> $manifest Normalized change manifest.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function update_response( string $response, array $expected, array $manifest, string $original ) {
		$path = (string) ( $expected['path'] ?? '' );
		$decoded = json_decode( trim( $response ), true );
		if ( ! is_array( $decoded ) && str_contains( $response, '```' ) ) {
			return $this->error( $path, 0, 'markdown_fence', __( 'The response must be JSON without Markdown code fences.', 'wp-autoplugin' ) );
		}
		if (
			! is_array( $decoded )
			|| [ 'path', 'replacements' ] !== array_values( array_intersect( [ 'path', 'replacements' ], array_keys( $decoded ) ) )
			|| array_diff( array_keys( $decoded ), [ 'path', 'replacements' ] )
			|| ! is_string( $decoded['path'] ?? null )
			|| ! is_array( $decoded['replacements'] ?? null )
			|| ! $decoded['replacements']
			|| count( $decoded['replacements'] ) > 20
		) {
			return $this->error( $path, 0, 'replacement_shape', __( 'The provider response must contain only the requested path and 1–20 exact search/replace operations.', 'wp-autoplugin' ) );
		}
		if ( $path !== $decoded['path'] ) {
			return $this->error( $path, 0, 'wrong_path', __( 'The provider returned a different file path than requested.', 'wp-autoplugin' ) );
		}

		$edits   = [];
		$issues  = [];
		$payload = 0;
		$seen    = [];
		foreach ( $decoded['replacements'] as $replacement ) {
			if (
				! is_array( $replacement )
				|| array_diff( array_keys( $replacement ), [ 'search', 'replace' ] )
				|| ! array_key_exists( 'search', $replacement )
				|| ! array_key_exists( 'replace', $replacement )
				|| ! is_string( $replacement['search'] )
				|| ! is_string( $replacement['replace'] )
				|| '' === $replacement['search']
			) {
				$issues[] = $this->issue( $path, 0, 'replacement_shape', __( 'Each replacement must contain only a non-empty search string and a replacement string.', 'wp-autoplugin' ) );
				continue;
			}
			$search  = $replacement['search'];
			$replace = $replacement['replace'];
			$payload += strlen( $search ) + strlen( $replace );
			if ( isset( $seen[ $search ] ) ) {
				$issues[] = $this->issue( $path, 0, 'duplicate_search', __( 'Each search block must be unique within the response.', 'wp-autoplugin' ) );
				continue;
			}
			$seen[ $search ] = true;
			if ( $search === $replace ) {
				$issues[] = $this->issue( $path, 0, 'unchanged_replacement', __( 'A replacement must change its matched source block.', 'wp-autoplugin' ) );
				continue;
			}
			if ( $search === $original ) {
				$issues[] = $this->issue( $path, 0, 'whole_file_replace', __( 'An Update must use targeted replacements and cannot replace the entire file.', 'wp-autoplugin' ) );
				continue;
			}
			$matches = substr_count( $original, $search );
			if ( 1 !== $matches ) {
				$issues[] = $this->issue( $path, 0, 'search_match_count', __( 'Each search block must occur exactly once in the original target file.', 'wp-autoplugin' ) );
				continue;
			}
			$start   = (int) strpos( $original, $search );
			$edits[] = [ 'start' => $start, 'end' => $start + strlen( $search ), 'search' => $search, 'replace' => $replace ];
		}
		if ( $payload > self::MAX_PROJECT_BYTES ) {
			$issues[] = $this->issue( $path, 0, 'replacement_payload_large', __( 'The targeted replacement response exceeds the 256 KiB limit.', 'wp-autoplugin' ) );
		}
		usort( $edits, static fn( array $left, array $right ): int => $left['start'] <=> $right['start'] );
		foreach ( $edits as $index => $edit ) {
			if ( $index > 0 && $edits[ $index - 1 ]['end'] > $edit['start'] ) {
				$issues[] = $this->issue( $path, 0, 'overlapping_replacements', __( 'Search blocks must not overlap in the original target file.', 'wp-autoplugin' ) );
				break;
			}
		}
		if ( $issues ) {
			return new \WP_Error( 'code_validation_failed', $issues[0]['message'], [ 'issues' => array_slice( $issues, 0, 5 ), 'retryable' => true ] );
		}

		$content = $original;
		foreach ( array_reverse( $edits ) as $edit ) {
			$content = substr_replace( $content, $edit['replace'], $edit['start'], $edit['end'] - $edit['start'] );
		}
		$issues = $this->file_issues(
			[
				'path'        => $path,
				'type'        => $expected['type'] ?? '',
				'change_type' => 'update',
				'content'     => $content,
			],
			$expected,
			$manifest
		);
		if ( $issues ) {
			return new \WP_Error( 'code_validation_failed', $issues[0]['message'], [ 'issues' => array_slice( $issues, 0, 5 ), 'retryable' => true ] );
		}
		return [ 'path' => $path, 'content' => $content, 'replacements' => count( $edits ) ];
	}

	/**
	 * Validate the exact complete project or planned change set represented by a manifest.
	 *
	 * @param array<int, array<string, mixed>> $files    Complete staged files.
	 * @param array<string, mixed>             $manifest Normalized manifest.
	 * @return array<int, array<string, mixed>>
	 */
	public function project_issues( array $files, array $manifest, int $max_file_bytes = self::MAX_FILE_BYTES ): array {
		$expected = [];
		foreach ( (array) ( $manifest['files'] ?? [] ) as $file ) {
			$expected[ $file['path'] ] = $file;
		}
		$actual = [];
		$issues = [];
		$total  = 0;
		foreach ( $files as $file ) {
			$path = $this->path( (string) ( $file['path'] ?? '' ) );
			if ( '' !== $path && isset( $actual[ $path ] ) ) {
				$issues[] = $this->issue( $path, 0, 'duplicate_path', __( 'The staged revision contains a duplicate file path.', 'wp-autoplugin' ) );
				continue;
			}
			if ( '' !== $path ) {
				$actual[ $path ] = true;
			}
			if ( ! isset( $expected[ $path ] ) ) {
				$issues[] = $this->issue( $path, 0, 'unexpected_file', __( 'The staged revision contains a file that is not in the Plan.', 'wp-autoplugin' ) );
				continue;
			}
			$content = (string) ( $file['content'] ?? '' );
			$total  += strlen( $content );
			$issues = array_merge( $issues, $this->file_issues( $file, $expected[ $path ], $manifest, $max_file_bytes ) );
		}
		foreach ( $expected as $path => $file ) {
			if ( ! isset( $actual[ $path ] ) ) {
				$issues[] = $this->issue( $path, 0, 'missing_file', __( 'A planned file is missing from the staged revision.', 'wp-autoplugin' ) );
			}
		}
		if ( $total > self::MAX_PROJECT_BYTES ) {
			$issues[] = $this->issue( '', 0, 'project_too_large', __( 'The staged revision exceeds the 256 KiB limit.', 'wp-autoplugin' ) );
		}

		return $issues;
	}

	/** @param array<string, mixed> $structured @param array<string, mixed> $context */
	private function change_plan( array $structured, array $context, string $operation ) {
		$project = $structured['project_structure'] ?? null;
		$raw     = is_array( $project ) && is_array( $project['files'] ?? null ) ? $project['files'] : [];
		$target  = is_array( $context['target_metadata'] ?? null ) ? $context['target_metadata'] : [];
		$kind    = sanitize_key( (string) ( $context['target_kind'] ?? $target['kind'] ?? '' ) );
		if ( ! in_array( $kind, [ 'plugin', 'theme' ], true ) ) {
			return new \WP_Error( 'code_target_invalid', __( 'Code changes require an installed plugin or theme target.', 'wp-autoplugin' ) );
		}
		$files = [];
		foreach ( $raw as $file ) {
			$type = is_array( $file ) ? sanitize_key( (string) ( $file['type'] ?? '' ) ) : '';
			if ( ! in_array( $type, self::GENERATED_TYPES, true ) ) {
				return new \WP_Error( 'code_plan_manifest_invalid', __( 'The Plan file map is invalid. Regenerate the Plan before generating Code.', 'wp-autoplugin' ) );
			}
			$files[] = [
				'path'        => is_array( $file ) ? $file['path'] ?? '' : '',
				'type'        => $type,
				'description' => is_array( $file ) ? $file['description'] ?? '' : '',
				'operation'   => is_array( $file ) ? $file['action'] ?? '' : '',
			];
		}
		$target_ref = (string) ( $context['target_ref'] ?? $target['ref'] ?? '' );
		return $this->manifest(
			[
				'scope'         => 'changes',
				'artifact_kind' => $kind,
				'operation'     => $operation,
				'plugin_name'   => (string) ( $context['project_name'] ?? $target['name'] ?? '' ),
				'main_file'     => 'plugin' === $kind ? basename( wp_normalize_path( $target_ref ) ) : '',
				'target_ref'    => $target_ref,
				'files'         => $files,
			]
		);
	}

	/** @param array<string, mixed> $manifest */
	private function change_manifest( array $manifest ) {
		$kind      = sanitize_key( (string) ( $manifest['artifact_kind'] ?? '' ) );
		$operation = sanitize_key( (string) ( $manifest['operation'] ?? '' ) );
		$raw_files = is_array( $manifest['files'] ?? null ) ? $manifest['files'] : [];
		if ( ! in_array( $kind, [ 'plugin', 'theme' ], true ) || ! in_array( $operation, [ 'modify', 'fix' ], true ) || ! $raw_files || count( $raw_files ) > self::MAX_FILES ) {
			return new \WP_Error( 'code_change_manifest_invalid', sprintf( __( 'The change manifest requires between 1 and %d valid plugin or theme file actions.', 'wp-autoplugin' ), self::MAX_FILES ) );
		}

		$files = [];
		$seen  = [];
		foreach ( $raw_files as $file ) {
			$path        = is_array( $file ) ? $this->path( (string) ( $file['path'] ?? '' ) ) : '';
			$type        = is_array( $file ) ? sanitize_key( (string) ( $file['type'] ?? '' ) ) : '';
			$description = is_array( $file ) ? trim( (string) ( $file['description'] ?? '' ) ) : '';
			$operation   = is_array( $file ) ? sanitize_key( (string) ( $file['operation'] ?? $file['action'] ?? '' ) ) : '';
			$extension   = strtolower( (string) pathinfo( $path, PATHINFO_EXTENSION ) );
			if ( '' === $path || isset( $seen[ $path ] ) || ! in_array( $type, self::SUPPORTED_TYPES, true ) || $extension !== $type || '' === $description || ! in_array( $operation, [ 'add', 'update', 'delete' ], true ) ) {
				return new \WP_Error( 'code_change_manifest_invalid', __( 'The change manifest contains an unsafe, duplicate, unsupported, or undescribed file action.', 'wp-autoplugin' ) );
			}
			$seen[ $path ] = true;
			$files[]       = compact( 'path', 'type', 'description', 'operation' );
		}
		usort( $files, static fn( array $left, array $right ): int => ( 'delete' === $left['operation'] ) <=> ( 'delete' === $right['operation'] ) );

		$main_file = 'plugin' === $kind ? $this->path( (string) ( $manifest['main_file'] ?? '' ) ) : '';
		if ( 'plugin' === $kind && ( '' === $main_file || str_contains( $main_file, '/' ) || 'php' !== strtolower( (string) pathinfo( $main_file, PATHINFO_EXTENSION ) ) ) ) {
			return new \WP_Error( 'code_change_main_file_invalid', __( 'The target plugin main file could not be identified safely.', 'wp-autoplugin' ) );
		}
		foreach ( $files as $file ) {
			if ( 'plugin' === $kind && $main_file === $file['path'] && 'delete' === $file['operation'] ) {
				return new \WP_Error( 'code_change_main_file_delete', __( 'A staged change set cannot delete the target plugin main file.', 'wp-autoplugin' ) );
			}
			if ( 'theme' === $kind && 'style.css' === $file['path'] && 'delete' === $file['operation'] ) {
				return new \WP_Error( 'code_change_theme_stylesheet_delete', __( 'A staged change set cannot delete the target theme stylesheet.', 'wp-autoplugin' ) );
			}
		}

		$result = [
			'scope'         => 'changes',
			'artifact_kind' => $kind,
			'operation'     => sanitize_key( (string) ( $manifest['operation'] ?? '' ) ),
			'plugin_name'   => trim( (string) ( $manifest['plugin_name'] ?? '' ) ),
			'main_file'     => $main_file,
			'target_ref'    => (string) ( $manifest['target_ref'] ?? '' ),
			'files'         => $files,
		];
		$fingerprint = (string) ( $manifest['target_fingerprint'] ?? '' );
		if ( preg_match( '/^[a-f0-9]{64}$/', $fingerprint ) ) {
			$result['target_fingerprint'] = $fingerprint;
		}
		$complete_fingerprint = (string) ( $manifest['complete_target_fingerprint'] ?? '' );
		if ( preg_match( '/^[a-f0-9]{64}$/', $complete_fingerprint ) ) {
			$result['complete_target_fingerprint'] = $complete_fingerprint;
		}
		$base_hashes = [];
		foreach ( (array) ( $manifest['base_hashes'] ?? [] ) as $path => $hash ) {
			if ( isset( $seen[ $path ] ) && is_string( $hash ) && preg_match( '/^[a-f0-9]{64}$/', $hash ) ) {
				$base_hashes[ $path ] = $hash;
			}
		}
		if ( $base_hashes ) {
			$result['base_hashes'] = $base_hashes;
		}
		return $result;
	}

	/** @param array<string, mixed> $file @param array<string, mixed> $expected @param array<string, mixed> $manifest */
	private function file_issues( array $file, array $expected, array $manifest, int $max_file_bytes = self::MAX_FILE_BYTES ): array {
		$path        = $this->path( (string) ( $file['path'] ?? '' ) );
		$type        = sanitize_key( (string) ( $file['type'] ?? $expected['type'] ?? '' ) );
		$change_type = sanitize_key( (string) ( $file['change_type'] ?? $file['action'] ?? 'add' ) );
		$operation   = sanitize_key( (string) ( $expected['operation'] ?? 'add' ) );
		$content     = (string) ( $file['content'] ?? '' );
		$extension   = strtolower( (string) pathinfo( $path, PATHINFO_EXTENSION ) );
		$issues      = [];

		if ( '' === $path || $path !== (string) $expected['path'] ) {
			$issues[] = $this->issue( (string) ( $expected['path'] ?? $path ), 0, 'wrong_path', __( 'The file path does not match the Plan.', 'wp-autoplugin' ) );
		}
		if ( $type !== (string) $expected['type'] || $extension !== $type || ! in_array( $type, self::SUPPORTED_TYPES, true ) ) {
			$issues[] = $this->issue( $path, 0, 'wrong_type', __( 'The file type does not match the planned extension.', 'wp-autoplugin' ) );
		}
		if ( $operation !== $change_type ) {
			$issues[] = $this->issue( $path, 0, 'wrong_action', __( 'The staged file action does not match the Plan.', 'wp-autoplugin' ) );
		}
		if ( 'delete' === $operation ) {
			if ( '' !== $content ) {
				$issues[] = $this->issue( $path, 0, 'delete_content', __( 'A deleted file must not contain replacement content.', 'wp-autoplugin' ) );
			}
			return $issues;
		}
		if ( '' === trim( $content ) ) {
			$issues[] = $this->issue( $path, 0, 'empty_content', __( 'File content cannot be empty.', 'wp-autoplugin' ) );
		}
		if ( str_contains( $content, "\0" ) ) {
			$issues[] = $this->issue( $path, 0, 'nul_byte', __( 'File content cannot contain NUL bytes.', 'wp-autoplugin' ) );
		}
		if ( strlen( $content ) > $max_file_bytes ) {
			$issues[] = $this->issue(
				$path,
				0,
				'file_too_large',
				sprintf(
					/* translators: %d: maximum file size in KiB. */
					__( 'File content exceeds the %d KiB limit.', 'wp-autoplugin' ),
					(int) floor( $max_file_bytes / 1024 )
				)
			);
		}
		if ( in_array( $type, [ 'php', 'js', 'jsx', 'ts', 'tsx', 'css', 'scss' ], true ) && str_contains( $content, '```' ) ) {
			$issues[] = $this->issue( $path, 0, 'markdown_fence', __( 'File content cannot contain Markdown code fences.', 'wp-autoplugin' ) );
		}
		if ( 'json' === $type && '' !== trim( $content ) ) {
			json_decode( $content, true );
			if ( JSON_ERROR_NONE !== json_last_error() ) {
				$issues[] = $this->issue(
					$path,
					0,
					'json_syntax',
					sprintf(
						/* translators: %s: JSON parser error. */
						__( 'JSON syntax error: %s', 'wp-autoplugin' ),
						json_last_error_msg()
					)
				);
			}
		}
		if ( 'php' === $type && '' !== $content ) {
			$tokens = token_get_all( $content );
			if ( ! array_filter( $tokens, static fn( $token ): bool => is_array( $token ) && T_OPEN_TAG === $token[0] ) ) {
				$issues[] = $this->issue( $path, 1, 'php_open_tag', __( 'PHP files must contain a PHP opening tag.', 'wp-autoplugin' ) );
			}
			try {
				token_get_all( $content, TOKEN_PARSE );
			} catch ( \ParseError $error ) {
				$issues[] = $this->issue( $path, $error->getLine(), 'php_syntax', $error->getMessage() );
			}
			if ( 'plugin' === ( $manifest['artifact_kind'] ?? 'plugin' ) ) {
				$headers = $this->plugin_headers( $content );
				if ( $path === (string) ( $manifest['main_file'] ?? '' ) ) {
					if ( 1 !== count( $headers ) || '' === trim( $headers[0] ?? '' ) ) {
						$issues[] = $this->issue( $path, 0, 'plugin_header', __( 'The main file must contain exactly one Plugin Name header with a value.', 'wp-autoplugin' ) );
					}
				} elseif ( $headers ) {
					$issues[] = $this->issue( $path, 0, 'supporting_plugin_header', __( 'Supporting PHP files must not contain a Plugin Name header.', 'wp-autoplugin' ) );
				}
			}
		}

		return $issues;
	}

	/** @return array<int, string> */
	private function plugin_headers( string $content ): array {
		$headers = [];
		foreach ( token_get_all( $content ) as $token ) {
			if ( ! is_array( $token ) || ! in_array( $token[0], [ T_COMMENT, T_DOC_COMMENT ], true ) ) {
				continue;
			}
			if ( preg_match_all( '/^[ \t\/*#@]*Plugin Name\s*:\s*(.*?)\s*$/mi', $token[1], $matches ) ) {
				$headers = array_merge( $headers, $matches[1] );
			}
		}
		return $headers;
	}

	private function path( string $path ): string {
		$path     = str_replace( '\\', '/', trim( $path ) );
		$segments = explode( '/', $path );
		if ( '' === $path || str_starts_with( $path, '/' ) || preg_match( '/^[A-Za-z]:/', $path ) || preg_match( '/[\x00-\x1F]/', $path ) || array_intersect( [ '', '.', '..' ], $segments ) ) {
			return '';
		}
		return trim( $path, '/' );
	}

	private function error( string $path, int $line, string $code, string $message ): \WP_Error {
		return new \WP_Error( 'code_validation_failed', $message, [ 'issues' => [ $this->issue( $path, $line, $code, $message ) ], 'retryable' => true ] );
	}

	/** @return array{path:string,line:int,code:string,message:string} */
	private function issue( string $path, int $line, string $code, string $message ): array {
		return [ 'path' => $path, 'line' => max( 0, $line ), 'code' => $code, 'message' => $message ];
	}
}
