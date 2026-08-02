<?php

use WP_Autoplugin\V2\Infrastructure\Database\Installer;
use WP_Autoplugin\V2\Infrastructure\Database\Job_Repository;
use WP_Autoplugin\V2\Infrastructure\Database\Plan_Repository;
use WP_Autoplugin\V2\Infrastructure\Database\Project_Repository;

/** Integration coverage for immutable, versioned Plan artifacts. */
final class PlanRepositoryTest extends WP_UnitTestCase {
	private int $project_id = 0;
	private int $owner_id   = 0;

	public function tear_down(): void {
		global $wpdb;

		if ( $this->project_id ) {
			$wpdb->update(
				Installer::table( 'jobs' ),
				[ 'status' => 'failed' ],
				[ 'project_id' => $this->project_id ]
			);
			( new Project_Repository() )->delete_project( $this->project_id, $this->owner_id );
		}

		parent::tear_down();
	}

	public function test_versions_plans_and_expands_compact_job_results(): void {
		Installer::activate();
		$this->owner_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		$created        = ( new Project_Repository() )->create(
			[
				'kind' => 'new_plugin',
				'ref'  => 'plan-repository-' . wp_generate_uuid4(),
				'name' => 'Plan repository fixture',
			],
			'create',
			'Build a fixture plugin.',
			$this->owner_id
		);
		$this->project_id = (int) $created['id'];

		$jobs      = new Job_Repository();
		$plans     = new Plan_Repository();
		$plan_job  = $jobs->create( $this->project_id, 'plan', [], $this->owner_id );
		$first     = $plans->create_artifact(
			$this->project_id,
			(int) $plan_job['id'],
			$this->result( '# Initial Plan', 'fixture.php' ),
			$this->owner_id
		);
		$idempotent = $plans->create_artifact(
			$this->project_id,
			(int) $plan_job['id'],
			$this->result( '# Ignored duplicate', 'ignored.php' ),
			$this->owner_id
		);
		$this->assertSame( (int) $first['id'], (int) $idempotent['id'] );
		$compacted = $plans->compact_job_result( $this->result( '# Initial Plan', 'fixture.php' ), (int) $first['id'] );
		$jobs->update(
			(int) $plan_job['id'],
			[
				'status'      => 'completed',
				'progress'    => 100,
				'result'      => $compacted,
				'finished_at' => current_time( 'mysql', true ),
			]
		);

		$stored_result = json_decode(
			(string) $GLOBALS['wpdb']->get_var(
				$GLOBALS['wpdb']->prepare(
					'SELECT result FROM ' . Installer::table( 'jobs' ) . ' WHERE id = %d',
					(int) $plan_job['id']
				)
			),
			true
		);
		$this->assertSame( [ 'outcome' => 'artifact', 'plan_id' => (int) $first['id'] ], $stored_result );

		$hydrated = $jobs->find( (int) $plan_job['id'] );
		$this->assertSame( '# Initial Plan', $hydrated['result']['content'] );
		$this->assertSame( (int) $first['id'], $hydrated['result']['artifact']['plan_id'] );

		$code_job = $jobs->create(
			$this->project_id,
			'code',
			[ 'plan_id' => (int) $first['id'] ],
			$this->owner_id
		);
		$jobs->update(
			(int) $code_job['id'],
			[
				'status'      => 'completed',
				'result'      => [
					'plan_id'     => (int) $first['id'],
					'revision_id' => 10,
				],
				'finished_at' => current_time( 'mysql', true ),
			]
		);
		$code_result = $jobs->find( (int) $code_job['id'] )['result'];
		$this->assertSame( (int) $first['id'], $code_result['plan_id'] );
		$this->assertArrayNotHasKey( 'artifact', $code_result );
		$this->assertArrayNotHasKey( 'content', $code_result );

		$manual = $plans->create_manual_successor( $first, '# Administrator edit', $this->owner_id );
		$this->assertSame( 2, $manual['plan_number'] );
		$this->assertSame( 'manual', $manual['origin'] );
		$this->assertSame( 'pending_structure', $manual['status'] );
		$this->assertSame( (int) $first['id'], $manual['parent_plan_id'] );
		$this->assertNull( $manual['structured'] );

		$structure_job = $jobs->create(
			$this->project_id,
			'plan_structure',
			[ 'plan_id' => (int) $manual['id'] ],
			$this->owner_id
		);
		$ready         = $plans->create_artifact(
			$this->project_id,
			(int) $structure_job['id'],
			$this->result( '# Administrator edit', 'renamed.php' ),
			$this->owner_id,
			(int) $manual['id']
		);

		$this->assertSame( 3, $ready['plan_number'] );
		$this->assertSame( 'ready', $ready['status'] );
		$this->assertSame( 'manual', $ready['origin'] );
		$this->assertSame( (int) $manual['id'], $ready['parent_plan_id'] );
		$this->assertSame( (int) $ready['id'], (int) $plans->latest_ready( $this->project_id )['id'] );
		$jobs->update(
			(int) $structure_job['id'],
			[
				'status'      => 'completed',
				'progress'    => 100,
				'finished_at' => current_time( 'mysql', true ),
			]
		);

		$history = $plans->list_for_workspace( $this->project_id );
		$this->assertSame( [ 3, 1 ], array_column( $history, 'plan_number' ) );
		$this->assertArrayNotHasKey( 'content', $history[0] );
		$this->assertArrayNotHasKey( 'structured', $history[0] );

		$restored = $plans->restore( (int) $first['id'], (int) $ready['id'], $this->owner_id );
		$this->assertFalse( is_wp_error( $restored ), is_wp_error( $restored ) ? $restored->get_error_message() : '' );
		$this->assertSame( 4, $restored['plan_number'] );
		$this->assertSame( 'restore', $restored['origin'] );
		$this->assertSame( (int) $first['id'], $restored['parent_plan_id'] );
		$this->assertSame( '# Initial Plan', $restored['content'] );
		$this->assertSame( 'fixture.php', $restored['structured']['project_structure']['files'][0]['path'] );
		$this->assertSame( (int) $restored['id'], $plans->latest_id( $this->project_id ) );

		$stale = $plans->restore( (int) $first['id'], (int) $ready['id'], $this->owner_id );
		$this->assertWPError( $stale );
		$this->assertSame( 'plan_conflict', $stale->get_error_code() );

		$active = $jobs->create(
			$this->project_id,
			'conversation',
			[
				'stage'   => 'plan',
				'plan_id' => (int) $restored['id'],
			],
			$this->owner_id
		);
		$blocked = $plans->restore( (int) $ready['id'], (int) $restored['id'], $this->owner_id );
		$this->assertWPError( $blocked );
		$this->assertSame( 'plan_conflict', $blocked->get_error_code() );
		$this->assertStringContainsString( 'active Plan work', $blocked->get_error_message() );
		$jobs->update( (int) $active['id'], [ 'status' => 'cancelled' ] );
	}

	/** @return array<string, mixed> */
	private function result( string $content, string $path ): array {
		return [
			'content'    => $content,
			'outcome'    => 'artifact',
			'structured' => [
				'project_structure' => [
					'directories' => [],
					'files'       => [
						[
							'path'        => $path,
							'type'        => 'php',
							'description' => 'Fixture file.',
							'action'      => 'add',
						],
					],
				],
			],
		];
	}
}
