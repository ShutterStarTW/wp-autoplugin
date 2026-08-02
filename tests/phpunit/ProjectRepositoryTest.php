<?php

use WP_Autoplugin\V2\Infrastructure\Database\Job_Repository;
use WP_Autoplugin\V2\Infrastructure\Database\Project_Repository;

/** Durable project lifecycle, ownership, search, and activity coverage. */
final class ProjectRepositoryTest extends WP_Autoplugin_Integration_Test_Case {
	public function test_create_find_and_open_listing_are_owner_isolated(): void {
		$other_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		$mine     = $this->create_test_project( null, 'create', 'Build my searchable calendar plugin.' );
		$other    = $this->create_test_project( null, 'create', 'Build another plugin.', $other_id );
		$projects = new Project_Repository();

		$this->assertSame( 'Test Plugin', $mine['project_name'] );
		$this->assertSame( 'new_plugin', $mine['target_kind'] );
		$this->assertSame( 'new_plugin', $mine['target_metadata']['kind'] );
		$this->assertSame( [ (int) $mine['id'] ], array_column( $projects->list_open( $this->admin_id ), 'id' ) );
		$this->assertSame( [ (int) $other['id'] ], array_column( $projects->list_open( $other_id ), 'id' ) );
	}

	public function test_close_and_reopen_are_non_destructive_and_owner_checked(): void {
		$project  = $this->create_test_project();
		$projects = new Project_Repository();
		$jobs     = new Job_Repository();
		$job      = $jobs->create( (int) $project['id'], 'plan', [], $this->admin_id );

		$this->assertFalse( $projects->close( (int) $project['id'], self::factory()->user->create( [ 'role' => 'administrator' ] ) ) );
		$this->assertTrue( $projects->close( (int) $project['id'], $this->admin_id ) );
		$this->assertSame( [], $projects->list_open( $this->admin_id ) );
		$this->assertNotNull( $jobs->find( (int) $job['id'] ) );
		$this->assertNull( $projects->reopen( (int) $project['id'], self::factory()->user->create( [ 'role' => 'administrator' ] ) ) );

		$reopened = $projects->reopen( (int) $project['id'], $this->admin_id );
		$this->assertSame( (int) $project['id'], (int) $reopened['id'] );
		$this->assertSame( 0, $reopened['is_closed'] );
		$this->assertNull( $reopened['closed_at'] );
		$this->assertNotNull( $jobs->find( (int) $job['id'] ) );
	}

	public function test_open_tabs_can_be_persistently_reordered_per_user(): void {
		$first    = $this->create_test_project( null, 'create', 'Build the first plugin.' );
		$second   = $this->create_test_project( null, 'create', 'Build the second plugin.' );
		$third    = $this->create_test_project( null, 'create', 'Build the third plugin.' );
		$projects = new Project_Repository();
		$order    = [ (int) $first['id'], (int) $third['id'], (int) $second['id'] ];

		$this->assertSame( $order, array_column( $projects->reorder_open( $order, $this->admin_id ), 'id' ) );
		$this->assertSame( $order, array_column( $projects->list_open( $this->admin_id ), 'id' ) );

		$other_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		$this->expectException( InvalidArgumentException::class );
		$projects->reorder_open( $order, $other_id );
	}

	public function test_search_pagination_and_activity_summaries_use_durable_records(): void {
		$first  = $this->create_test_project( null, 'create', 'Build the Alpha calendar.' );
		$second = $this->create_test_project( null, 'create', 'Build the Beta importer.' );
		$jobs   = new Job_Repository();
		$plan   = $jobs->create( (int) $first['id'], 'plan', [], $this->admin_id );
		$jobs->update( (int) $plan['id'], [ 'status' => 'completed' ] );
		$follow_up = $jobs->create( (int) $first['id'], 'conversation', [ 'stage' => 'plan' ], $this->admin_id );
		$jobs->update( (int) $follow_up['id'], [ 'status' => 'failed' ] );
		$jobs->event( (int) $follow_up['id'], 'plan_retry', 'Retrying.' );

		$result = ( new Project_Repository() )->list_projects( $this->admin_id, 'Alpha', 1, 1 );

		$this->assertSame( 1, $result['total'] );
		$this->assertSame( 1, $result['total_pages'] );
		$this->assertSame( (int) $first['id'], (int) $result['items'][0]['id'] );
		$this->assertSame( 2, $result['items'][0]['activity_summary']['total_jobs'] );
		$this->assertSame( 1, $result['items'][0]['activity_summary']['follow_up_jobs'] );
		$this->assertSame( 1, $result['items'][0]['activity_summary']['retry_count'] );
		$this->assertSame( 'complete', $result['items'][0]['activity_summary']['stages']['plan'] );
		$this->assertNotSame( (int) $second['id'], (int) $result['items'][0]['id'] );
	}
}
