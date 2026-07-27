<?php

use WP_Autoplugin\V2\Domain\AI\Global_Instructions;
use WP_Autoplugin\V2\Infrastructure\Database\Installer;
use WP_Autoplugin\V2\Infrastructure\Database\Job_Repository;
use WP_Autoplugin\V2\Infrastructure\Database\Workspace_Repository;

/** Coverage for private per-job custom-instruction snapshots and prompt precedence. */
final class GlobalInstructionsTest extends WP_UnitTestCase {
	private int $workspace_id = 0;
	private int $project_id   = 0;

	public function tear_down(): void {
		global $wpdb;

		if ( $this->workspace_id ) {
			$job_ids = array_map( 'intval', $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM ' . Installer::table( 'jobs' ) . ' WHERE workspace_id = %d', $this->workspace_id ) ) );
			foreach ( $job_ids as $job_id ) {
				$wpdb->delete( Installer::table( 'job_events' ), [ 'job_id' => $job_id ] );
			}
			$wpdb->delete( Installer::table( 'jobs' ), [ 'workspace_id' => $this->workspace_id ] );
			$wpdb->delete( Installer::table( 'workspaces' ), [ 'id' => $this->workspace_id ] );
		}
		if ( $this->project_id ) {
			$wpdb->delete( Installer::table( 'projects' ), [ 'id' => $this->project_id ] );
		}
		delete_option( Global_Instructions::OPTION_NAME );
		parent::tear_down();
	}

	public function test_prompt_wrapper_is_an_empty_noop_and_enforces_specificity(): void {
		$base     = 'Base response contract.';
		$content  = "# Global style\n\nUse tabs.";
		$snapshot = [
			'content'      => $content,
			'content_hash' => hash( 'sha256', $content ),
		];

		$this->assertSame( $base, Global_Instructions::apply( $base, null ) );
		$wrapped = Global_Instructions::apply( $base, $snapshot );
		$this->assertStringStartsWith( $base, $wrapped );
		$this->assertStringContainsString( $content, $wrapped );
		$this->assertStringContainsString( 'current administrator request has priority', $wrapped );
		$this->assertStringContainsString( 'root_plugin_instructions', $wrapped );
		$this->assertStringContainsString( 'hard constraints', $wrapped );
	}

	public function test_ai_jobs_snapshot_instructions_privately_while_non_ai_jobs_do_not(): void {
		global $wpdb;

		Installer::activate();
		$user_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		$created = ( new Workspace_Repository() )->create(
			[
				'kind' => 'new_plugin',
				'ref'  => 'global-instructions-' . wp_generate_uuid4(),
				'name' => 'Global instructions test',
			],
			'create',
			'Create a test plugin.',
			$user_id
		);
		$this->workspace_id = (int) $created['workspace_id'];
		$this->project_id   = (int) $created['project_id'];

		$first = "# First snapshot\n\nUse tabs.";
		update_option( Global_Instructions::OPTION_NAME, $first, false );
		$jobs = new Job_Repository();
		$plan = $jobs->create( $this->workspace_id, 'plan', [], $user_id );

		update_option( Global_Instructions::OPTION_NAME, 'A later setting.', false );
		$this->assertSame(
			[ 'content' => $first, 'content_hash' => hash( 'sha256', $first ) ],
			$jobs->global_instructions( (int) $plan['id'] )
		);
		$this->assertArrayNotHasKey( 'global_instructions', $plan );
		$this->assertArrayNotHasKey( 'global_instructions_hash', $plan );

		$raw = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT global_instructions, global_instructions_hash FROM ' . Installer::table( 'jobs' ) . ' WHERE id = %d',
				(int) $plan['id']
			),
			ARRAY_A
		);
		$this->assertSame( $first, $raw['global_instructions'] );
		$this->assertSame( hash( 'sha256', $first ), $raw['global_instructions_hash'] );

		$package = $jobs->create( $this->workspace_id, 'package', [], $user_id );
		$this->assertNull( $jobs->global_instructions( (int) $package['id'] ) );
	}

	public function test_snapshot_hash_mismatch_is_rejected(): void {
		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'snapshot is invalid' );

		Global_Instructions::validate_snapshot(
			[
				'content'      => 'Use tabs.',
				'content_hash' => str_repeat( '0', 64 ),
			]
		);
	}
}
