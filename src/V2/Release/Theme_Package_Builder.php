<?php

namespace WP_Autoplugin\V2\Release;

use WP_Autoplugin\V2\Domain\Target\Source_Tools;

/** Builds bounded, revision-exact private theme ZIP archives. */
final class Theme_Package_Builder {
	private const VCS = [ '.git', '.svn', '.hg' ];

	/** @return array<string,mixed>|\WP_Error */
	public function build( array $workspace, array $revision, string $mode, string $destination_slug = '' ) {
		$manifest = (array) ( $revision['project_manifest'] ?? [] );
		if ( ! in_array( $mode, [ 'replacement', 'copy' ], true ) || 'changes' !== ( $manifest['scope'] ?? '' ) || 'theme' !== ( $manifest['artifact_kind'] ?? '' ) ) {
			return new \WP_Error( 'release_theme_matrix', __( 'Theme packages require an installed-theme change revision.', 'wp-autoplugin' ) );
		}
		$expected_complete = (string) ( $manifest['complete_target_fingerprint'] ?? '' );
		if ( ! preg_match( '/^[a-f0-9]{64}$/', $expected_complete ) ) {
			return new \WP_Error( 'release_theme_legacy_revision', __( 'Regenerate Code before releasing this theme revision.', 'wp-autoplugin' ) );
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
			$source_slug = (string) ( $workspace['target_metadata']['stylesheet'] ?? $workspace['target_ref'] ?? '' );
			$slug = 'copy' === $mode ? $this->slug( $destination_slug ) : $this->installed_slug( $source_slug );
			if ( is_wp_error( $slug ) ) {
				return $slug;
			}
			if ( 'copy' === $mode && $this->theme_path_exists( $slug ) ) {
				return new \WP_Error( 'release_theme_copy_collision', __( 'The selected theme copy slug is already installed.', 'wp-autoplugin' ) );
			}

			$source = ( new Package_Builder() )->target_root( (string) $workspace['target_ref'], 'theme' );
			if ( is_wp_error( $source ) ) {
				return $source;
			}
			try {
				$current_fingerprint = ( new Source_Tools( (array) $workspace['target_metadata'] ) )->tree_fingerprint();
			} catch ( \Throwable $error ) {
				return new \WP_Error( 'release_theme_unavailable', __( 'The installed theme is unavailable for packaging.', 'wp-autoplugin' ) );
			}
			if ( ! hash_equals( (string) ( $manifest['target_fingerprint'] ?? '' ), $current_fingerprint ) ) {
				return new \WP_Error( 'release_theme_changed', __( 'The installed theme changed after this revision was staged.', 'wp-autoplugin' ) );
			}

			$scanner  = new Package_Builder();
			$complete = $scanner->fingerprint_target( (string) $workspace['target_ref'], true, 'theme' );
			if ( is_wp_error( $complete ) ) {
				return $complete;
			}
			if ( ! hash_equals( $expected_complete, $complete['fingerprint'] ) ) {
				return new \WP_Error( 'release_theme_complete_changed', __( 'The complete installed theme tree changed after this revision was staged.', 'wp-autoplugin' ) );
			}
			$baseline = $this->verify_overlay_baseline( $source, (array) $revision['files'] );
			if ( is_wp_error( $baseline ) ) {
				return $baseline;
			}

			$root = $work . '/' . $slug;
			if ( ! wp_mkdir_p( $root ) ) {
				return new \WP_Error( 'release_temp_unavailable', __( 'The private theme package tree could not be created.', 'wp-autoplugin' ) );
			}
			$copied = $this->copy_target( $source, $root );
			if ( is_wp_error( $copied ) ) {
				return $copied;
			}
			$copied_tree = $scanner->scan_tree( $root );
			if ( is_wp_error( $copied_tree ) || ! hash_equals( $complete['fingerprint'], (string) ( $copied_tree['fingerprint'] ?? '' ) ) ) {
				return is_wp_error( $copied_tree ) ? $copied_tree : new \WP_Error( 'release_theme_copy_drift', __( 'The installed theme changed while its package tree was being copied.', 'wp-autoplugin' ) );
			}

			foreach ( (array) $revision['files'] as $file ) {
				$path = (string) $file['path'];
				if ( 'delete' === ( $file['change_type'] ?? '' ) ) {
					$this->remove( $root, $path );
				} else {
					$this->write( $root, $path, (string) $file['content'] );
				}
			}

			$stylesheet = $this->safe_destination( $root, 'style.css' );
			$source_css = is_file( $stylesheet ) ? file_get_contents( $stylesheet ) : false; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Private bounded package tree.
			if ( false === $source_css ) {
				return new \WP_Error( 'release_theme_stylesheet', __( 'The package does not contain a readable root style.css file.', 'wp-autoplugin' ) );
			}
			$transformed = ( new Theme_Header_Transformer() )->transform(
				$source_css,
				$mode,
				$slug,
				(string) ( $workspace['target_metadata']['name'] ?? $workspace['project_name'] ?? '' )
			);
			$this->write( $root, 'style.css', $transformed['content'] );

			$validated = $this->validate_materialized_theme( $root, (array) $workspace['target_metadata'] );
			if ( is_wp_error( $validated ) ) {
				return $validated;
			}
			$tree = $scanner->scan_tree( $root );
			if ( is_wp_error( $tree ) ) {
				return $tree;
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
				return new \WP_Error( 'release_theme_package_verify', __( 'The completed theme package could not be verified.', 'wp-autoplugin' ) );
			}
			return [
				'path'                    => $archive,
				'sha256'                  => $hash,
				'size'                    => (int) $size,
				'slug'                    => $slug,
				'target_ref'              => $slug,
				'artifact_kind'           => 'theme',
				'source_tree_fingerprint' => $complete['fingerprint'],
				'tree_fingerprint'        => $tree['fingerprint'],
				'header_transforms'       => $transformed['transforms'],
				'template'                => $validated['template'],
				'is_child'                => '' !== $validated['template'],
			];
		} catch ( \Throwable $error ) {
			if ( is_file( $archive ) ) {
				wp_delete_file( $archive );
			}
			return new \WP_Error( 'release_theme_package_failed', $error->getMessage() );
		} finally {
			$this->delete_tree( $work, $private );
		}
	}

	/** @return array{template:string}|\WP_Error */
	public function validate_materialized_theme( string $root, array $metadata ) {
		$style = $root . '/style.css';
		if ( ! is_file( $style ) ) {
			return new \WP_Error( 'release_theme_stylesheet', __( 'The package does not contain a root style.css file.', 'wp-autoplugin' ) );
		}
		$data  = get_file_data(
			$style,
			[
				'Name'        => 'Theme Name',
				'Template'    => 'Template',
				'RequiresWP'  => 'Requires at least',
				'RequiresPHP' => 'Requires PHP',
			],
			'theme'
		);
		if ( '' === trim( (string) ( $data['Name'] ?? '' ) ) ) {
			return new \WP_Error( 'release_theme_header', __( 'The package stylesheet does not contain a valid Theme Name header.', 'wp-autoplugin' ) );
		}
		$template = trim( (string) ( $data['Template'] ?? '' ) );
		$expected = ! empty( $metadata['is_child'] ) ? (string) ( $metadata['template'] ?? '' ) : '';
		if ( $template !== $expected ) {
			return new \WP_Error( 'release_theme_template_changed', __( 'Release cannot change the theme parent relationship.', 'wp-autoplugin' ) );
		}
		if ( '' !== $template ) {
			$parent = wp_get_theme( $template );
			if ( ! $parent->exists() || $parent->errors() ) {
				return new \WP_Error( 'release_theme_parent_missing', __( 'The child theme parent is not installed and valid.', 'wp-autoplugin' ) );
			}
			if ( $parent->get_template() !== $parent->get_stylesheet() ) {
				return new \WP_Error( 'release_theme_grandchild', __( 'WordPress does not support installing a child of another child theme.', 'wp-autoplugin' ) );
			}
		} elseif ( ! is_file( $root . '/index.php' ) && ! is_file( $root . '/templates/index.html' ) && ! is_file( $root . '/block-templates/index.html' ) ) {
			return new \WP_Error( 'release_theme_index', __( 'A standalone theme package requires index.php or a block-theme index template.', 'wp-autoplugin' ) );
		}
		if ( ! is_php_version_compatible( (string) ( $data['RequiresPHP'] ?? '' ) ) ) {
			return new \WP_Error( 'release_theme_php', __( 'The current PHP version does not meet the packaged theme requirements.', 'wp-autoplugin' ) );
		}
		if ( ! is_wp_version_compatible( (string) ( $data['RequiresWP'] ?? '' ) ) ) {
			return new \WP_Error( 'release_theme_wordpress', __( 'The current WordPress version does not meet the packaged theme requirements.', 'wp-autoplugin' ) );
		}
		return [ 'template' => $template ];
	}

	private function verify_overlay_baseline( string $root, array $files ) {
		foreach ( $files as $file ) {
			$relative  = $this->relative( (string) ( $file['path'] ?? '' ) );
			$path      = $this->safe_destination( $root, $relative );
			$exists    = is_file( $path );
			$operation = (string) ( $file['change_type'] ?? '' );
			if ( 'add' === $operation ) {
				if ( $exists ) {
					return new \WP_Error( 'release_theme_baseline_drift', sprintf( __( '%s now exists in the installed theme.', 'wp-autoplugin' ), $relative ) );
				}
				continue;
			}
			$hash = $exists ? hash_file( 'sha256', $path ) : false;
			if ( ! in_array( $operation, [ 'update', 'delete' ], true ) || false === $hash || ! hash_equals( (string) ( $file['base_content_hash'] ?? '' ), $hash ) ) {
				return new \WP_Error( 'release_theme_baseline_drift', sprintf( __( '%s no longer matches the staged baseline.', 'wp-autoplugin' ), $relative ) );
			}
		}
		return true;
	}

	private function copy_target( string $source, string $destination ) {
		$root = trailingslashit( wp_normalize_path( $source ) );
		$iterator = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $source, \FilesystemIterator::SKIP_DOTS ), \RecursiveIteratorIterator::SELF_FIRST );
		foreach ( $iterator as $item ) {
			$relative = ltrim( substr( wp_normalize_path( $item->getPathname() ), strlen( $root ) ), '/' );
			if ( array_intersect( self::VCS, explode( '/', $relative ) ) ) {
				continue;
			}
			if ( $item->isLink() ) {
				return new \WP_Error( 'release_theme_symlink', __( 'Theme packages cannot contain symbolic links.', 'wp-autoplugin' ) );
			}
			$target = $destination . '/' . $relative;
			if ( $item->isDir() ) {
				if ( ! wp_mkdir_p( $target ) ) {
					return new \WP_Error( 'release_theme_copy', __( 'A theme package directory could not be created.', 'wp-autoplugin' ) );
				}
			} elseif ( $item->isFile() && ( ! wp_mkdir_p( dirname( $target ) ) || ! copy( $item->getPathname(), $target ) ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_copy -- Private bounded package staging.
				return new \WP_Error( 'release_theme_copy', __( 'An installed theme file could not be copied.', 'wp-autoplugin' ) );
			}
		}
		return true;
	}

	private function zip( string $work, string $archive ) {
		if ( class_exists( '\ZipArchive' ) ) {
			$zip = new \ZipArchive();
			if ( true !== $zip->open( $archive, \ZipArchive::CREATE | \ZipArchive::OVERWRITE ) ) {
				return new \WP_Error( 'release_theme_zip_open', __( 'The theme ZIP archive could not be created.', 'wp-autoplugin' ) );
			}
			$root = trailingslashit( wp_normalize_path( $work ) );
			foreach ( new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $work, \FilesystemIterator::SKIP_DOTS ) ) as $file ) {
				if ( $file->isFile() && ! $file->isLink() ) {
					$relative = ltrim( substr( wp_normalize_path( $file->getPathname() ), strlen( $root ) ), '/' );
					$zip->addFile( $file->getPathname(), $relative );
				}
			}
			return $zip->close() ? true : new \WP_Error( 'release_theme_zip_close', __( 'The theme ZIP archive could not be finalized.', 'wp-autoplugin' ) );
		}
		require_once ABSPATH . 'wp-admin/includes/class-pclzip.php';
		$entries = array_values( array_filter( (array) scandir( $work ), static fn( string $entry ): bool => ! in_array( $entry, [ '.', '..' ], true ) ) );
		if ( 1 !== count( $entries ) ) {
			return new \WP_Error( 'release_theme_zip_tree', __( 'The package must contain exactly one theme root.', 'wp-autoplugin' ) );
		}
		$zip = new \PclZip( $archive );
		return 0 !== $zip->create( $work . '/' . $entries[0], PCLZIP_OPT_REMOVE_PATH, $work )
			? true
			: new \WP_Error( 'release_theme_zip_fallback', __( 'WordPress could not create the theme ZIP archive.', 'wp-autoplugin' ) );
	}

	private function write( string $root, string $relative, string $content ): void {
		$path = $this->safe_destination( $root, $relative );
		if ( ! wp_mkdir_p( dirname( $path ) ) || false === file_put_contents( $path, $content ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Private bounded package staging.
			throw new \RuntimeException( __( 'A theme package file could not be written.', 'wp-autoplugin' ) );
		}
	}

	private function remove( string $root, string $relative ): void {
		$path = $this->safe_destination( $root, $relative );
		if ( 'style.css' === $relative ) {
			throw new \RuntimeException( __( 'A theme release cannot delete style.css.', 'wp-autoplugin' ) );
		}
		if ( is_file( $path ) && ! unlink( $path ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Private bounded package staging.
			throw new \RuntimeException( __( 'A deleted revision file could not be removed from the theme package.', 'wp-autoplugin' ) );
		}
	}

	private function safe_destination( string $root, string $relative ): string {
		$relative = $this->relative( $relative );
		$path = wp_normalize_path( $root . '/' . $relative );
		if ( ! str_starts_with( $path, trailingslashit( wp_normalize_path( $root ) ) ) ) {
			throw new \RuntimeException( __( 'A package path escaped its theme root.', 'wp-autoplugin' ) );
		}
		return $path;
	}

	private function relative( string $path ): string {
		$path  = str_replace( '\\', '/', trim( $path ) );
		$parts = explode( '/', $path );
		if ( '' === $path || strlen( $path ) > 1024 || str_starts_with( $path, '/' ) || preg_match( '/^[A-Za-z]:/', $path ) || preg_match( '/[\x00-\x1F]/', $path ) || array_intersect( [ '', '.', '..' ], $parts ) ) {
			throw new \RuntimeException( __( 'A theme package contains an unsafe relative path.', 'wp-autoplugin' ) );
		}
		return $path;
	}

	/** @return string|\WP_Error */
	private function slug( string $slug ) {
		$slug = strtolower( trim( $slug ) );
		return '' !== $slug && strlen( $slug ) <= 100 && $slug === sanitize_title( $slug ) && preg_match( '/^[a-z0-9][a-z0-9-]*$/', $slug )
			? $slug
			: new \WP_Error( 'release_theme_slug_invalid', __( 'Choose a lowercase theme slug containing only letters, numbers, and hyphens.', 'wp-autoplugin' ) );
	}

	/** @return string|\WP_Error */
	private function installed_slug( string $slug ) {
		$slug  = str_replace( '\\', '/', trim( $slug ) );
		$parts = explode( '/', $slug );
		return '' !== $slug && strlen( $slug ) <= 500 && ! str_starts_with( $slug, '/' ) && ! preg_match( '/[\x00-\x1F]/', $slug ) && ! array_intersect( [ '', '.', '..' ], $parts )
			? $slug
			: new \WP_Error( 'release_theme_slug_invalid', __( 'The installed theme slug is unsafe for packaging.', 'wp-autoplugin' ) );
	}

	private function theme_path_exists( string $slug ): bool {
		$theme = wp_get_theme( $slug );
		return $theme->exists() || is_dir( $theme->get_stylesheet_directory() );
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
