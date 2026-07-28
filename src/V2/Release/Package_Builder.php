<?php

namespace WP_Autoplugin\V2\Release;

use WP_Autoplugin\V2\Domain\Revision\Version_Bumper;
use WP_Autoplugin\V2\Domain\Target\Source_Tools;

/** Builds bounded, revision-exact private plugin ZIP archives. */
final class Package_Builder {
	private const VCS = [ '.git', '.svn', '.hg' ];

	/** @return array<string,mixed>|\WP_Error */
	public function build( array $workspace, array $revision, string $mode, string $destination_slug = '' ) {
		$manifest = (array) ( $revision['project_manifest'] ?? [] );
		if ( ! in_array( $mode, [ 'project', 'fork', 'replacement' ], true ) ) {
			return new \WP_Error( 'release_package_mode', __( 'The requested package mode is invalid.', 'wp-autoplugin' ) );
		}
		if ( 'project' === $mode && 'project' !== ( $manifest['scope'] ?? '' ) ) {
			return new \WP_Error( 'release_package_matrix', __( 'A project ZIP requires a complete plugin revision.', 'wp-autoplugin' ) );
		}
		if ( in_array( $mode, [ 'fork', 'replacement' ], true ) && ( 'changes' !== ( $manifest['scope'] ?? '' ) || 'plugin' !== ( $manifest['artifact_kind'] ?? '' ) ) ) {
			return new \WP_Error( 'release_package_matrix', __( 'Fork and replacement ZIPs require an installed-plugin change revision.', 'wp-autoplugin' ) );
		}

		$private = $this->private_root();
		if ( is_wp_error( $private ) ) {
			return $private;
		}
		$token   = wp_generate_password( 32, false, false );
		$work    = $private . '/build-' . $token;
		$archive = $private . '/package-' . $token . '.zip';
		if ( ! wp_mkdir_p( $work ) ) {
			return new \WP_Error( 'release_temp_unavailable', __( 'A private package workspace could not be created.', 'wp-autoplugin' ) );
		}
		@chmod( $work, 0700 );
		try {
			$slug = 'project' === $mode
				? $this->slug( $destination_slug ?: sanitize_title( (string) ( $manifest['plugin_name'] ?? '' ) ) )
				: ( 'fork' === $mode ? $this->slug( $destination_slug ) : $this->target_slug( (string) $workspace['target_ref'] ) );
			if ( is_wp_error( $slug ) ) {
				return $slug;
			}
			if ( 'fork' === $mode && file_exists( WP_PLUGIN_DIR . '/' . $slug ) ) {
				return new \WP_Error( 'release_fork_collision', __( 'The selected fork slug is already installed.', 'wp-autoplugin' ) );
			}
			$root = $work . '/' . $slug;
			if ( ! wp_mkdir_p( $root ) ) {
				return new \WP_Error( 'release_temp_unavailable', __( 'The private package tree could not be created.', 'wp-autoplugin' ) );
			}
			$headers            = [];
			$source_fingerprint = null;
			if ( 'project' === $mode ) {
				foreach ( (array) $revision['files'] as $file ) {
					if ( 'delete' === ( $file['change_type'] ?? '' ) ) {
						throw new \RuntimeException( __( 'A complete plugin project cannot contain a deleted file.', 'wp-autoplugin' ) );
					}
					$this->write( $root, (string) $file['path'], (string) $file['content'] );
				}
				$main_relative = (string) $manifest['main_file'];
			} else {
				$source = $this->target_root( (string) $workspace['target_ref'] );
				if ( is_wp_error( $source ) ) {
					return $source;
				}
				try {
					$current_fingerprint = ( new Source_Tools( (array) $workspace['target_metadata'] ) )->tree_fingerprint();
				} catch ( \Throwable $error ) {
					return new \WP_Error( 'release_target_unavailable', __( 'The installed plugin is unavailable for packaging.', 'wp-autoplugin' ) );
				}
				if ( ! hash_equals( (string) ( $manifest['target_fingerprint'] ?? '' ), $current_fingerprint ) ) {
					return new \WP_Error( 'release_target_changed', __( 'The installed plugin changed after this revision was staged.', 'wp-autoplugin' ) );
				}
					$complete_source = $this->fingerprint_target( (string) $workspace['target_ref'] );
				if ( is_wp_error( $complete_source ) ) {
					return $complete_source;
				}
					$expected_complete = (string) ( $manifest['complete_target_fingerprint'] ?? '' );
				if ( '' !== $expected_complete && ! hash_equals( $expected_complete, $complete_source['fingerprint'] ) ) {
					return new \WP_Error( 'release_complete_target_changed', __( 'The complete installed plugin tree changed after this revision was staged.', 'wp-autoplugin' ) );
				}
					$baseline = $this->verify_overlay_baseline( $source, (array) $revision['files'] );
				if ( is_wp_error( $baseline ) ) {
					return $baseline;
				}
				$scan = $this->copy_target( $source, $root );
				if ( is_wp_error( $scan ) ) {
					return $scan;
				}
				$copied = $this->scan_tree( $root );
				if ( is_wp_error( $copied ) ) {
					return $copied;
				}
				if ( ! hash_equals( $complete_source['fingerprint'], $copied['fingerprint'] ) ) {
					return new \WP_Error( 'release_copy_drift', __( 'The installed plugin changed while its package tree was being copied.', 'wp-autoplugin' ) );
				}
					$source_fingerprint = $complete_source['fingerprint'];
				foreach ( (array) $revision['files'] as $file ) {
					$path = (string) $file['path'];
					if ( 'delete' === ( $file['change_type'] ?? '' ) ) {
						$this->remove( $root, $path );
					} else {
						$this->write( $root, $path, (string) $file['content'] );
					}
				}
				$main_relative = basename( (string) $workspace['target_ref'] );
				$main_path     = $this->safe_destination( $root, $main_relative );
				$main          = file_get_contents( $main_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Private bounded build tree.
				if ( false === $main ) {
					throw new \RuntimeException( __( 'The package main plugin file could not be read.', 'wp-autoplugin' ) );
				}
				if ( 'fork' === $mode ) {
					$transformed = $this->fork_headers( $main, (string) ( $workspace['target_metadata']['name'] ?? $workspace['project_name'] ?? '' ), $slug );
				} else {
					$transformed = [
						'content'    => ( new Version_Bumper() )->bump( $main ),
						'transforms' => [ 'version' => 'semantic_patch' ],
					];
				}
				$this->write( $root, $main_relative, $transformed['content'] );
				$headers = $transformed['transforms'];
			}

			$tree = $this->scan_tree( $root );
			if ( is_wp_error( $tree ) ) {
				return $tree;
			}
			$main = $this->safe_destination( $root, $main_relative );
			if ( ! is_file( $main ) || ! $this->valid_plugin_header( $main ) ) {
				return new \WP_Error( 'release_plugin_header', __( 'The package main file does not contain a valid plugin header.', 'wp-autoplugin' ) );
			}
			$zipped = $this->zip( $work, $archive );
			if ( is_wp_error( $zipped ) ) {
				return $zipped;
			}
			@chmod( $archive, 0600 );
			$size = filesize( $archive );
			$hash = hash_file( 'sha256', $archive );
			if ( false === $size || false === $hash ) {
				wp_delete_file( $archive );
				return new \WP_Error( 'release_package_verify', __( 'The completed package could not be verified.', 'wp-autoplugin' ) );
			}
			return [
				'path'                    => $archive,
				'sha256'                  => $hash,
				'size'                    => (int) $size,
				'slug'                    => $slug,
				'artifact_kind'           => 'plugin',
				'target_ref'              => $slug . '/' . $main_relative,
				'source_tree_fingerprint' => $source_fingerprint,
				'tree_fingerprint'        => $tree['fingerprint'],
				'header_transforms'       => $headers,
			];
		} catch ( \Throwable $error ) {
			if ( is_file( $archive ) ) {
				wp_delete_file( $archive );
			}
			return new \WP_Error( 'release_package_failed', $error->getMessage() );
		} finally {
			$this->delete_tree( $work, $private );
		}
	}

	/** @return array{fingerprint:string,files:int,size:int}|\WP_Error */
	public function scan_tree( string $root, bool $exclude_vcs = false, bool $enforce_limits = true ) {
		$root_real = realpath( $root );
		if ( false === $root_real || ! is_dir( $root_real ) ) {
			return new \WP_Error( 'release_tree_missing', __( 'The release tree is unavailable.', 'wp-autoplugin' ) );
		}
		$max_files = max( 1, (int) apply_filters( 'wp_autoplugin_v2_release_max_files', 25000 ) );
		$max_total = max( 1, (int) apply_filters( 'wp_autoplugin_v2_release_max_bytes', 268435456 ) );
		$max_file  = max( 1, (int) apply_filters( 'wp_autoplugin_v2_release_max_file_bytes', 67108864 ) );
		$rows      = [];
		$total     = 0;
		$iterator  = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $root_real, \FilesystemIterator::SKIP_DOTS ) );
		foreach ( $iterator as $file ) {
			if ( $file->isLink() ) {
				return new \WP_Error( 'release_symlink', __( 'Release packages cannot contain symbolic links.', 'wp-autoplugin' ) );
			}
			if ( ! $file->isFile() ) {
				continue;
			}
			$relative = ltrim( substr( wp_normalize_path( $file->getPathname() ), strlen( trailingslashit( wp_normalize_path( $root_real ) ) ) ), '/' );
			if ( $exclude_vcs && array_intersect( self::VCS, explode( '/', $relative ) ) ) {
				continue;
			}
			$this->relative( $relative );
			$size   = (int) $file->getSize();
			$total += $size;
			if ( $enforce_limits && ( count( $rows ) + 1 > $max_files || $size > $max_file || $total > $max_total ) ) {
				return new \WP_Error( 'release_tree_limit', __( 'The release tree exceeds the configured package file or size limit.', 'wp-autoplugin' ) );
			}
			$hash = hash_file( 'sha256', $file->getPathname() );
			if ( false === $hash ) {
				return new \WP_Error( 'release_tree_read', __( 'A release file could not be fingerprinted.', 'wp-autoplugin' ) );
			}
			$rows[] = $relative . "\0" . $hash . "\0" . $size;
		}
		sort( $rows, SORT_STRING );
		return [
			'fingerprint' => hash( 'sha256', implode( "\n", $rows ) ),
			'files'       => count( $rows ),
			'size'        => $total,
		];
	}

	/** Fingerprint the complete bounded installed plugin or theme tree, excluding only VCS metadata. */
	public function fingerprint_target( string $target_ref, bool $enforce_limits = true, string $artifact_kind = 'plugin' ) {
		$root = $this->target_root( $target_ref, $artifact_kind );
		if ( is_wp_error( $root ) ) {
			return $root;
		}
		if ( is_dir( $root ) ) {
			return $this->scan_tree( $root, true, $enforce_limits );
		}
		$size      = filesize( $root );
		$max_file  = max( 1, (int) apply_filters( 'wp_autoplugin_v2_release_max_file_bytes', 67108864 ) );
		$max_total = max( 1, (int) apply_filters( 'wp_autoplugin_v2_release_max_bytes', 268435456 ) );
		$hash      = hash_file( 'sha256', $root );
		if ( false === $size || false === $hash ) {
			return new \WP_Error( 'release_tree_read', __( 'The single-file plugin could not be fingerprinted.', 'wp-autoplugin' ) );
		}
		if ( $enforce_limits && ( $size > $max_file || $size > $max_total ) ) {
			return new \WP_Error( 'release_tree_limit', __( 'The plugin tree exceeds the configured package file or size limit.', 'wp-autoplugin' ) );
		}
		$row = basename( $root ) . "\0" . $hash . "\0" . (int) $size;
		return [
			'fingerprint' => hash( 'sha256', $row ),
			'files'       => 1,
			'size'        => (int) $size,
		];
	}

	/** @return string|\WP_Error */
	public function target_root( string $target_ref, string $artifact_kind = 'plugin' ) {
		$target_ref = wp_normalize_path( $target_ref );
		if ( str_starts_with( $target_ref, '/' ) || str_contains( $target_ref, '..' ) || preg_match( '/[\x00-\x1F]/', $target_ref ) ) {
			return new \WP_Error( 'release_target_path', __( 'The release target path is invalid.', 'wp-autoplugin' ) );
		}

		if ( 'theme' === $artifact_kind ) {
			$theme    = wp_get_theme( $target_ref );
			$path     = $theme->exists() ? $theme->get_stylesheet_directory() : '';
			$boundary = $theme->exists() ? $theme->get_theme_root() : '';
		} else {
			$directory = dirname( $target_ref );
			$path      = WP_PLUGIN_DIR . '/' . ( '.' === $directory ? $target_ref : $directory );
			$boundary  = WP_PLUGIN_DIR;
		}

		$real      = $path ? realpath( $path ) : false;
		$root_real = $boundary ? realpath( $boundary ) : false;
		$root      = trailingslashit( wp_normalize_path( $root_real ?: $boundary ) );
		if ( false === $real || '' === $root || ! str_starts_with( trailingslashit( wp_normalize_path( $real ) ), $root ) || $this->has_symlink_component( $path, $boundary ) ) {
			return new \WP_Error( 'release_target_path', __( 'The release target path is unavailable or unsafe.', 'wp-autoplugin' ) );
		}
		return wp_normalize_path( $real );
	}

	private function has_symlink_component( string $path, string $boundary ): bool {
		$path     = wp_normalize_path( $path );
		$boundary = untrailingslashit( wp_normalize_path( $boundary ) );
		if ( '' === $boundary || ! str_starts_with( $path, trailingslashit( $boundary ) ) ) {
			return true;
		}
		$current = $boundary;
		foreach ( explode( '/', ltrim( substr( $path, strlen( $boundary ) ), '/' ) ) as $component ) {
			if ( '' === $component ) {
				continue;
			}
			$current .= '/' . $component;
			if ( is_link( $current ) ) {
				return true;
			}
		}
		return false;
	}

	private function copy_target( string $source, string $destination ) {
		if ( is_file( $source ) ) {
			if ( is_link( $source ) ) {
				return new \WP_Error( 'release_symlink', __( 'Plugin packages cannot contain symbolic links.', 'wp-autoplugin' ) );
			}
			return copy( $source, $destination . '/' . basename( $source ) ) ? true : new \WP_Error( 'release_copy', __( 'The installed plugin could not be copied.', 'wp-autoplugin' ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_copy -- Private bounded package staging.
		}
		$root     = trailingslashit( wp_normalize_path( $source ) );
		$iterator = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $source, \FilesystemIterator::SKIP_DOTS ), \RecursiveIteratorIterator::SELF_FIRST );
		foreach ( $iterator as $item ) {
			$relative = ltrim( substr( wp_normalize_path( $item->getPathname() ), strlen( $root ) ), '/' );
			$parts    = explode( '/', $relative );
			if ( array_intersect( self::VCS, $parts ) ) {
				continue;
			}
			if ( $item->isLink() ) {
				return new \WP_Error( 'release_symlink', __( 'Plugin packages cannot contain symbolic links.', 'wp-autoplugin' ) );
			}
			$target = $destination . '/' . $relative;
			if ( $item->isDir() ) {
				if ( ! wp_mkdir_p( $target ) ) {
					return new \WP_Error( 'release_copy', __( 'A plugin package directory could not be created.', 'wp-autoplugin' ) );
				}
			} elseif ( $item->isFile() ) {
				if ( ! wp_mkdir_p( dirname( $target ) ) || ! copy( $item->getPathname(), $target ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_copy -- Private bounded package staging.
					return new \WP_Error( 'release_copy', __( 'An installed plugin file could not be copied.', 'wp-autoplugin' ) );
				}
			}
		}
		return true;
	}

	private function verify_overlay_baseline( string $root, array $files ) {
		foreach ( $files as $file ) {
			$relative  = $this->relative( (string) ( $file['path'] ?? '' ) );
			$path      = is_file( $root ) ? ( basename( $root ) === $relative ? $root : '' ) : $this->safe_destination( $root, $relative );
			$exists    = '' !== $path && is_file( $path );
			$operation = (string) ( $file['change_type'] ?? '' );
			if ( 'add' === $operation ) {
				if ( $exists ) {
					return new \WP_Error( 'release_baseline_drift', sprintf( __( '%s now exists in the installed plugin.', 'wp-autoplugin' ), $relative ) );
				}
				continue;
			}
			$hash = $exists ? hash_file( 'sha256', $path ) : false;
			if ( ! in_array( $operation, [ 'update', 'delete' ], true ) || false === $hash || ! hash_equals( (string) ( $file['base_content_hash'] ?? '' ), $hash ) ) {
				return new \WP_Error( 'release_baseline_drift', sprintf( __( '%s no longer matches the staged baseline.', 'wp-autoplugin' ), $relative ) );
			}
		}
		return true;
	}

	private function fork_headers( string $source, string $name, string $slug ): array {
		$next      = ( new Version_Bumper() )->bump( $source );
		$fork_name = trim( $name ) . ' — ' . __( 'WP-Autoplugin Fork', 'wp-autoplugin' );
		$next      = preg_replace_callback( '/^(\s*\*?\s*Plugin Name:\s*).+$/mi', static fn( array $match ): string => $match[1] . $fork_name, $next, 1, $name_count );
		if ( ! $name_count || ! is_string( $next ) ) {
			throw new \RuntimeException( __( 'The original Plugin Name header could not be transformed.', 'wp-autoplugin' ) );
		}
		$uri = 'https://wp-autoplugin.local/fork/' . rawurlencode( $slug );
		if ( preg_match( '/^(\s*\*?\s*Update URI:\s*).+$/mi', $next ) ) {
			$next = (string) preg_replace( '/^(\s*\*?\s*Update URI:\s*).+$/mi', '$1' . $uri, $next, 1 );
		} else {
			$next = (string) preg_replace( '/^(\s*\*?\s*Plugin Name:\s*.+)$/mi', "$1\n * Update URI: $uri", $next, 1 );
		}
		return [
			'content'    => $next,
			'transforms' => [
				'plugin_name' => $fork_name,
				'update_uri'  => $uri,
				'version'     => 'semantic_patch',
			],
		];
	}

	private function valid_plugin_header( string $path ): bool {
		if ( ! function_exists( 'get_plugin_data' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$data = get_plugin_data( $path, false, false );
		return '' !== trim( (string) ( $data['Name'] ?? '' ) );
	}

	private function zip( string $work, string $archive ) {
		if ( class_exists( '\ZipArchive' ) ) {
			$zip = new \ZipArchive();
			if ( true !== $zip->open( $archive, \ZipArchive::CREATE | \ZipArchive::OVERWRITE ) ) {
				return new \WP_Error( 'release_zip_open', __( 'The ZIP archive could not be created.', 'wp-autoplugin' ) );
			}
			$root = trailingslashit( wp_normalize_path( $work ) );
			foreach ( new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $work, \FilesystemIterator::SKIP_DOTS ) ) as $file ) {
				if ( $file->isFile() && ! $file->isLink() ) {
					$relative = ltrim( substr( wp_normalize_path( $file->getPathname() ), strlen( $root ) ), '/' );
					$zip->addFile( $file->getPathname(), $relative );
				}
			}
			return $zip->close() ? true : new \WP_Error( 'release_zip_close', __( 'The ZIP archive could not be finalized.', 'wp-autoplugin' ) );
		}
		require_once ABSPATH . 'wp-admin/includes/class-pclzip.php';
		$zip     = new \PclZip( $archive );
		$entries = array_values( array_filter( (array) scandir( $work ), static fn( string $entry ): bool => ! in_array( $entry, [ '.', '..' ], true ) ) );
		if ( 1 !== count( $entries ) ) {
			return new \WP_Error( 'release_zip_tree', __( 'The package must contain exactly one plugin root.', 'wp-autoplugin' ) );
		}
		return 0 !== $zip->create( $work . '/' . $entries[0], PCLZIP_OPT_REMOVE_PATH, $work )
			? true
			: new \WP_Error( 'release_zip_fallback', __( 'WordPress could not create the ZIP archive.', 'wp-autoplugin' ) );
	}

	private function write( string $root, string $relative, string $content ): void {
		$path = $this->safe_destination( $root, $relative );
		if ( ! wp_mkdir_p( dirname( $path ) ) || false === file_put_contents( $path, $content ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Private bounded package staging.
			throw new \RuntimeException( __( 'A package file could not be written.', 'wp-autoplugin' ) );
		}
	}

	private function remove( string $root, string $relative ): void {
		$path = $this->safe_destination( $root, $relative );
		if ( is_file( $path ) && ! unlink( $path ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Private bounded package staging.
			throw new \RuntimeException( __( 'A deleted revision file could not be removed from the package.', 'wp-autoplugin' ) );
		}
	}

	private function safe_destination( string $root, string $relative ): string {
		$relative = $this->relative( $relative );
		$path     = wp_normalize_path( $root . '/' . $relative );
		if ( ! str_starts_with( $path, trailingslashit( wp_normalize_path( $root ) ) ) ) {
			throw new \RuntimeException( __( 'A package path escaped its plugin root.', 'wp-autoplugin' ) );
		}
		return $path;
	}

	private function relative( string $path ): string {
		$path  = str_replace( '\\', '/', trim( $path ) );
		$parts = explode( '/', $path );
		if ( '' === $path || strlen( $path ) > 1024 || str_starts_with( $path, '/' ) || preg_match( '/^[A-Za-z]:/', $path ) || preg_match( '/[\x00-\x1F]/', $path ) || array_intersect( [ '', '.', '..' ], $parts ) ) {
			throw new \RuntimeException( __( 'A package contains an unsafe relative path.', 'wp-autoplugin' ) );
		}
		return $path;
	}

	/** @return string|\WP_Error */
	private function slug( string $slug ) {
		$slug = strtolower( trim( $slug ) );
		return '' !== $slug && strlen( $slug ) <= 100 && $slug === sanitize_title( $slug ) && preg_match( '/^[a-z0-9][a-z0-9-]*$/', $slug )
			? $slug
			: new \WP_Error( 'release_slug_invalid', __( 'Choose a lowercase plugin slug containing only letters, numbers, and hyphens.', 'wp-autoplugin' ) );
	}

	private function target_slug( string $plugin_file ): string {
		$directory = dirname( $plugin_file );
		return '.' === $directory ? sanitize_title( pathinfo( $plugin_file, PATHINFO_FILENAME ) ) : basename( $directory );
	}

	/** @return string|\WP_Error */
	private function private_root() {
		$base   = wp_normalize_path( sys_get_temp_dir() . '/wp-autoplugin-v2-release' );
		$public = trailingslashit( wp_normalize_path( realpath( ABSPATH ) ?: ABSPATH ) );
		if ( str_starts_with( trailingslashit( $base ), $public ) || ( ! is_dir( $base ) && ! wp_mkdir_p( $base ) ) ) {
			return new \WP_Error( 'release_private_temp', __( 'A private temporary release directory is unavailable.', 'wp-autoplugin' ) );
		}
		@chmod( $base, 0700 );
		return $base;
	}

	private function delete_tree( string $path, string $private_root ): void {
		$path = wp_normalize_path( $path );
		if ( ! str_starts_with( trailingslashit( $path ), trailingslashit( wp_normalize_path( $private_root ) ) ) || ! is_dir( $path ) ) {
			return;
		}
		$iterator = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $path, \FilesystemIterator::SKIP_DOTS ), \RecursiveIteratorIterator::CHILD_FIRST );
		foreach ( $iterator as $item ) {
			if ( $item->isDir() && ! $item->isLink() ) {
				@rmdir( $item->getPathname() );
			} else {
				@unlink( $item->getPathname() );
			}
		}
		@rmdir( $path );
	}
}
