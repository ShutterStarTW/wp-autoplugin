<?php

use WP_Autoplugin\V2\Infrastructure\AI\Google_Direct_Transport;

/** Request-shape and durable continuation coverage for Gemini native tools. */
final class GoogleAgentTransportTest extends WP_UnitTestCase {
	/** @var array<int, array<string, mixed>> */
	private array $requests = [];

	public function set_up(): void {
		parent::set_up();
		add_filter( 'pre_http_request', [ $this, 'capture_request' ], 10, 3 );
	}

	public function tear_down(): void {
		remove_filter( 'pre_http_request', [ $this, 'capture_request' ], 10 );
		parent::tear_down();
	}

	public function test_tool_calls_preserve_ids_and_thought_signatures_across_turns(): void {
		$transport = new Google_Direct_Transport( 'test-key', 'gemini-3.5-flash-lite' );
		$tools     = [
			[
				'name'        => 'read_file',
				'description' => 'Read a bounded source range.',
				'parameters'  => [
					'type'       => 'object',
					'properties' => [
						'path' => [ 'type' => 'string' ],
					],
					'required'   => [ 'path' ],
				],
			],
			[
				'name'        => 'get_target_metadata',
				'description' => 'Read target metadata.',
				'parameters'  => [
					'type'       => 'object',
					'properties' => new stdClass(),
				],
			],
		];

		$first = $transport->send(
			'Inspect only the source needed to answer.',
			[ [ 'role' => 'user', 'content' => 'Inspect the plugin.' ] ],
			$tools
		);

		$this->assertFalse( is_wp_error( $first ) );
		$this->assertSame( 'tool_calls', $first['type'] );
		$this->assertCount( 2, $first['tool_calls'] );
		$this->assertSame( 'call-read', $first['tool_calls'][0]['id'] );
		$this->assertSame( 'opaque/signature==', $first['tool_calls'][0]['thought_signature'] );
		$this->assertArrayNotHasKey( 'thought_signature', $first['tool_calls'][1] );
		$this->assertSame( 'gemini-response-1', $first['request_id'] );

		$second = $transport->send(
			'Inspect only the source needed to answer.',
			[
				[ 'role' => 'user', 'content' => 'Inspect the plugin.' ],
				[
					'role'           => 'assistant',
					'content'        => $first['text'],
					'tool_calls'     => $first['tool_calls'],
					'provider_parts' => $first['provider_parts'],
				],
				[
					'role'    => 'tool',
					'call_id' => 'call-read',
					'name'    => 'read_file',
					'content' => "plugin.php\n1: <?php",
				],
				[
					'role'    => 'tool',
					'call_id' => 'call-meta',
					'name'    => 'get_target_metadata',
					'content' => '{"name":"Example"}',
				],
			],
			$tools
		);

		$this->assertFalse( is_wp_error( $second ) );
		$this->assertSame( 'final', $second['type'] );
		$this->assertSame( 'Final answer.', $second['content'] );
		$this->assertSame( 'gemini-response-2', $second['request_id'] );

		$first_request = $this->requests[0];
		$this->assertSame( 'AUTO', $first_request['toolConfig']['functionCallingConfig']['mode'] );
		$this->assertSame( 'read_file', $first_request['tools'][0]['functionDeclarations'][0]['name'] );
		$this->assertArrayNotHasKey( 'responseMimeType', $first_request['generationConfig'] );

		$continuation = $this->requests[1]['contents'];
		$this->assertSame( 'model', $continuation[1]['role'] );
		$this->assertSame( 'call-read', $continuation[1]['parts'][1]['functionCall']['id'] );
		$this->assertSame( 'opaque/signature==', $continuation[1]['parts'][1]['thoughtSignature'] );
		$this->assertArrayNotHasKey( 'thoughtSignature', $continuation[1]['parts'][2] );
		$this->assertSame( 'user', $continuation[2]['role'] );
		$this->assertCount( 2, $continuation[2]['parts'] );
		$this->assertSame( 'call-read', $continuation[2]['parts'][0]['functionResponse']['id'] );
		$this->assertSame( 'call-meta', $continuation[2]['parts'][1]['functionResponse']['id'] );
	}

	/**
	 * @param mixed                $response Short-circuit response.
	 * @param array<string, mixed> $args     HTTP request arguments.
	 * @return array<string, mixed>
	 */
	public function capture_request( $response, array $args, string $url ): array {
		if ( ! str_contains( $url, 'generativelanguage.googleapis.com' ) ) {
			return $response;
		}

		$this->requests[] = (array) json_decode( (string) $args['body'], true );
		if ( 1 === count( $this->requests ) ) {
			$body = [
				'responseId'    => 'gemini-response-1',
				'candidates'    => [
					[
						'finishReason' => 'STOP',
						'content'      => [
							'role'  => 'model',
							'parts' => [
								[ 'text' => 'Inspecting.' ],
								[
									'functionCall'    => [
										'id'   => 'call-read',
										'name' => 'read_file',
										'args' => [ 'path' => 'plugin.php' ],
									],
									'thoughtSignature' => 'opaque/signature==',
								],
								[
									'functionCall' => [
										'id'   => 'call-meta',
										'name' => 'get_target_metadata',
										'args' => new stdClass(),
									],
								],
							],
						],
					],
				],
				'usageMetadata' => [
					'promptTokenCount'     => 30,
					'candidatesTokenCount' => 8,
				],
			];
		} else {
			$body = [
				'responseId'    => 'gemini-response-2',
				'candidates'    => [
					[
						'finishReason' => 'STOP',
						'content'      => [
							'role'  => 'model',
							'parts' => [ [ 'text' => 'Final answer.' ] ],
						],
					],
				],
				'usageMetadata' => [
					'promptTokenCount'     => 80,
					'candidatesTokenCount' => 12,
				],
			];
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
