<?php

use WP_Autoplugin\V2\Domain\AI\Code_Follow_Up_Response;

/** Focused parsing coverage for Code question and change classification. */
final class CodeFollowUpResponseTest extends WP_UnitTestCase {
	private array $base;

	protected function setUp(): void {
		parent::setUp();
		$this->base = [
			'plugin_name' => 'Example',
			'main_file'   => 'example.php',
			'files'       => [
				[ 'path' => 'assets/app.js', 'type' => 'js', 'description' => 'Behavior.' ],
				[ 'path' => 'assets/style.css', 'type' => 'css', 'description' => 'Styles.' ],
				[ 'path' => 'example.php', 'type' => 'php', 'description' => 'Bootstrap.' ],
			],
		];
	}

	public function test_parses_markdown_answer_without_a_manifest(): void {
		$result = ( new Code_Follow_Up_Response() )->parse( '{"outcome":"answer","content":"The setting is registered in `example.php`."}', $this->base );

		$this->assertFalse( is_wp_error( $result ) );
		$this->assertSame( 'answer', $result['outcome'] );
	}

	public function test_change_requires_resolved_request_and_acceptance_criteria(): void {
		$response = [
			'outcome'  => 'changes',
			'content'  => 'Update the behavior.',
			'manifest' => $this->base,
			'changes'  => [ [ 'path' => 'assets/app.js', 'instruction' => 'Update the behavior.' ] ],
		];

		$this->assertWPError( ( new Code_Follow_Up_Response() )->parse( wp_json_encode( $response ), $this->base ) );
		$response['resolved_request']    = 'Update the requested behavior.';
		$response['acceptance_criteria'] = [];
		$this->assertWPError( ( new Code_Follow_Up_Response() )->parse( wp_json_encode( $response ), $this->base ) );
	}

	public function test_normalizes_add_update_delete_and_main_file_last(): void {
		$response = wp_json_encode(
			[
				'outcome'             => 'changes',
				'content'             => 'Replace the stylesheet with an admin script.',
				'resolved_request'    => 'Replace the stylesheet with an admin script.',
				'acceptance_criteria' => [ 'The plugin uses the new admin script instead of the old stylesheet.' ],
				'manifest'            => [
					'plugin_name' => 'Example',
					'main_file'   => 'example.php',
					'files'       => [
						[ 'path' => 'assets/app.js', 'type' => 'js', 'description' => 'Behavior.' ],
						[ 'path' => 'assets/admin.js', 'type' => 'js', 'description' => 'Admin behavior.' ],
						[ 'path' => 'example.php', 'type' => 'php', 'description' => 'Bootstrap.' ],
					],
				],
				'changes'             => [
					[ 'path' => 'assets/app.js', 'instruction' => 'Update the existing behavior.' ],
					[ 'path' => 'assets/admin.js', 'instruction' => 'Create the admin behavior.' ],
					[ 'path' => 'example.php', 'instruction' => 'Enqueue the admin script.' ],
				],
			]
		);
		$result = ( new Code_Follow_Up_Response() )->parse( $response, $this->base );

		$this->assertFalse( is_wp_error( $result ) );
		$this->assertSame( [ 'assets/admin.js' ], $result['change_set']['added_paths'] );
		$this->assertSame( [ 'assets/style.css' ], $result['change_set']['deleted_paths'] );
		$this->assertSame( [ 'assets/app.js', 'example.php' ], $result['change_set']['updated_paths'] );
		$this->assertSame( 'Replace the stylesheet with an admin script.', $result['resolved_request'] );
		$this->assertCount( 1, $result['acceptance_criteria'] );
		$this->assertSame( 'example.php', $result['files'][ count( $result['files'] ) - 1 ]['path'] );
	}

	public function test_rejects_new_file_without_instruction_and_noop_change(): void {
		$missing = [
			'outcome'  => 'changes',
			'content'  => 'Add a file.',
			'manifest' => $this->base,
			'changes'  => [],
		];
		$missing['manifest']['files'][] = [ 'path' => 'assets/new.js', 'type' => 'js', 'description' => 'New.' ];

		$this->assertWPError( ( new Code_Follow_Up_Response() )->parse( wp_json_encode( $missing ), $this->base ) );
		$this->assertWPError(
			( new Code_Follow_Up_Response() )->parse(
				wp_json_encode( [ 'outcome' => 'changes', 'content' => 'No changes.', 'manifest' => $this->base, 'changes' => [] ] ),
				$this->base
			)
		);
	}

	public function test_adds_supported_non_code_files_to_a_complete_project(): void {
		$response = [
			'outcome'             => 'changes',
			'content'             => 'Add project documentation.',
			'resolved_request'    => 'Add the requested project documentation files.',
			'acceptance_criteria' => [ 'The project includes metadata, a template, a README, and release notes.' ],
			'manifest'            => $this->base,
			'changes'             => [
				[ 'path' => 'block.json', 'instruction' => 'Define the block metadata.' ],
				[ 'path' => 'templates/notice.html', 'instruction' => 'Create the notice fragment.' ],
				[ 'path' => 'README.md', 'instruction' => 'Document installation and usage.' ],
				[ 'path' => 'notes.txt', 'instruction' => 'Add the release notes.' ],
			],
		];
		$response['manifest']['files'][] = [ 'path' => 'block.json', 'type' => 'json', 'description' => 'Block metadata.' ];
		$response['manifest']['files'][] = [ 'path' => 'templates/notice.html', 'type' => 'html', 'description' => 'Notice fragment.' ];
		$response['manifest']['files'][] = [ 'path' => 'README.md', 'type' => 'md', 'description' => 'Usage documentation.' ];
		$response['manifest']['files'][] = [ 'path' => 'notes.txt', 'type' => 'txt', 'description' => 'Release notes.' ];

		$result = ( new Code_Follow_Up_Response() )->parse( wp_json_encode( $response ), $this->base );

		$this->assertFalse( is_wp_error( $result ) );
		$this->assertSame( [ 'block.json', 'templates/notice.html', 'README.md', 'notes.txt' ], $result['change_set']['added_paths'] );
		$this->assertSame( [ 'json', 'html', 'md', 'txt' ], array_column( $result['files'], 'type' ) );
	}

	public function test_main_file_role_change_requires_both_retained_php_files(): void {
		$target = [
			'plugin_name' => 'Example',
			'main_file'   => 'new-main.php',
			'files'       => [
				[ 'path' => 'example.php', 'type' => 'php', 'description' => 'Supporting bootstrap.' ],
				[ 'path' => 'new-main.php', 'type' => 'php', 'description' => 'New main.' ],
			],
		];
		$response = [
			'outcome'             => 'changes',
			'content'             => 'Move the plugin header.',
			'resolved_request'    => 'Move the plugin header to the new main file.',
			'acceptance_criteria' => [ 'Only the new main file contains the plugin header.' ],
			'manifest'            => $target,
			'changes'             => [ [ 'path' => 'new-main.php', 'instruction' => 'Create the new main file.' ] ],
		];

		$this->assertWPError( ( new Code_Follow_Up_Response() )->parse( wp_json_encode( $response ), $this->base ) );
	}

	public function test_preserves_extension_identity_for_complete_project_changes(): void {
		$base = array_merge( $this->base, [ 'scope' => 'project', 'artifact_kind' => 'plugin', 'operation' => 'hook_extension' ] );
		$response = [
			'outcome'             => 'changes',
			'content'             => 'Update the extension behavior.',
			'resolved_request'    => 'Update the extension hook callback behavior.',
			'acceptance_criteria' => [ 'The staged callback implements the requested behavior.' ],
			'manifest'            => $this->base,
			'changes'             => [ [ 'path' => 'assets/app.js', 'instruction' => 'Update the hook callback behavior.' ] ],
		];

		$result = ( new Code_Follow_Up_Response() )->parse( wp_json_encode( $response ), $base );

		$this->assertFalse( is_wp_error( $result ) );
		$this->assertSame( 'hook_extension', $result['manifest']['operation'] );
		$this->assertSame( 'project', $result['manifest']['scope'] );
	}

	public function test_change_set_omission_unstages_and_explicit_delete_remains_a_target_action(): void {
		$base = [
			'scope'              => 'changes',
			'artifact_kind'      => 'theme',
			'operation'          => 'modify',
			'plugin_name'        => 'Fixture Theme',
			'main_file'          => '',
			'target_ref'         => 'fixture-theme',
			'target_fingerprint' => str_repeat( 'a', 64 ),
			'base_hashes'        => [ 'functions.php' => str_repeat( 'b', 64 ), 'assets/old.css' => str_repeat( 'c', 64 ) ],
			'files'              => [
				[ 'path' => 'functions.php', 'type' => 'php', 'description' => 'Current update.', 'operation' => 'update' ],
				[ 'path' => 'assets/old.css', 'type' => 'css', 'description' => 'Current deletion.', 'operation' => 'delete' ],
			],
		];
		$response = [
			'outcome'             => 'changes',
			'content'             => 'Keep the PHP update, unstage the old deletion, and replace a script.',
			'resolved_request'    => 'Keep the PHP update, unstage the old deletion, and replace the script.',
			'acceptance_criteria' => [ 'The desired staged change set contains only the requested actions.' ],
			'manifest'            => [
				'files' => [
					[ 'path' => 'functions.php', 'type' => 'php', 'description' => 'Refine behavior.', 'operation' => 'update' ],
					[ 'path' => 'assets/new.js', 'type' => 'js', 'description' => 'New behavior.', 'operation' => 'add' ],
					[ 'path' => 'assets/obsolete.js', 'type' => 'js', 'description' => 'Remove obsolete behavior.', 'operation' => 'delete' ],
				],
			],
			'changes'             => [
				[ 'path' => 'functions.php', 'instruction' => 'Refine only the staged callback.' ],
				[ 'path' => 'assets/new.js', 'instruction' => 'Create the replacement behavior.' ],
			],
		];

		$result = ( new Code_Follow_Up_Response() )->parse( wp_json_encode( $response ), $base );

		$this->assertFalse( is_wp_error( $result ) );
		$this->assertSame( [ 'assets/new.js' ], $result['change_set']['added_paths'] );
		$this->assertSame( [ 'functions.php' ], $result['change_set']['updated_paths'] );
		$this->assertSame( [ 'assets/obsolete.js' ], $result['change_set']['deleted_paths'] );
		$this->assertSame( [ 'update', 'add' ], array_column( $result['files'], 'operation' ) );
		$this->assertNotContains( 'assets/old.css', array_column( $result['manifest']['files'], 'path' ) );
	}

	public function test_change_set_rejects_generation_for_delete_and_identical_successor(): void {
		$base = [
			'scope'         => 'changes',
			'artifact_kind' => 'theme',
			'operation'     => 'fix',
			'plugin_name'   => 'Fixture Theme',
			'main_file'     => '',
			'target_ref'    => 'fixture-theme',
			'files'         => [ [ 'path' => 'functions.php', 'type' => 'php', 'description' => 'Fix.', 'operation' => 'update' ] ],
		];
		$identical = [ 'outcome' => 'changes', 'content' => 'No change.', 'manifest' => [ 'files' => $base['files'] ], 'changes' => [] ];
		$delete = [
			'outcome'  => 'changes',
			'content'  => 'Delete it.',
			'manifest' => [ 'files' => [ [ 'path' => 'functions.php', 'type' => 'php', 'description' => 'Delete.', 'operation' => 'delete' ] ] ],
			'changes'  => [ [ 'path' => 'functions.php', 'instruction' => 'Return empty content.' ] ],
		];

		$this->assertWPError( ( new Code_Follow_Up_Response() )->parse( wp_json_encode( $identical ), $base ) );
		$this->assertWPError( ( new Code_Follow_Up_Response() )->parse( wp_json_encode( $delete ), $base ) );
	}

	public function test_change_set_allows_supported_non_code_generation(): void {
		$base = [
			'scope'         => 'changes',
			'artifact_kind' => 'theme',
			'operation'     => 'modify',
			'plugin_name'   => 'Fixture Theme',
			'main_file'     => '',
			'target_ref'    => 'fixture-theme',
			'files'         => [
				[ 'path' => 'README.md', 'type' => 'md', 'description' => 'Current documentation.', 'operation' => 'update' ],
			],
		];
		$response = [
			'outcome'             => 'changes',
			'content'             => 'Update the documentation and add release notes.',
			'resolved_request'    => 'Update the documentation and add the requested non-code files.',
			'acceptance_criteria' => [ 'All requested documentation and metadata files are staged.' ],
			'manifest'            => [
				'files' => [
					[ 'path' => 'README.md', 'type' => 'md', 'description' => 'Updated documentation.', 'operation' => 'update' ],
					[ 'path' => 'notes.txt', 'type' => 'txt', 'description' => 'Release notes.', 'operation' => 'add' ],
					[ 'path' => 'block.json', 'type' => 'json', 'description' => 'Block metadata.', 'operation' => 'add' ],
					[ 'path' => 'templates/notice.html', 'type' => 'html', 'description' => 'Notice fragment.', 'operation' => 'add' ],
				],
			],
			'changes'             => [
				[ 'path' => 'README.md', 'instruction' => 'Document the new behavior.' ],
				[ 'path' => 'notes.txt', 'instruction' => 'Summarize the release.' ],
				[ 'path' => 'block.json', 'instruction' => 'Define the block metadata.' ],
				[ 'path' => 'templates/notice.html', 'instruction' => 'Create the notice fragment.' ],
			],
		];

		$result = ( new Code_Follow_Up_Response() )->parse( wp_json_encode( $response ), $base );

		$this->assertFalse( is_wp_error( $result ) );
		$this->assertSame( [ 'md', 'txt', 'json', 'html' ], array_column( $result['files'], 'type' ) );
	}
}
