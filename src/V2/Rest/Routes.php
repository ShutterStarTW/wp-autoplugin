<?php

namespace WP_Autoplugin\V2\Rest;

use WP_Autoplugin\V2\Domain\Target\Target_Scanner;
use WP_Autoplugin\V2\Infrastructure\Database\Installer;
use WP_Autoplugin\V2\Infrastructure\Database\Job_Repository;
use WP_Autoplugin\V2\Infrastructure\Database\Workspace_Repository;
use WP_Autoplugin\V2\Infrastructure\Queue\Queue;

/**
 * Capability-checked REST interface for the v2 admin application.
 */
final class Routes {
	private const NAMESPACE = 'wp-autoplugin/v2';
	private const OPERATIONS = [ 'create', 'modify', 'fix', 'hook_extension', 'fork', 'explain' ];
	private const TASKS      = [ 'plan', 'code', 'review', 'explain' ];

	public function register(): void {
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
	}

	public function register_routes(): void {
		$permission = [ $this, 'can_manage' ];

		register_rest_route( self::NAMESPACE, '/bootstrap', [
			'methods'             => \WP_REST_Server::READABLE,
			'callback'            => [ $this, 'bootstrap' ],
			'permission_callback' => $permission,
		] );
		register_rest_route( self::NAMESPACE, '/targets', [
			'methods'             => \WP_REST_Server::READABLE,
			'callback'            => [ $this, 'targets' ],
			'permission_callback' => $permission,
		] );
		register_rest_route( self::NAMESPACE, '/workspaces', [
			[
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => [ $this, 'workspaces' ],
				'permission_callback' => $permission,
			],
			[
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'create_workspace' ],
				'permission_callback' => $permission,
				'args'                => [
					'target_kind' => [ 'required' => true, 'type' => 'string', 'sanitize_callback' => 'sanitize_key' ],
					'target_ref'  => [ 'required' => true, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ],
					'operation'   => [ 'required' => true, 'type' => 'string', 'enum' => self::OPERATIONS ],
					'request'     => [ 'required' => true, 'type' => 'string', 'sanitize_callback' => 'sanitize_textarea_field' ],
				],
			],
		] );
		register_rest_route( self::NAMESPACE, '/workspaces/(?P<id>\d+)', [
			'methods'             => \WP_REST_Server::READABLE,
			'callback'            => [ $this, 'workspace' ],
			'permission_callback' => $permission,
			'args'                => [ 'id' => [ 'type' => 'integer', 'minimum' => 1 ] ],
		] );
		register_rest_route( self::NAMESPACE, '/workspaces/(?P<id>\d+)/close', [
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => [ $this, 'close_workspace' ],
			'permission_callback' => $permission,
			'args'                => [ 'id' => [ 'type' => 'integer', 'minimum' => 1 ] ],
		] );
		register_rest_route( self::NAMESPACE, '/jobs', [
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => [ $this, 'create_job' ],
			'permission_callback' => $permission,
			'args'                => [
				'workspace_id' => [ 'required' => true, 'type' => 'integer', 'minimum' => 1 ],
				'task'         => [ 'required' => true, 'type' => 'string', 'enum' => self::TASKS ],
				'payload'      => [ 'type' => 'object', 'default' => [] ],
			],
		] );
		register_rest_route( self::NAMESPACE, '/jobs/(?P<id>\d+)', [
			'methods'             => \WP_REST_Server::READABLE,
			'callback'            => [ $this, 'job' ],
			'permission_callback' => $permission,
			'args'                => [ 'id' => [ 'type' => 'integer', 'minimum' => 1 ] ],
		] );
		register_rest_route( self::NAMESPACE, '/jobs/(?P<id>\d+)/events', [
			'methods'             => \WP_REST_Server::READABLE,
			'callback'            => [ $this, 'job_events' ],
			'permission_callback' => $permission,
			'args'                => [
				'id'    => [ 'type' => 'integer', 'minimum' => 1 ],
				'after' => [ 'type' => 'integer', 'minimum' => 0, 'default' => 0 ],
			],
		] );
		register_rest_route( self::NAMESPACE, '/jobs/(?P<id>\d+)/cancel', [
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => [ $this, 'cancel_job' ],
			'permission_callback' => $permission,
			'args'                => [ 'id' => [ 'type' => 'integer', 'minimum' => 1 ] ],
		] );
	}

	public function can_manage(): bool {
		return current_user_can( 'manage_options' );
	}

	public function bootstrap(): \WP_REST_Response {
		return rest_ensure_response( [
			'version'   => WP_AUTOPLUGIN_VERSION,
			'schema'    => Installer::SCHEMA_VERSION,
			'queue'     => ( new Queue() )->status(),
			'log_mode'  => get_option( 'wp_autoplugin_v2_log_mode', 'metadata' ),
		] );
	}

	public function targets(): \WP_REST_Response {
		return rest_ensure_response( [ 'items' => ( new Target_Scanner() )->all() ] );
	}

	public function workspaces(): \WP_REST_Response {
		return rest_ensure_response( [ 'items' => ( new Workspace_Repository() )->list_open( get_current_user_id() ) ] );
	}

	/**
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function create_workspace( \WP_REST_Request $request ) {
		$kind      = (string) $request['target_kind'];
		$ref       = (string) $request['target_ref'];
		$operation = (string) $request['operation'];
		$target    = ( new Target_Scanner() )->find( $kind, $ref );

		if ( ! $target ) {
			return new \WP_Error( 'wp_autoplugin_target_not_found', __( 'The selected target no longer exists.', 'wp-autoplugin' ), [ 'status' => 404 ] );
		}
		if ( 'new_plugin' === $kind && 'create' !== $operation ) {
			return new \WP_Error( 'wp_autoplugin_invalid_operation', __( 'A new plugin target only supports the create operation.', 'wp-autoplugin' ), [ 'status' => 400 ] );
		}
		if ( 'theme' === $kind && ! in_array( $operation, [ 'modify', 'fix', 'hook_extension', 'explain', 'fork' ], true ) ) {
			return new \WP_Error( 'wp_autoplugin_invalid_operation', __( 'Themes can be modified, fixed, inspected, forked, or extended through hooks.', 'wp-autoplugin' ), [ 'status' => 400 ] );
		}

		try {
			$repository = new Workspace_Repository();
			$created    = $repository->create(
				$target,
				$operation,
				(string) $request['request'],
				get_current_user_id()
			);
			$workspace                 = $repository->find( (int) $created['workspace_id'] );
			if ( ! $workspace ) {
				throw new \RuntimeException( __( 'The workspace was created but could not be loaded.', 'wp-autoplugin' ) );
			}
			$workspace['workspace_id'] = (int) $created['workspace_id'];

			return new \WP_REST_Response( $workspace, 201 );
		} catch ( \Throwable $error ) {
			return new \WP_Error( 'wp_autoplugin_workspace_error', $error->getMessage(), [ 'status' => 500 ] );
		}
	}

	/**
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function workspace( \WP_REST_Request $request ) {
		$workspace = ( new Workspace_Repository() )->find( (int) $request['id'] );
		return $workspace
			? rest_ensure_response( $workspace )
			: new \WP_Error( 'wp_autoplugin_workspace_not_found', __( 'Workspace not found.', 'wp-autoplugin' ), [ 'status' => 404 ] );
	}

	/**
	 * Close a tab without deleting its durable workspace data.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function close_workspace( \WP_REST_Request $request ) {
		$closed = ( new Workspace_Repository() )->close( (int) $request['id'], get_current_user_id() );
		return $closed
			? rest_ensure_response( [ 'id' => (int) $request['id'], 'closed' => true ] )
			: new \WP_Error( 'wp_autoplugin_workspace_not_found', __( 'Workspace not found or already closed.', 'wp-autoplugin' ), [ 'status' => 404 ] );
	}

	/**
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function create_job( \WP_REST_Request $request ) {
		$workspace_id = (int) $request['workspace_id'];
		if ( ! ( new Workspace_Repository() )->find( $workspace_id ) ) {
			return new \WP_Error( 'wp_autoplugin_workspace_not_found', __( 'Workspace not found.', 'wp-autoplugin' ), [ 'status' => 404 ] );
		}

		try {
			$jobs   = new Job_Repository();
			$job    = $jobs->create( $workspace_id, (string) $request['task'], (array) $request['payload'], get_current_user_id() );
			$runner = ( new Queue() )->dispatch( $job['id'] );
			$jobs->update( $job['id'], [ 'runner' => $runner ] );

			return new \WP_REST_Response( $jobs->find( $job['id'] ), 202 );
		} catch ( \Throwable $error ) {
			return new \WP_Error( 'wp_autoplugin_job_error', $error->getMessage(), [ 'status' => 500 ] );
		}
	}

	/**
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function job( \WP_REST_Request $request ) {
		$job = ( new Job_Repository() )->find( (int) $request['id'] );
		return $job
			? rest_ensure_response( $job )
			: new \WP_Error( 'wp_autoplugin_job_not_found', __( 'Job not found.', 'wp-autoplugin' ), [ 'status' => 404 ] );
	}

	/**
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function job_events( \WP_REST_Request $request ) {
		$jobs = new Job_Repository();
		if ( ! $jobs->find( (int) $request['id'] ) ) {
			return new \WP_Error( 'wp_autoplugin_job_not_found', __( 'Job not found.', 'wp-autoplugin' ), [ 'status' => 404 ] );
		}

		return rest_ensure_response( [ 'items' => $jobs->events( (int) $request['id'], (int) $request['after'] ) ] );
	}

	/**
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function cancel_job( \WP_REST_Request $request ) {
		$jobs = new Job_Repository();
		$job  = $jobs->find( (int) $request['id'] );
		if ( ! $job ) {
			return new \WP_Error( 'wp_autoplugin_job_not_found', __( 'Job not found.', 'wp-autoplugin' ), [ 'status' => 404 ] );
		}
		if ( in_array( $job['status'], [ 'completed', 'failed', 'cancelled' ], true ) ) {
			return new \WP_Error( 'wp_autoplugin_job_finished', __( 'A finished job cannot be cancelled.', 'wp-autoplugin' ), [ 'status' => 409 ] );
		}

		$jobs->request_cancel( $job['id'] );

		return rest_ensure_response( $jobs->find( $job['id'] ) );
	}

}
