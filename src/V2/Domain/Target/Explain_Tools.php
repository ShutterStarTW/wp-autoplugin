<?php

namespace WP_Autoplugin\V2\Domain\Target;

/**
 * Bounded, read-only source tools for the Explain agent.
 */
final class Explain_Tools {
	private const EXTENSIONS       = [ 'php', 'js', 'jsx', 'ts', 'tsx', 'css', 'scss', 'json', 'md', 'txt', 'xml', 'html' ];
	private const SKIPPED          = [ '.git', 'node_modules', 'vendor', 'tests' ];
	private const MAX_FILE_BYTES   = 262144;
	private const MAX_RESULT_BYTES = 65536;
	private const MAX_TREE_FILES   = 2000;
	private const MAX_SEARCH_FILES = 200;
	private const MAX_SEARCH_BYTES = 2097152;
	private const MAX_SEARCH_HITS  = 50;

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
				'description' => 'Read a source file with line numbers. Use start_line and end_line for large files.',
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
		];
	}

	/**
	 * Initial structure and main-entry context.
	 *
	 * @return array{content:string,tree_fingerprint:string,inspected:array<string,string>}
	 */
	public function bootstrap(): array {
		$tree = $this->tree();
		$main = $this->read_file( [ 'path' => $this->main_file, 'start_line' => 1, 'end_line' => 2000 ] );
		return [
			'content'          => "Target metadata:\n" . wp_json_encode( $this->public_metadata(), JSON_PRETTY_PRINT ) . "\n\nSource structure:\n" . $this->tree_text( $tree ) . "\n\nMain entry file:\n" . $main['content'],
			'tree_fingerprint' => $this->fingerprint( $tree ),
			'inspected'        => $main['inspected'],
		];
	}

	public function tree_fingerprint(): string {
		return $this->fingerprint( $this->tree() );
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
	 * @param array<string, mixed> $arguments Validated by the tool itself.
	 * @return array{content:string,bytes:int,inspected:array<string,string>,path:string}
	 */
	public function execute( string $name, array $arguments ): array {
		$this->validate_arguments( $name, $arguments );
		return match ( $name ) {
			'list_files'         => $this->list_files( $arguments ),
			'read_file'          => $this->read_file( $arguments ),
			'search_code'        => $this->search_code( $arguments ),
			'get_target_metadata'=> $this->metadata_result(),
			default              => throw new \InvalidArgumentException( __( 'The model requested an unsupported source tool.', 'wp-autoplugin' ) ),
		};
	}

	/** @param array<string, mixed> $arguments */
	private function validate_arguments( string $name, array $arguments ): void {
		$allowed = match ( $name ) {
			'list_files' => [ 'offset', 'limit' ],
			'read_file' => [ 'path', 'start_line', 'end_line' ],
			'search_code' => [ 'query', 'path', 'extension' ],
			'get_target_metadata' => [],
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
		$content = wp_json_encode( [ 'files' => $page, 'offset' => $offset, 'next_offset' => $offset + count( $page ) < count( $tree ) ? $offset + count( $page ) : null, 'total' => count( $tree ) ], JSON_PRETTY_PRINT );
		return $this->result( (string) $content );
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
		foreach ( $slice as $index => $line ) {
			$text[] = sprintf( '%d: %s', $start + $index, $line );
		}
		$content = $relative . "\n" . implode( "\n", $text );
		$result  = $this->result( $content, [ $relative => hash( 'sha256', $contents ) ], $relative );
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
			if ( ++$scanned_files > self::MAX_SEARCH_FILES || $scanned_bytes + (int) $file['size'] > self::MAX_SEARCH_BYTES ) {
				break;
			}
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
		return $this->result( (string) wp_json_encode( [ 'query' => $query, 'hits' => $hits, 'truncated' => count( $hits ) >= self::MAX_SEARCH_HITS ], JSON_PRETTY_PRINT ), $inspected );
	}

	private function metadata_result(): array {
		return $this->result( (string) wp_json_encode( $this->public_metadata(), JSON_PRETTY_PRINT ) );
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

	/** @param array<int, array<string, mixed>> $tree */
	private function tree_text( array $tree ): string {
		$lines = array_map( static fn( array $file ): string => sprintf( '%s (%d bytes)', $file['path'], $file['size'] ), $tree );
		return $this->truncate( implode( "\n", $lines ) );
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

	/** @param array<string, string> $inspected */
	private function result( string $content, array $inspected = [], string $path = '' ): array {
		$content = $this->truncate( $content );
		return [ 'content' => $content, 'bytes' => strlen( $content ), 'inspected' => $inspected, 'path' => $path ];
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
			throw new \InvalidArgumentException( __( 'The Explain target is no longer available.', 'wp-autoplugin' ) );
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
