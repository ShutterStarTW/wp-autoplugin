<?php

use WP_Autoplugin\V2\Domain\Revision\Version_Bumper;
use WP_Autoplugin\V2\Release\Package_Builder;

/** Deterministic package-boundary and version-transform coverage. */
final class ReleaseServicesTest extends WP_UnitTestCase {
	private string $directory;

	protected function setUp(): void {
		parent::setUp();
		$this->directory = wp_normalize_path( sys_get_temp_dir() . '/wp-autoplugin-release-test-' . wp_generate_password( 16, false, false ) );
		wp_mkdir_p( $this->directory );
	}

	protected function tearDown(): void {
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

	public function test_tree_rejects_symbolic_links(): void {
		file_put_contents( $this->directory . '/source.php', '<?php' );
		if ( ! function_exists( 'symlink' ) || ! @symlink( $this->directory . '/source.php', $this->directory . '/linked.php' ) ) {
			$this->markTestSkipped( 'Symbolic links are unavailable in this test environment.' );
		}

		$this->assertWPError( ( new Package_Builder() )->scan_tree( $this->directory ) );
	}

	public function test_version_bump_changes_only_the_semantic_patch_header(): void {
		$source = "<?php\n/**\n * Plugin Name: Fixture\n * Version: 1.2.3-beta+build\n * Update URI: https://example.test/plugin\n */\nconst FIXTURE_VERSION = '1.2.3-beta+build';\n";
		$result = ( new Version_Bumper() )->bump( $source );

		$this->assertStringContainsString( 'Version: 1.2.4', $result );
		$this->assertStringContainsString( "FIXTURE_VERSION = '1.2.3-beta+build'", $result );
		$this->assertStringContainsString( 'Update URI: https://example.test/plugin', $result );
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
