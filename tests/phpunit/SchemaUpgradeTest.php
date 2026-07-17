<?php

use WP_Autoplugin\V2\Infrastructure\Database\Installer;

/** Verifies additive Code-slice schema upgrades. */
final class SchemaUpgradeTest extends WP_UnitTestCase {
	public function test_version_five_upgrade_preserves_revision_and_adds_code_schema(): void {
		global $wpdb;

		Installer::activate();
		$revisions = Installer::table( 'revisions' );
		$wpdb->insert(
			$revisions,
			[
				'workspace_id'    => 987654,
				'revision_number' => 1,
				'status'          => 'staged',
				'summary'         => 'Pre-Code schema fixture',
				'created_by'      => 1,
				'created_at'      => current_time( 'mysql', true ),
			]
		);
		$revision_id = (int) $wpdb->insert_id;
		update_option( 'wp_autoplugin_v2_schema_version', '5', false );

		Installer::maybe_upgrade();

		$this->assertSame( Installer::SCHEMA_VERSION, get_option( 'wp_autoplugin_v2_schema_version' ) );
		$this->assertSame( 'Pre-Code schema fixture', $wpdb->get_var( $wpdb->prepare( "SELECT summary FROM $revisions WHERE id = %d", $revision_id ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Internal test table.
		$this->assertSame( 'ai', $wpdb->get_var( $wpdb->prepare( "SELECT origin FROM $revisions WHERE id = %d", $revision_id ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Internal test table.
		$this->assertSame( Installer::table( 'code_runs' ), $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', Installer::table( 'code_runs' ) ) ) );
		$this->assertSame( Installer::table( 'code_run_files' ), $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', Installer::table( 'code_run_files' ) ) ) );

		$wpdb->delete( $revisions, [ 'id' => $revision_id ] );
	}

	public function test_version_six_upgrade_repairs_missing_code_revision_link(): void {
		global $wpdb;

		Installer::activate();
		$code_runs = Installer::table( 'code_runs' );
		$wpdb->query( "ALTER TABLE $code_runs DROP INDEX revision_id, DROP COLUMN revision_id" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Internal test table.
		update_option( 'wp_autoplugin_v2_schema_version', '6', false );

		Installer::maybe_upgrade();

		$this->assertSame( Installer::SCHEMA_VERSION, get_option( 'wp_autoplugin_v2_schema_version' ) );
		$this->assertSame(
			'revision_id',
			$wpdb->get_var( "SHOW COLUMNS FROM $code_runs LIKE 'revision_id'" ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Internal test table.
		);
		$this->assertSame(
			'revision_id',
			$wpdb->get_var( "SHOW INDEX FROM $code_runs WHERE Key_name = 'revision_id'", 2 ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Internal test table.
		);
	}

	public function test_version_seven_upgrade_adds_follow_up_state_without_losing_revisions(): void {
		global $wpdb;

		Installer::activate();
		$revisions     = Installer::table( 'revisions' );
		$code_runs     = Installer::table( 'code_runs' );
		$code_run_files = Installer::table( 'code_run_files' );
		$wpdb->insert(
			$revisions,
			[
				'workspace_id'    => 987655,
				'revision_number' => 1,
				'status'          => 'staged',
				'summary'         => 'Version seven fixture',
				'origin'          => 'ai',
				'created_by'      => 1,
				'created_at'      => current_time( 'mysql', true ),
			]
		);
		$revision_id = (int) $wpdb->insert_id;
		$wpdb->query( "ALTER TABLE $code_runs DROP INDEX mode_status" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Internal test table.
		foreach ( [ 'mode', 'phase', 'outcome', 'target_manifest', 'change_instructions', 'answer_content', 'change_summary' ] as $column ) {
			$wpdb->query( "ALTER TABLE $code_runs DROP COLUMN $column" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Internal test table.
		}
		$wpdb->query( "ALTER TABLE $revisions DROP COLUMN project_manifest" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Internal test table.
		$wpdb->query( "ALTER TABLE $code_run_files DROP COLUMN operation" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Internal test table.
		update_option( 'wp_autoplugin_v2_schema_version', '7', false );

		Installer::maybe_upgrade();

		$this->assertSame( Installer::SCHEMA_VERSION, get_option( 'wp_autoplugin_v2_schema_version' ) );
		$this->assertSame( 'Version seven fixture', $wpdb->get_var( $wpdb->prepare( "SELECT summary FROM $revisions WHERE id = %d", $revision_id ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Internal test table.
		$this->assertSame( 'project_manifest', $wpdb->get_var( "SHOW COLUMNS FROM $revisions LIKE 'project_manifest'" ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Internal test table.
		$this->assertSame( 'operation', $wpdb->get_var( "SHOW COLUMNS FROM $code_run_files LIKE 'operation'" ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Internal test table.
		foreach ( [ 'mode', 'phase', 'outcome', 'target_manifest', 'change_instructions', 'answer_content', 'change_summary' ] as $column ) {
			$this->assertSame( $column, $wpdb->get_var( "SHOW COLUMNS FROM $code_runs LIKE '$column'" ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Internal test table.
		}
		$this->assertSame( 'mode_status', $wpdb->get_var( "SHOW INDEX FROM $code_runs WHERE Key_name = 'mode_status'", 2 ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Internal test table.
		$wpdb->delete( $revisions, [ 'id' => $revision_id ] );
	}
}
