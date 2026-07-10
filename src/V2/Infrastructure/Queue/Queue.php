<?php

namespace WP_Autoplugin\V2\Infrastructure\Queue;

/**
 * Enqueues short job-runner callbacks outside the originating request.
 */
final class Queue {
	public const HOOK = 'wp_autoplugin_v2_run_job';
	public const GROUP = 'wp-autoplugin';
	private const STALE_AFTER = 5 * MINUTE_IN_SECONDS;

	/**
	 * Enqueue a job and return the selected runner name.
	 */
	public function dispatch( int $job_id ): string {
		if ( ! $this->is_initialized() ) {
			throw new \RuntimeException( __( 'Action Scheduler is not initialized.', 'wp-autoplugin' ) );
		}

		$action_id = as_enqueue_async_action( self::HOOK, [ $job_id ], self::GROUP );
		if ( $action_id <= 0 ) {
			throw new \RuntimeException( __( 'Action Scheduler could not enqueue the job.', 'wp-autoplugin' ) );
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
