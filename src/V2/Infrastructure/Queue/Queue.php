<?php

namespace WP_Autoplugin\V2\Infrastructure\Queue;

use WP_Autoplugin\V2\Infrastructure\Database\Agent_Run_Repository;
use WP_Autoplugin\V2\Infrastructure\Database\Job_Repository;

/**
 * Enqueues short job-runner callbacks outside the originating request.
 */
final class Queue {
	public const HOOK = 'wp_autoplugin_v2_run_job';
	public const GROUP = 'wp-autoplugin';
	private const RECOVERY_HOOK = 'wp_autoplugin_v2_recover_agent_jobs';
	private const STALE_AFTER = 5 * MINUTE_IN_SECONDS;

	public function register(): void {
		add_action( self::RECOVERY_HOOK, [ $this, 'recover_agent_jobs' ] );
		add_action( 'action_scheduler_init', [ $this, 'ensure_recovery' ] );
	}

	public function ensure_recovery(): void {
		if ( ! as_has_scheduled_action( self::RECOVERY_HOOK, [], self::GROUP ) ) {
			as_schedule_recurring_action( time() + 5 * MINUTE_IN_SECONDS, 5 * MINUTE_IN_SECONDS, self::RECOVERY_HOOK, [], self::GROUP, true );
		}
	}

	public function recover_agent_jobs(): void {
		$jobs = new Job_Repository();
		foreach ( ( new Agent_Run_Repository() )->recoverable() as $run ) {
			$args = [ (int) $run['job_id'], (int) $run['generation'] ];
			if ( as_has_scheduled_action( self::HOOK, $args, self::GROUP ) ) {
				continue;
			}
			$this->dispatch( $args[0], $args[1], true );
			$jobs->event( $args[0], 'agent_recovered', __( 'Recovered a stalled source-agent continuation.', 'wp-autoplugin' ), [ 'generation' => $args[1] ], 'warning' );
		}
	}

	/**
	 * Enqueue a job and return the selected runner name.
	 */
	public function dispatch( int $job_id, int $generation = 0, bool $deduplicate = false ): string {
		if ( ! $this->is_initialized() ) {
			throw new \RuntimeException( __( 'Action Scheduler is not initialized.', 'wp-autoplugin' ) );
		}

		$args = [ $job_id, $generation ];
		if ( $deduplicate && as_has_scheduled_action( self::HOOK, $args, self::GROUP ) ) {
			return 'action-scheduler';
		}

		/*
		 * Do not use Action Scheduler's $unique flag here. Its DB store checks
		 * only hook and group, not arguments, so the currently running agent
		 * action would prevent its next generation from being enqueued.
		 * Generation leases make an occasional check/insert race harmless.
		 */
		$action_id = as_enqueue_async_action( self::HOOK, $args, self::GROUP, false );
		if ( $action_id <= 0 ) {
			throw new \RuntimeException( __( 'Action Scheduler could not enqueue the job.', 'wp-autoplugin' ) );
		}

		return 'action-scheduler';
	}

	public function schedule( int $job_id, int $generation, int $delay ): string {
		if ( ! $this->is_initialized() ) {
			throw new \RuntimeException( __( 'Action Scheduler is not initialized.', 'wp-autoplugin' ) );
		}
		$args = [ $job_id, $generation ];
		if ( as_has_scheduled_action( self::HOOK, $args, self::GROUP ) ) {
			return 'action-scheduler';
		}
		$action_id = as_schedule_single_action( time() + max( 1, $delay ), self::HOOK, $args, self::GROUP, false );
		if ( $action_id <= 0 ) {
			throw new \RuntimeException( __( 'Action Scheduler could not enqueue the retry.', 'wp-autoplugin' ) );
		}
		return 'action-scheduler';
	}

	/**
	 * Describe background processing health without making network requests.
	 *
	 * @return array<string, bool|int|string|null>
	 */
	public function status(): array {
		$available        = function_exists( 'as_enqueue_async_action' ) && class_exists( 'ActionScheduler', false );
		$initialized      = $this->is_initialized();
		$wp_cron_disabled = defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON;
		$pending          = 0;
		$stale            = 0;

		if ( $initialized ) {
			try {
				$store   = \ActionScheduler::store();
				$pending = (int) $store->query_actions(
					[
						'hook'   => self::HOOK,
						'group'  => self::GROUP,
						'status' => \ActionScheduler_Store::STATUS_PENDING,
					],
					'count'
				);
				$stale   = (int) $store->query_actions(
					[
						'hook'         => self::HOOK,
						'group'        => self::GROUP,
						'status'       => \ActionScheduler_Store::STATUS_PENDING,
						'date'         => as_get_datetime_object( time() - self::STALE_AFTER ),
						'date_compare' => '<=',
					],
					'count'
				);
			} catch ( \Throwable $error ) {
				$initialized = false;
			}
		}

		$degraded = ! $available || ! $initialized || $wp_cron_disabled || $stale > 0;
		if ( ! $available ) {
			$message = __( 'Action Scheduler is unavailable.', 'wp-autoplugin' );
		} elseif ( ! $initialized ) {
			$message = __( 'Action Scheduler is loaded but its data store is unavailable.', 'wp-autoplugin' );
		} elseif ( $stale > 0 ) {
			$message = sprintf(
				/* translators: %d: number of overdue background jobs. */
				_n( '%d background job has been pending for more than five minutes.', '%d background jobs have been pending for more than five minutes.', $stale, 'wp-autoplugin' ),
				$stale
			);
		} elseif ( $wp_cron_disabled ) {
			$message = __( 'Action Scheduler is ready, but WP-Cron is disabled. Configure a real server cron for recovery processing.', 'wp-autoplugin' );
		} else {
			$message = __( 'Action Scheduler is ready.', 'wp-autoplugin' );
		}

		return [
			'runner'           => 'action-scheduler',
			'available'        => $available,
			'initialized'      => $initialized,
			'version'          => $this->version(),
			'pending_actions'  => $pending,
			'stale_actions'    => $stale,
			'wp_cron_disabled' => $wp_cron_disabled,
			'degraded'         => $degraded,
			'message'          => $message,
		];
	}

	private function is_initialized(): bool {
		return class_exists( 'ActionScheduler', false ) && \ActionScheduler::is_initialized();
	}

	private function version(): ?string {
		if ( ! class_exists( 'ActionScheduler_Versions', false ) ) {
			return null;
		}

		$version = \ActionScheduler_Versions::instance()->latest_version();
		return is_string( $version ) ? $version : null;
	}
}
