<?php

namespace WP_Autoplugin\V2\Release;

use WP_Autoplugin\V2\Domain\Target\Source_Tools;
use WP_Autoplugin\V2\Infrastructure\Database\Release_Repository;

/** Performs capability-gated theme copy installation, direct modification, and rollback. */
final class Theme_Promotion_Service {
	/** @return array<string,mixed>|\WP_Error */
	public function install_copy( array $promotion, array $workspace, array $revision, string $slug ) {
		if ( ! user_can( (int) $promotion['created_by'], 'install_themes' ) ) {
			return new \WP_Error( 'theme_promotion_capability', __( 'The promotion owner is no longer allowed to install themes.', 'wp-autoplugin' ), [ 'status' => 403 ] );
		}
		$filesystem = $this->filesystem();
		if ( is_wp_error( $filesystem ) ) {
			return $filesystem;
		}

		$built = ( new Theme_Package_Builder() )->build( $workspace, $revision, 'copy', $slug );
		if ( is_wp_error( $built ) ) {
			return $built;
		}
		$destination = wp_normalize_path( get_theme_root() . '/' . $built['slug'] );
		$preexisting = is_dir( $destination );
		if ( $preexisting ) {
			if ( is_file( $built['path'] ) ) {
				wp_delete_file( $built['path'] );
			}
			return new \WP_Error( 'theme_promotion_collision', __( 'The destination theme already exists.', 'wp-autoplugin' ), [ 'status' => 409 ] );
		}
		$installed_destination = false;
		try {
			if ( '' !== $built['template'] ) {
				$parent = wp_get_theme( $built['template'] );
				if ( ! $parent->exists() || $parent->errors() || '' !== (string) $parent->get( 'Template' ) ) {
					return new \WP_Error( 'theme_promotion_parent', __( 'The installed parent theme is missing or invalid. The copy was not installed.', 'wp-autoplugin' ), [ 'status' => 409 ] );
				}
			}
			require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
			require_once ABSPATH . 'wp-admin/includes/class-theme-upgrader.php';
			$upgrader     = new \Theme_Upgrader( new \Automatic_Upgrader_Skin() );
			$parent_guard = static function ( $install_result, $_hook_extra, $_child_result ) use ( $upgrader, $built ) {
				remove_filter( 'upgrader_post_install', [ $upgrader, 'check_parent_theme_filter' ], 10 );
				if ( '' === $built['template'] ) {
					return $install_result;
				}
				$parent = wp_get_theme( $built['template'] );
				return $parent->exists() && ! $parent->errors() && '' === (string) $parent->get( 'Template' )
					? $install_result
					: new \WP_Error( 'theme_promotion_parent', __( 'The installed parent theme became unavailable. WP-Autoplugin did not attempt to download it.', 'wp-autoplugin' ) );
			};
			add_filter( 'upgrader_post_install', $parent_guard, 9, 3 );
			try {
				$installed = $upgrader->install(
					$built['path'],
					[
						'clear_update_cache' => true,
						'overwrite_package'  => false,
					]
				);
			} finally {
				remove_filter( 'upgrader_post_install', $parent_guard, 9 );
				remove_filter( 'upgrader_post_install', [ $upgrader, 'check_parent_theme_filter' ], 10 );
			}
			if ( is_wp_error( $installed ) ) {
				return $installed;
			}
			if ( true !== $installed ) {
				return new \WP_Error( 'theme_promotion_install', __( 'WordPress could not install the verified theme copy.', 'wp-autoplugin' ) );
			}
			$installed_destination = true;

			wp_clean_themes_cache( true );
			$theme = wp_get_theme( $built['slug'] );
			if ( '' !== $theme->get_stylesheet_directory() ) {
				$destination = $theme->get_stylesheet_directory();
			}
			if ( ! $theme->exists() || $theme->errors() ) {
				$this->remove_failed_install( $filesystem, $destination );
				return new \WP_Error( 'theme_promotion_verify', __( 'The installed theme copy did not pass WordPress theme validation.', 'wp-autoplugin' ) );
			}
			$requirements = validate_theme_requirements( $built['slug'] );
			if ( is_wp_error( $requirements ) ) {
				$this->remove_failed_install( $filesystem, $destination );
				return $requirements;
			}
			$tree = ( new Package_Builder() )->fingerprint_target( $built['slug'], true, 'theme' );
			if ( is_wp_error( $tree ) || ! hash_equals( (string) $built['tree_fingerprint'], (string) ( $tree['fingerprint'] ?? '' ) ) ) {
				$this->remove_failed_install( $filesystem, $destination );
				return is_wp_error( $tree ) ? $tree : new \WP_Error( 'theme_promotion_manifest_mismatch', __( 'The installed theme tree did not match the verified package.', 'wp-autoplugin' ) );
			}

			if ( ! ( new Release_Repository() )->update_promotion(
				(int) $promotion['id'],
				[
					'status'                 => 'installed',
					'artifact_kind'          => 'theme',
					'destination_target_ref' => $built['slug'],
					'destination_slug'       => $built['slug'],
					'target_fingerprint'     => $built['tree_fingerprint'],
					'header_transforms'      => $built['header_transforms'],
					'active_before'          => 0,
					'active_after'           => 0,
					'finished_at'            => current_time( 'mysql', true ),
				]
			) ) {
				$this->remove_failed_install( $filesystem, $destination );
				return new \WP_Error( 'theme_promotion_save', __( 'The installed theme copy could not be recorded and was removed.', 'wp-autoplugin' ) );
			}
			return [
				'status'            => 'installed',
				'artifact_kind'     => 'theme',
				'target_ref'        => $built['slug'],
				'slug'              => $built['slug'],
				'active'            => false,
				'template'          => $built['template'],
				'is_child'          => $built['is_child'],
				'header_transforms' => $built['header_transforms'],
			];
		} catch ( \Throwable $error ) {
			if ( $installed_destination && ! $preexisting && is_dir( $destination ) ) {
				$this->remove_failed_install( $filesystem, $destination );
			}
			return new \WP_Error( 'theme_promotion_install', $error->getMessage(), [ 'status' => 500 ] );
		} finally {
			if ( is_file( $built['path'] ) ) {
				wp_delete_file( $built['path'] );
			}
		}
	}

	/** @return array<string,mixed>|\WP_Error */
	public function modify( array $promotion, array $workspace, array $revision ) {
		if ( ! user_can( (int) $promotion['created_by'], 'update_themes' ) ) {
			return new \WP_Error( 'theme_promotion_capability', __( 'The promotion owner is no longer allowed to modify themes.', 'wp-autoplugin' ), [ 'status' => 403 ] );
		}
		$filesystem = $this->filesystem();
		if ( is_wp_error( $filesystem ) ) {
			return $filesystem;
		}
		$target_ref = (string) $workspace['target_ref'];
		if ( $this->in_use( $target_ref ) ) {
			return new \WP_Error( 'theme_promotion_in_use', $this->in_use_reason( $target_ref ), [ 'status' => 409 ] );
		}
		$builder = new Package_Builder();
		$root    = $builder->target_root( $target_ref, 'theme' );
		if ( is_wp_error( $root ) ) {
			return $root;
		}
		try {
			$tools = new Source_Tools( (array) $workspace['target_metadata'] );
			if ( ! hash_equals( (string) ( $revision['project_manifest']['target_fingerprint'] ?? '' ), $tools->tree_fingerprint() ) ) {
				return new \WP_Error( 'theme_promotion_target_changed', __( 'The installed theme changed after this revision was staged.', 'wp-autoplugin' ) );
			}
		} catch ( \Throwable $error ) {
			return new \WP_Error( 'theme_promotion_target_unavailable', __( 'The installed theme is unavailable for direct modification.', 'wp-autoplugin' ) );
		}
		$tree = $builder->fingerprint_target( $target_ref, true, 'theme' );
		if ( is_wp_error( $tree ) ) {
			return $tree;
		}
		$expected = (string) ( $revision['project_manifest']['complete_target_fingerprint'] ?? '' );
		if ( ! preg_match( '/^[a-f0-9]{64}$/', $expected ) ) {
			return new \WP_Error( 'theme_promotion_legacy_revision', __( 'Regenerate Code before directly modifying this theme.', 'wp-autoplugin' ) );
		}
		if ( ! hash_equals( $expected, $tree['fingerprint'] ) ) {
			return new \WP_Error( 'theme_promotion_complete_changed', __( 'The complete installed theme tree changed after this revision was staged.', 'wp-autoplugin' ) );
		}
		$preflight = ( new Theme_Package_Builder() )->build( $workspace, $revision, 'replacement' );
		if ( is_wp_error( $preflight ) ) {
			return $preflight;
		}
		if ( is_file( $preflight['path'] ) ) {
			wp_delete_file( $preflight['path'] );
		}

		$records = [];
		foreach ( (array) $revision['files'] as $file ) {
			$path      = (string) $file['path'];
			$target    = $this->target_path( $root, $path );
			$exists    = is_file( $target );
			$operation = (string) $file['change_type'];
			$before    = $exists ? file_get_contents( $target ) : null; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Conflict-checked local theme snapshot.
			if ( 'style.css' === $path && 'delete' === $operation ) {
				return new \WP_Error( 'theme_promotion_stylesheet_delete', __( 'A theme release cannot delete style.css.', 'wp-autoplugin' ) );
			}
			if ( ( 'add' === $operation && $exists ) || ( in_array( $operation, [ 'update', 'delete' ], true ) && ( ! $exists || false === $before || ! hash_equals( (string) $file['base_content_hash'], hash( 'sha256', $before ) ) ) ) ) {
				return new \WP_Error( 'theme_promotion_file_drift', sprintf( __( '%s no longer matches the staged baseline.', 'wp-autoplugin' ), $path ) );
			}
			$after            = 'delete' === $operation ? null : (string) $file['content'];
			$records[ $path ] = $this->record( $path, $operation, $before, $after );
		}

		$style_path   = $this->target_path( $root, 'style.css' );
		$style_before = file_get_contents( $style_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Conflict-checked local theme snapshot.
		if ( false === $style_before ) {
			return new \WP_Error( 'theme_promotion_stylesheet', __( 'The target theme stylesheet could not be read.', 'wp-autoplugin' ) );
		}
		$style_staged = isset( $records['style.css'] ) ? (string) $records['style.css']['promoted_content'] : $style_before;
		try {
			$transformed = ( new Theme_Header_Transformer() )->transform( $style_staged, 'direct' );
		} catch ( \Throwable $error ) {
			return new \WP_Error( 'theme_promotion_version', $error->getMessage() );
		}
		$records['style.css'] = $this->record( 'style.css', 'update', $style_before, $transformed['content'] );
		$records              = array_values( $records );

		$repository = new Release_Repository();
		$repository->replace_promotion_files( (int) $promotion['id'], $records );
		if ( ! $repository->update_promotion(
			(int) $promotion['id'],
			[
				'artifact_kind'          => 'theme',
				'source_target_ref'      => $target_ref,
				'destination_target_ref' => $target_ref,
				'target_fingerprint'     => $tree['fingerprint'],
				'header_transforms'      => $transformed['transforms'],
			]
		) ) {
			return new \WP_Error( 'theme_promotion_save', __( 'The rollback record could not be finalized, so no theme files were changed.', 'wp-autoplugin' ) );
		}

		$directories = [];
		try {
			if ( $this->in_use( $target_ref ) ) {
				throw new \RuntimeException( $this->in_use_reason( $target_ref ) );
			}
			$immediate_tree = $builder->fingerprint_target( $target_ref, true, 'theme' );
			if ( is_wp_error( $immediate_tree ) || ! hash_equals( $expected, (string) ( $immediate_tree['fingerprint'] ?? '' ) ) ) {
				throw new \RuntimeException( __( 'The complete theme tree changed immediately before direct modification.', 'wp-autoplugin' ) );
			}
			$this->verify( $root, $records, false );
			if ( $this->in_use( $target_ref ) ) {
				throw new \RuntimeException( $this->in_use_reason( $target_ref ) );
			}
			foreach ( $records as $record ) {
				if ( ! $record['promoted_exists'] ) {
					continue;
				}
				$path = $this->target_path( $root, $record['path'] );
				$this->ensure_directories( dirname( $path ), $root, $filesystem, $directories );
				if ( ! $filesystem->put_contents( $path, $record['promoted_content'], FS_CHMOD_FILE ) ) {
					throw new \RuntimeException( sprintf( __( 'Could not write %s.', 'wp-autoplugin' ), $record['path'] ) );
				}
			}
			foreach ( $records as $record ) {
				if ( $record['promoted_exists'] ) {
					continue;
				}
				$path = $this->target_path( $root, $record['path'] );
				if ( is_file( $path ) && ! $filesystem->delete( $path, false ) ) {
					throw new \RuntimeException( sprintf( __( 'Could not delete %s.', 'wp-autoplugin' ), $record['path'] ) );
				}
			}
			$this->verify( $root, $records, true );
		} catch ( \Throwable $error ) {
			$restored = $this->restore_records( $root, $records, $filesystem, $directories, true );
			$this->invalidate( $root, $records );
			$repository->update_promotion(
				(int) $promotion['id'],
				[
					'status'              => $restored ? 'failed' : 'rollback_failed',
					'error_message'       => $error->getMessage(),
					'created_directories' => $directories,
					'finished_at'         => current_time( 'mysql', true ),
				]
			);
			return new \WP_Error( $restored ? 'theme_promotion_write_failed' : 'theme_promotion_restore_failed', $error->getMessage() );
		}

		$this->invalidate( $root, $records );
		if ( ! $repository->update_promotion(
			(int) $promotion['id'],
			[
				'status'              => 'completed',
				'created_directories' => $directories,
				'active_before'       => 0,
				'active_after'        => 0,
				'finished_at'         => current_time( 'mysql', true ),
			]
		) ) {
			$restored = $this->restore_records( $root, $records, $filesystem, $directories, true );
			$this->invalidate( $root, $records );
			return new \WP_Error(
				$restored ? 'theme_promotion_save' : 'theme_promotion_restore_failed',
				$restored
					? __( 'The completed promotion could not be recorded, so its file changes were restored.', 'wp-autoplugin' )
					: __( 'The completed promotion could not be recorded and its file changes could not be fully restored.', 'wp-autoplugin' )
			);
		}
		return [
			'status'             => 'completed',
			'artifact_kind'      => 'theme',
			'target_ref'         => $target_ref,
			'active'             => false,
			'rollback_available' => true,
			'warning'            => __( 'Upstream updates remain enabled and may overwrite these changes. Rollback restores files only.', 'wp-autoplugin' ),
		];
	}

	/** @return array<string,mixed>|\WP_Error */
	public function rollback( array $promotion ) {
		if ( ! user_can( (int) $promotion['created_by'], 'update_themes' ) ) {
			return new \WP_Error( 'theme_promotion_capability', __( 'The promotion owner is no longer allowed to roll back theme files.', 'wp-autoplugin' ), [ 'status' => 403 ] );
		}
		$filesystem = $this->filesystem();
		if ( is_wp_error( $filesystem ) ) {
			return $filesystem;
		}
		$repository = new Release_Repository();
		$target_ref = (string) $promotion['destination_target_ref'];
		if ( 'modify_theme_original' !== $promotion['mode'] || 'completed' !== $promotion['status'] || ! $repository->is_latest_in_place( (int) $promotion['id'], $target_ref, 'theme' ) ) {
			return new \WP_Error( 'theme_promotion_rollback_state', __( 'Only the latest successful direct theme modification can be rolled back.', 'wp-autoplugin' ), [ 'status' => 409 ] );
		}
		if ( $this->in_use( $target_ref ) ) {
			return new \WP_Error( 'theme_promotion_rollback_in_use', $this->in_use_reason( $target_ref ), [ 'status' => 409 ] );
		}
		$root = ( new Package_Builder() )->target_root( $target_ref, 'theme' );
		if ( is_wp_error( $root ) ) {
			return $root;
		}
		$records = $repository->promotion_files( (int) $promotion['id'] );
		try {
			$this->verify( $root, $records, true );
		} catch ( \Throwable $error ) {
			return new \WP_Error( 'theme_promotion_rollback_conflict', __( 'Rollback was not started because an affected theme file changed after promotion.', 'wp-autoplugin' ), [ 'status' => 409 ] );
		}
		if ( $this->in_use( $target_ref ) ) {
			return new \WP_Error( 'theme_promotion_rollback_in_use', $this->in_use_reason( $target_ref ), [ 'status' => 409 ] );
		}
		if ( ! $this->restore_records( $root, $records, $filesystem, (array) $promotion['created_directories'], true ) ) {
			$repository->update_promotion(
				(int) $promotion['id'],
				[
					'status'        => 'rollback_failed',
					'error_message' => __( 'One or more promoted theme files could not be restored.', 'wp-autoplugin' ),
					'finished_at'   => current_time( 'mysql', true ),
				]
			);
			return new \WP_Error( 'theme_promotion_rollback_failed', __( 'One or more promoted theme files could not be restored.', 'wp-autoplugin' ) );
		}
		$this->invalidate( $root, $records );
		$repository->update_promotion(
			(int) $promotion['id'],
			[
				'status'       => 'rolled_back',
				'active_after' => 0,
				'finished_at'  => current_time( 'mysql', true ),
			]
		);
		return [
			'status'        => 'rolled_back',
			'artifact_kind' => 'theme',
			'target_ref'    => $target_ref,
			'active'        => false,
			'warning'       => __( 'Theme files were restored. Database and runtime side effects were not reverted.', 'wp-autoplugin' ),
		];
	}

	public function in_use( string $stylesheet ): bool {
		return '' !== $this->in_use_reason( $stylesheet );
	}

	public function in_use_reason( string $stylesheet ): string {
		if ( get_stylesheet() === $stylesheet ) {
			return __( 'Direct theme modification and rollback are blocked while this theme is active.', 'wp-autoplugin' );
		}
		if ( get_template() === $stylesheet ) {
			return __( 'Direct theme modification and rollback are blocked because this theme is the parent of the active child theme.', 'wp-autoplugin' );
		}
		return '';
	}

	private function filesystem() {
		if ( is_multisite() ) {
			return new \WP_Error( 'theme_promotion_multisite', __( 'Theme filesystem mutation is not available on multisite yet.', 'wp-autoplugin' ), [ 'status' => 409 ] );
		}
		if ( defined( 'DISALLOW_FILE_MODS' ) && DISALLOW_FILE_MODS ) {
			return new \WP_Error( 'theme_promotion_file_mods_disabled', __( 'WordPress file modifications are disabled.', 'wp-autoplugin' ), [ 'status' => 409 ] );
		}
		require_once ABSPATH . 'wp-admin/includes/file.php';
		if ( ! WP_Filesystem() ) {
			return new \WP_Error( 'theme_promotion_filesystem_credentials', __( 'WordPress could not initialize filesystem access without additional credentials.', 'wp-autoplugin' ), [ 'status' => 409 ] );
		}
		global $wp_filesystem;
		return $wp_filesystem;
	}

	private function remove_failed_install( $filesystem, string $destination ): void {
		if ( '' !== $destination && is_dir( $destination ) ) {
			$filesystem->delete( $destination, true );
			wp_clean_themes_cache( true );
		}
	}

	private function record( string $path, string $operation, ?string $before, ?string $after ): array {
		return [
			'path'             => $path,
			'operation'        => $operation,
			'base_exists'      => null !== $before,
			'base_content'     => $before,
			'base_hash'        => null === $before ? null : hash( 'sha256', $before ),
			'promoted_exists'  => null !== $after,
			'promoted_content' => $after,
			'promoted_hash'    => null === $after ? null : hash( 'sha256', $after ),
		];
	}

	private function target_path( string $root, string $relative ): string {
		$relative = str_replace( '\\', '/', trim( $relative ) );
		$parts    = explode( '/', $relative );
		if ( '' === $relative || str_starts_with( $relative, '/' ) || preg_match( '/^[A-Za-z]:/', $relative ) || preg_match( '/[\x00-\x1F]/', $relative ) || array_intersect( [ '', '.', '..' ], $parts ) ) {
			throw new \RuntimeException( __( 'A theme promotion file path is unsafe.', 'wp-autoplugin' ) );
		}
		$path = wp_normalize_path( trailingslashit( $root ) . $relative );
		if ( ! str_starts_with( $path, trailingslashit( wp_normalize_path( $root ) ) ) ) {
			throw new \RuntimeException( __( 'A promotion file path escaped the theme root.', 'wp-autoplugin' ) );
		}
		$current = $root;
		foreach ( array_slice( $parts, 0, -1 ) as $part ) {
			$current .= '/' . $part;
			if ( is_link( $current ) ) {
				throw new \RuntimeException( __( 'A theme promotion path traverses a symbolic link.', 'wp-autoplugin' ) );
			}
		}
		return $path;
	}

	private function ensure_directories( string $directory, string $root, $filesystem, array &$created ): void {
		if ( is_dir( $directory ) ) {
			return;
		}
		$missing = [];
		$current = $directory;
		while ( ! is_dir( $current ) && str_starts_with( trailingslashit( wp_normalize_path( $current ) ), trailingslashit( wp_normalize_path( $root ) ) ) ) {
			$missing[] = $current;
			$current   = dirname( $current );
		}
		foreach ( array_reverse( $missing ) as $path ) {
			if ( ! $filesystem->mkdir( $path, FS_CHMOD_DIR ) ) {
				throw new \RuntimeException( __( 'A required theme directory could not be created.', 'wp-autoplugin' ) );
			}
			$created[] = wp_normalize_path( $path );
		}
	}

	private function verify( string $root, array $records, bool $promoted ): void {
		foreach ( $records as $record ) {
			$path   = $this->target_path( $root, (string) $record['path'] );
			$exists = is_file( $path );
			$prefix = $promoted ? 'promoted' : 'base';
			if ( $exists !== (bool) $record[ $prefix . '_exists' ] ) {
				throw new \RuntimeException( __( 'A promoted theme file presence check failed.', 'wp-autoplugin' ) );
			}
			if ( $exists && ! hash_equals( (string) $record[ $prefix . '_hash' ], (string) hash_file( 'sha256', $path ) ) ) {
				throw new \RuntimeException( __( 'A promoted theme file hash check failed.', 'wp-autoplugin' ) );
			}
		}
	}

	private function restore_records( string $root, array $records, $filesystem, array $directories, bool $verify ): bool {
		try {
			foreach ( $records as $record ) {
				$path = $this->target_path( $root, (string) $record['path'] );
				if ( $record['base_exists'] ) {
					if ( ! $filesystem->put_contents( $path, (string) $record['base_content'], FS_CHMOD_FILE ) ) {
						return false;
					}
				} elseif ( is_file( $path ) && ! $filesystem->delete( $path, false ) ) {
					return false;
				}
			}
			foreach ( array_reverse( $directories ) as $directory ) {
				if ( is_dir( $directory ) && [] === array_diff( (array) scandir( $directory ), [ '.', '..' ] ) ) {
					$filesystem->rmdir( $directory );
				}
			}
			if ( $verify ) {
				$this->verify( $root, $records, false );
			}
			return true;
		} catch ( \Throwable $error ) {
			return false;
		}
	}

	private function invalidate( string $root, array $records ): void {
		wp_clean_themes_cache( true );
		if ( ! function_exists( 'opcache_invalidate' ) ) {
			return;
		}
		foreach ( $records as $record ) {
			if ( 'php' === strtolower( (string) pathinfo( $record['path'], PATHINFO_EXTENSION ) ) ) {
				@opcache_invalidate( $this->target_path( $root, $record['path'] ), true );
			}
		}
	}
}
