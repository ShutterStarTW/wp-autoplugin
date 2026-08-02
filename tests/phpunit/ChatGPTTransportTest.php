<?php

use WP_Autoplugin\V2\Infrastructure\AI\ChatGPT_Token_Store;
use WP_Autoplugin\V2\Infrastructure\AI\ChatGPT_Transport;
use WP_Autoplugin\V2\Infrastructure\AI\ChatGPT_SSE_Parser;

/** Request-shape, SSE, retry, and image-redaction coverage. */
final class ChatGPTTransportTest extends WP_UnitTestCase {
	private array $request = [];

	public function set_up(): void {
		parent::set_up();
		$payload = rtrim( strtr( base64_encode( wp_json_encode( [ 'exp' => time() + 3600 ] ) ), '+/', '-_' ), '=' );
		( new ChatGPT_Token_Store() )->save( [ 'access_token' => 'header.' . $payload . '.signature', 'refresh_token' => 'refresh-secret', 'expires_at' => time() + 3600, 'account_id' => 'acct-test' ] );
	}

	public function tear_down(): void {
		remove_all_filters( 'pre_http_request' );
		delete_option( ChatGPT_Token_Store::OPTION );
		parent::tear_down();
	}

	public function test_request_and_sse_tool_call_are_normalized_without_commentary(): void {
		add_filter( 'pre_http_request', [ $this, 'capture' ], 10, 3 );
		$result = ( new ChatGPT_Transport( 'chatgpt:gpt-5.6-sol', 'gpt-5.6-sol', 'ultra' ) )->send(
			'Inspect source.',
			[ [ 'role' => 'user', 'content' => 'Read the file.' ] ],
			[ [ 'name' => 'read_file', 'description' => 'Read one file.', 'parameters' => [ 'type' => 'object', 'properties' => [ 'path' => [ 'type' => 'string', 'pattern' => '^src/', 'format' => 'path', 'enum' => [ 'src/a.php' ] ] ] ] ] ]
		);

		$this->assertFalse( is_wp_error( $result ) );
		$this->assertSame( 'tool_calls', $result['type'] );
		$this->assertSame( 'read_file', $result['tool_calls'][0]['name'] );
		$this->assertSame( 'gpt-5.6-sol', $this->request['body']['model'] );
		$this->assertTrue( $this->request['body']['stream'] );
		$this->assertFalse( $this->request['body']['store'] );
		$this->assertArrayNotHasKey( 'max_output_tokens', $this->request['body'] );
		$this->assertArrayNotHasKey( 'pattern', $this->request['body']['tools'][0]['parameters']['properties']['path'] );
		$this->assertArrayNotHasKey( 'enum', $this->request['body']['tools'][0]['parameters']['properties']['path'] );
		$this->assertSame( 'codex_cli_rs', $this->request['headers']['originator'] );
		$this->assertSame( 'acct-test', $this->request['headers']['ChatGPT-Account-ID'] );
	}

	public function test_truncated_stream_is_ambiguous_and_private_image_errors_are_redacted(): void {
		$truncated = ChatGPT_SSE_Parser::parse( 'data: {"type":"response.output_text.delta","delta":"partial"}' . "\n\n" );
		$this->assertWPError( $truncated );
		$this->assertTrue( $truncated->get_error_data()['ambiguous'] );

		add_filter( 'pre_http_request', static fn() => new WP_Error( 'http_request_failed', 'data:image/png;base64,PRIVATE timed out' ), 10, 3 );
		$result = ( new ChatGPT_Transport( 'chatgpt:gpt-5.6-luna', 'gpt-5.6-luna', 'medium' ) )->complete(
			'Inspect the image.',
			'',
			[ 'prompt_images' => [ [ 'mime_type' => 'image/png', 'content' => 'PRIVATE' ] ] ]
		);
		$this->assertWPError( $result );
		$this->assertStringNotContainsString( 'PRIVATE', $result->get_error_message() );
		$this->assertStringNotContainsString( 'base64', $result->get_error_message() );
		$this->assertTrue( $result->get_error_data()['ambiguous'] );
	}

	/** @param mixed $response @return array<string, mixed> */
	public function capture( $response, array $args, string $url ): array {
		if ( 'https://chatgpt.com/backend-api/codex/responses' !== $url ) {
			return $response;
		}
		$this->request = [ 'body' => json_decode( (string) $args['body'], true ), 'headers' => $args['headers'] ];
		$events = [
			[ 'type' => 'response.output_item.added', 'item' => [ 'type' => 'message', 'phase' => 'commentary' ] ],
			[ 'type' => 'response.output_text.delta', 'delta' => 'private analysis' ],
			[ 'type' => 'response.output_item.added', 'item' => [ 'type' => 'function_call' ] ],
			[ 'type' => 'response.output_item.done', 'item' => [ 'type' => 'function_call', 'call_id' => 'call_1', 'name' => 'read_file', 'arguments' => '{"path":"src/a.php"}' ] ],
			[ 'type' => 'response.completed', 'response' => [ 'id' => 'resp_1', 'status' => 'completed', 'usage' => [ 'input_tokens' => 12, 'output_tokens' => 4 ] ] ],
		];
		$body = '';
		foreach ( $events as $event ) {
			$body .= 'data: ' . wp_json_encode( $event ) . "\n\n";
		}
		return [ 'headers' => [], 'body' => $body, 'response' => [ 'code' => 200, 'message' => 'OK' ], 'cookies' => [], 'filename' => null ];
	}
}
