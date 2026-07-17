<?php

use WP_Autoplugin\V2\Domain\Revision\Code_Validator;

/** Focused deterministic validation coverage for Code staging. */
final class CodeValidatorTest extends WP_UnitTestCase {
	private Code_Validator $validator;

	protected function setUp(): void {
		parent::setUp();
		$this->validator = new Code_Validator();
	}

	public function test_legacy_plan_infers_one_root_php_file_and_generates_it_last(): void {
		$plan = $this->validator->plan(
			[
				'plugin_name'       => 'Example',
				'project_structure' => [
					'files' => [
						[ 'path' => 'example.php', 'type' => 'php', 'action' => 'add', 'description' => 'Bootstrap.' ],
						[ 'path' => 'assets/style.css', 'type' => 'css', 'action' => 'add', 'description' => 'Styles.' ],
					],
				],
			]
		);

		$this->assertFalse( is_wp_error( $plan ) );
		$this->assertSame( 'example.php', $plan['main_file'] );
		$this->assertSame( [ 'assets/style.css', 'example.php' ], array_column( $plan['files'], 'path' ) );
	}

	public function test_legacy_plan_with_ambiguous_root_php_files_requires_regeneration(): void {
		$plan = $this->validator->plan(
			[
				'plugin_name'       => 'Ambiguous',
				'project_structure' => [
					'files' => [
						[ 'path' => 'one.php', 'type' => 'php', 'action' => 'add', 'description' => 'One.' ],
						[ 'path' => 'two.php', 'type' => 'php', 'action' => 'add', 'description' => 'Two.' ],
					],
				],
			]
		);

		$this->assertWPError( $plan );
		$this->assertSame( 'code_plan_main_file_missing', $plan->get_error_code() );
	}

	public function test_response_rejects_wrong_path_and_markdown_fence(): void {
		$expected = [ 'path' => 'example.php', 'type' => 'php' ];
		$this->assertWPError( $this->validator->response( '{"path":"other.php","content":"<?php"}', $expected, 'example.php' ) );
		$this->assertWPError( $this->validator->response( '```json {"path":"example.php","content":"<?php"} ```', $expected, 'example.php' ) );
	}

	public function test_project_rejects_php_syntax_and_invalid_plugin_headers(): void {
		$manifest = [
			'main_file' => 'example.php',
			'files'     => [
				[ 'path' => 'includes/helper.php', 'type' => 'php' ],
				[ 'path' => 'example.php', 'type' => 'php' ],
			],
		];
		$issues = $this->validator->project_issues(
			[
				[ 'path' => 'includes/helper.php', 'type' => 'php', 'change_type' => 'add', 'content' => "<?php\n/* Plugin Name: Wrong */\nfunction broken( {" ],
				[ 'path' => 'example.php', 'type' => 'php', 'change_type' => 'add', 'content' => "<?php\n// no header" ],
			],
			$manifest
		);

		$codes = array_column( $issues, 'code' );
		$this->assertContains( 'php_syntax', $codes );
		$this->assertContains( 'supporting_plugin_header', $codes );
		$this->assertContains( 'plugin_header', $codes );
	}

	public function test_project_rejects_incomplete_and_oversize_output(): void {
		$manifest = [
			'main_file' => 'example.php',
			'files'     => [
				[ 'path' => 'example.php', 'type' => 'php' ],
				[ 'path' => 'assets/app.js', 'type' => 'js' ],
			],
		];
		$issues = $this->validator->project_issues(
			[
				[ 'path' => 'example.php', 'type' => 'php', 'change_type' => 'add', 'content' => "<?php\n/* Plugin Name: Example */\n" . str_repeat( ' ', Code_Validator::MAX_FILE_BYTES ) ],
			],
			$manifest
		);

		$codes = array_column( $issues, 'code' );
		$this->assertContains( 'file_too_large', $codes );
		$this->assertContains( 'missing_file', $codes );
	}

	public function test_revision_manifest_rejects_duplicates_and_invalid_main_file(): void {
		$duplicate = $this->validator->manifest(
			[
				'plugin_name' => 'Example',
				'main_file'   => 'example.php',
				'files'       => [
					[ 'path' => 'example.php', 'type' => 'php', 'description' => 'Main.' ],
					[ 'path' => 'example.php', 'type' => 'php', 'description' => 'Duplicate.' ],
				],
			]
		);
		$nested_main = $this->validator->manifest(
			[
				'plugin_name' => 'Example',
				'main_file'   => 'includes/example.php',
				'files'       => [ [ 'path' => 'includes/example.php', 'type' => 'php', 'description' => 'Main.' ] ],
			]
		);

		$this->assertWPError( $duplicate );
		$this->assertWPError( $nested_main );
	}

	public function test_existing_theme_plan_preserves_add_update_and_delete_actions(): void {
		$plan = $this->validator->plan(
			[
				'project_structure' => [
					'files' => [
						[ 'path' => 'functions.php', 'type' => 'php', 'action' => 'update', 'description' => 'Update behavior.' ],
						[ 'path' => 'assets/new.css', 'type' => 'css', 'action' => 'add', 'description' => 'Add styles.' ],
						[ 'path' => 'assets/old.js', 'type' => 'js', 'action' => 'delete', 'description' => 'Remove obsolete script.' ],
					],
				],
			],
			[
				'operation'       => 'modify',
				'target_kind'     => 'theme',
				'target_ref'      => 'fixture-theme',
				'project_name'    => 'Fixture Theme',
				'target_metadata' => [ 'kind' => 'theme', 'ref' => 'fixture-theme', 'name' => 'Fixture Theme' ],
			]
		);

		$this->assertFalse( is_wp_error( $plan ) );
		$this->assertSame( 'changes', $plan['scope'] );
		$this->assertSame( 'theme', $plan['artifact_kind'] );
		$this->assertSame( [ 'update', 'add', 'delete' ], array_column( $plan['files'], 'operation' ) );
		$this->assertSame( '', $plan['main_file'] );
	}

	public function test_change_set_validates_update_and_delete_without_plugin_headers_for_theme(): void {
		$manifest = [
			'scope'         => 'changes',
			'artifact_kind' => 'theme',
			'operation'     => 'fix',
			'main_file'     => '',
			'files'         => [
				[ 'path' => 'functions.php', 'type' => 'php', 'description' => 'Fix behavior.', 'operation' => 'update' ],
				[ 'path' => 'assets/old.css', 'type' => 'css', 'description' => 'Remove styles.', 'operation' => 'delete' ],
			],
		];
		$issues = $this->validator->project_issues(
			[
				[ 'path' => 'functions.php', 'type' => 'php', 'change_type' => 'update', 'content' => "<?php\n/* Plugin Name: text in a theme is not treated as a plugin entry point */" ],
				[ 'path' => 'assets/old.css', 'type' => 'css', 'change_type' => 'delete', 'content' => '' ],
			],
			$manifest
		);

		$this->assertSame( [], $issues );
	}

	public function test_hook_extension_plan_infers_main_plugin_file(): void {
		$plan = $this->validator->plan(
			[
				'technically_feasible' => true,
				'plugin_name'           => 'Fixture Companion',
				'project_structure'     => [
					'files' => [
						[ 'path' => 'fixture-companion.php', 'type' => 'php', 'action' => 'add', 'description' => 'Bootstrap.' ],
					],
				],
			],
			[ 'operation' => 'hook_extension', 'target_kind' => 'plugin' ]
		);

		$this->assertFalse( is_wp_error( $plan ) );
		$this->assertSame( 'fixture-companion.php', $plan['main_file'] );
		$this->assertSame( 'hook_extension', $plan['operation'] );
	}

	public function test_hook_extension_plan_unwraps_redundant_plugin_root(): void {
		$plan = $this->validator->plan(
			[
				'technically_feasible' => true,
				'plugin_name'           => 'Fixture Companion',
				'project_structure'     => [
					'files' => [
						[ 'path' => 'fixture-companion/fixture-companion.php', 'type' => 'php', 'action' => 'add', 'description' => 'Bootstrap.' ],
						[ 'path' => 'fixture-companion/uninstall.php', 'type' => 'php', 'action' => 'add', 'description' => 'Cleanup.' ],
						[ 'path' => 'fixture-companion/includes/class-feature.php', 'type' => 'php', 'action' => 'add', 'description' => 'Feature.' ],
					],
				],
			],
			[ 'operation' => 'hook_extension', 'target_kind' => 'plugin' ]
		);

		$this->assertFalse( is_wp_error( $plan ) );
		$this->assertSame( 'fixture-companion.php', $plan['main_file'] );
		$this->assertSame(
			[ 'uninstall.php', 'includes/class-feature.php', 'fixture-companion.php' ],
			array_column( $plan['files'], 'path' )
		);
	}

	public function test_existing_plugin_plan_cannot_delete_main_file(): void {
		$plan = $this->validator->plan(
			[
				'project_structure' => [
					'files' => [
						[ 'path' => 'fixture.php', 'type' => 'php', 'action' => 'delete', 'description' => 'Remove entry point.' ],
					],
				],
			],
			[
				'operation'       => 'modify',
				'target_kind'     => 'plugin',
				'target_ref'      => 'fixture/fixture.php',
				'target_metadata' => [ 'kind' => 'plugin', 'ref' => 'fixture/fixture.php', 'name' => 'Fixture' ],
			]
		);

		$this->assertWPError( $plan );
		$this->assertSame( 'code_change_main_file_delete', $plan->get_error_code() );
	}
}
