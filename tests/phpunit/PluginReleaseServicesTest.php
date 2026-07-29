<?php

use WP_Autoplugin\V2\Domain\Target\Source_Tools;
use WP_Autoplugin\V2\Infrastructure\Database\Installer;
use WP_Autoplugin\V2\Infrastructure\Database\Release_Repository;
use WP_Autoplugin\V2\Release\Package_Builder;
use WP_Autoplugin\V2\Release\Promotion_Service;

/** Plugin package and promotion safety coverage. */
final class PluginReleaseServicesTest extends WP_UnitTestCase {
	/** @var array<int,string> */
	private array $plugin_directories = [];
	/** @var array<int,string> */
	private array $package_paths = [];
	/** @var array<int,int> */
	private array $promotion_ids = [];

	protected function tearDown(): void {
		global $wpdb;

		foreach ( $this->promotion_ids as $promotion_id ) {
			$wpdb->delete( Installer::table( 'promotion_files' ), [ 'promotion_id' => $promotion_id ] );
			$wpdb->delete( Installer::table( 'promotions' ), [ 'id' => $promotion_id ] );
		}
		foreach ( $this->package_paths as $path ) {
			if ( is_file( $path ) ) {
				wp_delete_file( $path );
			}
		}
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		foreach ( array_reverse( $this->plugin_directories ) as $directory ) {
			$relative = ltrim( substr( wp_normalize_path( $directory ), strlen( trailingslashit( wp_normalize_path( WP_PLUGIN_DIR ) ) ) ), '/' );
			$main     = $relative . '/' . basename( $directory ) . '.php';
			if ( is_plugin_active( $main ) ) {
				deactivate_plugins( $main, true );
			}
			$this->remove_tree( $directory );
		}
		wp_clean_plugins_cache( true );
		parent::tearDown();
	}

	public function test_project_package_is_revision_exact_and_install_stays_inactive(): void {
		if ( ! class_exists( ZipArchive::class ) ) {
			$this->markTestSkipped( 'ZipArchive is required to inspect the completed package.' );
		}
		if ( ! is_writable( WP_PLUGIN_DIR ) || is_multisite() || ( defined( 'DISALLOW_FILE_MODS' ) && DISALLOW_FILE_MODS ) ) {
			$this->markTestSkipped( 'Plugin installation is intentionally unavailable in this environment.' );
		}

		$slug      = $this->unique_slug( 'project' );
		$main_file = $slug . '.php';
		$main      = "<?php\n/**\n * Plugin Name: Project Release Fixture\n * Version: 1.0.0\n */\n";
		$helper    = "<?php\nreturn 'staged';\n";
		$workspace = [
			'target_ref'      => 'new_plugin',
			'target_metadata' => [ 'kind' => 'new_plugin', 'ref' => 'new_plugin', 'name' => 'Project Release Fixture' ],
			'project_name'    => 'Project Release Fixture',
		];
		$revision  = [
			'id'               => wp_rand( 900000, 999999 ),
			'project_manifest' => [
				'scope'         => 'project',
				'artifact_kind' => 'plugin',
				'plugin_name'   => 'Project Release Fixture',
				'main_file'     => $main_file,
			],
			'files'            => [
				[ 'path' => $main_file, 'change_type' => 'add', 'content' => $main ],
				[ 'path' => 'includes/helper.php', 'change_type' => 'add', 'content' => $helper ],
			],
		];
		$builder   = new Package_Builder();
		$built     = $builder->build( $workspace, $revision, 'project', $slug );

		$this->assertFalse( is_wp_error( $built ), is_wp_error( $built ) ? $built->get_error_message() : '' );
		$this->package_paths[] = $built['path'];
		$this->assertSame( $slug . '/' . $main_file, $built['target_ref'] );
		$this->assertSame( hash_file( 'sha256', $built['path'] ), $built['sha256'] );

		$zip = new ZipArchive();
		$this->assertTrue( $zip->open( $built['path'] ) );
		$entries = [];
		for ( $index = 0; $index < $zip->numFiles; ++$index ) {
			$entries[] = $zip->getNameIndex( $index );
		}
		sort( $entries );
		$this->assertSame( [ $slug . '/includes/helper.php', $slug . '/' . $main_file ], $entries );
		$this->assertSame( $main, $zip->getFromName( $slug . '/' . $main_file ) );
		$this->assertSame( $helper, $zip->getFromName( $slug . '/includes/helper.php' ) );
		$zip->close();

		Installer::activate();
		$user_id    = self::factory()->user->create( [ 'role' => 'administrator' ] );
		$repository = new Release_Repository();
		$promotion  = $repository->create_promotion(
			[ 'id' => wp_rand( 900000, 999999 ), 'project_id' => 987661, 'created_by' => $user_id, 'payload' => [] ],
			$revision,
			'install_project',
			null,
			$slug . '/' . $main_file,
			$slug,
			false
		);
		$this->promotion_ids[]    = (int) $promotion['id'];
		$this->plugin_directories[] = wp_normalize_path( trailingslashit( WP_PLUGIN_DIR ) . $slug );

		$installed = ( new Promotion_Service() )->install( $promotion, $workspace, $revision, 'project', $slug );
		$this->assertFalse( is_wp_error( $installed ), is_wp_error( $installed ) ? $installed->get_error_message() : '' );
		$this->assertSame( 'installed', $installed['status'] );
		$this->assertFalse( $installed['active'] );
		$this->assertFileExists( WP_PLUGIN_DIR . '/' . $slug . '/' . $main_file );
		$this->assertSame( $helper, file_get_contents( WP_PLUGIN_DIR . '/' . $slug . '/includes/helper.php' ) );
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		$this->assertFalse( is_plugin_active( $slug . '/' . $main_file ) );
	}

	public function test_direct_plugin_change_and_rollback_are_drift_safe(): void {
		if ( ! is_writable( WP_PLUGIN_DIR ) || is_multisite() || ( defined( 'DISALLOW_FILE_MODS' ) && DISALLOW_FILE_MODS ) ) {
			$this->markTestSkipped( 'Direct plugin mutation is intentionally unavailable in this environment.' );
		}

		$slug        = $this->unique_slug( 'direct' );
		$main_file   = $slug . '.php';
		$main_before = "<?php\n/**\n * Plugin Name: Direct Release Fixture\n * Version: 1.2.3\n */\n";
		$helper_before = "<?php\nreturn 'before';\n";
		$helper_after  = "<?php\nreturn 'after';\n";
		$root          = $this->install_plugin(
			$slug,
			[
				$main_file   => $main_before,
				'helper.php' => $helper_before,
				'obsolete.txt' => 'restore me',
			]
		);
		$target_ref = $slug . '/' . $main_file;
		$metadata   = [
			'kind' => 'plugin',
			'ref'  => $target_ref,
			'name' => 'Direct Release Fixture',
		];
		$complete   = ( new Package_Builder() )->fingerprint_target( $target_ref );
		$this->assertFalse( is_wp_error( $complete ), is_wp_error( $complete ) ? $complete->get_error_message() : '' );
		$revision = [
			'id'               => wp_rand( 900000, 999999 ),
			'project_manifest' => [
				'scope'                       => 'changes',
				'artifact_kind'               => 'plugin',
				'target_fingerprint'          => ( new Source_Tools( $metadata ) )->tree_fingerprint(),
				'complete_target_fingerprint' => $complete['fingerprint'],
			],
			'files'            => [
				[
					'path'              => 'helper.php',
					'change_type'       => 'update',
					'base_content_hash' => hash( 'sha256', $helper_before ),
					'content'           => $helper_after,
				],
				[
					'path'              => 'includes/new.php',
					'change_type'       => 'add',
					'base_content_hash' => null,
					'content'           => "<?php\nreturn true;\n",
				],
				[
					'path'              => 'obsolete.txt',
					'change_type'       => 'delete',
					'base_content_hash' => hash( 'sha256', 'restore me' ),
					'content'           => '',
				],
			],
		];

		Installer::activate();
		$user_id    = self::factory()->user->create( [ 'role' => 'administrator' ] );
		$repository = new Release_Repository();
		$promotion  = $repository->create_promotion(
			[ 'id' => wp_rand( 900000, 999999 ), 'project_id' => 987662, 'created_by' => $user_id, 'payload' => [] ],
			$revision,
			'modify_original',
			$target_ref,
			$target_ref,
			$slug,
			false
		);
		$this->promotion_ids[] = (int) $promotion['id'];
		$workspace = [ 'target_ref' => $target_ref, 'target_metadata' => $metadata ];
		$service   = new Promotion_Service();

		$result = $service->modify( $promotion, $workspace, $revision );
		$this->assertFalse( is_wp_error( $result ), is_wp_error( $result ) ? $result->get_error_message() : '' );
		$this->assertSame( $helper_after, file_get_contents( $root . '/helper.php' ) );
		$this->assertSame( "<?php\nreturn true;\n", file_get_contents( $root . '/includes/new.php' ) );
		$this->assertFileDoesNotExist( $root . '/obsolete.txt' );
		$this->assertStringContainsString( 'Version: 1.2.4', file_get_contents( $root . '/' . $main_file ) );

		$completed = $repository->promotion( (int) $promotion['id'] );
		$this->assertIsArray( $completed );
		file_put_contents( $root . '/helper.php', "<?php\nreturn 'drift';\n" );
		$drift = $service->rollback( $completed );
		$this->assertWPError( $drift );
		$this->assertSame( 'promotion_rollback_conflict', $drift->get_error_code() );
		file_put_contents( $root . '/helper.php', $helper_after );

		$rolled_back = $service->rollback( $completed );
		$this->assertFalse( is_wp_error( $rolled_back ), is_wp_error( $rolled_back ) ? $rolled_back->get_error_message() : '' );
		$this->assertSame( 'rolled_back', $rolled_back['status'] );
		$this->assertSame( $helper_before, file_get_contents( $root . '/helper.php' ) );
		$this->assertFileDoesNotExist( $root . '/includes/new.php' );
		$this->assertSame( 'restore me', file_get_contents( $root . '/obsolete.txt' ) );
		$this->assertSame( $main_before, file_get_contents( $root . '/' . $main_file ) );
	}

	private function unique_slug( string $suffix ): string {
		return 'wp-autoplugin-' . $suffix . '-' . strtolower( wp_generate_password( 8, false, false ) );
	}

	/** @param array<string,string> $files */
	private function install_plugin( string $slug, array $files ): string {
		$root = wp_normalize_path( trailingslashit( WP_PLUGIN_DIR ) . $slug );
		$this->assertDirectoryDoesNotExist( $root );
		$this->assertTrue( wp_mkdir_p( $root ) );
		$this->plugin_directories[] = $root;
		foreach ( $files as $relative => $content ) {
			$path = $root . '/' . $relative;
			$this->assertTrue( wp_mkdir_p( dirname( $path ) ) );
			file_put_contents( $path, $content );
		}
		wp_clean_plugins_cache( true );
		return $root;
	}

	private function remove_tree( string $directory ): void {
		if ( ! is_dir( $directory ) ) {
			return;
		}
		$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $directory, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
		foreach ( $iterator as $item ) {
			$item->isDir() && ! $item->isLink() ? rmdir( $item->getPathname() ) : unlink( $item->getPathname() );
		}
		rmdir( $directory );
	}
}
