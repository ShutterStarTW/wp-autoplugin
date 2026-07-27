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
						[ 'path' => 'block.json', 'type' => 'json', 'action' => 'add', 'description' => 'Block metadata.' ],
						[ 'path' => 'templates/notice.html', 'type' => 'html', 'action' => 'add', 'description' => 'Notice template.' ],
						[ 'path' => 'README.md', 'type' => 'md', 'action' => 'add', 'description' => 'Usage documentation.' ],
						[ 'path' => 'notes.txt', 'type' => 'txt', 'action' => 'add', 'description' => 'Release notes.' ],
					],
				],
			]
		);

		$this->assertFalse( is_wp_error( $plan ) );
		$this->assertSame( 'example.php', $plan['main_file'] );
		$this->assertSame( [ 'assets/style.css', 'block.json', 'templates/notice.html', 'README.md', 'notes.txt', 'example.php' ], array_column( $plan['files'], 'path' ) );
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

	public function test_generates_markdown_and_text_files_with_literal_fenced_examples(): void {
		$manifest = [
			'plugin_name' => 'Documented Plugin',
			'main_file'   => 'documented-plugin.php',
			'files'       => [
				[ 'path' => 'README.md', 'type' => 'md', 'description' => 'Usage documentation.' ],
				[ 'path' => 'notes.txt', 'type' => 'txt', 'description' => 'Release notes.' ],
				[ 'path' => 'documented-plugin.php', 'type' => 'php', 'description' => 'Bootstrap.' ],
			],
		];
		$markdown = "# Usage\n\n```php\nadd_action( 'init', 'example' );\n```\n";
		$text     = "Plain text notes.\n";

		$markdown_result = $this->validator->response(
			(string) wp_json_encode( [ 'path' => 'README.md', 'content' => $markdown ] ),
			[ 'path' => 'README.md', 'type' => 'md', 'operation' => 'add' ],
			$manifest
		);
		$text_result = $this->validator->response(
			(string) wp_json_encode( [ 'path' => 'notes.txt', 'content' => $text ] ),
			[ 'path' => 'notes.txt', 'type' => 'txt', 'operation' => 'add' ],
			$manifest
		);

		$this->assertFalse( is_wp_error( $markdown_result ) );
		$this->assertSame( $markdown, $markdown_result['content'] );
		$this->assertFalse( is_wp_error( $text_result ) );
		$this->assertSame( $text, $text_result['content'] );
	}

	public function test_generates_valid_json_and_html_and_rejects_invalid_json(): void {
		$manifest = [
			'plugin_name' => 'Block Plugin',
			'main_file'   => 'block-plugin.php',
			'files'       => [
				[ 'path' => 'block.json', 'type' => 'json', 'description' => 'Block metadata.' ],
				[ 'path' => 'templates/notice.html', 'type' => 'html', 'description' => 'Notice fragment.' ],
				[ 'path' => 'block-plugin.php', 'type' => 'php', 'description' => 'Bootstrap.' ],
			],
		];
		$json = "{\n\t\"apiVersion\": 3,\n\t\"name\": \"example/block\"\n}\n";
		$html = "<section class=\"notice\"><strong>Ready</strong></section>\n";

		$json_result = $this->validator->response(
			(string) wp_json_encode( [ 'path' => 'block.json', 'content' => $json ] ),
			[ 'path' => 'block.json', 'type' => 'json', 'operation' => 'add' ],
			$manifest
		);
		$html_result = $this->validator->response(
			(string) wp_json_encode( [ 'path' => 'templates/notice.html', 'content' => $html ] ),
			[ 'path' => 'templates/notice.html', 'type' => 'html', 'operation' => 'add' ],
			$manifest
		);
		$invalid_json = $this->validator->response(
			(string) wp_json_encode( [ 'path' => 'block.json', 'content' => '{"apiVersion":}' ] ),
			[ 'path' => 'block.json', 'type' => 'json', 'operation' => 'add' ],
			$manifest
		);

		$this->assertFalse( is_wp_error( $json_result ) );
		$this->assertSame( $json, $json_result['content'] );
		$this->assertFalse( is_wp_error( $html_result ) );
		$this->assertSame( $html, $html_result['content'] );
		$this->assertWPError( $invalid_json );
		$this->assertSame( 'json_syntax', $invalid_json->get_error_data()['issues'][0]['code'] );
	}

	public function test_update_response_applies_only_exact_targeted_replacements(): void {
		$original = "<?php\nfunction fixture_value() {\n\treturn 1;\n}\n";
		$expected = [ 'path' => 'functions.php', 'type' => 'php', 'operation' => 'update' ];
		$manifest = [ 'scope' => 'changes', 'artifact_kind' => 'theme', 'main_file' => '' ];
		$response = wp_json_encode(
			[
				'path'         => 'functions.php',
				'replacements' => [
					[ 'search' => "\treturn 1;", 'replace' => "\treturn 2;" ],
				],
			]
		);

		$result = $this->validator->update_response( (string) $response, $expected, $manifest, $original );

		$this->assertFalse( is_wp_error( $result ) );
		$this->assertSame( "<?php\nfunction fixture_value() {\n\treturn 2;\n}\n", $result['content'] );
		$this->assertSame( 1, $result['replacements'] );
	}

	public function test_update_response_rejects_whole_file_and_ambiguous_searches(): void {
		$original = "<?php\nreturn 1;\nreturn 1;\n";
		$expected = [ 'path' => 'functions.php', 'type' => 'php', 'operation' => 'update' ];
		$manifest = [ 'scope' => 'changes', 'artifact_kind' => 'theme', 'main_file' => '' ];
		$whole = wp_json_encode(
			[
				'path'         => 'functions.php',
				'replacements' => [ [ 'search' => $original, 'replace' => "<?php\nreturn 2;\n" ] ],
			]
		);
		$ambiguous = wp_json_encode(
			[
				'path'         => 'functions.php',
				'replacements' => [ [ 'search' => 'return 1;', 'replace' => 'return 2;' ] ],
			]
		);

		$whole_result = $this->validator->update_response( (string) $whole, $expected, $manifest, $original );
		$ambiguous_result = $this->validator->update_response( (string) $ambiguous, $expected, $manifest, $original );

		$this->assertWPError( $whole_result );
		$this->assertSame( 'whole_file_replace', $whole_result->get_error_data()['issues'][0]['code'] );
		$this->assertWPError( $ambiguous_result );
		$this->assertSame( 'search_match_count', $ambiguous_result->get_error_data()['issues'][0]['code'] );
	}

	public function test_update_response_preserves_valid_json(): void {
		$original = "{\n\t\"enabled\": false,\n\t\"label\": \"Example\"\n}\n";
		$expected = [ 'path' => 'settings.json', 'type' => 'json', 'operation' => 'update' ];
		$manifest = [ 'scope' => 'changes', 'artifact_kind' => 'theme', 'main_file' => '' ];
		$valid = wp_json_encode(
			[
				'path'         => 'settings.json',
				'replacements' => [ [ 'search' => '"enabled": false', 'replace' => '"enabled": true' ] ],
			]
		);
		$invalid = wp_json_encode(
			[
				'path'         => 'settings.json',
				'replacements' => [ [ 'search' => '"enabled": false', 'replace' => '"enabled": }' ] ],
			]
		);

		$valid_result   = $this->validator->update_response( (string) $valid, $expected, $manifest, $original );
		$invalid_result = $this->validator->update_response( (string) $invalid, $expected, $manifest, $original );

		$this->assertFalse( is_wp_error( $valid_result ) );
		$this->assertStringContainsString( '"enabled": true', $valid_result['content'] );
		$this->assertWPError( $invalid_result );
		$this->assertSame( 'json_syntax', $invalid_result->get_error_data()['issues'][0]['code'] );
	}

	public function test_updates_markdown_with_a_fenced_example(): void {
		$original = "# Usage\n\nOld example.\n";
		$response = wp_json_encode(
			[
				'path'         => 'README.md',
				'replacements' => [
					[ 'search' => 'Old example.', 'replace' => "```php\nexample();\n```" ],
				],
			]
		);
		$result = $this->validator->update_response(
			(string) $response,
			[ 'path' => 'README.md', 'type' => 'md', 'operation' => 'update' ],
			[ 'scope' => 'changes', 'artifact_kind' => 'theme', 'main_file' => '' ],
			$original
		);

		$this->assertFalse( is_wp_error( $result ) );
		$this->assertStringContainsString( "```php\nexample();\n```", $result['content'] );
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

	public function test_manual_target_edit_allows_a_bounded_large_text_file(): void {
		$manifest = [
			'scope'         => 'changes',
			'artifact_kind' => 'theme',
			'main_file'     => '',
			'files'         => [
				[ 'path' => 'notes.txt', 'type' => 'txt', 'operation' => 'update' ],
			],
		];
		$files = [
			[ 'path' => 'notes.txt', 'type' => 'txt', 'change_type' => 'update', 'content' => str_repeat( 'a', Code_Validator::MAX_FILE_BYTES + 1 ) ],
		];

		$generated_codes = array_column( $this->validator->project_issues( $files, $manifest ), 'code' );
		$manual_codes = array_column( $this->validator->project_issues( $files, $manifest, Code_Validator::MAX_MANUAL_FILE_BYTES ), 'code' );

		$this->assertContains( 'file_too_large', $generated_codes );
		$this->assertNotContains( 'file_too_large', $manual_codes );
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

	public function test_existing_theme_plan_rejects_parent_source_namespace_as_an_edit_path(): void {
		$plan = $this->validator->plan(
			[
				'project_structure' => [
					'files' => [
						[ 'path' => 'parent_theme:functions.php', 'type' => 'php', 'action' => 'update', 'description' => 'Invalid parent edit.' ],
					],
				],
			],
			[
				'operation'       => 'modify',
				'target_kind'     => 'theme',
				'target_ref'      => 'fixture-child',
				'target_metadata' => [ 'kind' => 'theme', 'ref' => 'fixture-child', 'name' => 'Fixture Child', 'is_child' => true ],
			]
		);

		$this->assertWPError( $plan );
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

	public function test_existing_theme_plan_cannot_delete_root_stylesheet(): void {
		$plan = $this->validator->plan(
			[
				'project_structure' => [
					'files' => [
						[ 'path' => 'style.css', 'type' => 'css', 'action' => 'delete', 'description' => 'Remove the stylesheet.' ],
					],
				],
			],
			[
				'operation'       => 'modify',
				'target_kind'     => 'theme',
				'target_ref'      => 'fixture-theme',
				'target_metadata' => [ 'kind' => 'theme', 'ref' => 'fixture-theme', 'name' => 'Fixture Theme' ],
			]
		);

		$this->assertWPError( $plan );
		$this->assertSame( 'code_change_theme_stylesheet_delete', $plan->get_error_code() );
	}
}
