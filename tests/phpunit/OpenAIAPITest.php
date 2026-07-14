<?php

use WP_Autoplugin\OpenAI_API;

/** Focused request-shape coverage for the legacy OpenAI transport. */
final class OpenAIAPITest extends WP_UnitTestCase {
	public function test_gpt_5_4_mini_uses_completion_tokens_without_temperature(): void {
		$api = new Testable_OpenAI_API();
		$api->set_model( 'gpt-5.4-mini' );

		$body = $api->request_body();
		$this->assertSame( 'gpt-5.4-mini', $body['model'] );
		$this->assertSame( 128000, $body['max_completion_tokens'] );
		$this->assertSame( 'medium', $body['reasoning_effort'] );
		$this->assertArrayNotHasKey( 'max_tokens', $body );
		$this->assertArrayNotHasKey( 'temperature', $body );
	}

	public function test_legacy_chat_model_keeps_max_tokens_and_temperature(): void {
		$api = new Testable_OpenAI_API();
		$api->set_model( 'gpt-4.1' );

		$body = $api->request_body();
		$this->assertSame( 32768, $body['max_tokens'] );
		$this->assertSame( 0.2, $body['temperature'] );
		$this->assertArrayNotHasKey( 'max_completion_tokens', $body );
	}

	public function test_legacy_override_is_normalized_for_gpt_5_family(): void {
		$api = new Testable_OpenAI_API();
		$api->set_model( 'gpt-5.4-mini' );

		$body = $api->request_body( [ 'max_tokens' => 2048, 'temperature' => 0.7 ] );
		$this->assertSame( 2048, $body['max_completion_tokens'] );
		$this->assertArrayNotHasKey( 'max_tokens', $body );
		$this->assertArrayNotHasKey( 'temperature', $body );
	}
}

/** Exposes protected request construction for unit tests. */
final class Testable_OpenAI_API extends OpenAI_API {
	public function request_body( array $overrides = [] ): array {
		return $this->build_request_body( [ [ 'role' => 'user', 'content' => 'Test' ] ], $overrides );
	}
}
