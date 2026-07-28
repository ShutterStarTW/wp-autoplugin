<?php

use WP_Autoplugin\V2\Infrastructure\Database\Installer;
use WP_Autoplugin\V2\Infrastructure\Database\Job_Repository;
use WP_Autoplugin\V2\Infrastructure\Database\Prompt_Attachment_Repository;
use WP_Autoplugin\V2\Infrastructure\Database\Review_Repository;
use WP_Autoplugin\V2\Infrastructure\Database\Usage_Repository;
use WP_Autoplugin\V2\Infrastructure\Database\Workspace_Repository;
use WP_Autoplugin\V2\Rest\Routes;

/** Verifies owned project deletion and complete durable-data cleanup. */
final class ProjectDeletionTest extends WP_UnitTestCase {
	/** @var array<int, int> Owner ID keyed by project ID. */
	private array $projects = [];

	/** @var array<int, int> */
	private array $target_ids = [];

	/** @var array<int, string> */
	private array $package_paths = [];

	public function tear_down(): void {
		global $wpdb;

		foreach ( $this->projects as $project_id => $owner_id ) {
			$workspace_ids = array_map(
				'intval',
				(array) $wpdb->get_col(
					$wpdb->prepare(
						'SELECT id FROM ' . Installer::table( 'workspaces' ) . ' WHERE project_id = %d',
						$project_id
					)
				)
			);
			foreach ( $workspace_ids as $workspace_id ) {
				$wpdb->update(
					Installer::table( 'jobs' ),
					[ 'status' => 'failed' ],
					[ 'workspace_id' => $workspace_id ]
				);
			}
			( new Workspace_Repository() )->delete_project( $project_id, $owner_id );
		}
		foreach ( $this->target_ids as $target_id ) {
			$wpdb->delete( Installer::table( 'targets' ), [ 'id' => $target_id ] );
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
		$owner_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		$other_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		$created  = $this->workspace( $owner_id, 'active' );
		$job      = ( new Job_Repository() )->create( $created['workspace_id'], 'plan', [], $owner_id );
		$routes   = new Routes();
		$request  = $this->delete_request( $created['project_id'] );

		wp_set_current_user( $other_id );
		$denied = $routes->delete_project( $request );
		$this->assertWPError( $denied );
		$this->assertSame( 404, $denied->get_error_data()['status'] );

		wp_set_current_user( $owner_id );
		$active = $routes->delete_project( $request );
		$this->assertWPError( $active );
		$this->assertSame( 409, $active->get_error_data()['status'] );
		$this->assertNotNull( ( new Workspace_Repository() )->find( $created['workspace_id'] ) );

		( new Job_Repository() )->update( (int) $job['id'], [ 'status' => 'failed' ] );
	}

	public function test_deletes_project_history_and_private_release_artifact(): void {
		global $wpdb;

		Installer::activate();
		$owner_id  = self::factory()->user->create( [ 'role' => 'administrator' ] );
		$created   = $this->workspace( $owner_id, 'cascade' );
		$workspace = $created['workspace_id'];
		$job       = ( new Job_Repository() )->create( $workspace, 'plan', [], $owner_id );
		$job_id    = (int) $job['id'];
		( new Job_Repository() )->update( $job_id, [ 'status' => 'completed' ] );
		( new Usage_Repository() )->record( $job_id, 'openai', 'fixture', 'plan', [ 'input_tokens' => 10, 'output_tokens' => 2 ] );
		( new Prompt_Attachment_Repository() )->attach(
			$job_id,
			$workspace,
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

		$now = current_time( 'mysql', true );
		$wpdb->insert(
			Installer::table( 'revisions' ),
			[
				'workspace_id'    => $workspace,
				'revision_number' => 1,
				'status'          => 'staged',
				'summary'         => 'Fixture',
				'origin'          => 'ai',
				'created_by'      => $owner_id,
				'created_at'      => $now,
			]
		);
		$revision_id = (int) $wpdb->insert_id;
		$wpdb->insert(
			Installer::table( 'revision_files' ),
			[
				'revision_id' => $revision_id,
				'path'        => 'fixture.php',
				'change_type' => 'add',
				'content'     => '<?php',
				'content_hash' => hash( 'sha256', '<?php' ),
			]
		);

		$review = ( new Review_Repository() )->create_report(
			[
				'id'           => $job_id,
				'workspace_id' => $workspace,
				'created_by'   => $owner_id,
				'payload'      => [ 'mode' => 'initial' ],
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
				'job_id'          => $job_id,
				'status'          => 'completed',
				'generation'      => 0,
				'model_turns'     => 1,
				'tool_calls'      => 0,
				'source_bytes'    => 0,
				'input_tokens'    => 1,
				'output_tokens'   => 1,
				'provider'        => 'openai',
				'model'           => 'fixture',
				'effort'          => '',
				'transcript'      => '[]',
				'tree_fingerprint' => hash( 'sha256', 'tree' ),
				'inspected_files' => '[]',
				'created_at'      => $now,
				'updated_at'      => $now,
			]
		);
		$run_id = (int) $wpdb->insert_id;
		$wpdb->insert(
			Installer::table( 'agent_steps' ),
			[ 'run_id' => $run_id, 'sequence' => 1, 'kind' => 'model', 'created_at' => $now ]
		);

		$wpdb->insert(
			Installer::table( 'code_runs' ),
			[
				'job_id'          => $job_id,
				'plan_job_id'     => $job_id,
				'revision_id'     => $revision_id,
				'status'          => 'completed',
				'mode'            => 'generate',
				'phase'           => 'completed',
				'generation'      => 0,
				'next_file_index' => 1,
				'provider'        => 'openai',
				'model'           => 'fixture',
				'effort'          => '',
				'prompt_slug'     => 'fixture',
				'prompt_version'  => 1,
				'created_at'      => $now,
				'updated_at'      => $now,
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
				'workspace_id' => $workspace,
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
				'workspace_id' => $workspace,
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
		$response = ( new Routes() )->delete_project( $this->delete_request( $created['project_id'] ) );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertSame(
			[
				'project_id'   => $created['project_id'],
				'workspace_ids' => [ $workspace ],
				'deleted'      => true,
			],
			$response->get_data()
		);
		$this->assertNull( ( new Workspace_Repository() )->find( $workspace ) );
		$this->assertSame( 0, $this->count( 'projects', 'id', $created['project_id'] ) );
		$this->assertSame( 0, $this->count( 'jobs', 'id', $job_id ) );
		$this->assertSame( 0, $this->count( 'job_events', 'job_id', $job_id ) );
		$this->assertSame( 0, $this->count( 'usage', 'job_id', $job_id ) );
		$this->assertSame( 0, $this->count( 'job_prompt_attachments', 'job_id', $job_id ) );
		$this->assertSame( 0, $this->count( 'prompt_attachments', 'workspace_id', $workspace ) );
		$this->assertSame( 0, $this->count( 'revisions', 'id', $revision_id ) );
		$this->assertSame( 0, $this->count( 'revision_files', 'revision_id', $revision_id ) );
		$this->assertSame( 0, $this->count( 'review_reports', 'workspace_id', $workspace ) );
		$this->assertSame( 0, $this->count( 'review_findings', 'id', $finding_id ) );
		$this->assertSame( 0, $this->count( 'review_finding_events', 'finding_id', $finding_id ) );
		$this->assertSame( 0, $this->count( 'agent_runs', 'id', $run_id ) );
		$this->assertSame( 0, $this->count( 'agent_steps', 'run_id', $run_id ) );
		$this->assertSame( 0, $this->count( 'code_runs', 'id', $code_run_id ) );
		$this->assertSame( 0, $this->count( 'code_run_files', 'run_id', $code_run_id ) );
		$this->assertSame( 0, $this->count( 'release_packages', 'id', $package_id ) );
		$this->assertSame( 0, $this->count( 'promotions', 'id', $promotion_id ) );
		$this->assertSame( 0, $this->count( 'promotion_files', 'promotion_id', $promotion_id ) );
		$this->assertSame( 1, $this->count( 'targets', 'id', $created['target_id'] ) );
		$this->assertFileDoesNotExist( $package_path );
	}

	/**
	 * @return array{project_id: int, workspace_id: int, target_id: int}
	 */
	private function workspace( int $owner_id, string $suffix ): array {
		global $wpdb;

		$created = ( new Workspace_Repository() )->create(
			[
				'kind' => 'new_plugin',
				'ref'  => 'project-delete-' . $suffix . '-' . wp_generate_uuid4(),
				'name' => 'Project deletion fixture',
			],
			'create',
			'Build a fixture.',
			$owner_id
		);
		$target_id = (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT target_id FROM ' . Installer::table( 'projects' ) . ' WHERE id = %d',
				$created['project_id']
			)
		);
		$this->projects[ (int) $created['project_id'] ] = $owner_id;
		$this->target_ids[]                             = $target_id;

		return [
			'project_id'   => (int) $created['project_id'],
			'workspace_id' => (int) $created['workspace_id'],
			'target_id'    => $target_id,
		];
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
