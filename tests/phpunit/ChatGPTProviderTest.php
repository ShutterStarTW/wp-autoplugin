<?php

use WP_Autoplugin\V2\Domain\AI\Model_Catalog;
use WP_Autoplugin\V2\Domain\AI\Model_Effort;
use WP_Autoplugin\V2\Infrastructure\AI\ChatGPT_Config;
use WP_Autoplugin\V2\Infrastructure\AI\ChatGPT_OAuth_Service;
use WP_Autoplugin\V2\Infrastructure\AI\ChatGPT_Session_Store;
use WP_Autoplugin\V2\Infrastructure\AI\ChatGPT_Token_Manager;
use WP_Autoplugin\V2\Infrastructure\AI\ChatGPT_Token_Store;
use WP_Autoplugin\V2\Rest\ChatGPT_Provider_Routes;
use WP_Autoplugin\V2\Rest\Routes as V2_Routes;

/** Security, persistence, and v2 isolation coverage for ChatGPT Subscription. */
final class ChatGPTProviderTest extends WP_UnitTestCase {
	private int $oauth_polls = 0;
	private const OPTIONS = [
		'_wp_autoplugin_chatgpt_oauth_tokens',
		'_wp_autoplugin_chatgpt_oauth_lock',
		'_wp_autoplugin_chatgpt_oauth_poll_lock',
		'_wp_autoplugin_chatgpt_models_lock',
		'wp_autoplugin_chatgpt_model_cache',
		'wp_autoplugin_v2_planner_model',
		'wp_autoplugin_v2_planner_model_effort',
		'wp_autoplugin_planner_model',
		'wp_autoplugin_planner_model_effort',
		'wp_autoplugin_model',
	];

	public function set_up(): void {
		parent::set_up();
		foreach ( self::OPTIONS as $option ) {
			delete_option( $option );
		}
		delete_transient( ChatGPT_Session_Store::TRANSIENT );
	}

	public function tear_down(): void {
		remove_all_filters( 'pre_http_request' );
		foreach ( self::OPTIONS as $option ) {
			delete_option( $option );
		}
		delete_transient( ChatGPT_Session_Store::TRANSIENT );
		parent::tear_down();
	}

	public function test_token_store_encrypts_and_rejects_tampering(): void {
		$store = new ChatGPT_Token_Store();
		$tokens = $this->tokens();
		$this->assertTrue( $store->save( $tokens ) );
		$stored = (string) get_option( ChatGPT_Token_Store::OPTION );
		$this->assertStringNotContainsString( $tokens['access_token'], $stored );
		$this->assertStringNotContainsString( $tokens['refresh_token'], $stored );
		$this->assertSame( $tokens, $store->get() );

		$envelope = json_decode( $stored, true );
		$envelope['ciphertext'] = substr( $envelope['ciphertext'], 0, -2 ) . 'AA';
		update_option( ChatGPT_Token_Store::OPTION, wp_json_encode( $envelope ), false );
		$this->assertWPError( $store->get() );
	}

	public function test_endpoint_allowlist_rejects_redirect_and_path_confusion_targets(): void {
		$this->assertTrue( ChatGPT_Config::is_api_url( 'https://chatgpt.com/backend-api/codex/responses' ) );
		foreach ( [
			'http://chatgpt.com/backend-api/codex/responses',
			'https://attacker.example/backend-api/codex/responses',
			'https://chatgpt.com:443/backend-api/codex/responses',
			'https://user@chatgpt.com/backend-api/codex/responses',
			'https://chatgpt.com/backend-api/codex/%2e%2e/private',
			'https://chatgpt.com/backend-api/codex/../private',
			'https://chatgpt.com/backend-api/codex/responses#fragment',
		] as $url ) {
			$this->assertFalse( ChatGPT_Config::is_api_url( $url ), $url );
		}
	}

	public function test_device_session_is_owned_expires_and_can_be_cancelled(): void {
		$owner = self::factory()->user->create( [ 'role' => 'administrator' ] );
		$other = self::factory()->user->create( [ 'role' => 'administrator' ] );
		$store = new ChatGPT_Session_Store();
		$session = $store->create( $owner, [ 'device_auth_id' => 'device', 'user_code' => 'CODE-123', 'verification_url' => ChatGPT_Config::VERIFICATION_URL, 'interval' => 5 ] );
		$this->assertFalse( is_wp_error( $session ) );
		$this->assertWPError( $store->cancel( $other, $session['session_id'] ) );
		$this->assertSame( 'cancelled', $store->cancel( $owner, $session['session_id'] )['status'] );

		$raw = get_transient( ChatGPT_Session_Store::TRANSIENT );
		$raw['expires_at'] = time() - 1;
		set_transient( ChatGPT_Session_Store::TRANSIENT, $raw, 30 );
		$this->assertNull( $store->status( $owner ) );
	}

	public function test_status_and_rest_responses_do_not_expose_tokens(): void {
		( new ChatGPT_Token_Store() )->save( $this->tokens() );
		$admin = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $admin );
		$state = ( new ChatGPT_OAuth_Service() )->status( $admin );
		$encoded = wp_json_encode( $state );
		$this->assertTrue( $state['connected'] );
		$this->assertStringNotContainsString( 'access-secret', $encoded );
		$this->assertStringNotContainsString( 'refresh-secret', $encoded );
		$this->assertTrue( ( new ChatGPT_Provider_Routes() )->can_manage() );

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'subscriber' ] ) );
		$this->assertFalse( ( new ChatGPT_Provider_Routes() )->can_manage() );
	}

	public function test_device_flow_pending_approval_and_model_sync_are_server_side(): void {
		add_filter( 'pre_http_request', [ $this, 'oauth_http' ], 10, 3 );
		$admin = self::factory()->user->create( [ 'role' => 'administrator' ] );
		$service = new ChatGPT_OAuth_Service();
		$started = $service->start( $admin );
		$this->assertFalse( is_wp_error( $started ) );
		$this->assertSame( 'CODE-123', $started['user_code'] );
		$this->assertArrayNotHasKey( 'device_auth_id', $started );

		$this->make_poll_due();
		$pending = $service->poll( $admin, $started['session_id'] );
		$this->assertSame( 'pending', $pending['status'] );
		$this->make_poll_due();
		$approved = $service->poll( $admin, $started['session_id'] );
		$this->assertSame( 'approved', $approved['status'] );
		$this->assertTrue( $approved['connected'] );
		$this->assertArrayNotHasKey( 'access_token', $approved );
		$this->assertArrayHasKey( 'gpt-5.6-sol', $approved['models']['models'] );
		$this->assertTrue( $service->status( $admin )['connected'] );
	}

	public function test_transient_refresh_failure_uses_valid_token_and_rotation_preserves_refresh_token(): void {
		$tokens = $this->tokens();
		$tokens['expires_at'] = time() + 60;
		( new ChatGPT_Token_Store() )->save( $tokens );
		add_filter( 'pre_http_request', static fn() => [ 'headers' => [], 'body' => '{}', 'response' => [ 'code' => 503, 'message' => 'Unavailable' ], 'cookies' => [], 'filename' => null ], 10, 3 );
		$fallback = ( new ChatGPT_Token_Manager() )->current();
		$this->assertSame( $tokens['access_token'], $fallback['access_token'] );

		remove_all_filters( 'pre_http_request' );
		$new_payload = rtrim( strtr( base64_encode( wp_json_encode( [ 'exp' => time() + 3600 ] ) ), '+/', '-_' ), '=' );
		add_filter( 'pre_http_request', static fn() => [ 'headers' => [], 'body' => wp_json_encode( [ 'access_token' => 'header.' . $new_payload . '.new-signature', 'expires_in' => 3600 ] ), 'response' => [ 'code' => 200, 'message' => 'OK' ], 'cookies' => [], 'filename' => null ], 10, 3 );
		$rotated = ( new ChatGPT_Token_Manager() )->current();
		$this->assertSame( 'refresh-secret', $rotated['refresh_token'] );
		$this->assertStringContainsString( 'new-signature', $rotated['access_token'] );
	}

	public function test_namespaced_role_override_preserves_shared_v1_selection(): void {
		( new ChatGPT_Token_Store() )->save( $this->tokens() );
		$account = hash_hmac( 'sha256', 'acct-test', wp_salt( 'auth' ) );
		update_option( 'wp_autoplugin_chatgpt_model_cache', [
			'account' => $account,
			'fetched_at' => time(),
			'attempted_at' => time(),
			'error' => '',
			'models' => [ 'gpt-5.6-sol' => [ 'label' => 'GPT-5.6 Sol', 'levels' => [ 'low', 'medium', 'ultra' ], 'default' => 'low' ] ],
		], false );
		update_option( 'wp_autoplugin_model', 'gpt-5.4-mini' );
		update_option( 'wp_autoplugin_planner_model', 'gpt-5.4' );
		update_option( 'wp_autoplugin_planner_model_effort', 'high' );

		$selection = ( new Model_Catalog() )->update( 'planner', 'chatgpt:gpt-5.6-sol', 'ultra' );
		$this->assertFalse( is_wp_error( $selection ) );
		$this->assertSame( 'chatgpt', $selection['provider'] );
		$this->assertSame( 'ultra', $selection['effort'] );
		$this->assertSame( 'gpt-5.4', get_option( 'wp_autoplugin_planner_model' ) );
		$this->assertSame( 'high', get_option( 'wp_autoplugin_planner_model_effort' ) );
		$snapshot_method = new ReflectionMethod( V2_Routes::class, 'snapshot_job_models' );
		$snapshot = $snapshot_method->invoke( new V2_Routes(), 'plan', [], [ 'target_kind' => 'new_plugin', 'target_metadata' => [ 'kind' => 'new_plugin' ] ] );
		$this->assertSame( [ 'provider' => 'chatgpt', 'model' => 'chatgpt:gpt-5.6-sol', 'effort' => 'ultra' ], $snapshot['prompt_model'] );

		$restored = ( new Model_Catalog() )->update( 'planner', '', '' );
		$this->assertTrue( $restored['inherits_default'] );
		$this->assertSame( '', get_option( 'wp_autoplugin_v2_planner_model', '' ) );
		$this->assertSame( 'ultra', Model_Effort::sanitize( 'ultra' ) );

		$new_tokens = $this->tokens();
		$new_tokens['account_id'] = 'acct-other';
		( new ChatGPT_Token_Store() )->save( $new_tokens );
		$this->assertFalse( ( new Model_Catalog() )->definition( 'chatgpt:gpt-5.6-sol' )['available'] );
	}

	public function test_catalog_always_uses_all_six_collision_safe_ids(): void {
		$items = array_values( array_filter( ( new Model_Catalog() )->catalog(), static fn( array $item ): bool => 'chatgpt' === $item['provider'] ) );
		$this->assertSame( array_map( [ ChatGPT_Config::class, 'catalog_id' ], array_keys( ChatGPT_Config::models() ) ), array_column( $items, 'id' ) );
	}

	/** @param mixed $response @return array<string, mixed> */
	public function oauth_http( $response, array $args, string $url ): array {
		if ( ChatGPT_Config::DEVICE_START_URL === $url ) {
			return $this->http_response( 200, [ 'device_auth_id' => 'device-test', 'user_code' => 'CODE-123', 'verification_uri' => ChatGPT_Config::VERIFICATION_URL, 'interval' => 3 ] );
		}
		if ( ChatGPT_Config::DEVICE_POLL_URL === $url ) {
			$this->oauth_polls++;
			return 1 === $this->oauth_polls ? $this->http_response( 202, [] ) : $this->http_response( 200, [ 'authorization_code' => 'authorization-code', 'code_verifier' => 'verifier' ] );
		}
		if ( ChatGPT_Config::TOKEN_URL === $url ) {
			return $this->http_response( 200, [ 'access_token' => $this->tokens()['access_token'], 'refresh_token' => 'refresh-secret', 'expires_in' => 3600 ] );
		}
		if ( str_starts_with( $url, ChatGPT_Config::API_BASE_URL . '/models' ) ) {
			return $this->http_response( 200, [ 'models' => [ [ 'slug' => 'gpt-5.6-sol', 'display_name' => 'GPT-5.6 Sol', 'visibility' => 'visible', 'supported_reasoning_levels' => [ [ 'effort' => 'low' ], [ 'effort' => 'ultra' ] ], 'default_reasoning_level' => 'low' ] ] ] );
		}
		return $response;
	}

	private function make_poll_due(): void {
		$session = get_transient( ChatGPT_Session_Store::TRANSIENT );
		$session['next_poll_at'] = time() - 1;
		set_transient( ChatGPT_Session_Store::TRANSIENT, $session, ChatGPT_Session_Store::TTL );
	}

	/** @param array<string, mixed> $body @return array<string, mixed> */
	private function http_response( int $status, array $body ): array {
		return [ 'headers' => [], 'body' => wp_json_encode( $body ), 'response' => [ 'code' => $status, 'message' => 'Test' ], 'cookies' => [], 'filename' => null ];
	}

	/** @return array<string, mixed> */
	private function tokens(): array {
		$payload = rtrim( strtr( base64_encode( wp_json_encode( [ 'exp' => time() + 3600, 'https://api.openai.com/auth' => [ 'chatgpt_account_id' => 'acct-test' ] ] ) ), '+/', '-_' ), '=' );
		return [ 'access_token' => 'header.' . $payload . '.signature-access-secret', 'refresh_token' => 'refresh-secret', 'expires_at' => time() + 3600, 'obtained_at' => time(), 'account_id' => 'acct-test', 'account_label' => 'admin@example.test' ];
	}
}
