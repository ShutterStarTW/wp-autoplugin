<?php

use WP_Autoplugin\V2\Infrastructure\Database\Code_Run_Repository;
use WP_Autoplugin\V2\Infrastructure\Database\Installer;
use WP_Autoplugin\V2\Infrastructure\Database\Job_Repository;
use WP_Autoplugin\V2\Infrastructure\Database\Project_Repository;
use WP_Autoplugin\V2\Infrastructure\Database\Revision_Intent_Repository;
use WP_Autoplugin\V2\Infrastructure\Database\Revision_Repository;
use WP_Autoplugin\V2\Orchestration\Review_Orchestrator;

/** Revision-scoped Review intent coverage, including Restore and inherited successors. */
final class RevisionIntentRepositoryTest extends WP_Autoplugin_Integration_Test_Case {
	public function test_code_amendments_follow_the_exact_revision_lineage(): void {
		$project   = $this->create_test_project( null, 'create', 'Create a Settings page.' );
		$fixture   = $this->stage_test_revision( (int) $project['id'], [ 'test-plugin.php' => $this->plugin( 'settings' ) ] );
		$revisions = new Revision_Repository();
		$initial   = $revisions->find( (int) $fixture['revision']['id'] );
		$amended   = $this->stage_follow_up(
			$initial,
			'conversation',
			'Please move it under Superdraft.',
			'Move the page under the Superdraft parent menu.',
			[ 'The page uses the verified Superdraft parent menu slug.' ],
			$this->plugin( 'superdraft' )
		);

		$intent = ( new Revision_Intent_Repository() )->for_revision( (int) $amended['id'] );
		$this->assertFalse( is_wp_error( $intent ), is_wp_error( $intent ) ? $intent->get_error_message() : '' );
		$this->assertCount( 1, $intent['accepted_code_changes'] );
		$this->assertSame( 'Move the page under the Superdraft parent menu.', $intent['accepted_code_changes'][0]['resolved_request'] );

		$manual = $revisions->save_successor(
			(int) $amended['id'],
			(int) $amended['id'],
			[ [ 'path' => 'test-plugin.php', 'content' => $this->plugin( 'superdraft-manual' ) ] ],
			$this->admin_id
		);
		$this->assertFalse( is_wp_error( $manual ), is_wp_error( $manual ) ? $manual->get_error_message() : '' );
		$manual_intent = ( new Revision_Intent_Repository() )->for_revision( (int) $manual['id'] );
		$this->assertCount( 1, $manual_intent['accepted_code_changes'] );

		$review_fix = $this->stage_follow_up(
			$revisions->find( (int) $manual['id'] ),
			'review_fix',
			'Fix the selected finding.',
			'Apply the Review fix.',
			[ 'The selected finding is addressed.' ],
			$this->plugin( 'superdraft-fixed' )
		);
		$review_fix_intent = ( new Revision_Intent_Repository() )->for_revision( (int) $review_fix['id'] );
		$this->assertCount( 1, $review_fix_intent['accepted_code_changes'] );
		$this->assertSame( 'Move the page under the Superdraft parent menu.', $review_fix_intent['accepted_code_changes'][0]['resolved_request'] );

		$restored_initial = $revisions->restore( (int) $initial['id'], (int) $review_fix['id'], $this->admin_id );
		$this->assertFalse( is_wp_error( $restored_initial ), is_wp_error( $restored_initial ) ? $restored_initial->get_error_message() : '' );
		$restored_initial_intent = ( new Revision_Intent_Repository() )->for_revision( (int) $restored_initial['id'] );
		$this->assertSame( [], $restored_initial_intent['accepted_code_changes'] );

		$restored_amendment = $revisions->restore( (int) $amended['id'], (int) $restored_initial['id'], $this->admin_id );
		$this->assertFalse( is_wp_error( $restored_amendment ), is_wp_error( $restored_amendment ) ? $restored_amendment->get_error_message() : '' );
		$restored_amendment_intent = ( new Revision_Intent_Repository() )->for_revision( (int) $restored_amendment['id'] );
		$this->assertCount( 1, $restored_amendment_intent['accepted_code_changes'] );
		$this->assertSame( (int) $amended['id'], (int) $restored_amendment_intent['provenance']['restored_from_revision_id'] );
	}

	public function test_review_context_keeps_predecessor_source_out_of_an_added_staged_file(): void {
		$project = $this->create_test_project( null, 'create', 'Create a Settings page.' );
		$fixture = $this->stage_test_revision( (int) $project['id'], [ 'test-plugin.php' => $this->plugin( 'settings' ) ] );
		$initial = ( new Revision_Repository() )->find( (int) $fixture['revision']['id'] );
		$amended = $this->stage_follow_up(
			$initial,
			'conversation',
			'Please move it under Superdraft.',
			'Move the page under the Superdraft parent menu.',
			[ 'The page uses the verified Superdraft parent menu slug.' ],
			$this->plugin( 'superdraft' )
		);

		$method = new ReflectionMethod( Review_Orchestrator::class, 'context' );
		$method->setAccessible( true );
		$context = $method->invoke(
			new Review_Orchestrator(),
			( new Project_Repository() )->find( (int) $project['id'] ),
			$amended,
			null,
			new Job_Repository()
		);

		$this->assertFalse( is_wp_error( $context ), is_wp_error( $context ) ? $context->get_error_message() : '' );
		$this->assertNull( $context['revision']['files'][0]['base_content'] );
		$this->assertNotEmpty( $context['revision']['predecessor_changes'] );
		$this->assertSame( 'Move the page under the Superdraft parent menu.', $context['effective_requirements']['accepted_code_changes'][0]['resolved_request'] );
	}

	/** @return array<string, mixed> */
	private function stage_follow_up( array $base, string $task, string $message, string $request, array $criteria, string $content ): array {
		global $wpdb;

		$jobs    = new Job_Repository();
		$payload = 'conversation' === $task
			? [ 'stage' => 'code', 'message' => $message, 'revision_id' => (int) $base['id'] ]
			: [ 'message' => $message, 'revision_id' => (int) $base['id'], 'finding_ids' => [ 1 ] ];
		$job     = $jobs->create( (int) $base['project_id'], $task, $payload, $this->admin_id );
		$runs    = new Code_Run_Repository();
		$run     = $runs->create_follow_up(
			(int) $job['id'],
			(int) $base['plan_id'],
			(int) $base['id'],
			'fixture',
			'fixture',
			'',
			'test-code-follow-up',
			1
		);
		$metadata = [
			'added_paths'         => [],
			'updated_paths'       => [ 'test-plugin.php' ],
			'deleted_paths'       => [],
			'resolved_request'    => $request,
			'acceptance_criteria' => $criteria,
			'compliance_attempts' => 0,
		];
		$wpdb->update(
			Installer::table( 'code_runs' ),
			[
				'target_manifest'    => wp_json_encode( $base['project_manifest'] ),
				'change_instructions' => wp_json_encode( $metadata ),
				'change_summary'      => $request,
			],
			[ 'id' => (int) $run['id'] ]
		);
		$run   = $runs->find_by_job( (int) $job['id'] );
		$files = [
			[
				'path'              => 'test-plugin.php',
				'type'              => 'php',
				'change_type'       => 'add',
				'content'           => $content,
				'base_content'      => null,
				'base_content_hash' => null,
			],
		];
		$revision = ( new Revision_Repository() )->stage_code_follow_up(
			$run,
			$base['project_manifest'],
			$files,
			(int) $base['project_id'],
			$this->admin_id,
			(int) $base['id'],
			$request,
			'review_fix' === $task ? 'review_fix' : 'ai'
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

		return ( new Revision_Repository() )->find( (int) $revision['id'] );
	}

	private function plugin( string $placement ): string {
		return "<?php\n/**\n * Plugin Name: Test Plugin\n * Author: Test Suite\n */\nfunction test_plugin_placement() {\n\treturn '{$placement}';\n}\n";
	}
}
