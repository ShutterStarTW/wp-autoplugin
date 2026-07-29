<?php

use WP_Autoplugin\V2\Infrastructure\AI\OpenAI_Agent_Transport;

/** Focused request-shape coverage for the v2 OpenAI Responses transport. */
final class OpenAIAgentTransportTest extends WP_UnitTestCase {
	/** @var array<string, mixed> */
	private array $request_body = [];
	private int $request_timeout = 0;
	/** @var array<string, mixed>|null */
	private ?array $response_data = null;

	public function tear_down(): void {
		remove_filter( 'pre_http_request', [ $this, 'capture_request' ], 10 );
		parent::tear_down();
	}

	public function test_json_mode_names_json_in_the_input_message(): void {
		add_filter( 'pre_http_request', [ $this, 'capture_request' ], 10, 3 );

		$result = ( new OpenAI_Agent_Transport( 'test-key', 'gpt-5.4-mini' ) )->complete(
			'Return the requested file.',
			'Current file: example.php',
			[ 'json' => true ]
		);

		$this->assertFalse( is_wp_error( $result ) );
		$this->assertSame( [ 'type' => 'json_object' ], $this->request_body['text']['format'] );
		$this->assertSame( 128000, $this->request_body['max_output_tokens'] );
		$this->assertSame( 300, $this->request_timeout );
		$this->assertStringContainsStringIgnoringCase(
			'json',
			$this->request_body['input'][0]['content'][0]['text']
		);
	}

	public function test_incomplete_response_preserves_reason_usage_and_request_id_without_retrying(): void {
		$this->response_data = [
			'id'                 => 'resp_incomplete',
			'status'             => 'incomplete',
			'incomplete_details' => [ 'reason' => 'max_output_tokens' ],
			'output'             => [],
			'usage'              => [ 'input_tokens' => 300, 'output_tokens' => 128000 ],
		];
		add_filter( 'pre_http_request', [ $this, 'capture_request' ], 10, 3 );

		$result = ( new OpenAI_Agent_Transport( 'test-key', 'gpt-5.4-mini', 'medium' ) )->complete(
			'Return the requested file.',
			'Current file: example.php'
		);

		$this->assertWPError( $result );
		$this->assertSame( 'agent_response_incomplete', $result->get_error_code() );
		$this->assertSame( 128000, $this->request_body['max_output_tokens'] );
		$this->assertSame(
			[
				'retryable'         => false,
				'ambiguous'         => false,
				'incomplete_reason' => 'max_output_tokens',
				'usage'             => [ 'input_tokens' => 300, 'output_tokens' => 128000 ],
				'request_id'        => 'resp_incomplete',
			],
			$result->get_error_data()
		);
	}

	/**
	 * @param mixed                $response Short-circuit response.
	 * @param array<string, mixed> $args     HTTP request arguments.
	 * @return array<string, mixed>
	 */
	public function capture_request( $response, array $args, string $url ): array {
		if ( 'https://api.openai.com/v1/responses' !== $url ) {
			return $response;
		}
		$this->request_body = (array) json_decode( (string) $args['body'], true );
		$this->request_timeout = (int) ( $args['timeout'] ?? 0 );
		$data = $this->response_data ?? [
			'id'     => 'resp_test',
			'status' => 'completed',
			'output' => [
				[
					'type'    => 'message',
					'content' => [ [ 'type' => 'output_text', 'text' => '{"path":"example.php","content":"<?php"}' ] ],
				],
			],
			'usage'  => [ 'input_tokens' => 10, 'output_tokens' => 5 ],
		];
		return [
			'headers'  => [],
			'body'     => wp_json_encode( $data ),
			'response' => [ 'code' => 200, 'message' => 'OK' ],
			'cookies'  => [],
			'filename' => null,
		];
	}
}
