<?php

namespace WP_Autoplugin\V2\Rest;

use WP_Autoplugin\V2\Domain\Target\Target_Scanner;
use WP_Autoplugin\V2\Infrastructure\Database\Installer;
use WP_Autoplugin\V2\Infrastructure\Database\Job_Repository;
use WP_Autoplugin\V2\Infrastructure\Database\Workspace_Repository;
use WP_Autoplugin\V2\Infrastructure\Queue\Queue;
use WP_Autoplugin\V2\Infrastructure\AI\Agent_Transport_Factory;

/**
 * Capability-checked REST interface for the v2 admin application.
 */
final class Routes {
	private const NAMESPACE = 'wp-autoplugin/v2';
	private const OPERATIONS = [ 'create', 'modify', 'fix', 'hook_extension', 'explain' ];
	private const TASKS      = [ 'plan', 'code', 'review', 'explain', 'conversation' ];
	private const CONVERSATION_STAGES = [ 'plan', 'explain' ];

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
		register_rest_route( self::NAMESPACE, '/workspaces/(?P<id>\d+)/jobs', [
			'methods'             => \WP_REST_Server::READABLE,
			'callback'            => [ $this, 'workspace_jobs' ],
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
		register_rest_route( self::NAMESPACE, '/jobs/(?P<id>\d+)/plan', [
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => [ $this, 'update_plan' ],
			'permission_callback' => $permission,
			'args'                => [
				'id'      => [ 'type' => 'integer', 'minimum' => 1 ],
				'content' => [ 'required' => true, 'type' => 'string' ],
			],
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
			'explain_agent' => ( new Agent_Transport_Factory() )->capability(),
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
		if ( 'theme' === $kind && ! in_array( $operation, [ 'modify', 'fix', 'hook_extension', 'explain' ], true ) ) {
			return new \WP_Error( 'wp_autoplugin_invalid_operation', __( 'Themes can be modified, fixed, inspected, or extended through hooks.', 'wp-autoplugin' ), [ 'status' => 400 ] );
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
		$workspace = $this->workspace_for_current_user( (int) $request['id'] );
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
		if ( ! $this->workspace_for_current_user( $workspace_id ) ) {
			return new \WP_Error( 'wp_autoplugin_workspace_not_found', __( 'Workspace not found.', 'wp-autoplugin' ), [ 'status' => 404 ] );
		}

		$jobs    = new Job_Repository();
		$task    = (string) $request['task'];
		$payload = (array) $request['payload'];
		if ( 'conversation' === $task ) {
			$payload = $this->conversation_payload( $payload, $workspace_id, $jobs );
			if ( is_wp_error( $payload ) ) {
				return $payload;
			}
		}
		$is_agentic_explain = 'explain' === $task || ( 'conversation' === $task && 'explain' === ( $payload['stage'] ?? '' ) );
		if ( $is_agentic_explain ) {
			$capability = ( new Agent_Transport_Factory() )->capability();
			if ( ! $capability['available'] ) {
				return new \WP_Error( 'wp_autoplugin_explain_agent_unavailable', $capability['message'], [ 'status' => 409 ] );
			}
		}
		$job = null;
		try {
			$job    = $jobs->create( $workspace_id, $task, $payload, get_current_user_id() );
			$runner = ( new Queue() )->dispatch( $job['id'] );
			$jobs->update( $job['id'], [ 'runner' => $runner ] );

			return new \WP_REST_Response( $jobs->find( $job['id'] ), 202 );
		} catch ( \Throwable $error ) {
			if ( $job ) {
				$jobs->update(
					$job['id'],
					[
						'status'        => 'failed',
						'error_message' => $error->getMessage(),
						'finished_at'   => current_time( 'mysql', true ),
					]
				);
				$jobs->event( $job['id'], 'failed', $error->getMessage(), [], 'error' );
			}
			return new \WP_Error( 'wp_autoplugin_job_error', $error->getMessage(), [ 'status' => 500 ] );
		}
	}

	/**
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function workspace_jobs( \WP_REST_Request $request ) {
		$workspace_id = (int) $request['id'];
		if ( ! $this->workspace_for_current_user( $workspace_id ) ) {
			return new \WP_Error( 'wp_autoplugin_workspace_not_found', __( 'Workspace not found.', 'wp-autoplugin' ), [ 'status' => 404 ] );
		}

		$jobs = new Job_Repository();
		$items = array_map( fn( array $job ): array => $this->with_latest_event( $job, $jobs ), $jobs->list_for_workspace( $workspace_id ) );
		return rest_ensure_response( [ 'items' => $items ] );
	}

	/**
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function job( \WP_REST_Request $request ) {
		$job = ( new Job_Repository() )->find( (int) $request['id'] );
		return $job && $this->workspace_for_current_user( (int) $job['workspace_id'] )
			? rest_ensure_response( $this->with_latest_event( $job, new Job_Repository() ) )
			: new \WP_Error( 'wp_autoplugin_job_not_found', __( 'Job not found.', 'wp-autoplugin' ), [ 'status' => 404 ] );
	}

	/**
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function job_events( \WP_REST_Request $request ) {
		$jobs = new Job_Repository();
		$job  = $jobs->find( (int) $request['id'] );
		if ( ! $job || ! $this->workspace_for_current_user( (int) $job['workspace_id'] ) ) {
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
		if ( ! $job || ! $this->workspace_for_current_user( (int) $job['workspace_id'] ) ) {
			return new \WP_Error( 'wp_autoplugin_job_not_found', __( 'Job not found.', 'wp-autoplugin' ), [ 'status' => 404 ] );
		}
		if ( in_array( $job['status'], [ 'completed', 'failed', 'cancelled' ], true ) ) {
			return new \WP_Error( 'wp_autoplugin_job_finished', __( 'A finished job cannot be cancelled.', 'wp-autoplugin' ), [ 'status' => 409 ] );
		}

		$jobs->request_cancel( $job['id'] );

		return rest_ensure_response( $jobs->find( $job['id'] ) );
	}

	/**
	 * Save a human-edited Plan and queue an immutable successor file map.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function update_plan( \WP_REST_Request $request ) {
		$jobs = new Job_Repository();
		$job  = $jobs->find( (int) $request['id'] );
		if ( ! $job || ! $this->workspace_for_current_user( (int) $job['workspace_id'] ) ) {
			return new \WP_Error( 'wp_autoplugin_job_not_found', __( 'Job not found.', 'wp-autoplugin' ), [ 'status' => 404 ] );
		}

		$successor = $jobs->create_plan_successor( $job, (string) $request['content'], get_current_user_id() );
		if ( ! $successor ) {
			return new \WP_Error( 'wp_autoplugin_plan_not_editable', __( 'Only completed plan artifacts can be edited.', 'wp-autoplugin' ), [ 'status' => 409 ] );
		}
		$regeneration = null;
		try {
			$regeneration = $jobs->create(
				(int) $successor['workspace_id'],
				'plan_structure',
				[ 'artifact_job_id' => (int) $successor['id'] ],
				get_current_user_id()
			);
			$runner = ( new Queue() )->dispatch( (int) $regeneration['id'] );
			$jobs->update( (int) $regeneration['id'], [ 'runner' => $runner ] );
			$regeneration = $jobs->find( (int) $regeneration['id'] );
		} catch ( \Throwable $error ) {
			if ( $regeneration ) {
				$jobs->update(
					(int) $regeneration['id'],
					[
						'status'        => 'failed',
						'error_message' => $error->getMessage(),
						'finished_at'   => current_time( 'mysql', true ),
					]
				);
				$jobs->event( (int) $regeneration['id'], 'failed', $error->getMessage(), [], 'error' );
				$regeneration = $jobs->find( (int) $regeneration['id'] );
			}
		}

		return new \WP_REST_Response(
			[
				'artifact'         => $successor,
				'regeneration_job' => $regeneration,
			],
			$regeneration && 'queued' === $regeneration['status'] ? 202 : 200
		);
	}

	/**
	 * Validate and normalize a shared stage-conversation payload.
	 *
	 * @param array<string, mixed> $payload Raw REST payload.
	 * @return array<string, mixed>|\WP_Error
	 */
	private function conversation_payload( array $payload, int $workspace_id, Job_Repository $jobs ) {
		$stage   = sanitize_key( (string) ( $payload['stage'] ?? '' ) );
		$message = sanitize_textarea_field( (string) ( $payload['message'] ?? '' ) );
		$parent  = absint( $payload['artifact_job_id'] ?? 0 );

		if ( ! in_array( $stage, self::CONVERSATION_STAGES, true ) ) {
			return new \WP_Error( 'wp_autoplugin_conversation_stage_unavailable', __( 'Follow-up messages are currently available for Plan and Explain.', 'wp-autoplugin' ), [ 'status' => 409 ] );
		}
		if ( '' === $message ) {
			return new \WP_Error( 'wp_autoplugin_conversation_message_required', __( 'A follow-up message is required.', 'wp-autoplugin' ), [ 'status' => 400 ] );
		}

		if ( $parent ) {
			$artifact = $jobs->find( $parent );
			if ( ! $artifact || $workspace_id !== (int) $artifact['workspace_id'] ) {
				return new \WP_Error( 'wp_autoplugin_conversation_artifact_not_found', __( 'The selected conversation artifact is not available in this workspace.', 'wp-autoplugin' ), [ 'status' => 404 ] );
			}
			if ( 'plan' === $stage && ! $jobs->is_plan_artifact( $artifact ) ) {
				return new \WP_Error( 'wp_autoplugin_conversation_artifact_invalid', __( 'Plan follow-ups must reply to a completed Plan artifact.', 'wp-autoplugin' ), [ 'status' => 409 ] );
			}
		}

		return [
			'stage'           => $stage,
			'message'         => $message,
			'artifact_job_id' => $parent,
		];
	}

	/**
	 * @return array<string, mixed>|null
	 */
	private function workspace_for_current_user( int $workspace_id ): ?array {
		$workspace = ( new Workspace_Repository() )->find( $workspace_id );

		return $workspace && (int) $workspace['created_by'] === get_current_user_id()
			? $workspace
			: null;
	}

	/** @param array<string, mixed> $job @return array<string, mixed> */
	private function with_latest_event( array $job, Job_Repository $jobs ): array {
		$latest = $jobs->latest_event( (int) $job['id'] );
		$job['latest_event'] = $latest ? [ 'event' => $latest['event'], 'message' => $latest['message'], 'level' => $latest['level'], 'sequence' => $latest['sequence'] ] : null;
		return $job;
	}

}
