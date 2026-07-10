<?php

namespace WP_Autoplugin\V2\Infrastructure\Queue;

/**
 * Enqueues short job-runner callbacks outside the originating request.
 */
final class Queue {
	public const HOOK = 'wp_autoplugin_v2_run_job';

	/**
	 * Enqueue a job and return the selected runner name.
	 */
	public function dispatch( int $job_id ): string {
		if ( function_exists( 'as_enqueue_async_action' ) ) {
			as_enqueue_async_action( self::HOOK, [ $job_id ], 'wp-autoplugin' );

			return 'action-scheduler';
		}

		wp_schedule_single_event( time(), self::HOOK, [ $job_id ] );

		return 'wp-cron';
	}

	/**
	 * Describe background processing health without making network requests.
	 *
	 * @return array<string, bool|string>
	 */
	public function status(): array {
		$action_scheduler = function_exists( 'as_enqueue_async_action' );
		$wp_cron_disabled = defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON;

		return [
			'runner'   => $action_scheduler ? 'action-scheduler' : 'wp-cron',
			'degraded' => ! $action_scheduler || $wp_cron_disabled,
			'message'  => $action_scheduler
				? __( 'Action Scheduler is available.', 'wp-autoplugin' )
				: __( 'Action Scheduler is unavailable; jobs use WP-Cron fallback. Configure a real server cron for reliable processing.', 'wp-autoplugin' ),
		];
	}
}
