<?php

use WP_Autoplugin\V2\Domain\AI\Plan_Response;

/** Focused validation coverage for terminal native Plan responses. */
final class PlanResponseTest extends WP_UnitTestCase {
	public function test_parses_initial_plan_artifact(): void {
		$response = wp_json_encode(
			[
				'outcome'    => 'artifact',
				'content'    => "# Plan\n\nUpdate the existing service.",
				'structured' => [
					'project_structure' => [
						'directories' => [ 'includes' ],
						'files'       => [
							[
								'path'        => 'includes/class-service.php',
								'type'        => 'php',
								'description' => 'Update the service behavior.',
								'action'      => 'update',
							],
						],
					],
				],
			]
		);

		$result = ( new Plan_Response() )->parse( (string) $response );
		$this->assertFalse( is_wp_error( $result ) );
		$this->assertSame( 'artifact', $result['outcome'] );
		$this->assertSame( [ 'includes/' ], $result['structured']['project_structure']['directories'] );
		$this->assertArrayNotHasKey( 'artifact', $result );
	}

	public function test_parses_follow_up_answer_without_replacing_artifact(): void {
		$result = ( new Plan_Response() )->parse( '{"outcome":"answer","content":"The current Plan already covers that."}', true, 42 );
		$this->assertSame( [ 'content' => 'The current Plan already covers that.', 'outcome' => 'answer' ], $result );
	}

	public function test_rejects_unsafe_or_duplicate_file_paths(): void {
		$response = wp_json_encode(
			[
				'outcome'    => 'artifact',
				'content'    => '# Unsafe plan',
				'structured' => [
					'project_structure' => [
						'directories' => [],
						'files'       => [
							[ 'path' => '../wp-config.php', 'type' => 'php', 'description' => 'Unsafe.', 'action' => 'update' ],
						],
					],
				],
			]
		);

		$this->assertWPError( ( new Plan_Response() )->parse( (string) $response ) );

		$reserved = json_decode( (string) $response, true );
		$reserved['structured']['project_structure']['files'][0]['path'] = 'parent_theme:functions.php';
		$this->assertWPError( ( new Plan_Response() )->parse( (string) wp_json_encode( $reserved ) ) );
	}

	public function test_initial_plan_cannot_finish_as_answer(): void {
		$this->assertWPError( ( new Plan_Response() )->parse( '{"outcome":"answer","content":"Need more detail."}' ) );
	}

	public function test_parses_new_plugin_plan_with_add_only_files(): void {
		$response = wp_json_encode(
			[
				'outcome'    => 'artifact',
				'content'    => "# Example Plugin\n\nCreate the requested behavior.",
				'structured' => [
					'plugin_name'       => 'Example Plugin',
					'main_file'         => 'example-plugin.php',
					'project_structure' => [
						'directories' => [ 'includes' ],
						'files'       => [
							[ 'path' => 'example-plugin.php', 'type' => 'php', 'description' => 'Bootstrap the plugin.', 'action' => 'add' ],
						],
					],
				],
			]
		);

		$result = ( new Plan_Response() )->parse( (string) $response, false, 0, 'create' );
		$this->assertFalse( is_wp_error( $result ) );
		$this->assertSame( 'Example Plugin', $result['structured']['plugin_name'] );
		$this->assertSame( 'example-plugin.php', $result['structured']['main_file'] );
		$this->assertSame( 'add', $result['structured']['project_structure']['files'][0]['action'] );
	}

	public function test_parses_supported_non_code_plan_files(): void {
		$response = wp_json_encode(
			[
				'outcome'    => 'artifact',
				'content'    => "# Documented Plugin\n\nCreate the plugin and its documentation.",
				'structured' => [
					'plugin_name'       => 'Documented Plugin',
					'main_file'         => 'documented-plugin.php',
					'project_structure' => [
						'directories' => [ 'docs' ],
						'files'       => [
							[ 'path' => 'documented-plugin.php', 'type' => 'php', 'description' => 'Bootstrap the plugin.', 'action' => 'add' ],
							[ 'path' => 'block.json', 'type' => 'json', 'description' => 'Define block metadata.', 'action' => 'add' ],
							[ 'path' => 'templates/notice.html', 'type' => 'html', 'description' => 'Render a notice fragment.', 'action' => 'add' ],
							[ 'path' => 'assets/icon.svg', 'type' => 'svg', 'description' => 'Provide the requested icon.', 'action' => 'add' ],
							[ 'path' => 'config/integration.xml', 'type' => 'xml', 'description' => 'Provide integration metadata.', 'action' => 'add' ],
							[ 'path' => 'README.md', 'type' => 'md', 'description' => 'Document installation and usage.', 'action' => 'add' ],
							[ 'path' => 'docs/license-notice.txt', 'type' => 'txt', 'description' => 'Describe bundled notices.', 'action' => 'add' ],
						],
					],
				],
			]
		);

		$result = ( new Plan_Response() )->parse( (string) $response, false, 0, 'create' );

		$this->assertFalse( is_wp_error( $result ) );
		$this->assertSame( [ 'php', 'json', 'html', 'svg', 'xml', 'md', 'txt' ], array_column( $result['structured']['project_structure']['files'], 'type' ) );
	}

	public function test_rejects_new_plugin_plan_that_updates_a_file(): void {
		$response = wp_json_encode(
			[
				'outcome'    => 'artifact',
				'content'    => '# Invalid new plugin',
				'structured' => [
					'plugin_name'       => 'Invalid Plugin',
					'main_file'         => 'existing.php',
					'project_structure' => [
						'directories' => [],
						'files'       => [
							[ 'path' => 'existing.php', 'type' => 'php', 'description' => 'Invalid update.', 'action' => 'update' ],
						],
					],
				],
			]
		);

		$this->assertWPError( ( new Plan_Response() )->parse( (string) $response, false, 0, 'create' ) );
	}

	public function test_rejects_new_plugin_plan_without_a_php_file(): void {
		$response = wp_json_encode(
			[
				'outcome'    => 'artifact',
				'content'    => '# Invalid new plugin',
				'structured' => [
					'plugin_name'       => 'Invalid Plugin',
					'main_file'         => 'plugin.php',
					'project_structure' => [
						'directories' => [],
						'files'       => [
							[ 'path' => 'assets/style.css', 'type' => 'css', 'description' => 'Styles only.', 'action' => 'add' ],
						],
					],
				],
			]
		);

		$this->assertWPError( ( new Plan_Response() )->parse( (string) $response, false, 0, 'create' ) );
	}

	public function test_rejects_new_plugin_plan_without_explicit_main_file(): void {
		$response = wp_json_encode(
			[
				'outcome'    => 'artifact',
				'content'    => '# Missing main file',
				'structured' => [
					'plugin_name'       => 'Missing Main File',
					'project_structure' => [
						'directories' => [],
						'files'       => [
							[ 'path' => 'missing-main.php', 'type' => 'php', 'description' => 'Bootstrap.', 'action' => 'add' ],
						],
					],
				],
			]
		);

		$this->assertWPError( ( new Plan_Response() )->parse( (string) $response, false, 0, 'create' ) );
	}

	public function test_parses_feasible_hook_extension_as_separate_plugin(): void {
		$response = wp_json_encode(
			[
				'outcome'    => 'artifact',
				'content'    => '# Fixture Companion\n\nUse the verified fixture hook.',
				'structured' => [
					'technically_feasible' => true,
					'plugin_name'           => 'Fixture Companion',
					'hooks'                 => [ 'fixture_before_answer' ],
					'project_structure'     => [
						'directories' => [ 'includes' ],
						'files'       => [
							[ 'path' => 'fixture-companion.php', 'type' => 'php', 'description' => 'Bootstrap the extension.', 'action' => 'add' ],
						],
					],
				],
			]
		);

		$result = ( new Plan_Response() )->parse( (string) $response, false, 0, 'hook_extension' );
		$this->assertFalse( is_wp_error( $result ) );
		$this->assertTrue( $result['structured']['technically_feasible'] );
		$this->assertSame( 'Fixture Companion', $result['structured']['plugin_name'] );
		$this->assertSame( 'fixture-companion.php', $result['structured']['main_file'] );
		$this->assertSame( 'add', $result['structured']['project_structure']['files'][0]['action'] );
	}

	public function test_unwraps_redundant_extension_plugin_root_and_identifies_main_file(): void {
		$response = wp_json_encode(
			[
				'outcome'    => 'artifact',
				'content'    => '# Wrapped extension Plan',
				'structured' => [
					'technically_feasible' => true,
					'plugin_name'           => 'Fixture Companion',
					'hooks'                 => [ 'init' ],
					'project_structure'     => [
						'directories' => [ 'fixture-companion', 'fixture-companion/includes' ],
						'files'       => [
							[ 'path' => 'fixture-companion/fixture-companion.php', 'type' => 'php', 'description' => 'Bootstrap the extension.', 'action' => 'add' ],
							[ 'path' => 'fixture-companion/uninstall.php', 'type' => 'php', 'description' => 'Clean up data.', 'action' => 'add' ],
							[ 'path' => 'fixture-companion/includes/class-feature.php', 'type' => 'php', 'description' => 'Implement the feature.', 'action' => 'add' ],
						],
					],
				],
			]
		);

		$result = ( new Plan_Response() )->parse( (string) $response, false, 0, 'hook_extension' );

		$this->assertFalse( is_wp_error( $result ) );
		$this->assertSame( 'fixture-companion.php', $result['structured']['main_file'] );
		$this->assertSame( [ 'includes/' ], $result['structured']['project_structure']['directories'] );
		$this->assertSame(
			[ 'fixture-companion.php', 'uninstall.php', 'includes/class-feature.php' ],
			array_column( $result['structured']['project_structure']['files'], 'path' )
		);
	}

	public function test_parses_infeasible_hook_extension_with_empty_file_map(): void {
		$response = wp_json_encode(
			[
				'outcome'    => 'artifact',
				'content'    => '# Not technically feasible\n\nThe required interception point is unavailable.',
				'structured' => [
					'technically_feasible' => false,
					'plugin_name'           => '',
					'hooks'                 => [],
					'project_structure'     => [ 'directories' => [], 'files' => [] ],
				],
			]
		);

		$result = ( new Plan_Response() )->parse( (string) $response, false, 0, 'hook_extension' );
		$this->assertFalse( is_wp_error( $result ) );
		$this->assertFalse( $result['structured']['technically_feasible'] );
		$this->assertSame( [], $result['structured']['project_structure']['files'] );
	}

	public function test_rejects_hook_extension_that_modifies_target_files(): void {
		$response = wp_json_encode(
			[
				'outcome'    => 'artifact',
				'content'    => '# Invalid extension',
				'structured' => [
					'technically_feasible' => true,
					'plugin_name'           => 'Invalid Companion',
					'hooks'                 => [ 'init' ],
					'project_structure'     => [
						'directories' => [],
						'files'       => [
							[ 'path' => 'target.php', 'type' => 'php', 'description' => 'Modify the target.', 'action' => 'update' ],
						],
					],
				],
			]
		);

		$this->assertWPError( ( new Plan_Response() )->parse( (string) $response, false, 0, 'hook_extension' ) );
	}
}
