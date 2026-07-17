<?php

namespace WP_Autoplugin\V2\Domain\Revision;

use WP_Autoplugin\V2\Domain\AI\Json_Response;

/** Deterministic validation for complete new-plugin source revisions. */
final class Code_Validator {
	public const MAX_FILES          = 20;
	public const MAX_FILE_BYTES     = 65536;
	public const MAX_PROJECT_BYTES  = 262144;
	private const SUPPORTED_TYPES   = [ 'php', 'js', 'css' ];

	/**
	 * Normalize and validate structured Plan metadata before billable work.
	 *
	 * @param array<string, mixed> $structured Plan structured data.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function plan( array $structured ) {
		$project = $structured['project_structure'] ?? null;
		$raw     = is_array( $project ) && is_array( $project['files'] ?? null ) ? $project['files'] : [];
		if ( ! $raw || count( $raw ) > self::MAX_FILES ) {
			return new \WP_Error( 'code_plan_files_invalid', sprintf( __( 'Code generation requires between 1 and %d planned files.', 'wp-autoplugin' ), self::MAX_FILES ) );
		}

		$files = [];
		foreach ( $raw as $file ) {
			$action      = is_array( $file ) ? sanitize_key( (string) ( $file['action'] ?? '' ) ) : '';
			if ( 'add' !== $action ) {
				return new \WP_Error( 'code_plan_manifest_invalid', __( 'The Plan file map is invalid. Regenerate the Plan before generating Code.', 'wp-autoplugin' ) );
			}
			$files[] = [
				'path'        => is_array( $file ) ? $file['path'] ?? '' : '',
				'type'        => is_array( $file ) ? $file['type'] ?? '' : '',
				'description' => is_array( $file ) ? $file['description'] ?? '' : '',
			];
		}

		$main_file = (string) ( $structured['main_file'] ?? '' );
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
				'plugin_name' => $structured['plugin_name'] ?? '',
				'main_file'   => $main_file,
				'files'       => $files,
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
	 * Normalize a revision-owned complete project manifest.
	 *
	 * @param array<string, mixed> $manifest Raw manifest.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function manifest( array $manifest ) {
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
			$extension   = strtolower( (string) pathinfo( $path, PATHINFO_EXTENSION ) );
			if ( '' === $path || isset( $seen[ $path ] ) || ! in_array( $type, self::SUPPORTED_TYPES, true ) || $extension !== $type || '' === $description ) {
				return new \WP_Error( 'code_manifest_invalid', __( 'The project manifest contains an unsafe, duplicate, unsupported, or undescribed file.', 'wp-autoplugin' ) );
			}
			$seen[ $path ] = true;
			$files[]       = compact( 'path', 'type', 'description' );
		}

		$main_file = $this->path( (string) ( $manifest['main_file'] ?? '' ) );
		$main_index = array_search( $main_file, array_column( $files, 'path' ), true );
		if ( '' === $main_file || str_contains( $main_file, '/' ) || false === $main_index || 'php' !== $files[ $main_index ]['type'] ) {
			return new \WP_Error( 'code_manifest_main_file_invalid', __( 'The project main_file must identify a root-level PHP file in the manifest.', 'wp-autoplugin' ) );
		}

		return [
			'plugin_name' => $plugin_name,
			'main_file'   => $main_file,
			'files'       => $files,
		];
	}

	/**
	 * Parse and validate one provider response against its expected manifest row.
	 *
	 * @param array<string, mixed> $expected Expected file.
	 * @return array<string, string>|\WP_Error
	 */
	public function response( string $response, array $expected, string $main_file ) {
		if ( str_contains( $response, '```' ) ) {
			return $this->error( $expected['path'], 0, 'markdown_fence', __( 'The response must be JSON without Markdown code fences.', 'wp-autoplugin' ) );
		}
		$decoded = json_decode( Json_Response::strip_fence( $response ), true );
		if ( ! is_array( $decoded ) || ! is_string( $decoded['path'] ?? null ) || ! is_string( $decoded['content'] ?? null ) ) {
			return $this->error( $expected['path'], 0, 'response_shape', __( 'The provider response must contain only path and complete content.', 'wp-autoplugin' ) );
		}
		if ( (string) $decoded['path'] !== (string) $expected['path'] ) {
			return $this->error( $expected['path'], 0, 'wrong_path', __( 'The provider returned a different file path than requested.', 'wp-autoplugin' ) );
		}

		$issues = $this->file_issues(
			[
				'path'        => $decoded['path'],
				'type'        => $expected['type'],
				'change_type' => 'add',
				'content'     => $decoded['content'],
			],
			$expected,
			$main_file
		);
		if ( $issues ) {
			return new \WP_Error( 'code_validation_failed', $issues[0]['message'], [ 'issues' => $issues, 'retryable' => true ] );
		}

		return [ 'path' => $decoded['path'], 'content' => $decoded['content'] ];
	}

	/**
	 * Validate the exact complete project represented by a manifest.
	 *
	 * @param array<int, array<string, mixed>> $files    Complete source files.
	 * @param array<string, mixed>             $manifest Normalized Plan manifest.
	 * @return array<int, array<string, mixed>>
	 */
	public function project_issues( array $files, array $manifest ): array {
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
				$issues[] = $this->issue( $path, 0, 'duplicate_path', __( 'The project contains a duplicate file path.', 'wp-autoplugin' ) );
				continue;
			}
			$actual[ $path ] = true;
			if ( ! isset( $expected[ $path ] ) ) {
				$issues[] = $this->issue( $path, 0, 'unexpected_file', __( 'The project contains a file that is not in the Plan.', 'wp-autoplugin' ) );
				continue;
			}
			$content = (string) ( $file['content'] ?? '' );
			$total  += strlen( $content );
			$issues = array_merge( $issues, $this->file_issues( $file, $expected[ $path ], (string) $manifest['main_file'] ) );
		}
		foreach ( $expected as $path => $file ) {
			if ( ! isset( $actual[ $path ] ) ) {
				$issues[] = $this->issue( $path, 0, 'missing_file', __( 'A planned file is missing from the project.', 'wp-autoplugin' ) );
			}
		}
		if ( $total > self::MAX_PROJECT_BYTES ) {
			$issues[] = $this->issue( '', 0, 'project_too_large', __( 'The complete project exceeds the 256 KiB staging limit.', 'wp-autoplugin' ) );
		}

		return $issues;
	}

	/** @param array<string, mixed> $file @param array<string, mixed> $expected */
	private function file_issues( array $file, array $expected, string $main_file ): array {
		$path         = $this->path( (string) ( $file['path'] ?? '' ) );
		$type         = sanitize_key( (string) ( $file['type'] ?? $expected['type'] ?? '' ) );
		$change_type  = sanitize_key( (string) ( $file['change_type'] ?? $file['action'] ?? 'add' ) );
		$content      = (string) ( $file['content'] ?? '' );
		$extension    = strtolower( (string) pathinfo( $path, PATHINFO_EXTENSION ) );
		$issues       = [];

		if ( '' === $path || $path !== (string) $expected['path'] ) {
			$issues[] = $this->issue( (string) ( $expected['path'] ?? $path ), 0, 'wrong_path', __( 'The file path does not match the Plan.', 'wp-autoplugin' ) );
		}
		if ( $type !== (string) $expected['type'] || $extension !== $type || ! in_array( $type, self::SUPPORTED_TYPES, true ) ) {
			$issues[] = $this->issue( $path, 0, 'wrong_type', __( 'The file type does not match the planned extension.', 'wp-autoplugin' ) );
		}
		if ( 'add' !== $change_type ) {
			$issues[] = $this->issue( $path, 0, 'wrong_action', __( 'New-plugin revisions may only contain add operations.', 'wp-autoplugin' ) );
		}
		if ( '' === trim( $content ) ) {
			$issues[] = $this->issue( $path, 0, 'empty_content', __( 'File content cannot be empty.', 'wp-autoplugin' ) );
		}
		if ( str_contains( $content, "\0" ) ) {
			$issues[] = $this->issue( $path, 0, 'nul_byte', __( 'File content cannot contain NUL bytes.', 'wp-autoplugin' ) );
		}
		if ( strlen( $content ) > self::MAX_FILE_BYTES ) {
			$issues[] = $this->issue( $path, 0, 'file_too_large', __( 'File content exceeds the 64 KiB limit.', 'wp-autoplugin' ) );
		}
		if ( str_contains( $content, '```' ) ) {
			$issues[] = $this->issue( $path, 0, 'markdown_fence', __( 'File content cannot contain Markdown code fences.', 'wp-autoplugin' ) );
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
			$headers = $this->plugin_headers( $content );
			if ( $path === $main_file ) {
				if ( 1 !== count( $headers ) || '' === trim( $headers[0] ?? '' ) ) {
					$issues[] = $this->issue( $path, 0, 'plugin_header', __( 'The main file must contain exactly one Plugin Name header with a value.', 'wp-autoplugin' ) );
				}
			} elseif ( $headers ) {
				$issues[] = $this->issue( $path, 0, 'supporting_plugin_header', __( 'Supporting PHP files must not contain a Plugin Name header.', 'wp-autoplugin' ) );
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
		$path = str_replace( '\\', '/', trim( $path ) );
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
