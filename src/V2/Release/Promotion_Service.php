<?php

namespace WP_Autoplugin\V2\Release;

use WP_Autoplugin\V2\Domain\Revision\Version_Bumper;
use WP_Autoplugin\V2\Domain\Target\Source_Tools;
use WP_Autoplugin\V2\Infrastructure\Database\Release_Repository;

/** Performs capability-gated plugin installation, activation, modification, and rollback. */
final class Promotion_Service {
	/** @return array<string,mixed>|\WP_Error */
	public function install( array $promotion, array $workspace, array $revision, string $package_mode, string $slug ) {
		if ( ! user_can( (int) $promotion['created_by'], 'install_plugins' ) ) {
			return new \WP_Error( 'promotion_capability', __( 'The promotion owner is no longer allowed to install plugins.', 'wp-autoplugin' ), [ 'status' => 403 ] );
		}
		$filesystem = $this->filesystem();
		if ( is_wp_error( $filesystem ) ) {
			return $filesystem;
		}
		$builder = new Package_Builder();
		$built   = $builder->build( $workspace, $revision, $package_mode, $slug );
		if ( is_wp_error( $built ) ) {
			return $built;
		}
		$destination = trailingslashit( WP_PLUGIN_DIR ) . $built['slug'];
		$temp        = trailingslashit( WP_PLUGIN_DIR ) . '.wp-autoplugin-' . wp_generate_password( 24, false, false );
		try {
			if ( file_exists( $destination ) ) {
				return new \WP_Error( 'promotion_collision', __( 'The destination plugin is already installed.', 'wp-autoplugin' ) );
			}
			if ( ! $filesystem->mkdir( $temp, FS_CHMOD_DIR ) ) {
				return new \WP_Error( 'promotion_temp', __( 'A temporary plugin installation directory could not be created.', 'wp-autoplugin' ) );
			}
			require_once ABSPATH . 'wp-admin/includes/file.php';
			$unzipped = unzip_file( $built['path'], $temp );
			if ( is_wp_error( $unzipped ) ) {
				return $unzipped;
			}
			$extracted = $temp . '/' . $built['slug'];
			$tree      = $builder->scan_tree( $extracted );
			if ( is_wp_error( $tree ) || ! hash_equals( (string) $built['tree_fingerprint'], (string) ( $tree['fingerprint'] ?? '' ) ) ) {
				return is_wp_error( $tree ) ? $tree : new \WP_Error( 'promotion_manifest_mismatch', __( 'The installed temporary tree did not match the verified package.', 'wp-autoplugin' ) );
			}
			$main = $extracted . '/' . basename( (string) $built['plugin_file'] );
			if ( ! is_file( $main ) || ! $this->valid_plugin_header( $main ) ) {
				return new \WP_Error( 'promotion_plugin_header', __( 'The installed package does not contain its verified main plugin header.', 'wp-autoplugin' ) );
			}
			if ( file_exists( $destination ) || ! $filesystem->move( $extracted, $destination, false ) ) {
				return new \WP_Error( 'promotion_move', __( 'The verified plugin could not be moved to its final destination.', 'wp-autoplugin' ) );
			}
			$plugin_file = $built['plugin_file'];
			if ( ! file_exists( WP_PLUGIN_DIR . '/' . $plugin_file ) ) {
				$filesystem->delete( $destination, true );
				return new \WP_Error( 'promotion_verify', __( 'The installed plugin could not be verified at its final destination.', 'wp-autoplugin' ) );
			}
			( new Release_Repository() )->update_promotion(
				(int) $promotion['id'],
				[
					'status' => 'installed', 'destination_plugin_file' => $plugin_file, 'destination_slug' => $built['slug'],
					'target_fingerprint' => $built['tree_fingerprint'], 'header_transforms' => $built['header_transforms'],
					'active_before' => 0, 'active_after' => 0, 'finished_at' => current_time( 'mysql', true ),
				]
			);
			return [ 'status' => 'installed', 'plugin_file' => $plugin_file, 'slug' => $built['slug'], 'active' => false, 'header_transforms' => $built['header_transforms'] ];
		} finally {
			if ( file_exists( $temp ) ) {
				$filesystem->delete( $temp, true );
			}
			if ( is_file( $built['path'] ) ) {
				wp_delete_file( $built['path'] );
			}
		}
	}

	/** @return array<string,mixed>|\WP_Error */
	public function activate( array $promotion ) {
		if ( ! user_can( (int) $promotion['created_by'], 'activate_plugins' ) ) {
			return new \WP_Error( 'promotion_capability', __( 'You are not allowed to activate plugins.', 'wp-autoplugin' ), [ 'status' => 403 ] );
		}
		if ( ! in_array( $promotion['mode'], [ 'install_project', 'install_fork' ], true ) || ! in_array( $promotion['status'], [ 'installed', 'activation_failed' ], true ) ) {
			return new \WP_Error( 'promotion_activation_state', __( 'This promotion is not waiting for activation.', 'wp-autoplugin' ), [ 'status' => 409 ] );
		}
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		$destination = (string) $promotion['destination_plugin_file'];
		$source      = (string) $promotion['source_plugin_file'];
		$original_active = '' !== $source && is_plugin_active( $source );
		$valid = validate_plugin( $destination );
		if ( is_wp_error( $valid ) ) {
			return $this->activation_failed( $promotion, $valid->get_error_message(), $original_active );
		}
		$requirements = validate_plugin_requirements( $destination );
		if ( is_wp_error( $requirements ) ) {
			return $this->activation_failed( $promotion, $requirements->get_error_message(), $original_active );
		}
		$activator = new Isolated_Plugin_Activator();
		if ( 'install_fork' === $promotion['mode'] && $original_active ) {
			$probe = $activator->probe( (int) $promotion['created_by'] );
			if ( is_wp_error( $probe ) ) {
				return $this->activation_failed( $promotion, $probe->get_error_message(), true );
			}
			deactivate_plugins( $source, false, false );
		}
		$error = $activator->activate( $destination, (int) $promotion['created_by'] );
		if ( is_wp_error( $error ) ) {
			$message = $error->get_error_message();
			if ( 'install_fork' === $promotion['mode'] && $original_active ) {
				$reactivated = $activator->activate( $source, (int) $promotion['created_by'] );
				if ( is_wp_error( $reactivated ) ) {
					if ( $this->restore_active_plugin( $source, $destination ) ) {
						$message .= ' ' . __( 'The original plugin was marked active again, but WordPress could not rerun its activation hook.', 'wp-autoplugin' );
					} else {
						$message .= ' ' . sprintf( __( 'The original plugin also could not be reactivated: %s', 'wp-autoplugin' ), $reactivated->get_error_message() );
					}
				}
			}
			return $this->activation_failed( $promotion, $message, $original_active );
		}
		$status = 'install_fork' === $promotion['mode'] ? 'switched' : 'activated';
		( new Release_Repository() )->update_promotion( (int) $promotion['id'], [ 'status' => $status, 'error_message' => null, 'active_before' => $original_active ? 1 : 0, 'active_after' => 1, 'finished_at' => current_time( 'mysql', true ) ] );
		return [ 'status' => $status, 'plugin_file' => $destination, 'active' => true, 'original_plugin_file' => $source ?: null, 'original_active' => false, 'side_effect_warning' => __( 'Activation and deactivation may have database or runtime side effects that file rollback cannot undo.', 'wp-autoplugin' ) ];
	}

	/** @return \WP_Error */
	private function activation_failed( array $promotion, string $message, bool $original_active ) {
		( new Release_Repository() )->update_promotion(
			(int) $promotion['id'],
			[
				'status'        => 'activation_failed',
				'error_message' => $message,
				'active_before' => $original_active ? 1 : 0,
				'active_after'  => 0,
				'finished_at'   => current_time( 'mysql', true ),
			]
		);
		return new \WP_Error( 'promotion_activation_failed', $message );
	}

	private function restore_active_plugin( string $source, string $destination ): bool {
		$active = array_values( array_diff( (array) get_option( 'active_plugins', [] ), [ $destination ] ) );
		if ( ! in_array( $source, $active, true ) ) {
			$active[] = $source;
		}
		sort( $active );
		update_option( 'active_plugins', $active );
		wp_cache_delete( 'active_plugins', 'options' );
		wp_cache_delete( 'alloptions', 'options' );
		return in_array( $source, (array) get_option( 'active_plugins', [] ), true );
	}

	/** @return array<string,mixed>|\WP_Error */
	public function modify( array $promotion, array $workspace, array $revision ) {
		if ( ! user_can( (int) $promotion['created_by'], 'update_plugins' ) ) {
			return new \WP_Error( 'promotion_capability', __( 'The promotion owner is no longer allowed to modify plugins.', 'wp-autoplugin' ), [ 'status' => 403 ] );
		}
		$filesystem = $this->filesystem();
		if ( is_wp_error( $filesystem ) ) {
			return $filesystem;
		}
		$builder = new Package_Builder();
		$root    = $builder->target_root( (string) $workspace['target_ref'] );
		if ( is_wp_error( $root ) ) {
			return $root;
		}
		try {
			$tools = new Source_Tools( (array) $workspace['target_metadata'] );
			if ( ! hash_equals( (string) ( $revision['project_manifest']['target_fingerprint'] ?? '' ), $tools->tree_fingerprint() ) ) {
				return new \WP_Error( 'promotion_target_changed', __( 'The installed plugin changed after this revision was staged.', 'wp-autoplugin' ) );
			}
		} catch ( \Throwable $error ) {
			return new \WP_Error( 'promotion_target_unavailable', __( 'The installed plugin is unavailable for direct modification.', 'wp-autoplugin' ) );
		}
		$tree = $builder->fingerprint_target( (string) $workspace['target_ref'], false );
		if ( is_wp_error( $tree ) ) {
			return $tree;
		}
		$expected_complete = (string) ( $revision['project_manifest']['complete_target_fingerprint'] ?? '' );
		if ( '' === $expected_complete || ! hash_equals( $expected_complete, $tree['fingerprint'] ) ) {
			return new \WP_Error( 'promotion_complete_target_changed', __( 'The complete installed plugin tree changed after this revision was staged.', 'wp-autoplugin' ) );
		}
		$records = [];
		foreach ( (array) $revision['files'] as $file ) {
			$path      = (string) $file['path'];
			$target    = $this->target_path( $root, $path );
			$exists    = is_file( $target );
			$operation = (string) $file['change_type'];
			$before    = $exists ? file_get_contents( $target ) : null; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Conflict-checked local plugin snapshot.
			if ( ( 'add' === $operation && $exists ) || ( in_array( $operation, [ 'update', 'delete' ], true ) && ( ! $exists || false === $before || ! hash_equals( (string) $file['base_content_hash'], hash( 'sha256', $before ) ) ) ) ) {
				return new \WP_Error( 'promotion_file_drift', sprintf( __( '%s no longer matches the staged baseline.', 'wp-autoplugin' ), $path ) );
			}
			$after = 'delete' === $operation ? null : (string) $file['content'];
			$records[ $path ] = $this->record( $path, $operation, $before, $after );
		}
		$main = basename( (string) $workspace['target_ref'] );
		$main_path = $this->target_path( $root, $main );
		$main_before = file_get_contents( $main_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Conflict-checked local plugin snapshot.
		if ( false === $main_before ) {
			return new \WP_Error( 'promotion_main_file', __( 'The target main plugin file could not be read.', 'wp-autoplugin' ) );
		}
		$main_after = isset( $records[ $main ] ) ? (string) $records[ $main ]['promoted_content'] : $main_before;
		try {
			$main_after = ( new Version_Bumper() )->bump( $main_after );
		} catch ( \Throwable $error ) {
			return new \WP_Error( 'promotion_version', $error->getMessage() );
		}
		$records[ $main ] = $this->record( $main, 'update', $main_before, $main_after );
		$records = array_values( $records );
		$repository = new Release_Repository();
		$repository->replace_promotion_files( (int) $promotion['id'], $records );
		$repository->update_promotion( (int) $promotion['id'], [ 'target_fingerprint' => $tree['fingerprint'], 'header_transforms' => [ 'version' => 'semantic_patch' ] ] );

		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		$active = is_plugin_active( (string) $workspace['target_ref'] );
		$directories = [];
		$this->maintenance( $active, true );
		try {
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
			$repository->update_promotion( (int) $promotion['id'], [ 'status' => $restored ? 'failed' : 'rollback_failed', 'error_message' => $error->getMessage(), 'created_directories' => $directories, 'active_before' => $active ? 1 : 0, 'active_after' => $active ? 1 : 0, 'finished_at' => current_time( 'mysql', true ) ] );
			return new \WP_Error( $restored ? 'promotion_write_failed' : 'promotion_rollback_failed', $error->getMessage() );
		} finally {
			$this->maintenance( $active, false );
		}
		$this->invalidate( $root, $records );
		$repository->update_promotion( (int) $promotion['id'], [ 'status' => 'completed', 'created_directories' => $directories, 'active_before' => $active ? 1 : 0, 'active_after' => $active ? 1 : 0, 'finished_at' => current_time( 'mysql', true ) ] );
		return [ 'status' => 'completed', 'plugin_file' => (string) $workspace['target_ref'], 'active' => $active, 'rollback_available' => true, 'warning' => __( 'Upstream updates remain enabled and may overwrite these changes. Rollback restores files only.', 'wp-autoplugin' ) ];
	}

	/** @return array<string,mixed>|\WP_Error */
	public function rollback( array $promotion ) {
		if ( ! user_can( (int) $promotion['created_by'], 'update_plugins' ) ) {
			return new \WP_Error( 'promotion_capability', __( 'The promotion owner is no longer allowed to roll back plugin files.', 'wp-autoplugin' ), [ 'status' => 403 ] );
		}
		$filesystem = $this->filesystem();
		if ( is_wp_error( $filesystem ) ) {
			return $filesystem;
		}
		$repository = new Release_Repository();
		if ( 'modify_original' !== $promotion['mode'] || 'completed' !== $promotion['status'] || ! $repository->is_latest_in_place( (int) $promotion['id'], (string) $promotion['destination_plugin_file'] ) ) {
			return new \WP_Error( 'promotion_rollback_state', __( 'Only the latest successful direct modification can be rolled back.', 'wp-autoplugin' ), [ 'status' => 409 ] );
		}
		$builder = new Package_Builder();
		$root    = $builder->target_root( (string) $promotion['destination_plugin_file'] );
		if ( is_wp_error( $root ) ) {
			return $root;
		}
		$records = $repository->promotion_files( (int) $promotion['id'] );
		try {
			$this->verify( $root, $records, true );
		} catch ( \Throwable $error ) {
			return new \WP_Error( 'promotion_rollback_conflict', __( 'Rollback was not started because an affected file changed after promotion.', 'wp-autoplugin' ), [ 'status' => 409 ] );
		}
		$active = ! empty( $promotion['active_after'] );
		$this->maintenance( $active, true );
		try {
			$restored = $this->restore_records( $root, $records, $filesystem, (array) $promotion['created_directories'], true );
			if ( ! $restored ) {
				throw new \RuntimeException( __( 'One or more promoted files could not be restored.', 'wp-autoplugin' ) );
			}
		} catch ( \Throwable $error ) {
			$repository->update_promotion( (int) $promotion['id'], [ 'status' => 'rollback_failed', 'error_message' => $error->getMessage(), 'finished_at' => current_time( 'mysql', true ) ] );
			return new \WP_Error( 'promotion_rollback_failed', $error->getMessage() );
		} finally {
			$this->maintenance( $active, false );
		}
		$this->invalidate( $root, $records );
		$repository->update_promotion( (int) $promotion['id'], [ 'status' => 'rolled_back', 'active_after' => $active ? 1 : 0, 'finished_at' => current_time( 'mysql', true ) ] );
		return [ 'status' => 'rolled_back', 'plugin_file' => $promotion['destination_plugin_file'], 'active' => $active, 'warning' => __( 'Files were restored. Database and runtime side effects were not reverted.', 'wp-autoplugin' ) ];
	}

	private function filesystem() {
		if ( is_multisite() ) {
			return new \WP_Error( 'promotion_multisite', __( 'Plugin filesystem mutation is not available on multisite yet.', 'wp-autoplugin' ), [ 'status' => 409 ] );
		}
		if ( defined( 'DISALLOW_FILE_MODS' ) && DISALLOW_FILE_MODS ) {
			return new \WP_Error( 'promotion_file_mods_disabled', __( 'WordPress file modifications are disabled.', 'wp-autoplugin' ), [ 'status' => 409 ] );
		}
		require_once ABSPATH . 'wp-admin/includes/file.php';
		if ( ! WP_Filesystem() ) {
			return new \WP_Error( 'promotion_filesystem_credentials', __( 'WordPress could not initialize filesystem access without additional credentials.', 'wp-autoplugin' ), [ 'status' => 409 ] );
		}
		global $wp_filesystem;
		return $wp_filesystem;
	}

	private function record( string $path, string $operation, ?string $before, ?string $after ): array {
		return [ 'path' => $path, 'operation' => $operation, 'base_exists' => null !== $before, 'base_content' => $before, 'base_hash' => null === $before ? null : hash( 'sha256', $before ), 'promoted_exists' => null !== $after, 'promoted_content' => $after, 'promoted_hash' => null === $after ? null : hash( 'sha256', $after ) ];
	}

	private function target_path( string $root, string $relative ): string {
		$relative = str_replace( '\\', '/', trim( $relative ) );
		$parts    = explode( '/', $relative );
		if ( '' === $relative || str_starts_with( $relative, '/' ) || preg_match( '/^[A-Za-z]:/', $relative ) || preg_match( '/[\x00-\x1F]/', $relative ) || array_intersect( [ '', '.', '..' ], $parts ) ) {
			throw new \RuntimeException( __( 'A promotion file path is unsafe.', 'wp-autoplugin' ) );
		}
		if ( is_file( $root ) ) {
			if ( basename( $root ) !== $relative ) {
				throw new \RuntimeException( __( 'A single-file plugin cannot write another path.', 'wp-autoplugin' ) );
			}
			return $root;
		}
		$path = wp_normalize_path( trailingslashit( $root ) . $relative );
		if ( ! str_starts_with( $path, trailingslashit( wp_normalize_path( $root ) ) ) ) {
			throw new \RuntimeException( __( 'A promotion file path escaped the plugin root.', 'wp-autoplugin' ) );
		}
		$current = $root;
		foreach ( array_slice( $parts, 0, -1 ) as $part ) {
			$current .= '/' . $part;
			if ( is_link( $current ) ) {
				throw new \RuntimeException( __( 'A promotion path traverses a symbolic link.', 'wp-autoplugin' ) );
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
				throw new \RuntimeException( __( 'A required plugin directory could not be created.', 'wp-autoplugin' ) );
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
				throw new \RuntimeException( __( 'A promoted file presence check failed.', 'wp-autoplugin' ) );
			}
			if ( $exists && ! hash_equals( (string) $record[ $prefix . '_hash' ], (string) hash_file( 'sha256', $path ) ) ) {
				throw new \RuntimeException( __( 'A promoted file hash check failed.', 'wp-autoplugin' ) );
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
		if ( ! function_exists( 'opcache_invalidate' ) ) {
			return;
		}
		foreach ( $records as $record ) {
			if ( 'php' === strtolower( (string) pathinfo( $record['path'], PATHINFO_EXTENSION ) ) ) {
				@opcache_invalidate( $this->target_path( $root, $record['path'] ), true );
			}
		}
	}

	private function maintenance( bool $active, bool $enable ): void {
		if ( ! $active ) {
			return;
		}
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		$upgrader = new \WP_Upgrader( new \Automatic_Upgrader_Skin() );
		$upgrader->maintenance_mode( $enable );
	}

	private function valid_plugin_header( string $path ): bool {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		$data = get_plugin_data( $path, false, false );
		return '' !== trim( (string) ( $data['Name'] ?? '' ) );
	}
}
