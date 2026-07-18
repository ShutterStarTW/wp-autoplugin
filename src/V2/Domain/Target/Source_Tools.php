<?php

namespace WP_Autoplugin\V2\Domain\Target;

/**
 * Bounded, read-only target source access for agents and revision editing.
 */
final class Source_Tools {
	private const EXTENSIONS       = [ 'php', 'js', 'jsx', 'ts', 'tsx', 'css', 'scss', 'json', 'md', 'txt', 'xml', 'html' ];
	private const SKIPPED          = [ '.git', 'node_modules', 'vendor', 'tests' ];
	private const MAX_FILE_BYTES   = 262144;
	private const MAX_RESULT_BYTES = 65536;
	private const MAX_TREE_FILES   = 2000;
	private const MAX_SEARCH_FILES = 200;
	private const MAX_SEARCH_BYTES = 2097152;
	private const MAX_SEARCH_HITS  = 50;
	private const MAX_HOOK_FILES   = 1000;
	private const MAX_HOOK_BYTES   = 16777216;
	private const MAX_HOOKS        = 1000;
	private const HOOK_CONTEXT_LINES = 3;

	/** @var array<string, mixed> */
	private array $target;
	private string $root;
	private string $main_file;

	/** @param array<string, mixed> $target Target metadata snapshot. */
	public function __construct( array $target ) {
		$this->target    = $target;
		$this->root      = $this->resolve_root( $target );
		$this->main_file = $this->resolve_main_file( $target );
	}

	/** @return array<int, array<string, mixed>> */
	public function definitions(): array {
		return [
			[
				'name'        => 'list_files',
				'description' => 'List readable source files in the plugin or theme. Results are sorted and paginated.',
				'parameters'  => [
					'type'                 => 'object',
					'properties'           => [
						'offset' => [ 'type' => 'integer', 'minimum' => 0 ],
						'limit'  => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => 200 ],
					],
					'additionalProperties' => false,
				],
			],
			[
				'name'        => 'read_file',
				'description' => 'Read a source file with line numbers. Path must be an exact relative file path returned by list_files or search_code, without a line anchor, wildcard, or directory. Use start_line and end_line for large files.',
				'parameters'  => [
					'type'       => 'object',
					'properties' => [
						'path'       => [ 'type' => 'string' ],
						'start_line' => [ 'type' => 'integer', 'minimum' => 1 ],
						'end_line'   => [ 'type' => 'integer', 'minimum' => 1 ],
					],
					'required'             => [ 'path' ],
					'additionalProperties' => false,
				],
			],
			[
				'name'        => 'search_code',
				'description' => 'Search source files for an exact, case-insensitive text string and return matching lines.',
				'parameters'  => [
					'type'       => 'object',
					'properties' => [
						'query'     => [ 'type' => 'string' ],
						'path'      => [ 'type' => 'string' ],
						'extension' => [ 'type' => 'string', 'enum' => self::EXTENSIONS ],
					],
					'required'             => [ 'query' ],
					'additionalProperties' => false,
				],
			],
			[
				'name'        => 'get_target_metadata',
				'description' => 'Return the target headers and source statistics without reading source files.',
				'parameters'  => [ 'type' => 'object', 'properties' => new \stdClass(), 'additionalProperties' => false ],
			],
			[
				'name'        => 'list_hooks',
				'description' => 'List statically named action and filter hooks discovered in the target PHP source, including file, line, and bounded surrounding context. Results are sorted and paginated.',
				'parameters'  => [
					'type'                 => 'object',
					'properties'           => [
						'offset' => [ 'type' => 'integer', 'minimum' => 0 ],
						'limit'  => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => 50 ],
					],
					'additionalProperties' => false,
				],
			],
		];
	}

	/**
	 * Initial structure and main-entry context.
	 *
	 * @return array{content:string,tree_fingerprint:string,inspected:array<string,string>,audit:array<string,mixed>}
	 */
	public function bootstrap(): array {
		$tree      = $this->tree();
		$structure = $this->tree_context( $tree );
		$main      = $this->read_file( [ 'path' => $this->main_file, 'start_line' => 1, 'end_line' => 2000 ] );
		return [
			'content'          => "Target metadata:\n" . wp_json_encode( $this->public_metadata(), JSON_PRETTY_PRINT ) . "\n\nSource structure:\n" . $structure['content'] . "\n\nMain entry file:\n" . $main['content'],
			'tree_fingerprint' => $this->fingerprint( $tree ),
			'inspected'        => $main['inspected'],
			'audit'            => [
				'main_file'       => $this->main_file,
				'main_read'       => $main['audit'],
				'structure_files' => $structure['paths'],
				'structure_total' => count( $tree ),
				'structure_truncated' => count( $structure['paths'] ) < count( $tree ),
				'metadata'        => $this->public_metadata(),
			],
		];
	}

	public function tree_fingerprint(): string {
		return $this->fingerprint( $this->tree() );
	}

	/**
	 * Return the complete bounded text-source tree used by the revision editor.
	 *
	 * @return array{files:array<int,array<string,mixed>>,directories:array<int,string>,tree_fingerprint:string}
	 */
	public function revision_tree(): array {
		$tree        = $this->tree();
		$directories = [];
		foreach ( $tree as $file ) {
			$parts = explode( '/', (string) $file['path'] );
			array_pop( $parts );
			$directory = '';
			foreach ( $parts as $part ) {
				$directory .= ( '' === $directory ? '' : '/' ) . $part;
				$directories[ $directory ] = true;
			}
		}

		return [
			'files'            => array_map(
				static fn( array $file ): array => [
					'path' => (string) $file['path'],
					'type' => (string) $file['type'],
					'size' => (int) $file['size'],
				],
				$tree
			),
			'directories'      => array_keys( $directories ),
			'tree_fingerprint' => $this->fingerprint( $tree ),
		];
	}

	/**
	 * Return bounded source-tree metadata for direct Code follow-up analysis.
	 * Source bodies remain excluded; a focused body is read separately and only
	 * after the caller has verified the revision's target fingerprint.
	 *
	 * @return array{files:array<int,array<string,mixed>>,total:int,truncated:bool,tree_fingerprint:string}
	 */
	public function code_follow_up_tree(): array {
		$tree  = $this->tree();
		$files = [];
		$bytes = 0;
		foreach ( $tree as $file ) {
			$item = [
				'path' => (string) $file['path'],
				'type' => (string) $file['type'],
				'size' => (int) $file['size'],
			];
			$encoded    = wp_json_encode( $item, JSON_UNESCAPED_SLASHES ) ?: '';
			$item_bytes = strlen( $encoded ) + ( $files ? 1 : 0 );
			if ( $bytes + $item_bytes > self::MAX_RESULT_BYTES - 1024 ) {
				break;
			}
			$files[] = $item;
			$bytes  += $item_bytes;
		}
		return [
			'files'            => $files,
			'total'            => count( $tree ),
			'truncated'        => count( $files ) < count( $tree ),
			'tree_fingerprint' => $this->fingerprint( $tree ),
		];
	}

	/**
	 * Read one complete bounded text-source file for the revision editor.
	 *
	 * @return array{path:string,type:string,size:int,content:string,content_hash:string}|\WP_Error
	 */
	public function revision_file( string $relative ) {
		try {
			$relative = $this->normalize_relative( $relative );
			$path     = $this->safe_file( $relative );
		} catch ( \Throwable $error ) {
			return new \WP_Error( 'target_file_invalid', __( 'The requested target file is not available for revision editing.', 'wp-autoplugin' ), [ 'status' => 404 ] );
		}

		$content = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Read-only constrained target file.
		if ( false === $content ) {
			return new \WP_Error( 'target_file_unreadable', __( 'The requested target file could not be read.', 'wp-autoplugin' ), [ 'status' => 500 ] );
		}

		return [
			'path'         => $relative,
			'type'         => strtolower( (string) pathinfo( $relative, PATHINFO_EXTENSION ) ),
			'size'         => strlen( $content ),
			'content'      => $content,
			'content_hash' => hash( 'sha256', $content ),
		];
	}

	/** @param array<string, string> $inspected */
	public function inspected_unchanged( array $inspected ): bool {
		foreach ( $inspected as $relative => $hash ) {
			try {
				$path = $this->safe_file( (string) $relative );
			} catch ( \Throwable $error ) {
				return false;
			}
			if ( hash_file( 'sha256', $path ) !== $hash ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Read and fingerprint only the target files named by an approved Code change set.
	 *
	 * Add paths must not exist; update and delete paths must exist, match their declared
	 * type, and remain within the same bounded source limits as generated revisions.
	 *
	 * @param array<int, array<string, mixed>> $files Normalized change-manifest files.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function code_snapshot( array $files ) {
		$tree       = $this->tree();
		$by_path    = [];
		$source     = [];
		$hashes     = [];
		$total      = 0;
		$allowed    = self::EXTENSIONS;
		foreach ( $tree as $file ) {
			$by_path[ $file['path'] ] = $file;
		}

		foreach ( $files as $file ) {
			$relative  = $this->normalize_relative( (string) ( $file['path'] ?? '' ) );
			$type      = sanitize_key( (string) ( $file['type'] ?? '' ) );
			$operation = sanitize_key( (string) ( $file['operation'] ?? '' ) );
			$existing  = $by_path[ $relative ] ?? null;
			if ( ! in_array( $type, $allowed, true ) || ! in_array( $operation, [ 'add', 'update', 'delete' ], true ) ) {
				return new \WP_Error( 'code_target_file_invalid', __( 'The approved Code change set contains an unsupported target file.', 'wp-autoplugin' ) );
			}
			if ( 'add' === $operation ) {
				$availability = $this->add_path_availability( $relative );
				if ( 'exists' === $availability ) {
					return new \WP_Error( 'code_target_add_exists', sprintf( __( '%s now exists in the target. Regenerate the Plan before generating Code.', 'wp-autoplugin' ), $relative ) );
				}
				if ( 'available' !== $availability ) {
					return new \WP_Error( 'code_target_add_path_invalid', sprintf( __( '%s cannot be added safely within the target. Regenerate the Plan before generating Code.', 'wp-autoplugin' ), $relative ) );
				}
				continue;
			}
			if ( ! $existing || $type !== (string) $existing['type'] ) {
				return new \WP_Error( 'code_target_file_missing', sprintf( __( '%s is no longer a readable target file. Regenerate the Plan before generating Code.', 'wp-autoplugin' ), $relative ) );
			}
			if ( (int) $existing['size'] > 65536 || $total + (int) $existing['size'] > 262144 ) {
				return new \WP_Error( 'code_target_context_large', __( 'The planned target files exceed the 64 KiB per-file or 256 KiB aggregate Code limit.', 'wp-autoplugin' ) );
			}
			$path    = $this->safe_file( $relative );
			$content = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Read-only constrained source inspection.
			if ( false === $content ) {
				return new \WP_Error( 'code_target_file_unreadable', sprintf( __( '%s could not be read from the target.', 'wp-autoplugin' ), $relative ) );
			}
			$total             += strlen( $content );
			$hashes[ $relative ] = hash( 'sha256', $content );
			$source[]            = [ 'path' => $relative, 'type' => $type, 'operation' => $operation, 'content' => $content ];
		}

		return [
			'target_fingerprint' => $this->fingerprint( $tree ),
			'base_hashes'        => $hashes,
			'source_files'       => $source,
			'source_bytes'       => $total,
		];
	}

	private function add_path_availability( string $relative ): string {
		if ( is_file( $this->root ) ) {
			return basename( $this->root ) === $relative ? 'exists' : 'invalid';
		}

		$current = $this->root;
		$parts   = explode( '/', $relative );
		foreach ( $parts as $index => $part ) {
			$current .= '/' . $part;
			if ( is_link( $current ) ) {
				return 'invalid';
			}
			if ( ! file_exists( $current ) ) {
				return 'available';
			}
			if ( $index === count( $parts ) - 1 ) {
				return 'exists';
			}
			if ( ! is_dir( $current ) ) {
				return 'invalid';
			}
		}

		return 'invalid';
	}

	/**
	 * @param array<string, mixed> $arguments Validated by the tool itself.
	 * @return array{content:string,bytes:int,inspected:array<string,string>,path:string,audit:array<string,mixed>,error:bool}
	 */
	public function execute( string $name, array $arguments ): array {
		try {
			$this->validate_arguments( $name, $arguments );
			return match ( $name ) {
				'list_files'         => $this->list_files( $arguments ),
				'read_file'          => $this->read_file( $arguments ),
				'search_code'        => $this->search_code( $arguments ),
				'get_target_metadata'=> $this->metadata_result(),
				'list_hooks'         => $this->list_hooks( $arguments ),
				default              => throw new \InvalidArgumentException( __( 'The model requested an unsupported source tool.', 'wp-autoplugin' ) ),
			};
		} catch ( \InvalidArgumentException | \RuntimeException $error ) {
			return $this->error_result( $name, $arguments, $error->getMessage() );
		}
	}

	/** @param array<string, mixed> $arguments */
	private function validate_arguments( string $name, array $arguments ): void {
		$allowed = match ( $name ) {
			'list_files' => [ 'offset', 'limit' ],
			'read_file' => [ 'path', 'start_line', 'end_line' ],
			'search_code' => [ 'query', 'path', 'extension' ],
			'get_target_metadata' => [],
			'list_hooks' => [ 'offset', 'limit' ],
			default => throw new \InvalidArgumentException( __( 'The model requested an unsupported source tool.', 'wp-autoplugin' ) ),
		};
		if ( array_diff( array_keys( $arguments ), $allowed ) ) {
			throw new \InvalidArgumentException( __( 'The model returned unsupported source-tool arguments.', 'wp-autoplugin' ) );
		}
		foreach ( $arguments as $value ) {
			if ( ! is_scalar( $value ) && null !== $value ) {
				throw new \InvalidArgumentException( __( 'The model returned malformed source-tool arguments.', 'wp-autoplugin' ) );
			}
		}
		if ( in_array( $name, [ 'read_file', 'search_code' ], true ) ) {
			$required = 'read_file' === $name ? 'path' : 'query';
			if ( ! isset( $arguments[ $required ] ) || '' === trim( (string) $arguments[ $required ] ) ) {
				throw new \InvalidArgumentException( __( 'The model omitted a required source-tool argument.', 'wp-autoplugin' ) );
			}
		}
	}

	/** @param array<string, mixed> $arguments */
	private function list_files( array $arguments ): array {
		$offset = max( 0, (int) ( $arguments['offset'] ?? 0 ) );
		$limit  = min( 200, max( 1, (int) ( $arguments['limit'] ?? 100 ) ) );
		$tree   = $this->tree();
		$page   = array_slice( $tree, $offset, $limit );
		do {
			$next_offset = $offset + count( $page ) < count( $tree ) ? $offset + count( $page ) : null;
			$content     = (string) wp_json_encode( [ 'files' => $page, 'offset' => $offset, 'next_offset' => $next_offset, 'total' => count( $tree ) ], JSON_PRETTY_PRINT );
			if ( strlen( $content ) <= self::MAX_RESULT_BYTES || ! $page ) {
				break;
			}
			array_pop( $page );
		} while ( true );
		return $this->result(
			$content,
			[],
			'',
			[
				'offset'         => $offset,
				'limit'          => $limit,
				'returned_count' => count( $page ),
				'total_files'    => count( $tree ),
				'returned_files' => array_column( $page, 'path' ),
			]
		);
	}

	/** @param array<string, mixed> $arguments */
	private function read_file( array $arguments ): array {
		$relative = $this->normalize_relative( (string) ( $arguments['path'] ?? '' ) );
		$path     = $this->safe_file( $relative );
		$start    = max( 1, (int) ( $arguments['start_line'] ?? 1 ) );
		$end      = max( $start, (int) ( $arguments['end_line'] ?? ( $start + 399 ) ) );
		$end      = min( $end, $start + 1999 );
		$contents = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Read-only constrained source inspection.
		if ( false === $contents ) {
			throw new \RuntimeException( __( 'The requested source file could not be read.', 'wp-autoplugin' ) );
		}
		$lines = preg_split( '/\R/', $contents );
		$slice = array_slice( $lines ?: [], $start - 1, $end - $start + 1 );
		$text  = [];
		$used  = strlen( $relative ) + 1;
		$truncated = false;
		foreach ( $slice as $index => $line ) {
			$numbered = sprintf( '%d: %s', $start + $index, $line );
			if ( $used + strlen( $numbered ) + 1 > self::MAX_RESULT_BYTES - 50 ) {
				$truncated = true;
				break;
			}
			$text[] = $numbered;
			$used  += strlen( $numbered ) + 1;
		}
		$content = $relative . "\n" . implode( "\n", $text ) . ( $truncated ? "\n[Result truncated by WP-Autoplugin]" : '' );
		$result  = $this->result(
			$content,
			[ $relative => hash( 'sha256', $contents ) ],
			$relative,
			[
				'path'       => $relative,
				'start_line' => $start,
				'end_line'   => $text ? $start + count( $text ) - 1 : $start,
				'truncated'  => $truncated,
			]
		);
		return $result;
	}

	/** @param array<string, mixed> $arguments */
	private function search_code( array $arguments ): array {
		$query = trim( (string) ( $arguments['query'] ?? '' ) );
		if ( '' === $query || strlen( $query ) > 200 ) {
			throw new \InvalidArgumentException( __( 'Source searches require a query of at most 200 characters.', 'wp-autoplugin' ) );
		}
		$prefix    = isset( $arguments['path'] ) ? $this->normalize_relative( (string) $arguments['path'], true ) : '';
		$extension = strtolower( (string) ( $arguments['extension'] ?? '' ) );
		if ( $extension && ! in_array( $extension, self::EXTENSIONS, true ) ) {
			throw new \InvalidArgumentException( __( 'The requested search extension is not supported.', 'wp-autoplugin' ) );
		}

		$hits = $inspected = [];
		$scanned_files = $scanned_bytes = 0;
		foreach ( $this->tree() as $file ) {
			$relative = (string) $file['path'];
			if ( $prefix && ! str_starts_with( $relative, $prefix ) ) {
				continue;
			}
			if ( $extension && $extension !== strtolower( pathinfo( $relative, PATHINFO_EXTENSION ) ) ) {
				continue;
			}
			if ( $scanned_files >= self::MAX_SEARCH_FILES || $scanned_bytes + (int) $file['size'] > self::MAX_SEARCH_BYTES ) {
				break;
			}
			++$scanned_files;
			$path     = $this->safe_file( $relative );
			$contents = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Read-only constrained source inspection.
			if ( false === $contents ) {
				continue;
			}
			$scanned_bytes += strlen( $contents );
			foreach ( preg_split( '/\R/', $contents ) ?: [] as $index => $line ) {
				if ( false !== stripos( $line, $query ) ) {
					$hits[]   = [ 'path' => $relative, 'line' => $index + 1, 'text' => substr( $line, 0, 500 ) ];
					$inspected[ $relative ] = hash( 'sha256', $contents );
					if ( count( $hits ) >= self::MAX_SEARCH_HITS ) {
						break 2;
					}
				}
			}
		}
		$truncated = count( $hits ) >= self::MAX_SEARCH_HITS;
		return $this->result(
			(string) wp_json_encode( [ 'query' => $query, 'hits' => $hits, 'truncated' => $truncated ], JSON_PRETTY_PRINT ),
			$inspected,
			'',
			[
				'query'         => $query,
				'path_filter'   => $prefix,
				'extension'     => $extension,
				'scanned_files' => $scanned_files,
				'scanned_bytes' => $scanned_bytes,
				'match_count'   => count( $hits ),
				'matched_files' => array_keys( $inspected ),
				'truncated'     => $truncated,
			]
		);
	}

	private function metadata_result(): array {
		$metadata = $this->public_metadata();
		return $this->result( (string) wp_json_encode( $metadata, JSON_PRETTY_PRINT ), [], '', [ 'metadata' => $metadata ] );
	}

	/** @param array<string, mixed> $arguments */
	private function list_hooks( array $arguments ): array {
		$offset = max( 0, (int) ( $arguments['offset'] ?? 0 ) );
		$limit  = min( 50, max( 1, (int) ( $arguments['limit'] ?? 25 ) ) );
		$hooks  = $inspected = [];
		$scanned_files = $scanned_bytes = 0;
		$scan_truncated = false;

		foreach ( $this->tree() as $file ) {
			$relative = (string) $file['path'];
			if ( 'php' !== $file['type'] || $this->is_skipped_hook_path( $relative ) ) {
				continue;
			}
			if ( $scanned_files >= self::MAX_HOOK_FILES || $scanned_bytes + (int) $file['size'] > self::MAX_HOOK_BYTES || count( $hooks ) >= self::MAX_HOOKS ) {
				$scan_truncated = true;
				break;
			}
			++$scanned_files;
			$path     = $this->safe_file( $relative );
			$contents = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Read-only constrained source inspection.
			if ( false === $contents ) {
				continue;
			}
			$scanned_bytes += strlen( $contents );
			$inspected[ $relative ] = hash( 'sha256', $contents );
			$discovered = $this->discover_file_hooks( $relative, $contents );
			if ( $discovered ) {
				$remaining = self::MAX_HOOKS - count( $hooks );
				$hooks = array_merge( $hooks, array_slice( $discovered, 0, $remaining ) );
				if ( count( $discovered ) > $remaining || count( $hooks ) >= self::MAX_HOOKS ) {
					$scan_truncated = true;
					break;
				}
			}
		}

		usort(
			$hooks,
			static fn( array $a, array $b ): int => [ $a['path'], $a['line'], $a['name'] ] <=> [ $b['path'], $b['line'], $b['name'] ]
		);
		$total = count( $hooks );
		$page  = array_slice( $hooks, $offset, $limit );
		do {
			$next_offset = $offset + count( $page ) < $total ? $offset + count( $page ) : null;
			$content = (string) wp_json_encode(
				[
					'hooks'          => $page,
					'offset'         => $offset,
					'next_offset'    => $next_offset,
					'total'          => $total,
					'scan_truncated' => $scan_truncated,
				],
				JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
			);
			if ( strlen( $content ) <= self::MAX_RESULT_BYTES || ! $page ) {
				break;
			}
			array_pop( $page );
		} while ( true );

		$returned_files = [];
		foreach ( $page as $hook ) {
			$returned_files[ $hook['path'] ] = true;
		}
		return $this->result(
			$content,
			$inspected,
			'',
			[
				'offset'          => $offset,
				'limit'           => $limit,
				'returned_count'  => count( $page ),
				'total_hooks'     => $total,
				'next_offset'     => $next_offset,
				'scanned_files'   => $scanned_files,
				'scanned_bytes'   => $scanned_bytes,
				'matched_files'   => array_keys( $returned_files ),
				'scan_truncated'  => $scan_truncated,
			]
		);
	}

	private function is_skipped_hook_path( string $path ): bool {
		$components = explode( '/', $path );
		return (bool) array_intersect( $components, [ 'docs', 'build', 'dist' ] );
	}

	/** @return array<int, array{name:string,type:string,path:string,line:int,context:string}> */
	private function discover_file_hooks( string $relative, string $contents ): array {
		$methods = [
			'apply_filters'            => 'filter',
			'apply_filters_ref_array'  => 'filter',
			'apply_filters_deprecated' => 'filter',
			'do_action'                => 'action',
			'do_action_ref_array'      => 'action',
			'do_action_deprecated'     => 'action',
		];
		$tokens = token_get_all( $contents );
		$lines  = preg_split( '/\R/', $contents ) ?: [];
		$hooks  = [];

		foreach ( $tokens as $index => $token ) {
			if ( ! is_array( $token ) || T_STRING !== $token[0] ) {
				continue;
			}
			$method = strtolower( $token[1] );
			if ( ! isset( $methods[ $method ] ) || $this->is_method_call( $tokens, $index ) ) {
				continue;
			}
			$open = $this->next_code_token( $tokens, $index + 1 );
			if ( null === $open || '(' !== $this->token_text( $tokens[ $open ] ) ) {
				continue;
			}
			$argument = $this->next_code_token( $tokens, $open + 1 );
			if ( null === $argument || ! is_array( $tokens[ $argument ] ) || T_CONSTANT_ENCAPSED_STRING !== $tokens[ $argument ][0] ) {
				continue;
			}
			$after_argument = $this->next_code_token( $tokens, $argument + 1 );
			if ( null === $after_argument || ! in_array( $this->token_text( $tokens[ $after_argument ] ), [ ',', ')' ], true ) ) {
				continue;
			}
			$name = $this->decode_hook_name( (string) $tokens[ $argument ][1] );
			if ( '' === $name ) {
				continue;
			}
			$line = (int) $token[2];
			$hooks[] = [
				'name'    => $name,
				'type'    => $methods[ $method ],
				'path'    => $relative,
				'line'    => $line,
				'context' => $this->hook_context( $lines, $line ),
			];
		}

		return $hooks;
	}

	/** @param array<int, mixed> $tokens */
	private function is_method_call( array $tokens, int $index ): bool {
		for ( $position = $index - 1; $position >= 0; --$position ) {
			$token = $tokens[ $position ];
			if ( is_array( $token ) && in_array( $token[0], [ T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ], true ) ) {
				continue;
			}
			return is_array( $token ) && in_array( $token[0], [ T_OBJECT_OPERATOR, T_DOUBLE_COLON ], true );
		}
		return false;
	}

	/** @param array<int, mixed> $tokens */
	private function next_code_token( array $tokens, int $index ): ?int {
		for ( $count = count( $tokens ); $index < $count; ++$index ) {
			$token = $tokens[ $index ];
			if ( is_array( $token ) && in_array( $token[0], [ T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ], true ) ) {
				continue;
			}
			return $index;
		}
		return null;
	}

	/** @param mixed $token */
	private function token_text( $token ): string {
		return is_array( $token ) ? (string) $token[1] : (string) $token;
	}

	private function decode_hook_name( string $literal ): string {
		if ( strlen( $literal ) < 2 ) {
			return '';
		}
		$quote = $literal[0];
		$value = substr( $literal, 1, -1 );
		return "'" === $quote
			? str_replace( [ '\\\\', "\\'" ], [ '\\', "'" ], $value )
			: stripcslashes( $value );
	}

	/** @param array<int, string> $lines */
	private function hook_context( array $lines, int $line ): string {
		$hook_index = max( 0, $line - 1 );
		$end        = $this->hook_statement_end( $lines, $hook_index );
		$start      = max( 0, $hook_index - self::HOOK_CONTEXT_LINES );
		$end        = min( count( $lines ) - 1, $end + self::HOOK_CONTEXT_LINES, $hook_index + 30 );
		$context    = [];
		foreach ( array_slice( $lines, $start, $end - $start + 1, true ) as $index => $text ) {
			$context[] = sprintf( '%d: %s', $index + 1, $text );
		}
		return implode( "\n", $context );
	}

	/** @param array<int, string> $lines */
	private function hook_statement_end( array $lines, int $start ): int {
		$depth = 0;
		$found = false;
		$limit = min( count( $lines ), $start + 30 );
		for ( $index = $start; $index < $limit; ++$index ) {
			$length = strlen( $lines[ $index ] );
			for ( $character = 0; $character < $length; ++$character ) {
				if ( '(' === $lines[ $index ][ $character ] ) {
					++$depth;
					$found = true;
				} elseif ( ')' === $lines[ $index ][ $character ] && $found ) {
					$depth = max( 0, $depth - 1 );
				} elseif ( ';' === $lines[ $index ][ $character ] && $found && 0 === $depth ) {
					return $index;
				}
			}
		}
		return max( $start, $limit - 1 );
	}

	/** @return array<string, mixed> */
	private function public_metadata(): array {
		return array_intersect_key( $this->target, array_flip( [ 'kind', 'ref', 'name', 'version', 'author', 'description', 'active', 'source_files', 'lines', 'tokens', 'hooks' ] ) );
	}

	/** @return array<int, array{path:string,size:int,modified:int,type:string}> */
	private function tree(): array {
		$root = trailingslashit( wp_normalize_path( $this->root ) );
		$files = [];
		if ( is_file( $this->root ) ) {
			return [ $this->describe_file( $this->root, basename( $this->root ) ) ];
		}
		$directory = new \RecursiveDirectoryIterator( $root, \FilesystemIterator::SKIP_DOTS );
		$filter = new \RecursiveCallbackFilterIterator(
			$directory,
			static function ( \SplFileInfo $file ): bool {
				return ! $file->isDir() || ! in_array( $file->getFilename(), self::SKIPPED, true );
			}
		);
		foreach ( new \RecursiveIteratorIterator( $filter ) as $file ) {
			if ( count( $files ) >= self::MAX_TREE_FILES || ! $file->isFile() || $file->isLink() || $file->getSize() > self::MAX_FILE_BYTES ) {
				continue;
			}
			$extension = strtolower( $file->getExtension() );
			if ( ! in_array( $extension, self::EXTENSIONS, true ) ) {
				continue;
			}
			$path = wp_normalize_path( $file->getRealPath() ?: '' );
			if ( ! str_starts_with( $path, $root ) ) {
				continue;
			}
			$files[] = $this->describe_file( $path, ltrim( substr( $path, strlen( $root ) ), '/' ) );
		}
		usort( $files, static fn( array $a, array $b ): int => strcmp( $a['path'], $b['path'] ) );
		return $files;
	}

	private function describe_file( string $path, string $relative ): array {
		return [ 'path' => $relative, 'size' => (int) filesize( $path ), 'modified' => (int) filemtime( $path ), 'type' => strtolower( pathinfo( $relative, PATHINFO_EXTENSION ) ) ];
	}

	/** @param array<int, array<string, mixed>> $tree */
	private function fingerprint( array $tree ): string {
		return hash( 'sha256', (string) wp_json_encode( $tree ) );
	}

	/** @param array<int, array<string, mixed>> $tree @return array{content:string,paths:array<int,string>} */
	private function tree_context( array $tree ): array {
		$lines = $paths = [];
		$bytes = 0;
		foreach ( $tree as $file ) {
			$line = sprintf( '%s (%d bytes)', $file['path'], $file['size'] );
			if ( $bytes + strlen( $line ) + 1 > self::MAX_RESULT_BYTES - 50 ) {
				break;
			}
			$lines[] = $line;
			$paths[] = (string) $file['path'];
			$bytes  += strlen( $line ) + 1;
		}
		$content = implode( "\n", $lines );
		if ( count( $paths ) < count( $tree ) ) {
			$content .= "\n[Structure truncated by WP-Autoplugin]";
		}
		return [ 'content' => $content, 'paths' => $paths ];
	}

	private function safe_file( string $relative ): string {
		$relative = $this->normalize_relative( $relative );
		if ( is_file( $this->root ) && basename( $this->root ) !== $relative ) {
			throw new \InvalidArgumentException( __( 'The requested source path is not readable.', 'wp-autoplugin' ) );
		}
		$path     = is_file( $this->root ) ? $this->root : trailingslashit( $this->root ) . $relative;
		$real     = realpath( $path );
		$root     = wp_normalize_path( $this->root );
		$real     = false === $real ? '' : wp_normalize_path( $real );
		$inside   = is_file( $root ) ? $real === $root : str_starts_with( $real, trailingslashit( $root ) );
		if ( ! $inside || ! is_file( $real ) || $this->has_symlink_component( $relative ) || filesize( $real ) > self::MAX_FILE_BYTES || ! in_array( strtolower( pathinfo( $real, PATHINFO_EXTENSION ) ), self::EXTENSIONS, true ) ) {
			throw new \InvalidArgumentException( __( 'The requested source path is not readable.', 'wp-autoplugin' ) );
		}
		return $real;
	}

	private function has_symlink_component( string $relative ): bool {
		if ( is_file( $this->root ) ) {
			return is_link( $this->root );
		}
		$current = $this->root;
		foreach ( explode( '/', $relative ) as $component ) {
			$current .= '/' . $component;
			if ( is_link( $current ) ) {
				return true;
			}
		}
		return false;
	}

	private function normalize_relative( string $path, bool $allow_directory = false ): string {
		$path = ltrim( wp_normalize_path( trim( $path ) ), '/' );
		if ( '' === $path && $allow_directory ) {
			return '';
		}
		if ( '' === $path || str_contains( $path, "\0" ) || preg_match( '#(^|/)\.\.(/|$)#', $path ) || preg_match( '#^[A-Za-z]:/#', $path ) ) {
			throw new \InvalidArgumentException( __( 'The requested source path is invalid.', 'wp-autoplugin' ) );
		}
		return $path;
	}

	/** @param array<string, string> $inspected @param array<string, mixed> $audit */
	private function result( string $content, array $inspected = [], string $path = '', array $audit = [] ): array {
		$content = $this->truncate( $content );
		return [ 'content' => $content, 'bytes' => strlen( $content ), 'inspected' => $inspected, 'path' => $path, 'audit' => $audit, 'error' => false ];
	}

	/** @param array<string, mixed> $arguments */
	private function error_result( string $name, array $arguments, string $message ): array {
		$path    = isset( $arguments['path'] ) && is_scalar( $arguments['path'] ) ? substr( trim( (string) $arguments['path'] ), 0, 500 ) : '';
		$content = (string) wp_json_encode(
			[
				'error' => $message,
				'tool'  => sanitize_key( $name ),
				'path'  => $path,
			],
			JSON_PRETTY_PRINT
		);
		return [
			'content'   => $this->truncate( $content ),
			'bytes'     => strlen( $content ),
			'inspected' => [],
			'path'      => $path,
			'audit'     => [ 'tool' => sanitize_key( $name ), 'requested_path' => $path, 'error' => $message ],
			'error'     => true,
		];
	}

	private function truncate( string $content ): string {
		if ( strlen( $content ) <= self::MAX_RESULT_BYTES ) {
			return $content;
		}
		return substr( $content, 0, self::MAX_RESULT_BYTES - 50 ) . "\n[Result truncated by WP-Autoplugin]";
	}

	/** @param array<string, mixed> $target */
	private function resolve_root( array $target ): string {
		$kind = (string) ( $target['kind'] ?? '' );
		$ref  = (string) ( $target['ref'] ?? '' );
		if ( 'plugin' === $kind ) {
			$directory = dirname( $ref );
			$path = WP_PLUGIN_DIR . '/' . ( '.' === $directory ? $ref : $directory );
		} elseif ( 'theme' === $kind ) {
			$theme = wp_get_theme( $ref );
			$path  = $theme->exists() ? $theme->get_stylesheet_directory() : '';
		} else {
			$path = '';
		}
		$real = $path ? realpath( $path ) : false;
		if ( false === $real ) {
			throw new \InvalidArgumentException( __( 'The source target is no longer available.', 'wp-autoplugin' ) );
		}
		return wp_normalize_path( $real );
	}

	/** @param array<string, mixed> $target */
	private function resolve_main_file( array $target ): string {
		if ( 'plugin' === ( $target['kind'] ?? '' ) ) {
			$ref = (string) $target['ref'];
			return '.' === dirname( $ref ) ? basename( $ref ) : basename( $ref );
		}
		foreach ( [ 'functions.php', 'style.css', 'index.php' ] as $candidate ) {
			if ( is_file( trailingslashit( $this->root ) . $candidate ) ) {
				return $candidate;
			}
		}
		$tree = $this->tree();
		if ( empty( $tree ) ) {
			throw new \InvalidArgumentException( __( 'There is no readable source in this target.', 'wp-autoplugin' ) );
		}
		return (string) $tree[0]['path'];
	}
}
