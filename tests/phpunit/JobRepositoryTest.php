<?php

use WP_Autoplugin\V2\Infrastructure\Database\Job_Repository;

/** Durable job state, locking, cancellation, and event coverage. */
final class JobRepositoryTest extends WP_Autoplugin_Integration_Test_Case {
	public function test_jobs_hydrate_in_creation_order_and_events_are_append_only(): void {
		$project = $this->create_test_project();
		$jobs    = new Job_Repository();
		$first   = $jobs->create( (int) $project['id'], 'plan', [ 'message' => 'First' ], $this->admin_id );
		$second  = $jobs->create( (int) $project['id'], 'explain', [ 'message' => 'Second' ], $this->admin_id );
		$jobs->event( (int) $first['id'], 'Provider Retry!', 'Retrying.', [ 'attempt' => 2 ], 'warning' );

		$history = $jobs->list_for_workspace( (int) $project['id'] );
		$events  = $jobs->events( (int) $first['id'] );

		$this->assertSame( [ (int) $first['id'], (int) $second['id'] ], array_column( $history, 'id' ) );
		$this->assertSame( 'First', $history[0]['payload']['message'] );
		$this->assertSame( [ 1, 2 ], array_column( $events, 'sequence' ) );
		$this->assertSame( 'providerretry', $events[1]['event'] );
		$this->assertSame( [ 'attempt' => 2 ], $events[1]['context'] );
		$this->assertSame( [ 2 ], array_column( $jobs->events( (int) $first['id'], 1 ), 'sequence' ) );
		$this->assertSame( 2, $jobs->latest_event( (int) $first['id'] )['sequence'] );
	}

	public function test_artifact_work_is_mutually_exclusive_but_plan_work_remains_available(): void {
		$project = $this->create_test_project();
		$jobs    = new Job_Repository();
		$code    = $jobs->create( (int) $project['id'], 'code', [], $this->admin_id );

		$this->assertTrue( $jobs->has_active_code( (int) $project['id'] ) );
		$this->assertTrue( $jobs->has_active_artifact_work( (int) $project['id'] ) );
		$this->assertSame( 'queued', $jobs->create( (int) $project['id'], 'plan', [], $this->admin_id )['status'] );

		try {
			$jobs->create( (int) $project['id'], 'review', [], $this->admin_id );
			$this->fail( 'Concurrent artifact work should be rejected.' );
		} catch ( RuntimeException $error ) {
			$this->assertSame( 409, $error->getCode() );
		}

		$jobs->update( (int) $code['id'], [ 'status' => 'completed' ] );
		$this->assertSame( 'queued', $jobs->create( (int) $project['id'], 'review', [], $this->admin_id )['status'] );
	}

	public function test_queued_and_running_cancellation_are_repeatable_without_terminal_regression(): void {
		$project = $this->create_test_project();
		$jobs    = new Job_Repository();
		$queued  = $jobs->create( (int) $project['id'], 'plan', [], $this->admin_id );

		$this->assertTrue( $jobs->request_cancel( (int) $queued['id'] ) );
		$this->assertSame( 'cancelled', $jobs->find( (int) $queued['id'] )['status'] );
		$this->assertTrue( $jobs->request_cancel( (int) $queued['id'] ) );
		$this->assertSame( 'cancelled', $jobs->find( (int) $queued['id'] )['status'] );

		$running = $jobs->create( (int) $project['id'], 'explain', [], $this->admin_id );
		$jobs->update( (int) $running['id'], [ 'status' => 'running' ] );
		$this->assertTrue( $jobs->request_cancel( (int) $running['id'] ) );
		$this->assertSame( 1, $jobs->find( (int) $running['id'] )['cancel_requested'] );
		$this->assertSame( 'running', $jobs->find( (int) $running['id'] )['status'] );
	}

	public function test_job_creation_rejects_a_missing_project_without_leaving_a_row(): void {
		$jobs = new Job_Repository();

		try {
			$jobs->create( 987654321, 'plan', [], $this->admin_id );
			$this->fail( 'A missing project must reject job creation.' );
		} catch ( RuntimeException $error ) {
			$this->assertSame( 404, $error->getCode() );
		}

		$this->assertNull( $jobs->find( 987654321 ) );
	}
}
