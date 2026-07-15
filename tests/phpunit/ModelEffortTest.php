<?php

use WP_Autoplugin\V2\Domain\AI\Model_Effort;

/** Focused coverage for v2 model-effort capability and fallback rules. */
final class ModelEffortTest extends WP_UnitTestCase {
	public function test_normalizes_supported_effort_and_uses_model_default_for_invalid_values(): void {
		$this->assertSame( 'xhigh', Model_Effort::normalize( 'gpt-5.5', 'xhigh' ) );
		$this->assertSame( 'none', Model_Effort::normalize( 'gpt-5.4-mini', 'invalid' ) );
		$this->assertSame( 'high', Model_Effort::normalize( 'claude-opus-4-8', '' ) );
		$this->assertSame( '', Model_Effort::normalize( 'gpt-4.1', 'high' ) );
	}

	public function test_specialized_role_inherits_default_effort_when_it_uses_default_model(): void {
		update_option( 'wp_autoplugin_model', 'gpt-5.4' );
		update_option( 'wp_autoplugin_default_model_effort', 'high' );
		update_option( 'wp_autoplugin_planner_model', '' );
		update_option( 'wp_autoplugin_planner_model_effort', 'low' );

		$this->assertSame( 'gpt-5.4', Model_Effort::selected_model( 'planner' ) );
		$this->assertSame( 'high', Model_Effort::for_role( 'planner' ) );
	}

	public function test_specialized_model_uses_its_own_effort_and_capability_range(): void {
		update_option( 'wp_autoplugin_model', 'gpt-5.4' );
		update_option( 'wp_autoplugin_default_model_effort', 'none' );
		update_option( 'wp_autoplugin_reviewer_model', 'claude-opus-4-8' );
		update_option( 'wp_autoplugin_reviewer_model_effort', 'max' );

		$this->assertSame( 'max', Model_Effort::for_role( 'reviewer' ) );
	}
}
