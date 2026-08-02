<?php

use WP_Autoplugin\V2\Infrastructure\Database\Installer;

/** Verifies the clean, pre-release v2 schema baseline. */
final class CleanSchemaTest extends WP_UnitTestCase {
	public function test_installs_the_canonical_eighteen_tables(): void {
		global $wpdb;

		Installer::activate();
		$tables = [
			'projects',
			'plans',
			'revisions',
			'revision_files',
			'jobs',
			'job_events',
			'usage',
			'agent_runs',
			'code_runs',
			'code_run_files',
			'prompt_attachments',
			'job_prompt_attachments',
			'review_reports',
			'review_findings',
			'review_finding_events',
			'release_packages',
			'promotions',
			'promotion_files',
		];

		foreach ( $tables as $table ) {
			$name = Installer::table( $table );
			$this->assertSame( $name, $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $name ) ) );
		}
		$this->assertSame( Installer::SCHEMA_VERSION, get_option( 'wp_autoplugin_v2_schema_version' ) );
	}

	public function test_schema_uses_project_and_plan_foreign_keys_without_legacy_columns(): void {
		global $wpdb;

		Installer::activate();
		$this->assertSame( [ 'id', 'name', 'target_kind', 'target_ref', 'target_snapshot', 'operation', 'is_closed', 'request', 'created_by', 'created_at', 'updated_at', 'closed_at' ], $this->columns( Installer::table( 'projects' ) ) );
		foreach ( [ 'plans', 'revisions', 'jobs', 'prompt_attachments', 'review_reports', 'review_findings', 'release_packages', 'promotions' ] as $table ) {
			$columns = $this->columns( Installer::table( $table ) );
			$this->assertContains( 'project_id', $columns, "$table must belong directly to a project." );
			$this->assertNotContains( 'workspace_id', $columns, "$table must not retain the retired workspace foreign key." );
		}
		$this->assertNotContains( 'runner', $this->columns( Installer::table( 'jobs' ) ) );
		$this->assertContains( 'plan_id', $this->columns( Installer::table( 'revisions' ) ) );
		$this->assertContains( 'plan_id', $this->columns( Installer::table( 'code_runs' ) ) );
		$this->assertNotContains( 'plan_job_id', $this->columns( Installer::table( 'revisions' ) ) );
		$this->assertNotContains( 'plan_job_id', $this->columns( Installer::table( 'code_runs' ) ) );
		$this->assertNotContains( 'status', $this->columns( Installer::table( 'revisions' ) ) );
		$this->assertNotContains( 'patch', $this->columns( Installer::table( 'revision_files' ) ) );
		$this->assertNotContains( 'plugin_file', $this->columns( Installer::table( 'release_packages' ) ) );
		$this->assertNotContains( 'source_plugin_file', $this->columns( Installer::table( 'promotions' ) ) );
		$this->assertNotContains( 'destination_plugin_file', $this->columns( Installer::table( 'promotions' ) ) );

		foreach ( [ 'targets', 'workspaces', 'agent_steps', 'diagnostic_logs', 'prompt_templates' ] as $retired ) {
			try {
				Installer::table( $retired );
				$this->fail( "$retired must not be in the schema allowlist." );
			} catch ( InvalidArgumentException $error ) {
				$this->assertSame( 'Unknown WP-Autoplugin table.', $error->getMessage() );
			}
		}
	}

	/** @return array<int, string> */
	private function columns( string $table ): array {
		global $wpdb;

		return array_map(
			'strval',
			(array) $wpdb->get_col( "SHOW COLUMNS FROM $table" ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Internal allow-listed test table.
		);
	}
}
