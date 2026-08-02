<?php

use WP_Autoplugin\V2\Domain\AI\Capability_Matrix;
use WP_Autoplugin\V2\Domain\AI\Direct_Transport;
use WP_Autoplugin\V2\Infrastructure\AI\Anthropic_Agent_Transport;
use WP_Autoplugin\V2\Infrastructure\AI\Direct_Transport_Factory;
use WP_Autoplugin\V2\Infrastructure\AI\Google_Direct_Transport;
use WP_Autoplugin\V2\Infrastructure\AI\OpenAI_Agent_Transport;
use WP_Autoplugin\V2\Infrastructure\AI\OpenAI_Compatible_Direct_Transport;

/** Captures the provider-specific multimodal request contracts. */
final class MultimodalTransportTest extends WP_UnitTestCase {
	/** @var array<string, array<string, mixed>> */
	private array $requests = [];
	/** @var array<string, array<string, mixed>> */
	private array $request_arguments = [];
	private bool $echo_request_errors = false;

	public function set_up(): void {
		parent::set_up();
		add_filter( 'pre_http_request', [ $this, 'capture_request' ], 10, 3 );
	}

	public function tear_down(): void {
		remove_filter( 'pre_http_request', [ $this, 'capture_request' ], 10 );
		parent::tear_down();
	}

	public function test_openai_anthropic_gemini_and_xai_receive_their_native_image_shapes(): void {
		$image   = [ 'mime_type' => 'image/png', 'content' => 'private-image-bytes' ];
		$options = [ 'prompt_images' => [ $image ] ];

		$openai = ( new OpenAI_Agent_Transport( 'key', 'gpt-5.4-mini' ) )->complete( 'Instructions', '', $options );
		$anthropic = ( new Anthropic_Agent_Transport( 'key', 'claude-sonnet-4-6' ) )->complete( 'Instructions', '', $options );
		$gemini = ( new Google_Direct_Transport( 'key', 'gemini-2.5-pro' ) )->complete( 'Instructions', '', $options );
		$xai = ( new OpenAI_Compatible_Direct_Transport( 'xai', 'https://api.x.ai/v1/chat/completions', 'key', 'grok-4.3' ) )->complete( 'Instructions', '', $options );

		$this->assertFalse( is_wp_error( $openai ) );
		$this->assertFalse( is_wp_error( $anthropic ) );
		$this->assertFalse( is_wp_error( $gemini ) );
		$this->assertFalse( is_wp_error( $xai ) );
		$this->assertSame( 128000, $this->requests['https://api.openai.com/v1/responses']['max_output_tokens'] );
		$this->assertSame( 64000, $this->requests['https://api.anthropic.com/v1/messages']['max_tokens'] );
		foreach ( array_keys( $this->request_arguments ) as $url ) {
			$this->assertSame( 0, $this->request_arguments[ $url ]['redirection'] );
			$this->assertSame( Direct_Transport::MAX_RESPONSE_BYTES, $this->request_arguments[ $url ]['limit_response_size'] );
			$this->assertTrue( $this->request_arguments[ $url ]['reject_unsafe_urls'] );
		}

		$openai_image = $this->requests['https://api.openai.com/v1/responses']['input'][0]['content'][0];
		$this->assertSame( 'input_image', $openai_image['type'] );
		$this->assertStringStartsWith( 'data:image/png;base64,', $openai_image['image_url'] );

		$anthropic_image = $this->requests['https://api.anthropic.com/v1/messages']['messages'][0]['content'][0];
		$this->assertSame( 'image', $anthropic_image['type'] );
		$this->assertSame( 'base64', $anthropic_image['source']['type'] );
		$this->assertSame( 'image/png', $anthropic_image['source']['media_type'] );
		$this->assertSame( base64_encode( 'private-image-bytes' ), $anthropic_image['source']['data'] );

		$gemini_url   = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-pro:generateContent?key=key';
		$this->assertSame( 65536, $this->requests[ $gemini_url ]['generationConfig']['maxOutputTokens'] );
		$gemini_image = $this->requests[ $gemini_url ]['contents'][0]['parts'][0]['inline_data'];
		$this->assertSame( 'image/png', $gemini_image['mime_type'] );
		$this->assertSame( base64_encode( 'private-image-bytes' ), $gemini_image['data'] );

		$xai_image = $this->requests['https://api.x.ai/v1/chat/completions']['messages'][1]['content'][0];
		$this->assertArrayNotHasKey( 'max_tokens', $this->requests['https://api.x.ai/v1/chat/completions'] );
		$this->assertSame( 'image_url', $xai_image['type'] );
		$this->assertStringStartsWith( 'data:image/png;base64,', $xai_image['image_url']['url'] );
	}

	public function test_custom_endpoint_retains_its_explicit_output_budget(): void {
		$result = ( new OpenAI_Compatible_Direct_Transport( 'custom', 'https://8.8.8.8/v1/chat/completions', 'key', 'private-model' ) )->complete(
			'Instructions',
			'Request',
			[ 'max_output_tokens' => 32768 ]
		);

		$this->assertFalse( is_wp_error( $result ) );
		$this->assertSame( 32768, $this->requests['https://8.8.8.8/v1/chat/completions']['max_tokens'] );
	}

	public function test_custom_endpoint_transport_rejects_private_network_urls_before_requesting(): void {
		$result = ( new OpenAI_Compatible_Direct_Transport( 'custom', 'https://127.0.0.1/v1/chat/completions', 'key', 'private-model' ) )->complete(
			'Instructions',
			'Request'
		);

		$this->assertWPError( $result );
		$this->assertSame( 'direct_provider_endpoint', $result->get_error_code() );
		$this->assertArrayNotHasKey( 'https://127.0.0.1/v1/chat/completions', $this->requests );
	}

	public function test_custom_endpoints_require_an_explicit_capability_opt_in(): void {
		$matrix = new Capability_Matrix();
		$this->assertFalse( (bool) $matrix->for_model( 'custom', 'private-model' )['images'] );
		foreach ( [ 'openai' => 'gpt-5.4-mini', 'anthropic' => 'claude-sonnet-4-6', 'google' => 'gemini-2.5-pro', 'xai' => 'grok-4.3' ] as $provider => $model ) {
			$this->assertTrue( (bool) $matrix->for_model( $provider, $model )['images'] );
		}
		$this->assertFalse( (bool) $matrix->for_model( 'google', 'gemini-2.5-pro' )['native_read_tools'] );
		$this->assertTrue( (bool) $matrix->for_model( 'google', 'gemini-3.5-flash-lite' )['native_read_tools'] );

		$filter = static function ( array $capabilities, string $provider, string $model ): array {
			if ( 'custom' === $provider && 'private-model' === $model ) {
				$capabilities['images'] = true;
			}
			return $capabilities;
		};
		$previous_planner = get_option( 'wp_autoplugin_planner_model', null );
		$previous_custom  = get_option( 'wp_autoplugin_custom_models', null );
		add_filter( 'wp_autoplugin_v2_model_capabilities', $filter, 10, 3 );
		try {
			$this->assertTrue( (bool) $matrix->for_model( 'custom', 'private-model' )['images'] );
			update_option( 'wp_autoplugin_planner_model', 'private-model' );
			update_option(
				'wp_autoplugin_custom_models',
				[
					[
						'name'           => 'private-model',
						'modelParameter' => 'shared-remote-model',
						'url'            => 'https://8.8.8.8/v1/chat/completions',
						'apiKey'         => 'key',
					],
				]
			);
			$capability = ( new Direct_Transport_Factory() )->capability( 'plan' );
			$this->assertTrue( $capability['images'] );
			$this->assertSame( 'private-model', $capability['model'] );
		} finally {
			if ( null === $previous_planner ) {
				delete_option( 'wp_autoplugin_planner_model' );
			} else {
				update_option( 'wp_autoplugin_planner_model', $previous_planner );
			}
			if ( null === $previous_custom ) {
				delete_option( 'wp_autoplugin_custom_models' );
			} else {
				update_option( 'wp_autoplugin_custom_models', $previous_custom );
			}
			remove_filter( 'wp_autoplugin_v2_model_capabilities', $filter, 10 );
		}
	}

	public function test_latest_gemini_models_omit_deprecated_sampling_parameters(): void {
		foreach ( [ 'gemini-3.6-flash', 'gemini-3.5-flash-lite' ] as $model ) {
			$result = ( new Google_Direct_Transport( 'key', $model ) )->complete( 'Instructions', 'Request' );
			$this->assertFalse( is_wp_error( $result ) );

			$url = 'https://generativelanguage.googleapis.com/v1beta/models/' . $model . ':generateContent?key=key';
			$this->assertArrayNotHasKey( 'temperature', $this->requests[ $url ]['generationConfig'] );
		}

		$result = ( new Google_Direct_Transport( 'key', 'gemini-2.5-pro' ) )->complete( 'Instructions', 'Request' );
		$this->assertFalse( is_wp_error( $result ) );
		$url = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-pro:generateContent?key=key';
		$this->assertSame( 0.2, $this->requests[ $url ]['generationConfig']['temperature'] );
	}

	public function test_provider_errors_cannot_echo_private_image_content(): void {
		$this->echo_request_errors = true;
		$options = [ 'prompt_images' => [ [ 'mime_type' => 'image/png', 'content' => 'private-image-bytes' ] ] ];
		$responses = [
			( new OpenAI_Agent_Transport( 'key', 'gpt-5.4-mini' ) )->complete( 'Instructions', '', $options ),
			( new Anthropic_Agent_Transport( 'key', 'claude-sonnet-4-6' ) )->complete( 'Instructions', '', $options ),
			( new Google_Direct_Transport( 'key', 'gemini-2.5-pro' ) )->complete( 'Instructions', '', $options ),
			( new OpenAI_Compatible_Direct_Transport( 'custom', 'https://8.8.8.8/v1/chat/completions', 'key', 'private-model' ) )->complete( 'Instructions', '', $options ),
		];

		foreach ( $responses as $response ) {
			$this->assertWPError( $response );
			$this->assertStringNotContainsString( 'private-image-bytes', $response->get_error_message() );
			$this->assertStringNotContainsString( base64_encode( 'private-image-bytes' ), $response->get_error_message() );
		}
	}

	/**
	 * @param mixed                $response Short-circuit response.
	 * @param array<string, mixed> $args     HTTP request arguments.
	 * @return array<string, mixed>
	 */
	public function capture_request( $response, array $args, string $url ): array {
		$this->requests[ $url ]          = (array) json_decode( (string) $args['body'], true );
		$this->request_arguments[ $url ] = $args;
		if ( $this->echo_request_errors ) {
			return [
				'headers'  => [],
				'body'     => wp_json_encode( [ 'error' => [ 'message' => (string) $args['body'] ] ] ),
				'response' => [ 'code' => 400, 'message' => 'Bad Request' ],
				'cookies'  => [],
				'filename' => null,
			];
		}
		if ( str_contains( $url, 'openai.com/v1/responses' ) ) {
			$body = [ 'id' => 'openai', 'status' => 'completed', 'output' => [ [ 'type' => 'message', 'content' => [ [ 'type' => 'output_text', 'text' => 'OpenAI response' ] ] ] ], 'usage' => [] ];
		} elseif ( str_contains( $url, 'anthropic.com/v1/messages' ) ) {
			$body = [ 'id' => 'anthropic', 'stop_reason' => 'end_turn', 'content' => [ [ 'type' => 'text', 'text' => 'Anthropic response' ] ], 'usage' => [] ];
		} elseif ( str_contains( $url, 'generativelanguage.googleapis.com' ) ) {
			$body = [ 'candidates' => [ [ 'content' => [ 'parts' => [ [ 'text' => '{"ok":true}' ] ] ] ] ], 'usageMetadata' => [] ];
		} else {
			$body = [ 'id' => 'xai', 'choices' => [ [ 'message' => [ 'content' => 'xAI response' ] ] ], 'usage' => [] ];
		}

		return [
			'headers'  => [],
			'body'     => wp_json_encode( $body ),
			'response' => [ 'code' => 200, 'message' => 'OK' ],
			'cookies'  => [],
			'filename' => null,
		];
	}
}
