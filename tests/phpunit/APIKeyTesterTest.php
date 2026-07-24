<?php

use WP_Autoplugin\V2\Infrastructure\AI\API_Key_Tester;
use WP_Autoplugin\V2\Rest\API_Key_Test_Routes;

/** Request-shape, security, and permission coverage for API-key tests. */
final class APIKeyTesterTest extends WP_UnitTestCase {
	/** @var array<int, array{url:string,args:array<string,mixed>}> */
	private array $requests = [];

	public function set_up(): void {
		parent::set_up();
		add_filter( 'pre_http_request', [ $this, 'capture_request' ], 10, 3 );
	}

	public function tear_down(): void {
		remove_filter( 'pre_http_request', [ $this, 'capture_request' ], 10 );
		parent::tear_down();
	}

	public function test_each_provider_uses_its_models_endpoint_and_authentication_contract(): void {
		$tester = new API_Key_Tester();
		$this->assertTrue( $tester->test( 'openai', 'sk-openai-secret' ) );
		$this->assertTrue( $tester->test( 'anthropic', 'sk-ant-anthropic-secret' ) );
		$this->assertTrue( $tester->test( 'google', 'google-api-key-at-least-twenty-characters' ) );
		$this->assertTrue( $tester->test( 'xai', 'xai-secret' ) );

		$this->assertSame( 'https://api.openai.com/v1/models', $this->requests[0]['url'] );
		$this->assertSame( 'Bearer sk-openai-secret', $this->requests[0]['args']['headers']['Authorization'] );
		$this->assertSame( 'https://api.anthropic.com/v1/models', $this->requests[1]['url'] );
		$this->assertSame( 'sk-ant-anthropic-secret', $this->requests[1]['args']['headers']['x-api-key'] );
		$this->assertSame( '2023-06-01', $this->requests[1]['args']['headers']['anthropic-version'] );
		$this->assertStringStartsWith( 'https://generativelanguage.googleapis.com/v1beta/models?', $this->requests[2]['url'] );
		$this->assertStringContainsString( 'key=google-api-key-at-least-twenty-characters', $this->requests[2]['url'] );
		$this->assertSame( 'https://api.x.ai/v1/models', $this->requests[3]['url'] );
		$this->assertSame( 'Bearer xai-secret', $this->requests[3]['args']['headers']['Authorization'] );

		foreach ( $this->requests as $request ) {
			$this->assertSame( 15, $request['args']['timeout'] );
			$this->assertSame( 0, $request['args']['redirection'] );
			$this->assertSame( 1024, $request['args']['limit_response_size'] );
		}
	}

	public function test_errors_and_rest_responses_never_echo_the_submitted_key(): void {
		remove_filter( 'pre_http_request', [ $this, 'capture_request' ], 10 );
		$key = 'sk-private-secret';
		$rejected_response = static fn() => [
			'headers'  => [],
			'body'     => wp_json_encode( [ 'error' => [ 'message' => 'Rejected ' . $key ] ] ),
			'response' => [ 'code' => 401, 'message' => 'Unauthorized' ],
			'cookies'  => [],
			'filename' => null,
		];
		add_filter(
			'pre_http_request',
			$rejected_response,
			10,
			3
		);

		try {
			$error = ( new API_Key_Tester() )->test( 'openai', $key );
			$this->assertWPError( $error );
			$this->assertStringNotContainsString( $key, $error->get_error_message() );

			$request = new WP_REST_Request( 'POST', '/wp-autoplugin/v2/providers/openai/api-key/test' );
			$request->set_param( 'provider', 'openai' );
			$request->set_param( 'api_key', $key );
			$response = ( new API_Key_Test_Routes() )->test( $request );
			$this->assertWPError( $response );
			$this->assertStringNotContainsString( $key, wp_json_encode( $response ) );
		} finally {
			remove_filter( 'pre_http_request', $rejected_response, 10 );
		}
	}

	public function test_rejects_empty_malformed_and_unknown_keys_before_an_http_request(): void {
		$tester = new API_Key_Tester();
		foreach ( [
			[ 'openai', '' ],
			[ 'openai', 'not-an-openai-key' ],
			[ 'anthropic', 'sk-wrong-prefix' ],
			[ 'google', 'too-short' ],
			[ 'xai', 'not-an-xai-key' ],
			[ 'openai', 'sk-' . str_repeat( 'a', 4096 ) ],
			[ 'unknown', 'secret' ],
		] as [ $provider, $key ] ) {
			$this->assertWPError( $tester->test( $provider, $key ) );
		}
		$this->assertSame( [], $this->requests );
	}

	public function test_rest_route_requires_an_administrator_and_does_not_save_the_key(): void {
		$routes = new API_Key_Test_Routes();
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'subscriber' ] ) );
		$this->assertFalse( $routes->can_manage() );

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$this->assertTrue( $routes->can_manage() );
		$before  = get_option( 'wp_autoplugin_openai_api_key', null );
		$request = new WP_REST_Request( 'POST', '/wp-autoplugin/v2/providers/openai/api-key/test' );
		$request->set_param( 'provider', 'openai' );
		$request->set_param( 'api_key', 'sk-unsaved-key' );
		$response = $routes->test( $request );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertTrue( $response->get_data()['valid'] );
		$this->assertSame( $before, get_option( 'wp_autoplugin_openai_api_key', null ) );
		$this->assertStringNotContainsString( 'sk-unsaved-key', wp_json_encode( $response->get_data() ) );
	}

	/**
	 * @param mixed                $response Short-circuit response.
	 * @param array<string, mixed> $args     HTTP request arguments.
	 * @return array<string, mixed>
	 */
	public function capture_request( $response, array $args, string $url ): array {
		$this->requests[] = [ 'url' => $url, 'args' => $args ];
		return [
			'headers'  => [],
			'body'     => '{}',
			'response' => [ 'code' => 200, 'message' => 'OK' ],
			'cookies'  => [],
			'filename' => null,
		];
	}
}
