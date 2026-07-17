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
}
