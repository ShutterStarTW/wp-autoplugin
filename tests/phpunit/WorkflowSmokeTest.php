<?php

use WP_Autoplugin\V2\Domain\Revision\Code_Validator;
use WP_Autoplugin\V2\Infrastructure\Database\Code_Run_Repository;
use WP_Autoplugin\V2\Infrastructure\Database\Job_Repository;
use WP_Autoplugin\V2\Infrastructure\Database\Plan_Repository;
use WP_Autoplugin\V2\Infrastructure\Database\Project_Repository;
use WP_Autoplugin\V2\Infrastructure\Database\Review_Repository;
use WP_Autoplugin\V2\Infrastructure\Database\Revision_Repository;
use WP_Autoplugin\V2\Infrastructure\Database\Usage_Repository;
use WP_Autoplugin\V2\Infrastructure\Queue\Job_Runner;

/** Offline end-to-end smoke coverage for durable Plan → Code → Review work. */
final class WorkflowSmokeTest extends WP_Autoplugin_Integration_Test_Case {
	private bool $fail_code = false;

	public function set_up(): void {
		parent::set_up();
		add_filter( 'wp_autoplugin_v2_execute_job', [ $this, 'fake_provider' ], 1, 3 );
	}

	public function tear_down(): void {
		remove_filter( 'wp_autoplugin_v2_execute_job', [ $this, 'fake_provider' ], 1 );
		parent::tear_down();
	}

	public function test_plan_code_and_review_complete_with_durable_artifacts_events_and_usage(): void {
		$project = $this->create_test_project( null, 'create', 'Create the workflow fixture.' );
		$jobs    = new Job_Repository();
		$runner  = new Job_Runner();

		$plan_job = $jobs->create( (int) $project['id'], 'plan', [], $this->admin_id );
		$runner->run( (int) $plan_job['id'] );
		$plan_job = $jobs->find( (int) $plan_job['id'] );
		$plan     = ( new Plan_Repository() )->latest_ready( (int) $project['id'] );
		$this->assertSame( 'completed', $plan_job['status'] );
		$this->assertNotNull( $plan );
		$this->assertSame( (int) $plan['id'], (int) $plan_job['result']['plan_id'] );
		$this->assertSame( 'Workflow Fixture', ( new Project_Repository() )->find( (int) $project['id'] )['project_name'] );

		$code_job = $jobs->create(
			(int) $project['id'],
			'code',
			[
				'plan_id'                    => (int) $plan['id'],
				'expected_latest_revision_id' => null,
				'mode'                       => 'generate',
			],
			$this->admin_id
		);
		$runner->run( (int) $code_job['id'] );
		$code_job = $jobs->find( (int) $code_job['id'] );
		$revision = ( new Revision_Repository() )->find( (int) $code_job['result']['revision_id'] );
		$this->assertSame( 'completed', $code_job['status'] );
		$this->assertSame( [ 'assets/app.js', 'workflow-fixture.php' ], array_column( $revision['files'], 'path' ) );

		$review_job = $jobs->create(
			(int) $project['id'],
			'review',
			[
				'revision_id' => (int) $revision['id'],
				'mode'        => 'initial',
			],
			$this->admin_id
		);
		$runner->run( (int) $review_job['id'] );
		$review_job = $jobs->find( (int) $review_job['id'] );
		$report     = ( new Review_Repository() )->find( (int) $review_job['result']['report_id'] );

		$this->assertSame( 'completed', $review_job['status'] );
		$this->assertSame( 'all_clear', $report['verdict'] );
		$this->assertSame( [ 'queued', 'started', 'completed' ], array_column( $jobs->events( (int) $review_job['id'] ), 'event' ) );
		$usage = ( new Usage_Repository() )->summary_for_project( (int) $project['id'] );
		$this->assertSame( 30, $usage['total']['input_tokens'] );
		$this->assertSame( 15, $usage['total']['output_tokens'] );
		$this->assertCount( 3, $usage['executed_jobs'] );
	}

	public function test_provider_failure_creates_no_partial_revision_and_releases_the_lock(): void {
		$project = $this->create_test_project();
		$plan    = $this->create_ready_plan(
			(int) $project['id'],
			[
				[
					'path'        => 'workflow-fixture.php',
					'type'        => 'php',
					'description' => 'Main plugin file.',
					'action'      => 'add',
				],
			],
			'workflow-fixture.php',
			'Workflow Fixture'
		);
		$jobs          = new Job_Repository();
		$code_job      = $jobs->create( (int) $project['id'], 'code', [ 'plan_id' => (int) $plan['plan']['id'] ], $this->admin_id );
		$this->fail_code = true;

		( new Job_Runner() )->run( (int) $code_job['id'] );

		$failed = $jobs->find( (int) $code_job['id'] );
		$this->assertSame( 'failed', $failed['status'] );
		$this->assertStringContainsString( 'Synthetic provider failure', $failed['error_message'] );
		$this->assertNull( ( new Revision_Repository() )->latest_id( (int) $project['id'] ) );
		$this->assertNull( ( new Code_Run_Repository() )->find_by_job( (int) $code_job['id'] ) );
		$this->assertFalse( $jobs->has_active_artifact_work( (int) $project['id'] ) );
		$this->assertSame( 'queued', $jobs->create( (int) $project['id'], 'review', [], $this->admin_id )['status'] );
	}

	/**
	 * Deterministic in-process replacement for provider-facing orchestration.
	 *
	 * @param array<string, mixed>|null $result
	 * @param array<string, mixed>      $job
	 * @return array<string, mixed>|\WP_Error|null
	 */
	public function fake_provider( $result, array $job, int $generation = 0 ) {
		unset( $generation );
		if ( null !== $result ) {
			return $result;
		}
		if ( $this->fail_code && 'code' === $job['task'] ) {
			return new WP_Error( 'fixture_provider_failure', 'Synthetic provider failure.' );
		}

		( new Usage_Repository() )->record(
			(int) $job['id'],
			'fixture',
			'offline',
			(string) $job['task'],
			[ 'input_tokens' => 10, 'output_tokens' => 5 ]
		);

		if ( 'plan' === $job['task'] ) {
			return [
				'outcome'    => 'artifact',
				'content'    => 'Build the offline workflow fixture.',
				'structured' => [
					'plugin_name'      => 'Workflow Fixture',
					'main_file'        => 'workflow-fixture.php',
					'project_structure' => [
						'directories' => [ 'assets' ],
						'files'       => [
							[
								'path'        => 'workflow-fixture.php',
								'type'        => 'php',
								'description' => 'Main plugin file.',
								'action'      => 'add',
							],
							[
								'path'        => 'assets/app.js',
								'type'        => 'js',
								'description' => 'Browser behavior.',
								'action'      => 'add',
							],
						],
					],
				],
				'provider'   => 'fixture',
				'model'      => 'offline',
			];
		}

		if ( 'code' === $job['task'] ) {
			$workspace = ( new Project_Repository() )->find( (int) $job['project_id'] );
			$plan      = ( new Plan_Repository() )->find( (int) $job['payload']['plan_id'] );
			$manifest  = ( new Code_Validator() )->plan( $plan['structured'], $workspace );
			if ( is_wp_error( $manifest ) ) {
				return $manifest;
			}
			$runs = new Code_Run_Repository();
			$run  = $runs->create(
				(int) $job['id'],
				(int) $plan['id'],
				null,
				'fixture',
				'offline',
				'',
				'workflow-smoke',
				1,
				$manifest['files'],
				'generate',
				$manifest
			);
			$contents = [
				'assets/app.js'       => 'window.workflowFixture = true;',
				'workflow-fixture.php' => "<?php\n/**\n * Plugin Name: Workflow Fixture\n * Author: Test Suite\n */\n",
			];
			foreach ( $runs->files( (int) $run['id'] ) as $file ) {
				$run   = $runs->find_by_job( (int) $job['id'] );
				$token = wp_generate_password( 32, false, false );
				$runs->acquire( (int) $run['id'], (int) $run['generation'], $token );
				$runs->mark_generating( (int) $run['id'], (int) $file['sequence'], $token );
				$runs->complete_file( (int) $run['id'], (int) $file['sequence'], $token, $contents[ $file['path'] ], [] );
			}
			$revision = ( new Revision_Repository() )->stage_code_run(
				$runs->find_by_job( (int) $job['id'] ),
				$manifest,
				(int) $job['project_id'],
				(int) $job['created_by'],
				null
			);
			return is_wp_error( $revision )
				? $revision
				: [ 'outcome' => 'revision', 'revision_id' => (int) $revision['id'] ];
		}

		if ( 'review' === $job['task'] ) {
			$revision = ( new Revision_Repository() )->find( (int) $job['payload']['revision_id'] );
			$report   = ( new Review_Repository() )->create_report(
				$job,
				$revision,
				[
					'outcome'        => 'report',
					'summary'        => 'No actionable issues.',
					'tests'          => [ 'Activate the fixture.' ],
					'prior_findings' => [],
					'new_findings'   => [],
				],
				[
					'provider'       => 'fixture',
					'model'          => 'offline',
					'effort'         => '',
					'prompt_slug'    => 'workflow-smoke',
					'prompt_version' => 1,
				]
			);
			return [
				'outcome'   => 'report',
				'report_id' => (int) $report['id'],
				'verdict'   => $report['verdict'],
			];
		}

		return $result;
	}
}
