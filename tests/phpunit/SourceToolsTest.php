<?php

use WP_Autoplugin\V2\Domain\Target\Source_Tools;

/** Focused WordPress test-suite coverage for bounded agent source tools. */
final class SourceToolsTest extends WP_UnitTestCase {
	private string $root;
	private Source_Tools $tools;

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
		if ( is_link( $this->root . '/linked.php' ) ) {
			unlink( $this->root . '/linked.php' );
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
}
