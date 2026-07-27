<?php

namespace WP_Autoplugin\V2\Domain\Target;

/**
 * Discovers local plugin/theme targets and calculates bounded source statistics.
 */
final class Target_Scanner {
	private const SUPPORTED_EXTENSIONS = [ 'php', 'js', 'jsx', 'ts', 'tsx', 'css', 'scss', 'json', 'html', 'svg', 'xml', 'md', 'txt' ];
	private const SKIPPED_DIRECTORIES  = [ '.git', 'node_modules', 'vendor', 'tests' ];

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public function all(): array {
		return array_merge( [ $this->new_plugin_target() ], $this->plugins(), $this->themes() );
	}

	/**
	 * @return array<string, mixed>|null
	 */
	public function find( string $kind, string $ref ): ?array {
		if ( 'new_plugin' === $kind && 'new' === $ref ) {
			return $this->new_plugin_target();
		}

		foreach ( $this->all() as $target ) {
			if ( $kind === $target['kind'] && $ref === $target['ref'] ) {
				return $target;
			}
		}

		return null;
	}

	/**
	 * Refresh volatile theme identity and in-use state without rescanning source statistics.
	 *
	 * @param array<string, mixed> $metadata Persisted target metadata.
	 * @return array<string, mixed>
	 */
	public function refresh_metadata( string $kind, string $ref, array $metadata ): array {
		if ( 'theme' !== $kind ) {
			return $metadata;
		}
		$theme = wp_get_theme( $ref );
		if ( ! $theme->exists() ) {
			return $metadata;
		}
		return array_merge(
			$metadata,
			[
				'kind' => 'theme',
				'ref'  => $ref,
				'name' => (string) $theme->get( 'Name' ),
			],
			$this->theme_identity(
				$theme,
				is_array( $metadata['parent_theme'] ?? null ) ? (array) $metadata['parent_theme'] : null
			)
		);
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	private function plugins(): array {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$active  = (array) get_option( 'active_plugins', [] );
		$targets = [];

		foreach ( get_plugins() as $file => $data ) {
			$directory = dirname( $file );
			$root      = wp_normalize_path( WP_PLUGIN_DIR . '/' . ( '.' === $directory ? $file : $directory ) );
			$targets[] = $this->describe(
				'plugin',
				$file,
				(string) $data['Name'],
				$root,
				[
					'version'     => (string) $data['Version'],
					'author'      => wp_strip_all_tags( (string) $data['Author'] ),
					'description' => wp_strip_all_tags( (string) $data['Description'] ),
					'active'      => is_plugin_active( $file ) || in_array( $file, $active, true ),
				]
			);
		}

		return $targets;
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	private function themes(): array {
		$targets = [];
		foreach ( wp_get_themes() as $slug => $theme ) {
			$targets[] = $this->describe(
				'theme',
				(string) $slug,
				(string) $theme->get( 'Name' ),
				wp_normalize_path( $theme->get_stylesheet_directory() ),
				$this->theme_identity( $theme )
			);
		}

		return $targets;
	}

	/** @return array<string, mixed> */
	private function theme_identity( \WP_Theme $theme, ?array $persisted_parent = null ): array {
		$stylesheet    = (string) $theme->get_stylesheet();
		$template      = (string) $theme->get_template();
		$is_child      = '' !== $template && $template !== $stylesheet;
		$parent        = $is_child ? $theme->parent() : false;
		$parent_valid  = $is_child && $parent instanceof \WP_Theme && $parent->exists() && ! $parent->errors();
		$active_child  = get_stylesheet() === $stylesheet;
		$active_parent = get_template() === $stylesheet;
		$parent_theme  = $parent_valid ? $this->describe_parent_theme( $parent, $persisted_parent ) : null;
		return [
			'version'              => (string) $theme->get( 'Version' ),
			'author'               => wp_strip_all_tags( (string) $theme->get( 'Author' ) ),
			'description'          => wp_strip_all_tags( (string) $theme->get( 'Description' ) ),
			'active'               => $active_child,
			'stylesheet'           => $stylesheet,
			'template'             => $template,
			'is_child'             => $is_child,
			'is_block_theme'       => $theme->is_block_theme(),
			'parent_ref'           => $is_child ? $template : '',
			'parent_available'     => $parent_valid,
			'parent_name'          => $parent instanceof \WP_Theme ? (string) $parent->get( 'Name' ) : '',
			'parent_version'       => $parent instanceof \WP_Theme ? (string) $parent->get( 'Version' ) : '',
			'parent_theme'         => $parent_theme,
			'active_as_stylesheet' => $active_child,
			'active_as_template'   => $active_parent,
			'in_use'               => $active_child || $active_parent,
		];
	}

	/** @return array<string, mixed> */
	private function describe_parent_theme( \WP_Theme $theme, ?array $persisted = null ): array {
		$stylesheet = (string) $theme->get_stylesheet();
		$template   = (string) $theme->get_template();
		$stats      = is_array( $persisted ) && $stylesheet === ( $persisted['ref'] ?? '' )
			? array_intersect_key( $persisted, array_flip( [ 'source_files', 'lines', 'tokens', 'hooks' ] ) )
			: [];
		if ( count( $stats ) < 4 ) {
			$stats = $this->source_stats( wp_normalize_path( $theme->get_stylesheet_directory() ) );
		}

		return array_merge(
			[
				'kind'                 => 'theme',
				'ref'                  => $stylesheet,
				'name'                 => (string) $theme->get( 'Name' ),
				'version'              => (string) $theme->get( 'Version' ),
				'author'               => wp_strip_all_tags( (string) $theme->get( 'Author' ) ),
				'description'          => wp_strip_all_tags( (string) $theme->get( 'Description' ) ),
				'active'               => get_template() === $stylesheet,
				'stylesheet'           => $stylesheet,
				'template'             => $template,
				'is_child'             => '' !== $template && $template !== $stylesheet,
				'is_block_theme'       => $theme->is_block_theme(),
				'active_as_stylesheet' => get_stylesheet() === $stylesheet,
				'active_as_template'   => get_template() === $stylesheet,
				'in_use'               => get_stylesheet() === $stylesheet || get_template() === $stylesheet,
			],
			$stats
		);
	}

	/**
	 * @param array<string, mixed> $metadata Target header metadata.
	 * @return array<string, mixed>
	 */
	private function describe( string $kind, string $ref, string $name, string $root, array $metadata ): array {
		$stats = $this->source_stats( $root );

		return array_merge(
			[
				'kind' => $kind,
				'ref'  => $ref,
				'name' => $name,
			],
			$metadata,
			$stats
		);
	}

	/**
	 * @return array<string, int>
	 */
	private function source_stats( string $root ): array {
		$root_real = realpath( $root );
		if ( false === $root_real ) {
			return [ 'source_files' => 0, 'lines' => 0, 'tokens' => 0, 'hooks' => 0 ];
		}
		if ( is_file( $root_real ) ) {
			$extension = strtolower( pathinfo( $root_real, PATHINFO_EXTENSION ) );
			if ( ! in_array( $extension, self::SUPPORTED_EXTENSIONS, true ) || filesize( $root_real ) > 1048576 ) {
				return [ 'source_files' => 0, 'lines' => 0, 'tokens' => 0, 'hooks' => 0 ];
			}
			$content = file_get_contents( $root_real ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Read-only local source inspection.
			if ( false === $content ) {
				return [ 'source_files' => 0, 'lines' => 0, 'tokens' => 0, 'hooks' => 0 ];
			}
			preg_match_all( '/\b(?:do_action|apply_filters|add_action|add_filter)\s*\(\s*[\'\"]([^\'\"]+)[\'\"]/', $content, $matches );
			return [
				'source_files' => 1,
				'lines'        => '' === $content ? 0 : substr_count( $content, "\n" ) + 1,
				'tokens'       => (int) ceil( strlen( $content ) / 4 ),
				'hooks'        => count( array_unique( $matches[1] ?? [] ) ),
			];
		}
		if ( ! is_dir( $root_real ) ) {
			return [ 'source_files' => 0, 'lines' => 0, 'tokens' => 0, 'hooks' => 0 ];
		}

		$root_real = trailingslashit( wp_normalize_path( $root_real ) );
		$stats     = [ 'source_files' => 0, 'lines' => 0, 'tokens' => 0, 'hooks' => 0 ];
		$hook_names = [];
		$directory = new \RecursiveDirectoryIterator( $root_real, \FilesystemIterator::SKIP_DOTS );
		$filter    = new \RecursiveCallbackFilterIterator(
			$directory,
			static function ( \SplFileInfo $file ): bool {
				return ! $file->isDir() || ! in_array( $file->getFilename(), self::SKIPPED_DIRECTORIES, true );
			}
		);

		foreach ( new \RecursiveIteratorIterator( $filter ) as $file ) {
			if ( ! $file->isFile() || $file->isLink() ) {
				continue;
			}

			$extension = strtolower( $file->getExtension() );
			if ( ! in_array( $extension, self::SUPPORTED_EXTENSIONS, true ) || $file->getSize() > 1048576 ) {
				continue;
			}

			$path = wp_normalize_path( $file->getRealPath() ?: '' );
			if ( ! str_starts_with( $path, $root_real ) ) {
				continue;
			}

			$content = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Read-only local source inspection.
			if ( false === $content ) {
				continue;
			}

			++$stats['source_files'];
			$stats['lines']  += '' === $content ? 0 : substr_count( $content, "\n" ) + 1;
			$stats['tokens'] += (int) ceil( strlen( $content ) / 4 );

			if ( 'php' === $extension ) {
				preg_match_all( '/\b(?:do_action|apply_filters|add_action|add_filter)\s*\(\s*[\'\"]([^\'\"]+)[\'\"]/', $content, $matches );
				$hook_names = array_merge( $hook_names, $matches[1] ?? [] );
			}
		}
		$stats['hooks'] = count( array_unique( $hook_names ) );

		return $stats;
	}

	/**
	 * @return array<string, mixed>
	 */
	private function new_plugin_target(): array {
		return [
			'kind'        => 'new_plugin',
			'ref'         => 'new',
			'name'        => __( 'New plugin', 'wp-autoplugin' ),
			'version'     => '',
			'author'      => '',
			'description' => __( 'Start a new local plugin project.', 'wp-autoplugin' ),
			'active'      => false,
			'source_files'=> 0,
			'lines'       => 0,
			'tokens'      => 0,
			'hooks'       => 0,
		];
	}
}
