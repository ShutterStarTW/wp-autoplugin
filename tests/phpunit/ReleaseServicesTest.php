<?php

use WP_Autoplugin\V2\Domain\Revision\Version_Bumper;
use WP_Autoplugin\V2\Domain\Target\Source_Tools;
use WP_Autoplugin\V2\Infrastructure\Database\Installer;
use WP_Autoplugin\V2\Infrastructure\Database\Release_Repository;
use WP_Autoplugin\V2\Release\Package_Builder;
use WP_Autoplugin\V2\Release\Private_Release_Storage;
use WP_Autoplugin\V2\Release\Release_Matrix;
use WP_Autoplugin\V2\Release\Theme_Header_Transformer;
use WP_Autoplugin\V2\Release\Theme_Package_Builder;
use WP_Autoplugin\V2\Release\Theme_Promotion_Service;

/** Deterministic package-boundary and version-transform coverage. */
final class ReleaseServicesTest extends WP_UnitTestCase {
	private string $directory;
	/** @var array<int,string> */
	private array $theme_directories = [];
	/** @var array<int,string> */
	private array $package_paths = [];
	/** @var array<int,int> */
	private array $promotion_ids = [];

	protected function setUp(): void {
		parent::setUp();
		$this->directory = wp_normalize_path( sys_get_temp_dir() . '/wp-autoplugin-release-test-' . wp_generate_password( 16, false, false ) );
		wp_mkdir_p( $this->directory );
	}

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
		foreach ( array_reverse( $this->theme_directories ) as $directory ) {
			$this->remove_tree( $directory );
		}
		wp_clean_themes_cache( true );
		$this->remove_tree( $this->directory );
		parent::tearDown();
	}

	public function test_tree_fingerprint_is_stable_and_limits_fail_without_an_artifact(): void {
		file_put_contents( $this->directory . '/one.php', '<?php' );
		file_put_contents( $this->directory . '/two.js', 'window.fixture = true;' );
		$builder = new Package_Builder();

		$first  = $builder->scan_tree( $this->directory );
		$second = $builder->scan_tree( $this->directory );
		$this->assertFalse( is_wp_error( $first ) );
		$this->assertSame( $first, $second );

		$limit = static fn(): int => 1;
		add_filter( 'wp_autoplugin_v2_release_max_files', $limit );
		$this->assertWPError( $builder->scan_tree( $this->directory ) );
		remove_filter( 'wp_autoplugin_v2_release_max_files', $limit );
	}

	public function test_private_release_storage_rejects_external_or_tampered_archives(): void {
		$storage = new Private_Release_Storage();
		$root    = $storage->root();
		$this->assertFalse( is_wp_error( $root ) );

		$archive = $root . '/package-' . wp_generate_password( 12, false, false ) . '.zip';
		file_put_contents( $archive, 'archive bytes' );
		chmod( $archive, 0600 );
		$this->package_paths[] = $archive;
		$hash                  = hash_file( 'sha256', $archive );
		$size                  = filesize( $archive );

		$this->assertSame( $archive, $storage->verified_archive( $archive, $hash, $size ) );
		$this->assertNull( $storage->verified_archive( $archive, str_repeat( '0', 64 ), $size ) );
		$this->assertNull( $storage->verified_archive( $archive, $hash, $size + 1 ) );

		$external = $this->directory . '/package-external.zip';
		file_put_contents( $external, 'archive bytes' );
		$this->assertNull( $storage->verified_archive( $external, hash_file( 'sha256', $external ), filesize( $external ) ) );
	}

	public function test_tree_rejects_symbolic_links(): void {
		file_put_contents( $this->directory . '/source.php', '<?php' );
		if ( ! function_exists( 'symlink' ) || ! @symlink( $this->directory . '/source.php', $this->directory . '/linked.php' ) ) {
			$this->markTestSkipped( 'Symbolic links are unavailable in this test environment.' );
		}

		$result = ( new Package_Builder() )->scan_tree( $this->directory );
		$this->assertWPError( $result );
		$this->assertSame( 'release_symlink', $result->get_error_code() );
		$this->assertStringContainsString( 'linked.php', $result->get_error_message() );
	}

	public function test_source_fingerprint_omits_node_modules_with_symbolic_links(): void {
		file_put_contents( $this->directory . '/source.php', '<?php' );
		wp_mkdir_p( $this->directory . '/node_modules/.bin' );
		file_put_contents( $this->directory . '/node_modules/tool.js', 'tool' );
		if ( ! function_exists( 'symlink' ) || ! @symlink( '../tool.js', $this->directory . '/node_modules/.bin/tool' ) ) {
			$this->markTestSkipped( 'Symbolic links are unavailable in this test environment.' );
		}

		$builder = new Package_Builder();
		$first   = $builder->scan_tree( $this->directory, true );
		$this->assertFalse( is_wp_error( $first ), is_wp_error( $first ) ? $first->get_error_message() : '' );
		$this->assertSame( 1, $first['files'] );

		file_put_contents( $this->directory . '/node_modules/tool.js', 'changed tool' );
		$second = $builder->scan_tree( $this->directory, true );
		$this->assertFalse( is_wp_error( $second ), is_wp_error( $second ) ? $second->get_error_message() : '' );
		$this->assertSame( $first['fingerprint'], $second['fingerprint'] );
	}

	public function test_complete_tree_fingerprint_includes_binary_assets_but_excludes_vcs_metadata(): void {
		wp_mkdir_p( $this->directory . '/.git' );
		file_put_contents( $this->directory . '/asset.bin', "\x00\x01\x02" );
		file_put_contents( $this->directory . '/.git/config', 'first' );
		$builder = new Package_Builder();
		$first   = $builder->scan_tree( $this->directory, true );

		file_put_contents( $this->directory . '/.git/config', 'second' );
		$vcs_changed = $builder->scan_tree( $this->directory, true );
		file_put_contents( $this->directory . '/asset.bin', "\x00\x01\x03" );
		$binary_changed = $builder->scan_tree( $this->directory, true );

		$this->assertFalse( is_wp_error( $first ) );
		$this->assertSame( $first['fingerprint'], $vcs_changed['fingerprint'] );
		$this->assertNotSame( $first['fingerprint'], $binary_changed['fingerprint'] );
	}

	public function test_version_bump_changes_only_the_semantic_patch_header(): void {
		$source = "<?php\n/**\n * Plugin Name: Fixture\n * Version: 1.2.3-beta+build\n * Update URI: https://example.test/plugin\n */\nconst FIXTURE_VERSION = '1.2.3-beta+build';\n";
		$result = ( new Version_Bumper() )->bump( $source );

		$this->assertStringContainsString( 'Version: 1.2.4', $result );
		$this->assertStringContainsString( "FIXTURE_VERSION = '1.2.3-beta+build'", $result );
		$this->assertStringContainsString( 'Update URI: https://example.test/plugin', $result );
	}

	public function test_theme_header_transform_normalizes_versions_and_preserves_identity_headers(): void {
		$transformer = new Theme_Header_Transformer();
		foreach (
			[
				'1'                  => '1.0.1',
				'1.2'                => '1.2.1',
				'1.2.3'              => '1.2.4',
				'1.2.3-beta.1+build' => '1.2.4',
			] as $current => $expected
		) {
			$source = "/*\n * Theme Name: Fixture\n * Version: $current\n * Template: parent-theme\n * Text Domain: fixture\n */\n";
			$result = $transformer->transform( $source, 'replacement' );
			$this->assertStringContainsString( "Version: $expected", $result['content'] );
			$this->assertStringContainsString( 'Template: parent-theme', $result['content'] );
			$this->assertStringContainsString( 'Text Domain: fixture', $result['content'] );
		}
	}

	public function test_theme_copy_headers_are_deterministic_and_missing_version_is_inserted(): void {
		$source = "/*\n * Theme Name: Fixture\n * Template: parent-theme\n * Text Domain: fixture\n */\nbody { color: red; }\n";
		$result = ( new Theme_Header_Transformer() )->transform( $source, 'copy', 'fixture-copy', 'Fixture' );

		$this->assertStringContainsString( 'Theme Name: Fixture — WP-Autoplugin Copy', $result['content'] );
		$this->assertStringContainsString( 'Version: 0.0.1', $result['content'] );
		$this->assertStringContainsString( 'Update URI: https://wp-autoplugin.local/theme-copy/fixture-copy', $result['content'] );
		$this->assertStringContainsString( 'Template: parent-theme', $result['content'] );
		$this->assertStringContainsString( 'Text Domain: fixture', $result['content'] );
		$this->assertStringContainsString( 'body { color: red; }', $result['content'] );
	}

	public function test_theme_header_transform_preserves_a_single_line_comment_boundary(): void {
		$result = ( new Theme_Header_Transformer() )->transform( '/* Theme Name: Fixture */', 'replacement' );

		$this->assertSame( "/* Theme Name: Fixture */\n/* Version: 0.0.1 */", $result['content'] );
	}

	public function test_theme_header_transform_rejects_malformed_version(): void {
		$this->expectException( InvalidArgumentException::class );
		( new Theme_Header_Transformer() )->transform( "/*\n * Theme Name: Fixture\n * Version: next\n */\n", 'direct' );
	}

	public function test_release_matrix_rejects_cross_artifact_modes(): void {
		$this->assertTrue( Release_Matrix::allows( 'package', 'changes', 'theme', 'theme_replacement' ) );
		$this->assertTrue( Release_Matrix::allows( 'promotion', 'changes', 'theme', 'install_theme_copy' ) );
		$this->assertTrue( Release_Matrix::allows( 'promotion', 'changes', 'theme', 'modify_theme_original' ) );
		$this->assertFalse( Release_Matrix::allows( 'package', 'changes', 'plugin', 'theme_replacement' ) );
		$this->assertFalse( Release_Matrix::allows( 'promotion', 'changes', 'theme', 'install_fork' ) );
		$this->assertFalse( Release_Matrix::allows( 'promotion', 'changes', 'plugin', 'install_theme_copy' ) );
	}

	public function test_theme_in_use_includes_the_parent_of_the_active_child_theme(): void {
		$stylesheet = get_option( 'stylesheet' );
		$template   = get_option( 'template' );
		try {
			update_option( 'stylesheet', 'fixture-child' );
			update_option( 'template', 'fixture-parent' );
			$service = new Theme_Promotion_Service();

			$this->assertTrue( $service->in_use( 'fixture-child' ) );
			$this->assertTrue( $service->in_use( 'fixture-parent' ) );
			$this->assertFalse( $service->in_use( 'fixture-inactive' ) );
			$this->assertStringContainsString( 'parent of the active child theme', $service->in_use_reason( 'fixture-parent' ) );
		} finally {
			update_option( 'stylesheet', $stylesheet );
			update_option( 'template', $template );
		}
	}

	public function test_materialized_standalone_theme_requires_stylesheet_name_and_index(): void {
		$root = $this->directory . '/theme';
		wp_mkdir_p( $root );
		$builder = new Theme_Package_Builder();

		$this->assertSame( 'release_theme_stylesheet', $builder->validate_materialized_theme( $root, [ 'is_child' => false ] )->get_error_code() );
		file_put_contents( $root . '/style.css', "/*\n * Version: 1.0.0\n */\n" );
		$this->assertSame( 'release_theme_header', $builder->validate_materialized_theme( $root, [ 'is_child' => false ] )->get_error_code() );
		file_put_contents( $root . '/style.css', "/*\n * Theme Name: Fixture\n */\n" );
		$this->assertSame( 'release_theme_index', $builder->validate_materialized_theme( $root, [ 'is_child' => false ] )->get_error_code() );
		wp_mkdir_p( $root . '/templates' );
		file_put_contents( $root . '/templates/index.html', '<!-- wp:group /-->' );

		$this->assertSame( [ 'template' => '' ], $builder->validate_materialized_theme( $root, [ 'is_child' => false ] ) );
		$this->remove_tree( $root . '/templates' );
		wp_mkdir_p( $root . '/block-templates' );
		file_put_contents( $root . '/block-templates/index.html', '<!-- wp:group /-->' );
		$this->assertSame( [ 'template' => '' ], $builder->validate_materialized_theme( $root, [ 'is_child' => false ] ) );
	}

	public function test_materialized_child_theme_requires_the_exact_installed_parent(): void {
		$root = $this->directory . '/child';
		wp_mkdir_p( $root );
		file_put_contents( $root . '/style.css', "/*\n * Theme Name: Fixture Child\n * Template: definitely-missing-parent\n */\n" );
		$builder = new Theme_Package_Builder();

		$missing = $builder->validate_materialized_theme(
			$root,
			[ 'is_child' => true, 'template' => 'definitely-missing-parent' ]
		);
		$changed = $builder->validate_materialized_theme(
			$root,
			[ 'is_child' => true, 'template' => 'different-parent' ]
		);

		$this->assertSame( 'release_theme_parent_missing', $missing->get_error_code() );
		$this->assertSame( 'release_theme_template_changed', $changed->get_error_code() );
	}

	public function test_materialized_child_preserves_parent_and_rejects_a_grandchild(): void {
		$parent_slug = $this->unique_slug( 'parent' );
		$child_slug  = $this->unique_slug( 'child' );
		$third_slug  = $this->unique_slug( 'third' );
		$this->install_theme(
			$parent_slug,
			"/*\n * Theme Name: Parent Fixture\n * Version: 1.0.0\n */\n",
			[ 'index.php' => "<?php\n" ]
		);
		$child_root = $this->install_theme(
			$child_slug,
			"/*\n * Theme Name: Child Fixture\n * Template: $parent_slug\n */\n",
			[ 'functions.php' => "<?php\n" ]
		);
		$third_root = $this->install_theme(
			$third_slug,
			"/*\n * Theme Name: Third Fixture\n * Template: $child_slug\n */\n",
			[ 'functions.php' => "<?php\n" ]
		);
		$builder = new Theme_Package_Builder();

		$this->assertSame(
			[ 'template' => $parent_slug ],
			$builder->validate_materialized_theme( $child_root, [ 'is_child' => true, 'template' => $parent_slug ] )
		);
		$metadata = $this->theme_metadata( $child_slug );
		$revision = $this->theme_revision(
			$child_slug,
			$metadata,
			[
				[
					'path'              => 'functions.php',
					'change_type'       => 'update',
					'base_content_hash' => hash( 'sha256', "<?php\n" ),
					'content'           => "<?php\nreturn true;\n",
				],
			]
		);
		$workspace   = [ 'target_ref' => $child_slug, 'target_metadata' => $metadata, 'project_name' => 'Child Fixture' ];
		$replacement = $builder->build( $workspace, $revision, 'replacement' );
		$this->assertFalse( is_wp_error( $replacement ), is_wp_error( $replacement ) ? $replacement->get_error_message() : '' );
		$this->package_paths[] = $replacement['path'];
		$this->assertTrue( $replacement['is_child'] );
		$this->assertSame( $parent_slug, $replacement['template'] );
		$copy_slug = $this->unique_slug( 'child-copy' );
		$copy      = $builder->build( $workspace, $revision, 'copy', $copy_slug );
		$this->assertFalse( is_wp_error( $copy ), is_wp_error( $copy ) ? $copy->get_error_message() : '' );
		$this->package_paths[] = $copy['path'];
		$this->assertSame( $parent_slug, $copy['template'] );
		if ( class_exists( ZipArchive::class ) ) {
			$copy_root = $this->extract_package( $copy['path'], 'child-copy' ) . '/' . $copy_slug;
			$this->assertStringContainsString( 'Template: ' . $parent_slug, file_get_contents( $copy_root . '/style.css' ) );
		}
		$grandchild = $builder->validate_materialized_theme( $third_root, [ 'is_child' => true, 'template' => $child_slug ] );
		$this->assertWPError( $grandchild );
		$this->assertSame( 'release_theme_grandchild', $grandchild->get_error_code() );
	}

	public function test_materialized_theme_rejects_unsatisfied_php_requirement(): void {
		$root = $this->directory . '/requirements';
		wp_mkdir_p( $root );
		file_put_contents( $root . '/style.css', "/*\n * Theme Name: Fixture\n * Requires PHP: 999.0\n */\n" );
		file_put_contents( $root . '/index.php', '<?php' );
		$result = ( new Theme_Package_Builder() )->validate_materialized_theme( $root, [ 'is_child' => false ] );

		$this->assertSame( 'release_theme_php', $result->get_error_code() );
	}

	public function test_standalone_replacement_and_copy_packages_are_revision_exact(): void {
		if ( ! class_exists( ZipArchive::class ) ) {
			$this->markTestSkipped( 'ZipArchive is required to inspect the completed package.' );
		}

		$slug = $this->unique_slug( 'package' );
		$root = $this->install_theme(
			$slug,
			"/*\n * Theme Name: Release Fixture\n * Version: 1.2\n * Text Domain: release-fixture\n */\n",
			[
				'index.php'       => "<?php\n",
				'functions.php'   => "<?php\nreturn 'before';\n",
				'assets/data.bin' => "\x00\x01\x02",
				'obsolete.txt'    => 'remove me',
			]
		);
		$metadata = $this->theme_metadata( $slug );
		$revision = $this->theme_revision(
			$slug,
			$metadata,
			[
				[
					'path'              => 'functions.php',
					'change_type'       => 'update',
					'base_content_hash' => hash( 'sha256', "<?php\nreturn 'before';\n" ),
					'content'           => "<?php\nreturn 'after';\n",
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
					'base_content_hash' => hash( 'sha256', 'remove me' ),
					'content'           => '',
				],
			]
		);
		$workspace = [ 'target_ref' => $slug, 'target_metadata' => $metadata, 'project_name' => 'Release Fixture' ];
		$builder   = new Theme_Package_Builder();

		$replacement = $builder->build( $workspace, $revision, 'replacement' );
		$this->assertFalse( is_wp_error( $replacement ), is_wp_error( $replacement ) ? $replacement->get_error_message() : '' );
		$this->package_paths[] = $replacement['path'];
		$replacement_root = $this->extract_package( $replacement['path'], 'replacement' ) . '/' . $slug;
		$this->assertFileExists( $replacement_root . '/style.css' );
		$this->assertStringContainsString( 'Theme Name: Release Fixture', file_get_contents( $replacement_root . '/style.css' ) );
		$this->assertStringContainsString( 'Version: 1.2.1', file_get_contents( $replacement_root . '/style.css' ) );
		$this->assertStringNotContainsString( 'Update URI:', file_get_contents( $replacement_root . '/style.css' ) );
		$this->assertSame( "<?php\nreturn 'after';\n", file_get_contents( $replacement_root . '/functions.php' ) );
		$this->assertSame( "<?php\nreturn true;\n", file_get_contents( $replacement_root . '/includes/new.php' ) );
		$this->assertFileDoesNotExist( $replacement_root . '/obsolete.txt' );
		$this->assertSame( "\x00\x01\x02", file_get_contents( $replacement_root . '/assets/data.bin' ) );

		$copy_slug = $this->unique_slug( 'copy' );
		$copy      = $builder->build( $workspace, $revision, 'copy', $copy_slug );
		$this->assertFalse( is_wp_error( $copy ), is_wp_error( $copy ) ? $copy->get_error_message() : '' );
		$this->package_paths[] = $copy['path'];
		$copy_root = $this->extract_package( $copy['path'], 'copy' ) . '/' . $copy_slug;
		$copy_css  = file_get_contents( $copy_root . '/style.css' );
		$this->assertStringContainsString( 'Theme Name: Release Fixture — WP-Autoplugin Copy', $copy_css );
		$this->assertStringContainsString( 'Version: 1.2.1', $copy_css );
		$this->assertStringContainsString( 'Update URI: https://wp-autoplugin.local/theme-copy/' . $copy_slug, $copy_css );
		$this->assertStringContainsString( 'Text Domain: release-fixture', $copy_css );
		$this->assertSame( hash_file( 'sha256', $root . '/assets/data.bin' ), hash_file( 'sha256', $copy_root . '/assets/data.bin' ) );

		$collision = $builder->build( $workspace, $revision, 'copy', $slug );
		$this->assertWPError( $collision );
		$this->assertSame( 'release_theme_copy_collision', $collision->get_error_code() );

		file_put_contents( $root . '/assets/data.bin', "\x00\x01\x03" );
		$stale = $builder->build( $workspace, $revision, 'replacement' );
		$this->assertWPError( $stale );
		$this->assertSame( 'release_theme_complete_changed', $stale->get_error_code() );
	}

	public function test_direct_inactive_theme_change_and_rollback_are_drift_and_activity_safe(): void {
		if ( is_multisite() || ( defined( 'DISALLOW_FILE_MODS' ) && DISALLOW_FILE_MODS ) ) {
			$this->markTestSkipped( 'Direct theme mutation is intentionally unavailable in this environment.' );
		}

		$slug = $this->unique_slug( 'direct' );
		$root = $this->install_theme(
			$slug,
			"/*\n * Theme Name: Direct Fixture\n * Version: 2\n */\n",
			[
				'index.php'     => "<?php\n",
				'functions.php' => "<?php\nreturn 'before';\n",
				'obsolete.txt'  => 'restore me',
			]
		);
		$metadata = $this->theme_metadata( $slug );
		$revision = $this->theme_revision(
			$slug,
			$metadata,
			[
				[
					'path'              => 'functions.php',
					'change_type'       => 'update',
					'base_content_hash' => hash( 'sha256', "<?php\nreturn 'before';\n" ),
					'content'           => "<?php\nreturn 'after';\n",
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
			]
		);
		$revision['id'] = wp_rand( 900000, 999999 );
		$user_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		Installer::activate();
		$repository = new Release_Repository();
		$promotion  = $repository->create_promotion(
			[ 'id' => wp_rand( 900000, 999999 ), 'project_id' => 987660, 'created_by' => $user_id, 'payload' => [] ],
			$revision,
			'modify_theme_original',
			$slug,
			$slug,
			$slug,
			false,
			'theme'
		);
		$this->promotion_ids[] = (int) $promotion['id'];
		$workspace = [ 'target_ref' => $slug, 'target_metadata' => $metadata ];
		$service   = new Theme_Promotion_Service();

		$result = $service->modify( $promotion, $workspace, $revision );
		$this->assertFalse( is_wp_error( $result ), is_wp_error( $result ) ? $result->get_error_message() : '' );
		$this->assertSame( "<?php\nreturn 'after';\n", file_get_contents( $root . '/functions.php' ) );
		$this->assertSame( "<?php\nreturn true;\n", file_get_contents( $root . '/includes/new.php' ) );
		$this->assertFileDoesNotExist( $root . '/obsolete.txt' );
		$this->assertStringContainsString( 'Version: 2.0.1', file_get_contents( $root . '/style.css' ) );
		$records = $repository->promotion_files( (int) $promotion['id'] );
		$this->assertCount( 4, $records );
		$this->assertSame( hash( 'sha256', "/*\n * Theme Name: Direct Fixture\n * Version: 2.0.1\n */\n" ), $this->record_hash( $records, 'style.css', 'promoted_hash' ) );

		$completed = $repository->promotion( (int) $promotion['id'] );
		$this->assertIsArray( $completed );
		$stylesheet = get_option( 'stylesheet' );
		$template   = get_option( 'template' );
		try {
			update_option( 'stylesheet', $slug );
			update_option( 'template', $slug );
			$active = $service->rollback( $completed );
			$this->assertWPError( $active );
			$this->assertSame( 'theme_promotion_rollback_in_use', $active->get_error_code() );
		} finally {
			update_option( 'stylesheet', $stylesheet );
			update_option( 'template', $template );
		}

		file_put_contents( $root . '/functions.php', "<?php\nreturn 'drift';\n" );
		$drift = $service->rollback( $completed );
		$this->assertWPError( $drift );
		$this->assertSame( 'theme_promotion_rollback_conflict', $drift->get_error_code() );
		file_put_contents( $root . '/functions.php', "<?php\nreturn 'after';\n" );

		$rolled_back = $service->rollback( $completed );
		$this->assertFalse( is_wp_error( $rolled_back ), is_wp_error( $rolled_back ) ? $rolled_back->get_error_message() : '' );
		$this->assertSame( "<?php\nreturn 'before';\n", file_get_contents( $root . '/functions.php' ) );
		$this->assertFileDoesNotExist( $root . '/includes/new.php' );
		$this->assertSame( 'restore me', file_get_contents( $root . '/obsolete.txt' ) );
		$this->assertStringContainsString( 'Version: 2', file_get_contents( $root . '/style.css' ) );
		$this->assertStringNotContainsString( 'Version: 2.0.1', file_get_contents( $root . '/style.css' ) );
	}

	public function test_theme_direct_rollback_order_is_last_in_first_out(): void {
		global $wpdb;

		Installer::activate();
		$repository = new Release_Repository();
		$job_id     = wp_rand( 800000, 899998 );
		$revision   = [ 'id' => 654321 ];
		$first      = $repository->create_promotion(
			[ 'id' => $job_id, 'project_id' => 987659, 'created_by' => 1, 'payload' => [] ],
			$revision,
			'modify_theme_original',
			'fixture-theme',
			'fixture-theme',
			'fixture-theme',
			false,
			'theme'
		);
		$repository->update_promotion( (int) $first['id'], [ 'status' => 'completed' ] );
		$second = $repository->create_promotion(
			[ 'id' => $job_id + 1, 'project_id' => 987659, 'created_by' => 1, 'payload' => [] ],
			$revision,
			'modify_theme_original',
			'fixture-theme',
			'fixture-theme',
			'fixture-theme',
			false,
			'theme'
		);
		$repository->update_promotion( (int) $second['id'], [ 'status' => 'completed' ] );

		$this->assertFalse( $repository->is_latest_in_place( (int) $first['id'], 'fixture-theme', 'theme' ) );
		$this->assertTrue( $repository->is_latest_in_place( (int) $second['id'], 'fixture-theme', 'theme' ) );
		$repository->update_promotion( (int) $second['id'], [ 'status' => 'rolled_back' ] );
		$this->assertTrue( $repository->is_latest_in_place( (int) $first['id'], 'fixture-theme', 'theme' ) );

		$wpdb->delete( Installer::table( 'promotions' ), [ 'id' => (int) $first['id'] ] );
		$wpdb->delete( Installer::table( 'promotions' ), [ 'id' => (int) $second['id'] ] );
	}

	private function unique_slug( string $suffix ): string {
		return 'wp-autoplugin-' . $suffix . '-' . strtolower( wp_generate_password( 8, false, false ) );
	}

	/** @param array<string,string> $files */
	private function install_theme( string $slug, string $stylesheet, array $files ): string {
		if ( ! is_writable( get_theme_root() ) ) {
			$this->markTestSkipped( 'The WordPress theme root is not writable in this test environment.' );
		}
		$root = wp_normalize_path( trailingslashit( get_theme_root() ) . $slug );
		$this->assertDirectoryDoesNotExist( $root );
		$this->assertTrue( wp_mkdir_p( $root ) );
		$this->theme_directories[] = $root;
		file_put_contents( $root . '/style.css', $stylesheet );
		foreach ( $files as $relative => $content ) {
			$path = $root . '/' . $relative;
			$this->assertTrue( wp_mkdir_p( dirname( $path ) ) );
			file_put_contents( $path, $content );
		}
		wp_clean_themes_cache( true );
		$this->assertTrue( wp_get_theme( $slug )->exists() );
		return $root;
	}

	/** @return array<string,mixed> */
	private function theme_metadata( string $slug ): array {
		$theme      = wp_get_theme( $slug );
		$template   = (string) $theme->get( 'Template' );
		$is_child   = '' !== $template;
		return [
			'kind'             => 'theme',
			'ref'              => $slug,
			'name'             => (string) $theme->get( 'Name' ),
			'stylesheet'       => $slug,
			'template'         => $is_child ? $template : $slug,
			'is_child'         => $is_child,
			'parent_available' => ! $is_child || wp_get_theme( $template )->exists(),
		];
	}

	/** @param array<int,array<string,mixed>> $files */
	private function theme_revision( string $slug, array $metadata, array $files ): array {
		$complete = ( new Package_Builder() )->fingerprint_target( $slug, true, 'theme' );
		$this->assertFalse( is_wp_error( $complete ), is_wp_error( $complete ) ? $complete->get_error_message() : '' );
		return [
			'project_manifest' => [
				'scope'                       => 'changes',
				'artifact_kind'               => 'theme',
				'target_fingerprint'          => ( new Source_Tools( $metadata ) )->tree_fingerprint(),
				'complete_target_fingerprint' => $complete['fingerprint'],
			],
			'files'            => $files,
		];
	}

	private function extract_package( string $archive, string $suffix ): string {
		$destination = $this->directory . '/extract-' . $suffix;
		$this->assertTrue( wp_mkdir_p( $destination ) );
		$zip = new ZipArchive();
		$this->assertTrue( $zip->open( $archive ) );
		$this->assertTrue( $zip->extractTo( $destination ) );
		$zip->close();
		return $destination;
	}

	/** @param array<int,array<string,mixed>> $records */
	private function record_hash( array $records, string $path, string $field ): ?string {
		foreach ( $records as $record ) {
			if ( $path === $record['path'] ) {
				return $record[ $field ];
			}
		}
		return null;
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
