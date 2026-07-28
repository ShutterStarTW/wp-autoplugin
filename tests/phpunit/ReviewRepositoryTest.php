<?php

use WP_Autoplugin\V2\Infrastructure\Database\Installer;
use WP_Autoplugin\V2\Infrastructure\Database\Review_Repository;

/** Integration coverage for immutable reports and administrator finding history. */
final class ReviewRepositoryTest extends WP_UnitTestCase {
	private int $workspace_id;
	private int $revision_id;

	protected function setUp(): void {
		parent::setUp();
		global $wpdb;

		Installer::activate();
		$now = current_time( 'mysql', true );
		$wpdb->insert(
			Installer::table( 'projects' ),
			[ 'name' => 'Review fixture', 'status' => 'active', 'created_by' => 1, 'created_at' => $now, 'updated_at' => $now ]
		);
		$project_id = (int) $wpdb->insert_id;
		$wpdb->insert(
			Installer::table( 'workspaces' ),
			[ 'project_id' => $project_id, 'operation' => 'create', 'status' => 'staged', 'request' => 'Create a fixture.', 'created_by' => 1, 'created_at' => $now, 'updated_at' => $now ]
		);
		$this->workspace_id = (int) $wpdb->insert_id;
		$this->revision_id = $this->create_revision_fixture( $now );
	}

	public function test_report_verdict_is_immutable_while_dismiss_reopen_and_successors_update_projection(): void {
		$reviews = new Review_Repository();
		$report  = $reviews->create_report(
			$this->job( 910001, 'initial' ),
			[ 'id' => $this->revision_id ],
			[
				'outcome'        => 'report',
				'summary'        => 'One issue.',
				'tests'          => [],
				'prior_findings' => [],
				'new_findings'   => [ $this->finding() ],
			],
			$this->model()
		);

		$this->assertFalse( is_wp_error( $report ) );
		$this->assertSame( 'action_required', $report['verdict'] );
		$finding_id = (int) $report['findings'][0]['id'];
		$this->assertFalse( is_wp_error( $reviews->dismiss( $finding_id, (int) $report['id'], $this->revision_id, 'Accepted risk.', 1 ) ) );
		$this->assertSame( 'cleared_with_dismissals', $reviews->workspace_status( $this->workspace_id, $this->revision_id )['status'] );
		$this->assertFalse( is_wp_error( $reviews->reopen( $finding_id, (int) $report['id'], $this->revision_id, 'Reconsider.', 1 ) ) );

		$successor = $reviews->create_report(
			$this->job( 910002, 'follow_up', (int) $report['id'] ),
			[ 'id' => $this->revision_id ],
			[
				'outcome'        => 'report',
				'summary'        => 'The issue was retracted.',
				'tests'          => [],
				'prior_findings' => [ [ 'finding_id' => $finding_id, 'disposition' => 'retracted' ] ],
				'new_findings'   => [],
			],
			$this->model()
		);

		$this->assertFalse( is_wp_error( $successor ) );
		$this->assertSame( 'all_clear', $successor['verdict'] );
		$this->assertSame( $finding_id, (int) $successor['findings'][0]['id'] );
		$this->assertSame( 'retracted', $successor['findings'][0]['status'] );
		$this->assertSame( 'action_required', $reviews->find( (int) $report['id'] )['verdict'] );
		$this->assertSame( [ 'opened', 'dismissed', 'reopened', 'retracted' ], array_column( $reviews->events( $finding_id ), 'event' ) );
	}

	/** @return array<string, mixed> */
	private function job( int $id, string $mode, int $parent = 0 ): array {
		return [
			'id'           => $id,
			'workspace_id' => $this->workspace_id,
			'created_by'   => 1,
			'payload'      => [ 'mode' => $mode, 'parent_report_id' => $parent ?: null ],
		];
	}

	/** @return array<string, mixed> */
	private function model(): array {
		return [ 'provider' => 'openai', 'model' => 'fixture', 'effort' => '', 'prompt_slug' => 'staged-revision-review', 'prompt_version' => 1 ];
	}

	private function create_revision_fixture( string $now ): int {
		global $wpdb;

		$content  = "<?php\n/* Plugin Name: Fixture */\n";
		$inserted = $wpdb->insert(
			Installer::table( 'revisions' ),
			[
				'workspace_id'    => $this->workspace_id,
				'revision_number' => 1,
				'status'          => 'staged',
				'summary'         => 'Fixture revision',
				'origin'          => 'ai',
				'created_by'      => 1,
				'created_at'      => $now,
			]
		);
		$this->assertNotFalse( $inserted );
		$revision_id = (int) $wpdb->insert_id;
		$this->assertGreaterThan( 0, $revision_id );

		$inserted = $wpdb->insert(
			Installer::table( 'revision_files' ),
			[
				'revision_id'  => $revision_id,
				'path'         => 'fixture.php',
				'change_type'  => 'add',
				'content'      => $content,
				'content_hash' => hash( 'sha256', $content ),
			]
		);
		$this->assertNotFalse( $inserted );

		return $revision_id;
	}

	/** @return array<string, mixed> */
	private function finding(): array {
		return [
			'priority' => 'P1', 'category' => 'correctness', 'title' => 'Fixture issue', 'body' => 'The fixture is incomplete.',
			'suggested_fix' => 'Complete it.', 'path' => 'fixture.php', 'side' => 'staged', 'start_line' => 1, 'end_line' => 1,
			'anchor_hash' => hash( 'sha256', '<?php' ),
		];
	}
}
