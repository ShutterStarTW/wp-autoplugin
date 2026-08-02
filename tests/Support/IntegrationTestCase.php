<?php

use WP_Autoplugin\V2\Domain\Revision\Code_Validator;
use WP_Autoplugin\V2\Infrastructure\Database\Code_Run_Repository;
use WP_Autoplugin\V2\Infrastructure\Database\Installer;
use WP_Autoplugin\V2\Infrastructure\Database\Job_Repository;
use WP_Autoplugin\V2\Infrastructure\Database\Plan_Repository;
use WP_Autoplugin\V2\Infrastructure\Database\Project_Repository;
use WP_Autoplugin\V2\Infrastructure\Database\Revision_Repository;

/**
 * Shared fixture helpers for durable v2 integration tests.
 */
abstract class WP_Autoplugin_Integration_Test_Case extends WP_UnitTestCase {
	protected int $admin_id;

	/** @var array<int, int> Project owner keyed by project ID. */
	private array $test_projects = [];

	public function set_up(): void {
		parent::set_up();
		Installer::activate();
		$this->admin_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $this->admin_id );
	}

	public function tear_down(): void {
		global $wpdb;

		foreach ( array_reverse( $this->test_projects, true ) as $project_id => $owner_id ) {
			$wpdb->update(
				Installer::table( 'jobs' ),
				[ 'status' => 'failed' ],
				[ 'project_id' => $project_id ]
			);
			( new Project_Repository() )->delete_project( $project_id, $owner_id );
		}

		wp_set_current_user( 0 );
		parent::tear_down();
	}

	/**
	 * @param array<string, mixed>|null $target
	 */
	protected function create_test_project( ?array $target = null, string $operation = 'create', string $request = 'Build a test plugin.', ?int $owner_id = null ): array {
		$owner_id ??= $this->admin_id;
		$target    ??= [
			'kind' => 'new_plugin',
			'ref'  => 'new_plugin',
			'name' => 'Test Plugin',
		];
		$created    = ( new Project_Repository() )->create( $target, $operation, $request, $owner_id );
		$project_id = (int) $created['id'];

		$this->test_projects[ $project_id ] = $owner_id;

		return ( new Project_Repository() )->find( $project_id );
	}

	/**
	 * @param array<int, array{path:string,type:string,description:string,action:string}> $files
	 * @return array{job:array<string,mixed>,plan:array<string,mixed>,structured:array<string,mixed>}
	 */
	protected function create_ready_plan( int $project_id, array $files, string $main_file, string $plugin_name = 'Test Plugin' ): array {
		$jobs = new Job_Repository();
		$job  = $jobs->create( $project_id, 'plan', [], $this->admin_id );
		$jobs->update(
			(int) $job['id'],
			[
				'status'      => 'completed',
				'progress'    => 100,
				'finished_at' => current_time( 'mysql', true ),
			]
		);
		$structured = [
			'plugin_name'      => $plugin_name,
			'main_file'        => $main_file,
			'project_structure' => [
				'directories' => [],
				'files'       => $files,
			],
		];
		$plan       = ( new Plan_Repository() )->create_artifact(
			$project_id,
			(int) $job['id'],
			[
				'content'    => 'Create the test plugin.',
				'structured' => $structured,
			],
			$this->admin_id
		);

		return [
			'job'        => $job,
			'plan'       => $plan,
			'structured' => $structured,
		];
	}

	/**
	 * @param array<string, string> $contents File content keyed by Plan path.
	 * @return array{job:array<string,mixed>,plan:array<string,mixed>,revision:array<string,mixed>,manifest:array<string,mixed>}
	 */
	protected function stage_test_revision( int $project_id, array $contents, string $main_file = 'test-plugin.php' ): array {
		$files = [];
		foreach ( array_keys( $contents ) as $path ) {
			$files[] = [
				'path'        => $path,
				'type'        => strtolower( (string) pathinfo( $path, PATHINFO_EXTENSION ) ),
				'description' => 'Test fixture.',
				'action'      => 'add',
			];
		}
		$plan_fixture = $this->create_ready_plan( $project_id, $files, $main_file );
		$workspace    = ( new Project_Repository() )->find( $project_id );
		$manifest     = ( new Code_Validator() )->plan( $plan_fixture['structured'], $workspace );
		$this->assertFalse( is_wp_error( $manifest ), is_wp_error( $manifest ) ? $manifest->get_error_message() : '' );

		$jobs = new Job_Repository();
		$job  = $jobs->create(
			$project_id,
			'code',
			[
				'plan_id'                    => (int) $plan_fixture['plan']['id'],
				'expected_latest_revision_id' => null,
				'mode'                       => 'generate',
			],
			$this->admin_id
		);
		$runs = new Code_Run_Repository();
		$run  = $runs->create(
			(int) $job['id'],
			(int) $plan_fixture['plan']['id'],
			null,
			'fixture',
			'fixture',
			'',
			'test-code',
			1,
			$manifest['files'],
			'generate',
			$manifest
		);
		foreach ( $runs->files( (int) $run['id'] ) as $file ) {
			$run   = $runs->find_by_job( (int) $job['id'] );
			$token = wp_generate_password( 32, false, false );
			$this->assertTrue( $runs->acquire( (int) $run['id'], (int) $run['generation'], $token ) );
			$runs->mark_generating( (int) $run['id'], (int) $file['sequence'], $token );
			$this->assertTrue(
				$runs->complete_file(
					(int) $run['id'],
					(int) $file['sequence'],
					$token,
					(string) $contents[ $file['path'] ],
					[ 'input_tokens' => 1, 'output_tokens' => 1 ]
				)
			);
		}
		$run      = $runs->find_by_job( (int) $job['id'] );
		$revision = ( new Revision_Repository() )->stage_code_run(
			$run,
			$manifest,
			$project_id,
			$this->admin_id,
			null
		);
		$this->assertFalse( is_wp_error( $revision ), is_wp_error( $revision ) ? $revision->get_error_message() : '' );
		$jobs->update(
			(int) $job['id'],
			[
				'status'      => 'completed',
				'progress'    => 100,
				'finished_at' => current_time( 'mysql', true ),
			]
		);

		return [
			'job'      => $job,
			'plan'     => $plan_fixture['plan'],
			'revision' => $revision,
			'manifest' => $manifest,
		];
	}
}
