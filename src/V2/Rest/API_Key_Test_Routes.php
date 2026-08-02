<?php
/**
 * REST routes for testing built-in provider API keys.
 *
 * @package WP_Autoplugin
 */

namespace WP_Autoplugin\V2\Rest;

use WP_Autoplugin\V2\Infrastructure\AI\API_Key_Tester;

/** Administrator-only REST resource for testing built-in provider API keys. */
final class API_Key_Test_Routes {
	private const NAMESPACE = 'wp-autoplugin/v2';

	/**
	 * Create the route controller.
	 *
	 * @param API_Key_Tester|null $tester API-key testing service.
	 */
	public function __construct( private ?API_Key_Tester $tester = null ) {
		$this->tester ??= new API_Key_Tester();
	}

	/** Register the REST initialization hook. */
	public function register(): void {
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
	}

	/** Register API-key testing routes. */
	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/providers/(?P<provider>openai|anthropic|google|xai)/api-key/test',
			[
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'test' ],
				'permission_callback' => [ $this, 'can_manage' ],
				'args'                => [
					'provider' => [
						'type' => 'string',
						'enum' => [ 'openai', 'anthropic', 'google', 'xai' ],
					],
					'api_key'  => [
						'required'  => true,
						'type'      => 'string',
						'maxLength' => 4096,
					],
				],
			]
		);
	}

	/** Check whether the current user can manage provider settings. */
	public function can_manage(): bool {
		return current_user_can( 'manage_options' );
	}

	/**
	 * Test an API key without persisting it.
	 *
	 * @param \WP_REST_Request $request REST request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function test( \WP_REST_Request $request ) {
		$result = $this->tester->test( (string) $request['provider'], (string) $request['api_key'] );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response(
			[
				'valid'   => true,
				'message' => __( 'Connection successful. The API key is valid.', 'wp-autoplugin' ),
			]
		);
	}
}
