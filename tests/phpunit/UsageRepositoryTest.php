<?php

use WP_Autoplugin\V2\Infrastructure\Database\Installer;
use WP_Autoplugin\V2\Infrastructure\Database\Job_Repository;
use WP_Autoplugin\V2\Infrastructure\Database\Usage_Repository;
use WP_Autoplugin\V2\Infrastructure\Database\Workspace_Repository;
use WP_Autoplugin\V2\Rest\Routes;

/** Verifies durable project usage aggregation and owner authorization. */
final class UsageRepositoryTest extends WP_UnitTestCase {
	private int $workspace_id = 0;
	private int $project_id = 0;

	public function tear_down(): void {
		global $wpdb;
		if ( $this->workspace_id ) {
			$job_ids = array_map( 'intval', $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM ' . Installer::table( 'jobs' ) . ' WHERE workspace_id = %d', $this->workspace_id ) ) );
			foreach ( $job_ids as $job_id ) {
				$wpdb->delete( Installer::table( 'usage' ), [ 'job_id' => $job_id ] );
				$wpdb->delete( Installer::table( 'job_events' ), [ 'job_id' => $job_id ] );
			}
			$wpdb->delete( Installer::table( 'jobs' ), [ 'workspace_id' => $this->workspace_id ] );
			$wpdb->delete( Installer::table( 'workspaces' ), [ 'id' => $this->workspace_id ] );
		}
		if ( $this->project_id ) {
			$wpdb->delete( Installer::table( 'projects' ), [ 'id' => $this->project_id ] );
		}
		parent::tear_down();
	}

	public function test_aggregates_provider_calls_by_model_and_executed_job(): void {
		Installer::activate();
		$user_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		$created = ( new Workspace_Repository() )->create(
			[
				'kind' => 'new_plugin',
				'ref'  => 'usage-summary-' . wp_generate_uuid4(),
				'name' => 'Usage summary test',
			],
			'create',
			'Build a test plugin.',
			$user_id
		);
		$this->workspace_id = (int) $created['workspace_id'];
		$this->project_id   = (int) $created['project_id'];

		$jobs       = new Job_Repository();
		$repository = new Usage_Repository();
		$plan       = $jobs->create( $this->workspace_id, 'plan', [], $user_id );
		$jobs->update( (int) $plan['id'], [ 'status' => 'completed', 'progress' => 100, 'finished_at' => current_time( 'mysql', true ) ] );
		$repository->record( (int) $plan['id'], 'openai', 'gpt-test', 'plan', [ 'input_tokens' => 100, 'output_tokens' => 20 ] );
		$repository->record( (int) $plan['id'], 'openai', 'gpt-test', 'plan', [ 'input_tokens' => 25, 'output_tokens' => 5 ] );

		$code = $jobs->create( $this->workspace_id, 'code', [ 'mode' => 'generate' ], $user_id );
		$jobs->update( (int) $code['id'], [ 'status' => 'completed', 'progress' => 100, 'finished_at' => current_time( 'mysql', true ) ] );
		$repository->record( (int) $code['id'], 'anthropic', 'claude-test', 'code', [ 'input_tokens' => 300, 'output_tokens' => 60 ] );

		$review = $jobs->create( $this->workspace_id, 'review', [], $user_id );
		$jobs->update( (int) $review['id'], [ 'status' => 'failed', 'progress' => 100, 'finished_at' => current_time( 'mysql', true ) ] );
		$repository->record( (int) $review['id'], 'openai', 'gpt-test', 'review', [ 'input_tokens' => 50, 'output_tokens' => 10 ] );

		$summary = $repository->summary_for_project( $this->project_id );
		$this->assertSame( [ 'input_tokens' => 475, 'output_tokens' => 95 ], $summary['total'] );
		$this->assertCount( 2, $summary['models'] );
		$this->assertSame( 'claude-test', $summary['models'][0]['model'] );
		$this->assertSame( 1, $summary['models'][0]['job_count'] );
		$this->assertSame( 'gpt-test', $summary['models'][1]['model'] );
		$this->assertSame( 2, $summary['models'][1]['job_count'] );
		$this->assertSame( 175, $summary['models'][1]['input_tokens'] );
		$this->assertSame( 35, $summary['models'][1]['output_tokens'] );

		$this->assertCount( 3, $summary['executed_jobs'] );
		$this->assertSame( (int) $review['id'], $summary['executed_jobs'][0]['id'] );
		$this->assertSame( 'review', $summary['executed_jobs'][0]['stage'] );
		$this->assertSame( 'failed', $summary['executed_jobs'][0]['status'] );
		$this->assertSame( 125, $summary['executed_jobs'][2]['input_tokens'] );
		$this->assertSame( 25, $summary['executed_jobs'][2]['output_tokens'] );

		wp_set_current_user( $user_id );
		$request = new WP_REST_Request( 'GET', '/wp-autoplugin/v2/workspaces/' . $this->workspace_id . '/usage' );
		$request->set_param( 'id', $this->workspace_id );
		$response = ( new Routes() )->workspace_usage( $request );
		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertSame( $summary, $response->get_data() );

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$denied = ( new Routes() )->workspace_usage( $request );
		$this->assertWPError( $denied );
		$this->assertSame( 404, $denied->get_error_data()['status'] );
	}
}
