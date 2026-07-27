<?php

use WP_Autoplugin\V2\Domain\Target\Source_Tools;
use WP_Autoplugin\V2\Domain\Target\Target_Scanner;

/** Focused WordPress test-suite coverage for bounded agent source tools. */
final class SourceToolsTest extends WP_UnitTestCase {
	private string $root;
	private Source_Tools $tools;
	private ?string $parent_root = null;
	/** @var array<int, string> */
	private array $theme_directories = [];

	protected function setUp(): void {
		parent::setUp();
		$this->root = sys_get_temp_dir() . '/wp-autoplugin-explain-' . wp_generate_password( 8, false );
		mkdir( $this->root . '/includes', 0777, true );
		file_put_contents( $this->root . '/plugin.php', "<?php\n/* Plugin Name: Fixture */\nrequire __DIR__ . '/includes/class-fixture.php';\n" );
		file_put_contents( $this->root . '/includes/class-fixture.php', "<?php\nclass Fixture {\n\tpublic function answer() {\n\t\tdo_action( 'fixture_before_answer', \$this );\n\t\treturn apply_filters( 'fixture_answer', 42, \$this );\n\t}\n}\n" );

		$reflection  = new ReflectionClass( Source_Tools::class );
		$this->tools = $reflection->newInstanceWithoutConstructor();
		foreach ( [ 'target' => [ 'kind' => 'plugin', 'ref' => 'fixture/plugin.php', 'name' => 'Fixture' ], 'root' => $this->root, 'main_file' => 'plugin.php' ] as $property => $value ) {
			$field = $reflection->getProperty( $property );
			$field->setValue( $this->tools, $value );
		}
	}

	protected function tearDown(): void {
		foreach ( array_reverse( $this->theme_directories ) as $directory ) {
			$this->remove_tree( $directory );
		}
		if ( $this->theme_directories ) {
			wp_clean_themes_cache( true );
		}
		if ( null !== $this->parent_root ) {
			foreach ( [ $this->parent_root . '/inc/parent-hooks.php', $this->parent_root . '/functions.php', $this->parent_root . '/style.css' ] as $path ) {
				if ( is_file( $path ) ) {
					unlink( $path );
				}
			}
			if ( is_dir( $this->parent_root . '/inc' ) ) {
				rmdir( $this->parent_root . '/inc' );
			}
			if ( is_dir( $this->parent_root ) ) {
				rmdir( $this->parent_root );
			}
		}
		foreach ( [ $this->root . '/linked.php', $this->root . '/functions.php', $this->root . '/AGENTS.md', $this->root . '/includes/AGENTS.md' ] as $path ) {
			if ( is_file( $path ) || is_link( $path ) ) {
				unlink( $path );
			}
		}
		unlink( $this->root . '/includes/class-fixture.php' );
		unlink( $this->root . '/plugin.php' );
		rmdir( $this->root . '/includes' );
		rmdir( $this->root );
		parent::tearDown();
	}

	public function test_reads_only_requested_line_range(): void {
		$result = $this->tools->execute( 'read_file', [ 'path' => 'includes/class-fixture.php', 'start_line' => 2, 'end_line' => 3 ] );
		$this->assertStringContainsString( '2: class Fixture', $result['content'] );
		$this->assertStringContainsString( '3: \tpublic function answer()', $result['content'] );
		$this->assertStringNotContainsString( '1: <?php', $result['content'] );
		$this->assertArrayHasKey( 'includes/class-fixture.php', $result['inspected'] );
		$this->assertSame( 'includes/class-fixture.php', $result['audit']['path'] );
		$this->assertSame( 2, $result['audit']['start_line'] );
		$this->assertSame( 3, $result['audit']['end_line'] );
	}

	public function test_rejects_traversal_and_unknown_arguments(): void {
		$traversal = $this->tools->execute( 'read_file', [ 'path' => '../wp-config.php' ] );
		$this->assertTrue( $traversal['error'] );
		$this->assertSame( [], $traversal['inspected'] );
		$this->assertStringNotContainsString( 'DB_PASSWORD', $traversal['content'] );

		$unknown = $this->tools->execute( 'read_file', [ 'path' => 'plugin.php', 'unexpected' => true ] );
		$this->assertTrue( $unknown['error'] );
		$this->assertSame( 'plugin.php', $unknown['audit']['requested_path'] );
	}

	public function test_lists_and_searches_with_bounded_results(): void {
		$list = $this->tools->execute( 'list_files', [ 'offset' => 0, 'limit' => 1 ] );
		$decoded = json_decode( $list['content'], true );
		$this->assertCount( 1, $decoded['files'] );
		$this->assertSame( 2, $decoded['total'] );
		$this->assertSame( 1, $decoded['next_offset'] );
		$this->assertCount( 1, $list['audit']['returned_files'] );

		$search = $this->tools->execute( 'search_code', [ 'query' => 'return apply_filters', 'extension' => 'php' ] );
		$decoded = json_decode( $search['content'], true );
		$this->assertSame( 'includes/class-fixture.php', $decoded['hits'][0]['path'] );
		$this->assertSame( 5, $decoded['hits'][0]['line'] );
		$this->assertSame( 'return apply_filters', $search['audit']['query'] );
		$this->assertSame( [ 'includes/class-fixture.php' ], $search['audit']['matched_files'] );
	}

	public function test_lists_discovered_hooks_with_source_context(): void {
		$result  = $this->tools->execute( 'list_hooks', [ 'offset' => 0, 'limit' => 1 ] );
		$decoded = json_decode( $result['content'], true );

		$this->assertFalse( $result['error'] );
		$this->assertSame( 2, $decoded['total'] );
		$this->assertSame( 1, $decoded['next_offset'] );
		$this->assertSame( 'fixture_before_answer', $decoded['hooks'][0]['name'] );
		$this->assertSame( 'action', $decoded['hooks'][0]['type'] );
		$this->assertSame( 'includes/class-fixture.php', $decoded['hooks'][0]['path'] );
		$this->assertSame( 4, $decoded['hooks'][0]['line'] );
		$this->assertStringContainsString( "4: \t\tdo_action", $decoded['hooks'][0]['context'] );
		$this->assertSame( [ 'includes/class-fixture.php' ], $result['audit']['matched_files'] );
	}

	public function test_detects_source_changes_and_rejects_symlinks(): void {
		$bootstrap = $this->tools->bootstrap();
		$this->assertTrue( $this->tools->inspected_unchanged( $bootstrap['inspected'] ) );
		file_put_contents( $this->root . '/plugin.php', "<?php\n/* changed */\n" );
		$this->assertFalse( $this->tools->inspected_unchanged( $bootstrap['inspected'] ) );

		if ( function_exists( 'symlink' ) && symlink( $this->root . '/plugin.php', $this->root . '/linked.php' ) ) {
			$result = $this->tools->execute( 'read_file', [ 'path' => 'linked.php' ] );
			$this->assertTrue( $result['error'] );
			$this->assertSame( [], $result['inspected'] );
		}
	}

	public function test_bootstrap_always_includes_root_plugin_instructions(): void {
		$content = "# Project instructions\n\nUse tabs and preserve public hooks.\n";
		file_put_contents( $this->root . '/AGENTS.md', $content );

		$instructions = $this->tools->plugin_instructions();
		$this->assertSame( 'AGENTS.md', $instructions['path'] );
		$this->assertSame( $content, $instructions['content'] );
		$this->assertSame( strlen( $content ), $instructions['bytes'] );
		$this->assertSame( hash( 'sha256', $content ), $instructions['content_hash'] );

		$bootstrap = $this->tools->bootstrap();
		$this->assertStringContainsString( 'root_plugin_instructions', $bootstrap['content'] );
		$this->assertStringContainsString( 'Use tabs and preserve public hooks.', $bootstrap['content'] );
		$this->assertSame( $instructions['content_hash'], $bootstrap['inspected']['AGENTS.md'] );
		$this->assertSame( $instructions['content_hash'], $bootstrap['audit']['agent_instructions']['content_hash'] );
		$this->assertArrayNotHasKey( 'content', $bootstrap['audit']['agent_instructions'] );
	}

	public function test_nested_agents_file_is_not_treated_as_root_plugin_instructions(): void {
		file_put_contents( $this->root . '/includes/AGENTS.md', "Nested instructions must not be loaded automatically.\n" );

		$this->assertNull( $this->tools->plugin_instructions() );
		$this->assertStringNotContainsString( 'Nested instructions must not be loaded automatically.', $this->tools->bootstrap()['content'] );
	}

	public function test_rejects_oversized_root_plugin_instructions_instead_of_truncating_them(): void {
		file_put_contents( $this->root . '/AGENTS.md', str_repeat( 'a', 65537 ) );

		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( '64 KiB' );
		$this->tools->plugin_instructions();
	}

	public function test_rejects_invalid_root_plugin_instruction_text(): void {
		file_put_contents( $this->root . '/AGENTS.md', "Invalid \xFF text.\n" );

		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'valid UTF-8' );
		$this->tools->plugin_instructions();
	}

	public function test_rejects_symlinked_root_plugin_instructions(): void {
		if ( ! function_exists( 'symlink' ) || ! symlink( $this->root . '/plugin.php', $this->root . '/AGENTS.md' ) ) {
			$this->markTestSkipped( 'Symlinks are unavailable in this environment.' );
		}

		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'safe regular file' );
		$this->tools->plugin_instructions();
	}

	public function test_code_snapshot_reads_existing_actions_and_rejects_add_collisions(): void {
		$snapshot = $this->tools->code_snapshot(
			[
				[ 'path' => 'plugin.php', 'type' => 'php', 'operation' => 'update' ],
				[ 'path' => 'includes/class-fixture.php', 'type' => 'php', 'operation' => 'delete' ],
				[ 'path' => 'assets/new.css', 'type' => 'css', 'operation' => 'add' ],
			]
		);

		$this->assertFalse( is_wp_error( $snapshot ) );
		$this->assertCount( 2, $snapshot['source_files'] );
		$this->assertArrayHasKey( 'plugin.php', $snapshot['base_hashes'] );
		$this->assertArrayNotHasKey( 'assets/new.css', $snapshot['base_hashes'] );
		$this->assertMatchesRegularExpression( '/^[a-f0-9]{64}$/', $snapshot['target_fingerprint'] );

		$collision = $this->tools->code_snapshot( [ [ 'path' => 'plugin.php', 'type' => 'php', 'operation' => 'add' ] ] );
		$this->assertWPError( $collision );
		$this->assertSame( 'code_target_add_exists', $collision->get_error_code() );

		$large_path = $this->root . '/oversized.css';
		file_put_contents( $large_path, str_repeat( 'a', 65537 ) );
		$large_collision = $this->tools->code_snapshot( [ [ 'path' => 'oversized.css', 'type' => 'css', 'operation' => 'add' ] ] );
		unlink( $large_path );
		$this->assertWPError( $large_collision );
		$this->assertSame( 'code_target_add_exists', $large_collision->get_error_code() );
	}

	public function test_revision_tree_and_file_expose_bounded_source_without_bodies_in_manifest(): void {
		$tree = $this->tools->revision_tree();

		$this->assertSame( [ 'includes' ], $tree['directories'] );
		$this->assertSame( [ 'includes/class-fixture.php', 'plugin.php' ], array_column( $tree['files'], 'path' ) );
		$this->assertArrayNotHasKey( 'content', $tree['files'][0] );
		$this->assertMatchesRegularExpression( '/^[a-f0-9]{64}$/', $tree['tree_fingerprint'] );

		$file = $this->tools->revision_file( 'plugin.php' );
		$this->assertFalse( is_wp_error( $file ) );
		$this->assertSame( 'php', $file['type'] );
		$this->assertStringContainsString( 'Plugin Name: Fixture', $file['content'] );
		$this->assertSame( hash( 'sha256', $file['content'] ), $file['content_hash'] );

		$this->assertWPError( $this->tools->revision_file( '../wp-config.php' ) );
	}

	public function test_code_follow_up_tree_is_bounded_and_excludes_source_bodies(): void {
		$tree = $this->tools->code_follow_up_tree();

		$this->assertSame( 2, $tree['total'] );
		$this->assertFalse( $tree['truncated'] );
		$this->assertSame( [ 'includes/class-fixture.php', 'plugin.php' ], array_column( $tree['files'], 'path' ) );
		$this->assertArrayNotHasKey( 'content', $tree['files'][0] );
		$this->assertMatchesRegularExpression( '/^[a-f0-9]{64}$/', $tree['tree_fingerprint'] );
	}

	public function test_child_theme_can_inspect_parent_without_adding_parent_files_to_editable_tree(): void {
		$this->configure_parent_theme();

		$metadata = json_decode( $this->tools->execute( 'get_target_metadata', [] )['content'], true );
		$this->assertTrue( $metadata['is_child'] );
		$this->assertSame( 'Parent Fixture', $metadata['parent_theme']['name'] );
		$this->assertSame( 'parent-fixture', $metadata['parent_theme']['ref'] );

		$list = $this->tools->execute( 'list_files', [ 'source' => 'parent_theme' ] );
		$decoded = json_decode( $list['content'], true );
		$this->assertFalse( $list['error'] );
		$this->assertSame( 'parent_theme', $decoded['source'] );
		$this->assertSame( [ 'functions.php', 'inc/parent-hooks.php', 'style.css' ], array_column( $decoded['files'], 'path' ) );

		$read = $this->tools->execute( 'read_file', [ 'source' => 'parent_theme', 'path' => 'functions.php' ] );
		$this->assertStringContainsString( 'parent_theme:functions.php', $read['content'] );
		$this->assertStringContainsString( 'parent-hooks.php', $read['content'] );
		$this->assertArrayHasKey( 'parent_theme:functions.php', $read['inspected'] );
		$this->assertSame( 'parent_theme', $read['audit']['source'] );

		$child_read = $this->tools->execute( 'read_file', [ 'path' => 'functions.php' ] );
		$this->assertStringContainsString( 'Child source with the same relative path', $child_read['content'] );
		$this->assertStringNotContainsString( 'parent-hooks.php', $child_read['content'] );
		$this->assertArrayHasKey( 'functions.php', $child_read['inspected'] );

		$search = $this->tools->execute( 'search_code', [ 'source' => 'parent_theme', 'query' => 'parent_fixture_ready' ] );
		$decoded = json_decode( $search['content'], true );
		$this->assertSame( 'parent_theme', $decoded['hits'][0]['source'] );
		$this->assertSame( 'inc/parent-hooks.php', $decoded['hits'][0]['path'] );

		$hooks = $this->tools->execute( 'list_hooks', [ 'source' => 'parent_theme' ] );
		$decoded = json_decode( $hooks['content'], true );
		$this->assertSame( 'parent_theme', $decoded['hooks'][0]['source'] );
		$this->assertSame( 'parent_fixture_ready', $decoded['hooks'][0]['name'] );

		$editable = $this->tools->revision_tree();
		$this->assertSame( [ 'functions.php', 'includes/class-fixture.php', 'plugin.php' ], array_column( $editable['files'], 'path' ) );

		$bootstrap = $this->tools->bootstrap();
		$this->assertStringContainsString( 'Read-only parent theme source structure', $bootstrap['content'] );
		$this->assertStringContainsString( '"parent_theme"', $bootstrap['content'] );
		$this->assertStringContainsString( 'Parent Fixture', $bootstrap['content'] );
		$this->assertSame( 'functions.php', $bootstrap['audit']['parent_theme']['main_file'] );
	}

	public function test_parent_source_participates_only_in_inspection_consistency(): void {
		$this->configure_parent_theme();

		$target_fingerprint     = $this->tools->tree_fingerprint();
		$inspection_fingerprint = $this->tools->inspection_fingerprint();
		$parent_read            = $this->tools->execute( 'read_file', [ 'source' => 'parent_theme', 'path' => 'functions.php' ] );

		file_put_contents( $this->parent_root . '/functions.php', "<?php\n// Parent changed.\n" );

		$this->assertSame( $target_fingerprint, $this->tools->tree_fingerprint() );
		$this->assertNotSame( $inspection_fingerprint, $this->tools->inspection_fingerprint() );
		$this->assertFalse( $this->tools->inspected_unchanged( $parent_read['inspected'] ) );
	}

	public function test_parent_source_scope_is_rejected_for_non_child_targets(): void {
		$result = $this->tools->execute( 'read_file', [ 'source' => 'parent_theme', 'path' => 'plugin.php' ] );

		$this->assertTrue( $result['error'] );
		$this->assertSame( [], $result['inspected'] );
		$this->assertStringContainsString( 'does not have an available parent theme', $result['content'] );
	}

	public function test_scanner_and_source_tools_expose_installed_parent_theme_metadata_and_source(): void {
		if ( ! is_writable( get_theme_root() ) ) {
			$this->markTestSkipped( 'The WordPress theme root is not writable in this test environment.' );
		}
		$parent_slug = 'wp-autoplugin-parent-' . strtolower( wp_generate_password( 8, false, false ) );
		$child_slug  = 'wp-autoplugin-child-' . strtolower( wp_generate_password( 8, false, false ) );
		$parent_root = $this->install_theme(
			$parent_slug,
			"/*\nTheme Name: Parent Scanner Fixture\nVersion: 3.2.1\n*/\n",
			[ 'functions.php' => "<?php\ndo_action( 'scanner_parent_hook' );\n" ]
		);
		$this->install_theme(
			$child_slug,
			"/*\nTheme Name: Child Scanner Fixture\nTemplate: {$parent_slug}\n*/\n",
			[ 'functions.php' => "<?php\n// Child source.\n" ]
		);

		$target = ( new Target_Scanner() )->find( 'theme', $child_slug );
		$this->assertIsArray( $target );
		$this->assertTrue( $target['is_child'] );
		$this->assertSame( $parent_slug, $target['parent_theme']['ref'] );
		$this->assertSame( 'Parent Scanner Fixture', $target['parent_theme']['name'] );
		$this->assertSame( '3.2.1', $target['parent_theme']['version'] );
		$this->assertGreaterThanOrEqual( 2, $target['parent_theme']['source_files'] );

		$tools = new Source_Tools( $target );
		$this->assertTrue( $tools->has_parent_theme() );
		$read = $tools->execute( 'read_file', [ 'source' => 'parent_theme', 'path' => 'functions.php' ] );
		$this->assertFalse( $read['error'] );
		$this->assertStringContainsString( 'scanner_parent_hook', $read['content'] );
		$this->assertStringNotContainsString( $parent_root, $read['content'] );
	}

	private function configure_parent_theme(): void {
		$this->parent_root = sys_get_temp_dir() . '/wp-autoplugin-parent-' . wp_generate_password( 8, false );
		mkdir( $this->parent_root . '/inc', 0777, true );
		file_put_contents( $this->parent_root . '/style.css', "/*\nTheme Name: Parent Fixture\n*/\n" );
		file_put_contents( $this->parent_root . '/functions.php', "<?php\nrequire __DIR__ . '/inc/parent-hooks.php';\n" );
		file_put_contents( $this->parent_root . '/inc/parent-hooks.php', "<?php\ndo_action( 'parent_fixture_ready' );\n" );
		file_put_contents( $this->root . '/functions.php', "<?php\n// Child source with the same relative path.\n" );

		$target = [
			'kind'             => 'theme',
			'ref'              => 'child-fixture',
			'name'             => 'Child Fixture',
			'stylesheet'       => 'child-fixture',
			'template'         => 'parent-fixture',
			'is_child'         => true,
			'parent_ref'       => 'parent-fixture',
			'parent_available' => true,
			'parent_theme'     => [
				'kind'         => 'theme',
				'ref'          => 'parent-fixture',
				'name'         => 'Parent Fixture',
				'version'      => '2.0.0',
				'source_files' => 3,
				'lines'        => 8,
				'tokens'       => 30,
				'hooks'        => 1,
			],
		];
		$reflection = new ReflectionClass( Source_Tools::class );
		foreach (
			[
				'target'           => $target,
				'parent_target'    => $target['parent_theme'],
				'parent_root'      => $this->parent_root,
				'parent_main_file' => 'functions.php',
			] as $property => $value
		) {
			$field = $reflection->getProperty( $property );
			$field->setValue( $this->tools, $value );
		}
	}

	/** @param array<string, string> $files */
	private function install_theme( string $slug, string $stylesheet, array $files ): string {
		$root = wp_normalize_path( trailingslashit( get_theme_root() ) . $slug );
		$this->assertDirectoryDoesNotExist( $root );
		$this->assertTrue( wp_mkdir_p( $root ) );
		$this->theme_directories[] = $root;
		file_put_contents( $root . '/style.css', $stylesheet );
		file_put_contents( $root . '/index.php', "<?php\n" );
		foreach ( $files as $relative => $content ) {
			$path = $root . '/' . $relative;
			$this->assertTrue( wp_mkdir_p( dirname( $path ) ) );
			file_put_contents( $path, $content );
		}
		wp_clean_themes_cache( true );
		return $root;
	}

	private function remove_tree( string $directory ): void {
		if ( ! is_dir( $directory ) ) {
			return;
		}
		$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $directory, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
		foreach ( $iterator as $item ) {
			$item->isDir() ? rmdir( $item->getPathname() ) : unlink( $item->getPathname() );
		}
		rmdir( $directory );
	}
}
