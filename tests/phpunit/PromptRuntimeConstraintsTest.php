<?php

use WP_Autoplugin\V2\Domain\AI\Prompts\Existing_Target_Code_Prompt;
use WP_Autoplugin\V2\Domain\AI\Prompts\Existing_Target_Code_Follow_Up_Prompt;
use WP_Autoplugin\V2\Domain\AI\Prompts\Code_Follow_Up_Compliance_Prompt;
use WP_Autoplugin\V2\Domain\AI\Prompts\Extension_Plugin_Code_Prompt;
use WP_Autoplugin\V2\Domain\AI\Prompts\Extension_Plugin_Code_Follow_Up_Prompt;
use WP_Autoplugin\V2\Domain\AI\Prompts\New_Plugin_Code_Follow_Up_Prompt;
use WP_Autoplugin\V2\Domain\AI\Prompts\New_Plugin_Code_Prompt;
use WP_Autoplugin\V2\Domain\AI\Prompts\New_Plugin_Plan_Prompt;
use WP_Autoplugin\V2\Domain\AI\Prompts\Review_Prompt;
use WP_Autoplugin\V2\Domain\AI\Prompts\WordPress_Runtime_Constraints;
use WP_Autoplugin\V2\Domain\Target\Plugin_Instructions;
use WP_Autoplugin\V2\Orchestration\Code_Follow_Up_Orchestrator;
use WP_Autoplugin\V2\Orchestration\Source_Agent;

/** Ensures all active v2 Plan and Code prompts share the deployment constraints. */
final class PromptRuntimeConstraintsTest extends WP_UnitTestCase {
	public function test_runtime_constraints_describe_shared_hosting_limitations(): void {
		$constraints = WordPress_Runtime_Constraints::instructions();

		$this->assertStringContainsString( 'commonly shared hosting', $constraints );
		$this->assertStringContainsString( 'later coding step', $constraints );
		$this->assertStringContainsString( 'React/JSX/TypeScript', $constraints );
		$this->assertStringContainsString( 'Composer install or update', $constraints );
		$this->assertStringContainsString( 'vendor SDKs', $constraints );
		$this->assertStringContainsString( 'WordPress HTTP API', $constraints );
		$this->assertStringContainsString( 'CLI-only', $constraints );
		$this->assertStringContainsString( 'SVG', $constraints );
		$this->assertStringContainsString( 'XML', $constraints );
		$this->assertStringContainsString( 'Keep every file map minimal', $constraints );
		$this->assertStringContainsString( 'include a supporting file only when the administrator explicitly requests it or the required implementation genuinely needs it', $constraints );
		$this->assertStringContainsString( 'never add optional files by default', $constraints );
	}

	public function test_versioned_plan_and_code_prompts_include_runtime_constraints(): void {
		$plan                = new New_Plugin_Plan_Prompt();
		$code                = new New_Plugin_Code_Prompt();
		$extension           = new Extension_Plugin_Code_Prompt();
		$existing            = new Existing_Target_Code_Prompt();
		$follow_up           = new New_Plugin_Code_Follow_Up_Prompt();
		$extension_follow_up = new Extension_Plugin_Code_Follow_Up_Prompt();
		$target_follow_up    = new Existing_Target_Code_Follow_Up_Prompt();
		$prompts             = [
			$plan->initial_instructions(),
			$plan->follow_up_instructions(),
			$plan->structure_instructions(),
			$code->instructions(),
			$extension->instructions(),
			$existing->instructions( 'add' ),
			$existing->instructions( 'update' ),
			$follow_up->analysis_instructions(),
			$follow_up->file_instructions(),
			$extension_follow_up->analysis_instructions(),
			$extension_follow_up->file_instructions(),
			$target_follow_up->analysis_instructions(),
			$target_follow_up->file_instructions( 'add' ),
			$target_follow_up->file_instructions( 'update' ),
		];

		foreach ( $prompts as $prompt ) {
			$this->assertStringContainsString( WordPress_Runtime_Constraints::instructions(), $prompt );
		}
	}

	public function test_complete_plugin_code_prompts_make_wp_autoplugin_author_an_overridable_fallback(): void {
		$prompts = [
			( new New_Plugin_Code_Prompt() )->instructions(),
			( new Extension_Plugin_Code_Prompt() )->instructions(),
			( new New_Plugin_Code_Follow_Up_Prompt() )->file_instructions(),
			( new Extension_Plugin_Code_Follow_Up_Prompt() )->file_instructions(),
		];

		foreach ( $prompts as $prompt ) {
			$this->assertStringContainsString( 'Author: WP-Autoplugin', $prompt );
			$this->assertStringContainsString( 'fallback Author value', $prompt );
			$this->assertStringContainsString( 'site-wide instructions specify different plugin metadata', $prompt );
			$this->assertStringNotContainsString( 'exact header `Author: WP-Autoplugin`', $prompt );
		}
	}

	public function test_plan_and_code_prompts_allow_supported_non_code_files(): void {
		$prompts = [
			( new New_Plugin_Plan_Prompt() )->initial_instructions(),
			( new New_Plugin_Code_Prompt() )->instructions(),
			( new Extension_Plugin_Code_Prompt() )->instructions(),
			( new Existing_Target_Code_Prompt() )->instructions( 'add' ),
			( new New_Plugin_Code_Follow_Up_Prompt() )->analysis_instructions(),
			( new Extension_Plugin_Code_Follow_Up_Prompt() )->analysis_instructions(),
			( new Existing_Target_Code_Follow_Up_Prompt() )->analysis_instructions(),
		];

		foreach ( $prompts as $prompt ) {
			$this->assertStringContainsString( 'JSON', $prompt );
			$this->assertStringContainsString( 'HTML', $prompt );
			$this->assertStringContainsString( 'SVG', $prompt );
			$this->assertStringContainsString( 'XML', $prompt );
			$this->assertStringContainsString( 'Markdown', $prompt );
			$this->assertStringContainsString( 'plain', $prompt );
		}
	}

	public function test_source_aware_planners_include_runtime_constraints(): void {
		$method = new ReflectionMethod( Source_Agent::class, 'instructions' );
		$method->setAccessible( true );
		$run = [ 'tool_calls' => 0, 'source_bytes' => 0 ];

		foreach ( [ 'modify', 'hook_extension' ] as $operation ) {
			$prompt = $method->invoke( new Source_Agent(), $run, 'plan', false, $operation, false );
			$this->assertStringContainsString( WordPress_Runtime_Constraints::instructions(), $prompt );
		}
	}

	public function test_source_aware_prompts_apply_root_plugin_instructions_policy(): void {
		$method = new ReflectionMethod( Source_Agent::class, 'instructions' );
		$method->setAccessible( true );
		$run     = [ 'tool_calls' => 0, 'source_bytes' => 0 ];
		$prompts = [
			$method->invoke( new Source_Agent(), $run, 'explain', false, 'modify', false ),
			$method->invoke( new Source_Agent(), $run, 'plan', false, 'modify', false ),
			( new Existing_Target_Code_Prompt() )->instructions( 'update' ),
			( new Extension_Plugin_Code_Prompt() )->instructions(),
			( new Review_Prompt() )->instructions( false, false ),
		];

		foreach ( $prompts as $prompt ) {
			$this->assertStringContainsString( Plugin_Instructions::prompt_policy(), $prompt );
		}

		$follow_ups = [
			( new Existing_Target_Code_Follow_Up_Prompt() )->analysis_instructions(),
			( new Existing_Target_Code_Follow_Up_Prompt() )->file_instructions( 'add' ),
			( new Extension_Plugin_Code_Follow_Up_Prompt() )->analysis_instructions(),
			( new Extension_Plugin_Code_Follow_Up_Prompt() )->file_instructions(),
			( new Code_Follow_Up_Compliance_Prompt() )->instructions(),
		];
		foreach ( $follow_ups as $prompt ) {
			$this->assertStringContainsString( Plugin_Instructions::prompt_policy( true ), $prompt );
			$this->assertStringNotContainsString( 'approved-Plan', $prompt );
		}
	}

	public function test_code_follow_up_prompts_make_the_latest_request_authoritative(): void {
		$prompts = [
			( new New_Plugin_Code_Follow_Up_Prompt() )->analysis_instructions(),
			( new Extension_Plugin_Code_Follow_Up_Prompt() )->analysis_instructions(),
			( new Existing_Target_Code_Follow_Up_Prompt() )->analysis_instructions(),
			( new Code_Follow_Up_Compliance_Prompt() )->instructions(),
		];

		foreach ( $prompts as $prompt ) {
			$this->assertStringContainsString( "newest message is the authoritative request", $prompt );
			$this->assertStringContainsString( 'reference Plan', $prompt );
			$this->assertStringContainsString( '"change it"', $prompt );
			$this->assertStringContainsString( 'Never ignore the requested change', $prompt );
		}
	}

	public function test_complete_project_follow_ups_require_explicit_delete_actions(): void {
		$prompts = [
			( new New_Plugin_Code_Follow_Up_Prompt() )->analysis_instructions(),
			( new Extension_Plugin_Code_Follow_Up_Prompt() )->analysis_instructions(),
		];

		foreach ( $prompts as $prompt ) {
			$this->assertStringContainsString( 'Omitting an existing', $prompt );
			$this->assertStringContainsString( 'never deletes it', $prompt );
			$this->assertStringContainsString( 'operation:"delete"', $prompt );
			$this->assertStringContainsString( 'unless the administrator', $prompt );
		}
	}

	public function test_plan_and_follow_up_contracts_leave_file_type_derivation_to_the_server(): void {
		$method = new ReflectionMethod( Source_Agent::class, 'instructions' );
		$method->setAccessible( true );
		$run     = [ 'tool_calls' => 0, 'source_bytes' => 0 ];
		$prompts = [
			( new New_Plugin_Plan_Prompt() )->initial_instructions(),
			( new New_Plugin_Plan_Prompt() )->follow_up_instructions(),
			( new New_Plugin_Plan_Prompt() )->structure_instructions(),
			$method->invoke( new Source_Agent(), $run, 'plan', false, 'modify', false ),
			$method->invoke( new Source_Agent(), $run, 'plan', false, 'hook_extension', false ),
			( new New_Plugin_Code_Follow_Up_Prompt() )->analysis_instructions(),
			( new Extension_Plugin_Code_Follow_Up_Prompt() )->analysis_instructions(),
			( new Existing_Target_Code_Follow_Up_Prompt() )->analysis_instructions(),
		];

		foreach ( $prompts as $prompt ) {
			$this->assertStringContainsString( 'server derives each file type from its path', $prompt );
			$this->assertStringContainsString( 'do not return a type field', $prompt );
			$this->assertStringNotContainsString( '"type":', $prompt );
		}
	}

	public function test_compliance_input_includes_parent_manifest_and_topology_diff(): void {
		$parent_manifest = [
			'main_file' => 'example.php',
			'files'     => [
				[ 'path' => 'assets/app.js', 'type' => 'js' ],
				[ 'path' => 'example.php', 'type' => 'php' ],
			],
		];
		$candidate_manifest = [
			'main_file' => 'example.php',
			'files'     => [ [ 'path' => 'example.php', 'type' => 'php' ] ],
		];
		$topology_diff = [
			'manifest_added_paths'   => [],
			'manifest_removed_paths' => [ 'assets/app.js' ],
			'action_changed_paths'   => [],
			'main_file_changed'      => false,
			'generated_paths'        => [ 'example.php' ],
		];
		$input = json_decode(
			( new Code_Follow_Up_Compliance_Prompt() )->input(
				'Add a button.',
				[],
				'Add the requested button.',
				[ 'The button is visible.' ],
				[],
				$parent_manifest,
				$candidate_manifest,
				$topology_diff,
				[ [ 'path' => 'example.php', 'type' => 'php', 'operation' => 'add', 'content' => '<?php' ] ]
			),
			true
		);

		$this->assertSame( $parent_manifest, $input['parent_manifest'] );
		$this->assertSame( $topology_diff, $input['topology_diff'] );
		$this->assertSame( $candidate_manifest, $input['candidate_manifest'] );
	}

	public function test_compliance_topology_diff_is_derived_from_parent_and_candidate_manifests(): void {
		$method = new ReflectionMethod( Code_Follow_Up_Orchestrator::class, 'topology_diff' );
		$method->setAccessible( true );
		$diff = $method->invoke(
			new Code_Follow_Up_Orchestrator(),
			[
				'main_file' => 'example.php',
				'files'     => [
					[ 'path' => 'assets/app.js', 'type' => 'js', 'operation' => 'add' ],
					[ 'path' => 'assets/style.css', 'type' => 'css', 'operation' => 'add' ],
					[ 'path' => 'example.php', 'type' => 'php', 'operation' => 'add' ],
				],
			],
			[
				'main_file' => 'new-main.php',
				'files'     => [
					[ 'path' => 'assets/app.js', 'type' => 'js', 'operation' => 'update' ],
					[ 'path' => 'example.php', 'type' => 'php', 'operation' => 'add' ],
					[ 'path' => 'new-main.php', 'type' => 'php', 'operation' => 'add' ],
				],
			],
			[ [ 'path' => 'new-main.php' ], [ 'path' => 'assets/app.js' ] ]
		);

		$this->assertSame( [ 'new-main.php' ], $diff['manifest_added_paths'] );
		$this->assertSame( [ 'assets/style.css' ], $diff['manifest_removed_paths'] );
		$this->assertSame( [ 'assets/app.js' ], $diff['action_changed_paths'] );
		$this->assertTrue( $diff['main_file_changed'] );
		$this->assertSame( [ 'new-main.php', 'assets/app.js' ], $diff['generated_paths'] );
	}

	public function test_extension_follow_up_preserves_conversation_references_without_approving_the_old_plan(): void {
		$history = [
			[
				'message' => 'Will this add the menu item under Superdraft?',
				'outcome' => 'answer',
				'content' => 'No. To nest it, use add_submenu_page() with Superdraft’s parent slug.',
			],
		];
		$prompt = new Extension_Plugin_Code_Follow_Up_Prompt();
		$input  = json_decode(
			$prompt->analysis_input( 'Create the extension.', 'Use add_menu_page().', [], [], [], $history, 'Please change it' ),
			true
		);

		$this->assertSame( 'Use add_menu_page().', $input['reference_plan'] );
		$this->assertArrayNotHasKey( 'approved_plan', $input );
		$this->assertSame( $history, $input['recent_code_conversation'] );
		$this->assertSame( 'Please change it', $input['authoritative_latest_request'] );
		$this->assertSame( 'authoritative_latest_request', array_key_last( $input ) );
	}

	public function test_file_generation_receives_the_resolved_request_without_the_old_plan(): void {
		$input = json_decode(
			( new Extension_Plugin_Code_Follow_Up_Prompt() )->file_input(
				'Please change it',
				[],
				'Nest the settings page under Superdraft.',
				[ 'Use add_submenu_page(), not add_menu_page().' ],
				[],
				[ [ 'path' => 'extension.php', 'content' => 'old' ] ],
				[ 'plugin_name' => 'Extension', 'main_file' => 'extension.php', 'files' => [ [ 'path' => 'extension.php', 'type' => 'php' ] ] ],
				[ [ 'path' => 'extension.php', 'content' => 'old' ] ],
				[ 'path' => 'extension.php', 'type' => 'php', 'operation' => 'update', 'description' => 'Use the submenu API.' ],
				[]
			),
			true
		);

		$this->assertSame( 'Nest the settings page under Superdraft.', $input['resolved_request'] );
		$this->assertArrayNotHasKey( 'reference_plan', $input );
		$this->assertArrayNotHasKey( 'approved_plan', $input );
		$this->assertArrayNotHasKey( 'original_workspace_request', $input );
		$this->assertSame( 'Please change it', $input['authoritative_latest_request'] );
	}
}
