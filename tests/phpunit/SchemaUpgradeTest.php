<?php

use WP_Autoplugin\V2\Infrastructure\Database\Installer;
use WP_Autoplugin\V2\Infrastructure\Database\Release_Repository;

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

	public function test_version_eight_upgrade_adds_immutable_target_baselines(): void {
		global $wpdb;

		Installer::activate();
		$files = Installer::table( 'revision_files' );
		$wpdb->query( "ALTER TABLE $files DROP COLUMN base_content_hash, DROP COLUMN base_content" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Internal test table.
		update_option( 'wp_autoplugin_v2_schema_version', '8', false );

		Installer::maybe_upgrade();

		$this->assertSame( Installer::SCHEMA_VERSION, get_option( 'wp_autoplugin_v2_schema_version' ) );
		$this->assertSame( 'base_content', $wpdb->get_var( "SHOW COLUMNS FROM $files LIKE 'base_content'" ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Internal test table.
		$this->assertSame( 'base_content_hash', $wpdb->get_var( "SHOW COLUMNS FROM $files LIKE 'base_content_hash'" ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Internal test table.
	}

	public function test_version_nine_upgrade_preserves_revisions_and_adds_review_release_schema(): void {
		global $wpdb;

		Installer::activate();
		$revisions = Installer::table( 'revisions' );
		$wpdb->insert(
			$revisions,
			[
				'workspace_id'    => 987656,
				'revision_number' => 1,
				'status'          => 'staged',
				'summary'         => 'Version nine fixture',
				'origin'          => 'ai',
				'created_by'      => 1,
				'created_at'      => current_time( 'mysql', true ),
			]
		);
		$revision_id = (int) $wpdb->insert_id;
		$tables      = [ 'promotion_files', 'promotions', 'release_packages', 'review_finding_events', 'review_findings', 'review_reports' ];
		foreach ( $tables as $table ) {
			$wpdb->query( 'DROP TABLE ' . Installer::table( $table ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Internal allow-listed test tables.
		}
		update_option( 'wp_autoplugin_v2_schema_version', '9', false );

		Installer::maybe_upgrade();

		$this->assertSame( Installer::SCHEMA_VERSION, get_option( 'wp_autoplugin_v2_schema_version' ) );
		$this->assertSame( 'Version nine fixture', $wpdb->get_var( $wpdb->prepare( "SELECT summary FROM $revisions WHERE id = %d", $revision_id ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Internal test table.
		foreach ( array_reverse( $tables ) as $table ) {
			$this->assertSame( Installer::table( $table ), $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', Installer::table( $table ) ) ) );
		}
		$packages = Installer::table( 'release_packages' );
		$this->assertSame( 'header_transforms', $wpdb->get_var( "SHOW COLUMNS FROM $packages LIKE 'header_transforms'" ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Internal test table.
		$wpdb->delete( $revisions, [ 'id' => $revision_id ] );
	}

	public function test_version_ten_upgrade_preserves_existing_data_and_adds_prompt_attachment_tables(): void {
		global $wpdb;

		Installer::activate();
		$revisions = Installer::table( 'revisions' );
		$wpdb->insert(
			$revisions,
			[
				'workspace_id'    => 987657,
				'revision_number' => 1,
				'status'          => 'staged',
				'summary'         => 'Version ten fixture',
				'origin'          => 'ai',
				'created_by'      => 1,
				'created_at'      => current_time( 'mysql', true ),
			]
		);
		$revision_id = (int) $wpdb->insert_id;
		$wpdb->query( 'DROP TABLE ' . Installer::table( 'job_prompt_attachments' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Internal allow-listed test table.
		$wpdb->query( 'DROP TABLE ' . Installer::table( 'prompt_attachments' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Internal allow-listed test table.
		update_option( 'wp_autoplugin_v2_schema_version', '10', false );

		Installer::maybe_upgrade();

		$this->assertSame( Installer::SCHEMA_VERSION, get_option( 'wp_autoplugin_v2_schema_version' ) );
		$this->assertSame( 'Version ten fixture', $wpdb->get_var( $wpdb->prepare( "SELECT summary FROM $revisions WHERE id = %d", $revision_id ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Internal test table.
		foreach ( [ 'prompt_attachments', 'job_prompt_attachments' ] as $table ) {
			$this->assertSame( Installer::table( $table ), $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', Installer::table( $table ) ) ) );
		}
		$wpdb->delete( $revisions, [ 'id' => $revision_id ] );
	}

	public function test_version_eleven_upgrade_preserves_plugin_release_history_and_hydrates_generic_refs(): void {
		global $wpdb;

		Installer::activate();
		$packages   = Installer::table( 'release_packages' );
		$promotions = Installer::table( 'promotions' );
		foreach ( [ 'target_ref', 'artifact_kind' ] as $column ) {
			$wpdb->query( "ALTER TABLE $packages DROP COLUMN $column" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Internal test table.
		}
		foreach ( [ 'destination_target_ref', 'source_target_ref', 'artifact_kind' ] as $column ) {
			$wpdb->query( "ALTER TABLE $promotions DROP COLUMN $column" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Internal test table.
		}
		$wpdb->query( "ALTER TABLE $promotions MODIFY mode varchar(20) NOT NULL" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Internal test table.

		$job_id = wp_rand( 700000, 799999 );
		$now    = current_time( 'mysql', true );
		$wpdb->insert(
			$packages,
			[
				'job_id'         => $job_id,
				'workspace_id'   => 987658,
				'revision_id'    => 765432,
				'mode'           => 'replacement',
				'status'         => 'ready',
				'slug'           => 'fixture-plugin',
				'plugin_file'    => 'fixture-plugin/fixture-plugin.php',
				'review_override' => 0,
				'created_by'     => 1,
				'created_at'     => $now,
				'updated_at'     => $now,
			]
		);
		$package_id = (int) $wpdb->insert_id;
		$wpdb->insert(
			$promotions,
			[
				'job_id'                  => $job_id + 1,
				'workspace_id'            => 987658,
				'revision_id'             => 765432,
				'mode'                    => 'modify_original',
				'status'                  => 'completed',
				'source_plugin_file'      => 'fixture-plugin/fixture-plugin.php',
				'destination_plugin_file' => 'fixture-plugin/fixture-plugin.php',
				'destination_slug'        => 'fixture-plugin',
				'review_override'         => 0,
				'created_by'              => 1,
				'created_at'              => $now,
				'updated_at'              => $now,
			]
		);
		$promotion_id = (int) $wpdb->insert_id;
		update_option( 'wp_autoplugin_v2_schema_version', '11', false );

		Installer::maybe_upgrade();

		$this->assertSame( Installer::SCHEMA_VERSION, get_option( 'wp_autoplugin_v2_schema_version' ) );
		foreach ( [ 'artifact_kind', 'target_ref' ] as $column ) {
			$this->assertSame( $column, $wpdb->get_var( "SHOW COLUMNS FROM $packages LIKE '$column'" ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Internal test table.
		}
		foreach ( [ 'artifact_kind', 'source_target_ref', 'destination_target_ref' ] as $column ) {
			$this->assertSame( $column, $wpdb->get_var( "SHOW COLUMNS FROM $promotions LIKE '$column'" ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Internal test table.
		}
		$this->assertSame( 'varchar(30)', $wpdb->get_var( "SHOW COLUMNS FROM $promotions LIKE 'mode'", 1 ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Internal test table.
		$release = new Release_Repository();
		$package = $release->package( $package_id );
		$promotion = $release->promotion( $promotion_id );
		$this->assertSame( 'plugin', $package['artifact_kind'] );
		$this->assertSame( 'fixture-plugin/fixture-plugin.php', $package['target_ref'] );
		$this->assertSame( 'plugin', $promotion['artifact_kind'] );
		$this->assertSame( 'fixture-plugin/fixture-plugin.php', $promotion['source_target_ref'] );
		$this->assertSame( 'fixture-plugin/fixture-plugin.php', $promotion['destination_target_ref'] );

		$wpdb->delete( $packages, [ 'id' => $package_id ] );
		$wpdb->delete( $promotions, [ 'id' => $promotion_id ] );
	}
}
