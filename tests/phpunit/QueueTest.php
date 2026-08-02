<?php

use WP_Autoplugin\V2\Infrastructure\Database\Installer;
use WP_Autoplugin\V2\Infrastructure\Database\Job_Repository;
use WP_Autoplugin\V2\Infrastructure\Queue\Queue;

/** Action Scheduler dispatch, deduplication, status, and recovery coverage. */
final class QueueTest extends WP_Autoplugin_Integration_Test_Case {
	/** @var array<int, array{0:int,1:int}> */
	private array $scheduled_args = [];

	public function tear_down(): void {
		foreach ( $this->scheduled_args as $args ) {
			as_unschedule_all_actions( Queue::HOOK, $args, Queue::GROUP );
		}
		parent::tear_down();
	}

	public function test_status_reports_the_bundled_initialized_action_scheduler(): void {
		$status = ( new Queue() )->status();

		$this->assertSame( 'action-scheduler', $status['runner'] );
		$this->assertTrue( $status['available'] );
		$this->assertTrue( $status['initialized'] );
		$this->assertNotEmpty( $status['version'] );
		$this->assertIsInt( $status['pending_actions'] );
		$this->assertIsInt( $status['stale_actions'] );
	}

	public function test_dispatch_and_schedule_deduplicate_only_matching_job_generations(): void {
		$project = $this->create_test_project();
		$job     = ( new Job_Repository() )->create( (int) $project['id'], 'plan', [], $this->admin_id );
		$queue   = new Queue();
		$args    = [ (int) $job['id'], 0 ];
		$next    = [ (int) $job['id'], 1 ];
		$this->scheduled_args[] = $args;
		$this->scheduled_args[] = $next;

		$this->assertSame( 'action-scheduler', $queue->dispatch( $args[0], $args[1], true ) );
		$this->assertTrue( as_has_scheduled_action( Queue::HOOK, $args, Queue::GROUP ) );
		$this->assertSame( 1, $this->scheduled_count( $args ) );
		$this->assertSame( 'action-scheduler', $queue->dispatch( $args[0], $args[1], true ) );
		$this->assertSame( 1, $this->scheduled_count( $args ) );

		$this->assertSame( 'action-scheduler', $queue->schedule( $next[0], $next[1], 30 ) );
		$this->assertTrue( as_has_scheduled_action( Queue::HOOK, $next, Queue::GROUP ) );
		$this->assertSame( 1, $this->scheduled_count( $next ) );
	}

	public function test_abandoned_non_resumable_work_fails_and_releases_the_artifact_lock(): void {
		global $wpdb;

		$project = $this->create_test_project();
		$jobs    = new Job_Repository();
		$job     = $jobs->create( (int) $project['id'], 'package', [], $this->admin_id );
		$jobs->update( (int) $job['id'], [ 'status' => 'running' ] );
		$wpdb->update(
			Installer::table( 'jobs' ),
			[ 'updated_at' => gmdate( 'Y-m-d H:i:s', time() - 10 * MINUTE_IN_SECONDS ) ],
			[ 'id' => $job['id'] ]
		);

		$reconciled = ( new Queue() )->reconcile_abandoned_job( $jobs->find( (int) $job['id'] ), $project );

		$this->assertSame( 'failed', $reconciled['status'] );
		$this->assertNotEmpty( $reconciled['finished_at'] );
		$this->assertSame( 'failed', $jobs->latest_event( (int) $job['id'] )['event'] );
		$this->assertFalse( $jobs->has_active_artifact_work( (int) $project['id'] ) );
		$this->assertSame( 'queued', $jobs->create( (int) $project['id'], 'review', [], $this->admin_id )['status'] );
	}

	/** @param array{0:int,1:int} $args */
	private function scheduled_count( array $args ): int {
		return (int) ActionScheduler::store()->query_actions(
			[
				'hook'   => Queue::HOOK,
				'group'  => Queue::GROUP,
				'args'   => $args,
				'status' => ActionScheduler_Store::STATUS_PENDING,
			],
			'count'
		);
	}
}
