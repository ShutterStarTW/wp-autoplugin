<?php

use WP_Autoplugin\V2\Infrastructure\AI\OpenAI_Agent_Transport;

/** Focused request-shape coverage for the v2 OpenAI Responses transport. */
final class OpenAIAgentTransportTest extends WP_UnitTestCase {
	/** @var array<string, mixed> */
	private array $request_body = [];
	private int $request_timeout = 0;

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
		$this->assertSame( 300, $this->request_timeout );
		$this->assertStringContainsStringIgnoringCase(
			'json',
			$this->request_body['input'][0]['content'][0]['text']
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
		return [
			'headers'  => [],
			'body'     => wp_json_encode(
				[
					'id'     => 'resp_test',
					'status' => 'completed',
					'output' => [
						[
							'type'    => 'message',
							'content' => [ [ 'type' => 'output_text', 'text' => '{"path":"example.php","content":"<?php"}' ] ],
						],
					],
					'usage'  => [ 'input_tokens' => 10, 'output_tokens' => 5 ],
				]
			),
			'response' => [ 'code' => 200, 'message' => 'OK' ],
			'cookies'  => [],
			'filename' => null,
		];
	}
}
