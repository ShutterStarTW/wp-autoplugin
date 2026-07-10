<?php

namespace WP_Autoplugin\V2\Infrastructure\Queue;

use WP_Autoplugin\V2\Infrastructure\Database\Job_Repository;

/**
 * Executes one bounded job and persists every terminal state.
 */
final class Job_Runner {
	public function register(): void {
		add_action( Queue::HOOK, [ $this, 'run' ] );
	}

	public function run( int $job_id ): void {
		$jobs = new Job_Repository();
		$job  = $jobs->find( $job_id );

		if ( ! $job || ! in_array( $job['status'], [ 'queued', 'retrying' ], true ) ) {
			return;
		}

		if ( $job['cancel_requested'] ) {
			$jobs->update( $job_id, [ 'status' => 'cancelled', 'finished_at' => current_time( 'mysql', true ) ] );
			$jobs->event( $job_id, 'cancelled', __( 'Job cancelled before execution.', 'wp-autoplugin' ) );
			return;
		}

		$jobs->update( $job_id, [ 'status' => 'running', 'progress' => 5, 'started_at' => current_time( 'mysql', true ) ] );
		$jobs->event( $job_id, 'started', __( 'Background processing started.', 'wp-autoplugin' ) );

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
			$result = apply_filters( 'wp_autoplugin_v2_execute_job', null, $job );
			if ( is_wp_error( $result ) ) {
				throw new \RuntimeException( $result->get_error_message() );
			}
			if ( ! is_array( $result ) ) {
				throw new \RuntimeException( __( 'No v2 orchestration adapter is registered for this task.', 'wp-autoplugin' ) );
			}

			$latest = $jobs->find( $job_id );
			if ( $latest && $latest['cancel_requested'] ) {
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
			$jobs->event( $job_id, 'completed', __( 'Job completed.', 'wp-autoplugin' ) );
		} catch ( \Throwable $error ) {
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
