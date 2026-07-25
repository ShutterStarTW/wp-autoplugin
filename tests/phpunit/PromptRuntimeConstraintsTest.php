<?php

use WP_Autoplugin\V2\Domain\AI\Prompts\Existing_Target_Code_Prompt;
use WP_Autoplugin\V2\Domain\AI\Prompts\Existing_Target_Code_Follow_Up_Prompt;
use WP_Autoplugin\V2\Domain\AI\Prompts\Extension_Plugin_Code_Prompt;
use WP_Autoplugin\V2\Domain\AI\Prompts\Extension_Plugin_Code_Follow_Up_Prompt;
use WP_Autoplugin\V2\Domain\AI\Prompts\New_Plugin_Code_Follow_Up_Prompt;
use WP_Autoplugin\V2\Domain\AI\Prompts\New_Plugin_Code_Prompt;
use WP_Autoplugin\V2\Domain\AI\Prompts\New_Plugin_Plan_Prompt;
use WP_Autoplugin\V2\Domain\AI\Prompts\Review_Prompt;
use WP_Autoplugin\V2\Domain\AI\Prompts\WordPress_Runtime_Constraints;
use WP_Autoplugin\V2\Domain\Target\Plugin_Instructions;
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

	public function test_complete_plugin_code_prompts_require_wp_autoplugin_author(): void {
		$prompts = [
			( new New_Plugin_Code_Prompt() )->instructions(),
			( new Extension_Plugin_Code_Prompt() )->instructions(),
			( new New_Plugin_Code_Follow_Up_Prompt() )->file_instructions(),
			( new Extension_Plugin_Code_Follow_Up_Prompt() )->file_instructions(),
		];

		foreach ( $prompts as $prompt ) {
			$this->assertStringContainsString( 'Author: WP-Autoplugin', $prompt );
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
			( new Existing_Target_Code_Follow_Up_Prompt() )->analysis_instructions(),
			( new Existing_Target_Code_Follow_Up_Prompt() )->file_instructions( 'add' ),
			( new Extension_Plugin_Code_Follow_Up_Prompt() )->analysis_instructions(),
			( new Extension_Plugin_Code_Follow_Up_Prompt() )->file_instructions(),
			( new Review_Prompt() )->instructions( false, false ),
		];

		foreach ( $prompts as $prompt ) {
			$this->assertStringContainsString( Plugin_Instructions::prompt_policy(), $prompt );
		}
	}
}
