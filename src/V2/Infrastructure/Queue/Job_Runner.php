<?php

namespace WP_Autoplugin\V2\Infrastructure\Queue;

use WP_Autoplugin\V2\Domain\AI\Agent_Task;
use WP_Autoplugin\V2\Infrastructure\Database\Job_Repository;
use WP_Autoplugin\V2\Infrastructure\Database\Agent_Run_Repository;
use WP_Autoplugin\V2\Infrastructure\Database\Code_Run_Repository;
use WP_Autoplugin\V2\Infrastructure\Database\Workspace_Repository;

/**
 * Executes one bounded job and persists every terminal state.
 */
final class Job_Runner {
	public function register(): void {
		add_action( Queue::HOOK, [ $this, 'run' ], 10, 2 );
	}

	public function run( int $job_id, int $generation = 0 ): void {
		$jobs       = new Job_Repository();
		$workspaces = new Workspace_Repository();
		$job        = $jobs->find( $job_id );
		$workspace   = $job ? $workspaces->find( (int) $job['workspace_id'] ) : null;
		$is_agent_job = $job && $workspace && Agent_Task::uses_source_tools( $job, $workspace );
		$is_resumable = $is_agent_job || ( $job && Job_Repository::is_code_work( $job ) );

		if ( ! $job || ! in_array( $job['status'], $is_resumable ? [ 'queued', 'running', 'retrying' ] : [ 'queued', 'retrying' ], true ) ) {
			return;
		}

		if ( $job['cancel_requested'] ) {
			( new Agent_Run_Repository() )->terminate_by_job( $job_id, 'cancelled' );
			( new Code_Run_Repository() )->terminate_by_job( $job_id, 'cancelled' );
			$jobs->update( $job_id, [ 'status' => 'cancelled', 'finished_at' => current_time( 'mysql', true ) ] );
			$jobs->event( $job_id, 'cancelled', __( 'Job cancelled before execution.', 'wp-autoplugin' ) );
			return;
		}

		if ( 'queued' === $job['status'] ) {
			$jobs->update( $job_id, [ 'status' => 'running', 'progress' => 5, 'started_at' => current_time( 'mysql', true ) ] );
			$jobs->event( $job_id, 'started', __( 'Background processing started.', 'wp-autoplugin' ) );
		} else {
			$jobs->update( $job_id, [ 'status' => 'running' ] );
		}

		try {
			/**
			 * Execute a v2 job through an orchestration adapter.
			 *
			 * Adapters must return a redacted array result or WP_Error. They may not write
			 * target files; generated changes belong in staged revision records.
			 *
			 * @param array|null $result Job result.
			 * @param array      $job    Persisted job data.
			 */
			$result = apply_filters( 'wp_autoplugin_v2_execute_job', null, $job, $generation );
			if ( is_wp_error( $result ) ) {
				throw new \RuntimeException( $result->get_error_message() );
			}
			if ( ! is_array( $result ) ) {
				throw new \RuntimeException( __( 'No v2 orchestration adapter is registered for this task.', 'wp-autoplugin' ) );
			}
			if ( ! empty( $result['_continuation'] ) ) {
				return;
			}

			$latest = $jobs->find( $job_id );
			if ( $latest && $latest['cancel_requested'] && empty( $result['revision_id'] ) ) {
				( new Agent_Run_Repository() )->terminate_by_job( $job_id, 'cancelled' );
				( new Code_Run_Repository() )->terminate_by_job( $job_id, 'cancelled' );
				$jobs->update( $job_id, [ 'status' => 'cancelled', 'finished_at' => current_time( 'mysql', true ) ] );
				$jobs->event( $job_id, 'cancelled', __( 'Job cancelled.', 'wp-autoplugin' ) );
				return;
			}

			$jobs->update(
				$job_id,
				[
					'status'      => 'completed',
					'progress'    => 100,
					'result'      => $result,
					'finished_at' => current_time( 'mysql', true ),
				]
			);
			$plugin_name = is_string( $result['structured']['plugin_name'] ?? null )
				? trim( $result['structured']['plugin_name'] )
				: '';
			if (
				$workspace
				&& 'new_plugin' === ( $workspace['target_kind'] ?? '' )
				&& 'create' === ( $workspace['operation'] ?? '' )
				&& 'artifact' === ( $result['outcome'] ?? '' )
				&& '' !== $plugin_name
			) {
				$workspaces->rename_project( (int) $workspace['id'], $plugin_name );
			}
			$jobs->event( $job_id, 'completed', __( 'Job completed.', 'wp-autoplugin' ) );
		} catch ( \Throwable $error ) {
			( new Agent_Run_Repository() )->terminate_by_job( $job_id, 'failed' );
			( new Code_Run_Repository() )->terminate_by_job( $job_id, 'failed', $error->getMessage() );
			$jobs->update(
				$job_id,
				[
					'status'        => 'failed',
					'error_message' => $error->getMessage(),
					'finished_at'   => current_time( 'mysql', true ),
				]
			);
			$jobs->event( $job_id, 'failed', $error->getMessage(), [], 'error' );
		}
	}
}
