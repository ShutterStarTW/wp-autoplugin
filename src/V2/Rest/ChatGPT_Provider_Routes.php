<?php

namespace WP_Autoplugin\V2\Rest;

use WP_Autoplugin\V2\Infrastructure\AI\ChatGPT_OAuth_Service;

/** Administrator-only REST resources for the experimental ChatGPT provider. */
final class ChatGPT_Provider_Routes {
	private const NAMESPACE = 'wp-autoplugin/v2';

	public function __construct( private ?ChatGPT_OAuth_Service $service = null ) {
		$this->service ??= new ChatGPT_OAuth_Service();
	}

	public function register(): void {
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
	}

	public function register_routes(): void {
		$permission = [ $this, 'can_manage' ];
		register_rest_route( self::NAMESPACE, '/providers/chatgpt', [
			'methods'             => \WP_REST_Server::READABLE,
			'callback'            => [ $this, 'status' ],
			'permission_callback' => $permission,
		] );
		register_rest_route( self::NAMESPACE, '/providers/chatgpt/oauth/start', [
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => [ $this, 'start' ],
			'permission_callback' => $permission,
		] );
		foreach ( [ 'poll', 'cancel' ] as $action ) {
			register_rest_route( self::NAMESPACE, '/providers/chatgpt/oauth/' . $action, [
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => [ $this, $action ],
				'permission_callback' => $permission,
				'args'                => [
					'session_id' => [ 'required' => true, 'type' => 'string', 'pattern' => '^[a-f0-9]{48}$' ],
				],
			] );
		}
		register_rest_route( self::NAMESPACE, '/providers/chatgpt/connection', [
			'methods'             => \WP_REST_Server::DELETABLE,
			'callback'            => [ $this, 'disconnect' ],
			'permission_callback' => $permission,
		] );
		register_rest_route( self::NAMESPACE, '/providers/chatgpt/models/refresh', [
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => [ $this, 'refresh_models' ],
			'permission_callback' => $permission,
		] );
	}

	public function can_manage(): bool {
		return current_user_can( 'manage_options' );
	}

	/** @return array<string, mixed> */
	public function status(): array {
		return $this->service->status( get_current_user_id(), true );
	}

	/** @return array<string, mixed>|\WP_Error */
	public function start() {
		return $this->service->start( get_current_user_id() );
	}

	/** @return array<string, mixed>|\WP_Error */
	public function poll( \WP_REST_Request $request ) {
		return $this->service->poll( get_current_user_id(), (string) $request['session_id'] );
	}

	/** @return array<string, mixed>|\WP_Error */
	public function cancel( \WP_REST_Request $request ) {
		return $this->service->cancel( get_current_user_id(), (string) $request['session_id'] );
	}

	/** @return array<string, mixed>|\WP_Error */
	public function disconnect() {
		return $this->service->disconnect();
	}

	/** @return array<string, mixed>|\WP_Error */
	public function refresh_models() {
		return $this->service->refresh_models();
	}
}
