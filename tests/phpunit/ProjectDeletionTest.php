<?php

use WP_Autoplugin\V2\Infrastructure\Database\Installer;
use WP_Autoplugin\V2\Infrastructure\Database\Job_Repository;
use WP_Autoplugin\V2\Infrastructure\Database\Plan_Repository;
use WP_Autoplugin\V2\Infrastructure\Database\Project_Repository;
use WP_Autoplugin\V2\Infrastructure\Database\Prompt_Attachment_Repository;
use WP_Autoplugin\V2\Infrastructure\Database\Review_Repository;
use WP_Autoplugin\V2\Infrastructure\Database\Usage_Repository;
use WP_Autoplugin\V2\Rest\Routes;

/** Verifies owned project deletion and complete aggregate cleanup. */
final class ProjectDeletionTest extends WP_UnitTestCase {
	/** @var array<int, int> Owner ID keyed by project ID. */
	private array $projects = [];

	/** @var array<int, string> */
	private array $package_paths = [];

	public function tear_down(): void {
		global $wpdb;

		foreach ( $this->projects as $project_id => $owner_id ) {
			$wpdb->update(
				Installer::table( 'jobs' ),
				[ 'status' => 'failed' ],
				[ 'project_id' => $project_id ]
			);
			( new Project_Repository() )->delete_project( $project_id, $owner_id );
		}
		foreach ( $this->package_paths as $path ) {
			if ( is_file( $path ) ) {
				wp_delete_file( $path );
			}
		}

		parent::tear_down();
	}

	public function test_rejects_non_owner_and_active_project_deletion(): void {
		Installer::activate();
		$owner_id  = self::factory()->user->create( [ 'role' => 'administrator' ] );
		$other_id  = self::factory()->user->create( [ 'role' => 'administrator' ] );
		$project_id = $this->project( $owner_id, 'active' );
		$job        = ( new Job_Repository() )->create( $project_id, 'plan', [], $owner_id );
		$routes     = new Routes();
		$request    = $this->delete_request( $project_id );

		wp_set_current_user( $other_id );
		$denied = $routes->delete_project( $request );
		$this->assertWPError( $denied );
		$this->assertSame( 404, $denied->get_error_data()['status'] );

		wp_set_current_user( $owner_id );
		$active = $routes->delete_project( $request );
		$this->assertWPError( $active );
		$this->assertSame( 409, $active->get_error_data()['status'] );
		$this->assertNotNull( ( new Project_Repository() )->find( $project_id ) );

		( new Job_Repository() )->update( (int) $job['id'], [ 'status' => 'failed' ] );
	}

	public function test_deletes_every_project_owned_record_and_private_release_artifact(): void {
		global $wpdb;

		Installer::activate();
		$owner_id   = self::factory()->user->create( [ 'role' => 'administrator' ] );
		$project_id = $this->project( $owner_id, 'cascade' );
		$jobs       = new Job_Repository();
		$job        = $jobs->create( $project_id, 'plan', [], $owner_id );
		$job_id     = (int) $job['id'];
		$jobs->update( $job_id, [ 'status' => 'completed' ] );
		( new Usage_Repository() )->record( $job_id, 'openai', 'fixture', 'plan', [ 'input_tokens' => 10, 'output_tokens' => 2 ] );
		( new Prompt_Attachment_Repository() )->attach(
			$job_id,
			$project_id,
			$owner_id,
			[
				[
					'filename'  => 'fixture.png',
					'mime_type' => 'image/png',
					'byte_size' => 7,
					'width'     => 1,
					'height'    => 1,
					'sha256'    => hash( 'sha256', 'private' ),
					'content'   => 'private',
				],
			]
		);
		$plan = ( new Plan_Repository() )->create_artifact(
			$project_id,
			$job_id,
			[
				'content'    => 'Fixture Plan',
				'structured' => [
					'project_structure' => [
						'directories' => [],
						'files'       => [],
					],
				],
			],
			$owner_id
		);

		$now = current_time( 'mysql', true );
		$wpdb->insert(
			Installer::table( 'revisions' ),
			[
				'project_id'      => $project_id,
				'revision_number' => 1,
				'summary'         => 'Fixture',
				'origin'          => 'ai',
				'plan_id'         => $plan['id'],
				'source_job_id'   => $job_id,
				'created_by'      => $owner_id,
				'created_at'      => $now,
			]
		);
		$revision_id = (int) $wpdb->insert_id;
		$wpdb->insert(
			Installer::table( 'revision_files' ),
			[
				'revision_id'  => $revision_id,
				'path'         => 'fixture.php',
				'change_type'  => 'add',
				'content'      => '<?php',
				'content_hash' => hash( 'sha256', '<?php' ),
			]
		);

		$review = ( new Review_Repository() )->create_report(
			[
				'id'         => $job_id,
				'project_id' => $project_id,
				'created_by' => $owner_id,
				'payload'    => [ 'mode' => 'initial' ],
			],
			[ 'id' => $revision_id ],
			[
				'outcome'        => 'report',
				'summary'        => 'Fixture review.',
				'tests'          => [],
				'prior_findings' => [],
				'new_findings'   => [
					[
						'priority'      => 'P2',
						'category'      => 'maintainability',
						'title'         => 'Fixture finding',
						'body'          => 'Fixture body.',
						'suggested_fix' => 'Fixture fix.',
						'path'          => 'fixture.php',
						'side'          => 'staged',
						'start_line'    => 1,
						'end_line'      => 1,
						'anchor_hash'   => hash( 'sha256', '<?php' ),
					],
				],
			],
			[
				'provider'       => 'openai',
				'model'          => 'fixture',
				'effort'         => '',
				'prompt_slug'    => 'fixture',
				'prompt_version' => 1,
			]
		);
		$this->assertFalse( is_wp_error( $review ) );
		$finding_id = (int) $review['findings'][0]['id'];

		$wpdb->insert(
			Installer::table( 'agent_runs' ),
			[
				'job_id'           => $job_id,
				'status'           => 'completed',
				'provider'         => 'openai',
				'model'            => 'fixture',
				'effort'           => '',
				'transcript'       => '[]',
				'tree_fingerprint' => hash( 'sha256', 'tree' ),
				'inspected_files'  => '[]',
				'created_at'       => $now,
				'updated_at'       => $now,
			]
		);
		$run_id = (int) $wpdb->insert_id;

		$wpdb->insert(
			Installer::table( 'code_runs' ),
			[
				'job_id'         => $job_id,
				'plan_id'        => $plan['id'],
				'revision_id'    => $revision_id,
				'status'         => 'completed',
				'mode'           => 'generate',
				'phase'          => 'completed',
				'provider'       => 'openai',
				'model'          => 'fixture',
				'effort'         => '',
				'prompt_slug'    => 'fixture',
				'prompt_version' => 1,
				'created_at'     => $now,
				'updated_at'     => $now,
			]
		);
		$code_run_id = (int) $wpdb->insert_id;
		$wpdb->insert(
			Installer::table( 'code_run_files' ),
			[
				'run_id'     => $code_run_id,
				'sequence'   => 1,
				'path'       => 'fixture.php',
				'type'       => 'php',
				'operation'  => 'add',
				'status'     => 'completed',
				'created_at' => $now,
				'updated_at' => $now,
			]
		);

		$release_root = wp_normalize_path( sys_get_temp_dir() . '/wp-autoplugin-v2-release' );
		wp_mkdir_p( $release_root );
		$package_path = $release_root . '/package-' . wp_generate_password( 12, false, false ) . '.zip';
		file_put_contents( $package_path, 'fixture' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Private test fixture.
		$this->package_paths[] = $package_path;
		$wpdb->insert(
			Installer::table( 'release_packages' ),
			[
				'job_id'       => $job_id,
				'project_id'   => $project_id,
				'revision_id'  => $revision_id,
				'mode'         => 'project',
				'status'       => 'ready',
				'artifact_kind' => 'plugin',
				'slug'         => 'fixture',
				'temp_path'    => $package_path,
				'created_by'   => $owner_id,
				'created_at'   => $now,
				'updated_at'   => $now,
			]
		);
		$package_id = (int) $wpdb->insert_id;

		$wpdb->insert(
			Installer::table( 'promotions' ),
			[
				'job_id'       => $job_id,
				'project_id'   => $project_id,
				'revision_id'  => $revision_id,
				'mode'         => 'install_project',
				'status'       => 'completed',
				'artifact_kind' => 'plugin',
				'created_by'   => $owner_id,
				'created_at'   => $now,
				'updated_at'   => $now,
			]
		);
		$promotion_id = (int) $wpdb->insert_id;
		$wpdb->insert(
			Installer::table( 'promotion_files' ),
			[
				'promotion_id' => $promotion_id,
				'path'         => 'fixture.php',
				'operation'    => 'add',
				'created_at'   => $now,
			]
		);

		wp_set_current_user( $owner_id );
		$response = ( new Routes() )->delete_project( $this->delete_request( $project_id ) );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertSame( [ 'project_id' => $project_id, 'deleted' => true ], $response->get_data() );
		$this->assertNull( ( new Project_Repository() )->find( $project_id ) );
		$this->assertSame( 0, $this->count( 'projects', 'id', $project_id ) );
		$this->assertSame( 0, $this->count( 'plans', 'id', (int) $plan['id'] ) );
		$this->assertSame( 0, $this->count( 'jobs', 'id', $job_id ) );
		$this->assertSame( 0, $this->count( 'job_events', 'job_id', $job_id ) );
		$this->assertSame( 0, $this->count( 'usage', 'job_id', $job_id ) );
		$this->assertSame( 0, $this->count( 'job_prompt_attachments', 'job_id', $job_id ) );
		$this->assertSame( 0, $this->count( 'prompt_attachments', 'project_id', $project_id ) );
		$this->assertSame( 0, $this->count( 'revisions', 'id', $revision_id ) );
		$this->assertSame( 0, $this->count( 'revision_files', 'revision_id', $revision_id ) );
		$this->assertSame( 0, $this->count( 'review_reports', 'project_id', $project_id ) );
		$this->assertSame( 0, $this->count( 'review_findings', 'id', $finding_id ) );
		$this->assertSame( 0, $this->count( 'review_finding_events', 'finding_id', $finding_id ) );
		$this->assertSame( 0, $this->count( 'agent_runs', 'id', $run_id ) );
		$this->assertSame( 0, $this->count( 'code_runs', 'id', $code_run_id ) );
		$this->assertSame( 0, $this->count( 'code_run_files', 'run_id', $code_run_id ) );
		$this->assertSame( 0, $this->count( 'release_packages', 'id', $package_id ) );
		$this->assertSame( 0, $this->count( 'promotions', 'id', $promotion_id ) );
		$this->assertSame( 0, $this->count( 'promotion_files', 'promotion_id', $promotion_id ) );
		$this->assertFileDoesNotExist( $package_path );
		unset( $this->projects[ $project_id ] );
	}

	private function project( int $owner_id, string $suffix ): int {
		$created = ( new Project_Repository() )->create(
			[
				'kind' => 'new_plugin',
				'ref'  => 'project-delete-' . $suffix . '-' . wp_generate_uuid4(),
				'name' => 'Project deletion fixture',
			],
			'create',
			'Build a fixture.',
			$owner_id
		);
		$id                   = (int) $created['id'];
		$this->projects[ $id ] = $owner_id;

		return $id;
	}

	private function delete_request( int $project_id ): WP_REST_Request {
		$request = new WP_REST_Request( 'DELETE', '/wp-autoplugin/v2/projects/' . $project_id );
		$request->set_param( 'id', $project_id );
		return $request;
	}

	private function count( string $table, string $column, int $id ): int {
		global $wpdb;

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM ' . Installer::table( $table ) . " WHERE $column = %d",
				$id
			)
		);
	}
}
