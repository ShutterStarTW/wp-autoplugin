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

	public function test_normalizes_add_update_delete_and_main_file_last(): void {
		$response = wp_json_encode(
			[
				'outcome'  => 'changes',
				'content'  => 'Replace the stylesheet with an admin script.',
				'manifest' => [
					'plugin_name' => 'Example',
					'main_file'   => 'example.php',
					'files'       => [
						[ 'path' => 'assets/app.js', 'type' => 'js', 'description' => 'Behavior.' ],
						[ 'path' => 'assets/admin.js', 'type' => 'js', 'description' => 'Admin behavior.' ],
						[ 'path' => 'example.php', 'type' => 'php', 'description' => 'Bootstrap.' ],
					],
				],
				'changes'  => [
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
			'outcome'  => 'changes',
			'content'  => 'Move the plugin header.',
			'manifest' => $target,
			'changes'  => [ [ 'path' => 'new-main.php', 'instruction' => 'Create the new main file.' ] ],
		];

		$this->assertWPError( ( new Code_Follow_Up_Response() )->parse( wp_json_encode( $response ), $this->base ) );
	}
}
