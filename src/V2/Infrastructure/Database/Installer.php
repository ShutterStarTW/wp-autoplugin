<?php

namespace WP_Autoplugin\V2\Infrastructure\Database;

/**
 * Creates and upgrades v2 operational tables.
 */
final class Installer {
	public const SCHEMA_VERSION = '14';
	private const OPTION_NAME   = 'wp_autoplugin_v2_schema_version';

	/**
	 * Run activation tasks.
	 */
	public static function activate(): void {
		self::install_schema();
		self::add_defaults();
	}

	/**
	 * Upgrade the schema when plugin files are newer than the database.
	 */
	public static function maybe_upgrade(): void {
		if ( self::SCHEMA_VERSION !== get_option( self::OPTION_NAME ) ) {
			self::install_schema();
			self::add_defaults();
		}
	}

	/**
	 * Return a table name with the current WordPress prefix.
	 *
	 * @param string $suffix Logical table suffix.
	 */
	public static function table( string $suffix ): string {
		global $wpdb;

		$allowed = [
			'targets',
			'projects',
			'workspaces',
			'revisions',
			'revision_files',
			'jobs',
			'job_events',
			'usage',
			'agent_runs',
			'agent_steps',
			'code_runs',
			'code_run_files',
			'review_reports',
			'review_findings',
			'review_finding_events',
			'release_packages',
			'promotions',
			'promotion_files',
			'prompt_attachments',
			'job_prompt_attachments',
		];

		if ( ! in_array( $suffix, $allowed, true ) ) {
			throw new \InvalidArgumentException( 'Unknown WP-Autoplugin table.' );
		}

		return $wpdb->prefix . 'wp_autoplugin_' . $suffix;
	}

	/**
	 * Install tables with dbDelta so upgrades remain non-destructive.
	 */
	private static function install_schema(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset = $wpdb->get_charset_collate();

		$sql   = [];
		$sql[] = 'CREATE TABLE ' . self::table( 'targets' ) . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			kind varchar(20) NOT NULL,
			ref varchar(255) NOT NULL,
			name varchar(255) NOT NULL,
			metadata longtext NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY kind_ref (kind,ref)
		) $charset;";

		$sql[] = 'CREATE TABLE ' . self::table( 'projects' ) . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			target_id bigint(20) unsigned NULL,
			name varchar(255) NOT NULL,
			status varchar(20) NOT NULL DEFAULT 'active',
			created_by bigint(20) unsigned NOT NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY target_id (target_id),
			KEY created_by (created_by)
		) $charset;";

		$sql[] = 'CREATE TABLE ' . self::table( 'workspaces' ) . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			project_id bigint(20) unsigned NOT NULL,
			operation varchar(30) NOT NULL,
			status varchar(20) NOT NULL DEFAULT 'draft',
			is_closed tinyint(1) unsigned NOT NULL DEFAULT 0,
			request longtext NULL,
			base_revision_id bigint(20) unsigned NULL,
			created_by bigint(20) unsigned NOT NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			closed_at datetime NULL,
			PRIMARY KEY  (id),
			KEY project_id (project_id),
			KEY status (status),
			KEY user_open (created_by,is_closed)
		) $charset;";

		$sql[] = 'CREATE TABLE ' . self::table( 'revisions' ) . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			workspace_id bigint(20) unsigned NOT NULL,
			revision_number int(10) unsigned NOT NULL,
			status varchar(20) NOT NULL DEFAULT 'staged',
			summary text NULL,
			origin varchar(20) NOT NULL DEFAULT 'ai',
			plan_job_id bigint(20) unsigned NULL,
			source_job_id bigint(20) unsigned NULL,
			parent_revision_id bigint(20) unsigned NULL,
			restored_from_revision_id bigint(20) unsigned NULL,
			project_manifest longtext NULL,
			created_by bigint(20) unsigned NOT NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY workspace_revision (workspace_id,revision_number),
			KEY status (status),
			KEY parent_revision_id (parent_revision_id),
			KEY plan_job_id (plan_job_id)
		) $charset;";

		$sql[] = 'CREATE TABLE ' . self::table( 'revision_files' ) . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			revision_id bigint(20) unsigned NOT NULL,
			path varchar(500) NOT NULL,
			change_type varchar(20) NOT NULL,
			content longtext NULL,
			patch longtext NULL,
			content_hash char(64) NULL,
			base_content longtext NULL,
			base_content_hash char(64) NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY revision_path (revision_id,path(191))
		) $charset;";

		$sql[] = 'CREATE TABLE ' . self::table( 'jobs' ) . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			workspace_id bigint(20) unsigned NOT NULL,
			task varchar(30) NOT NULL,
			status varchar(20) NOT NULL DEFAULT 'queued',
			progress smallint(5) unsigned NOT NULL DEFAULT 0,
			cancel_requested tinyint(1) unsigned NOT NULL DEFAULT 0,
			runner varchar(30) NULL,
			payload longtext NULL,
			global_instructions longtext NULL,
			global_instructions_hash char(64) NULL,
			result longtext NULL,
			error_message text NULL,
			created_by bigint(20) unsigned NOT NULL,
			created_at datetime NOT NULL,
			started_at datetime NULL,
			finished_at datetime NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY workspace_id (workspace_id),
			KEY status (status)
		) $charset;";

		$sql[] = 'CREATE TABLE ' . self::table( 'job_events' ) . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			job_id bigint(20) unsigned NOT NULL,
			sequence int(10) unsigned NOT NULL,
			level varchar(20) NOT NULL DEFAULT 'info',
			event varchar(50) NOT NULL,
			message text NULL,
			context longtext NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY job_sequence (job_id,sequence)
		) $charset;";

		$sql[] = 'CREATE TABLE ' . self::table( 'prompt_attachments' ) . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			workspace_id bigint(20) unsigned NOT NULL,
			created_by bigint(20) unsigned NOT NULL,
			filename varchar(255) NOT NULL,
			mime_type varchar(50) NOT NULL,
			byte_size bigint(20) unsigned NOT NULL,
			width int(10) unsigned NOT NULL,
			height int(10) unsigned NOT NULL,
			sha256 char(64) NOT NULL,
			content longblob NOT NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY workspace_id (workspace_id),
			KEY created_by (created_by),
			KEY workspace_hash (workspace_id,sha256)
		) $charset;";

		$sql[] = 'CREATE TABLE ' . self::table( 'job_prompt_attachments' ) . " (
			job_id bigint(20) unsigned NOT NULL,
			attachment_id bigint(20) unsigned NOT NULL,
			sequence smallint(5) unsigned NOT NULL,
			PRIMARY KEY  (job_id,attachment_id),
			UNIQUE KEY job_sequence (job_id,sequence),
			KEY attachment_id (attachment_id)
		) $charset;";

		$sql[] = 'CREATE TABLE ' . self::table( 'usage' ) . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			job_id bigint(20) unsigned NOT NULL,
			provider varchar(50) NOT NULL,
			model varchar(100) NOT NULL,
			task varchar(30) NOT NULL,
			input_tokens bigint(20) unsigned NOT NULL DEFAULT 0,
			output_tokens bigint(20) unsigned NOT NULL DEFAULT 0,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY job_id (job_id)
		) $charset;";

		$sql[] = 'CREATE TABLE ' . self::table( 'agent_runs' ) . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			job_id bigint(20) unsigned NOT NULL,
			status varchar(20) NOT NULL DEFAULT 'active',
			generation int(10) unsigned NOT NULL DEFAULT 0,
			model_turns smallint(5) unsigned NOT NULL DEFAULT 0,
			tool_calls smallint(5) unsigned NOT NULL DEFAULT 0,
			source_bytes bigint(20) unsigned NOT NULL DEFAULT 0,
			input_tokens bigint(20) unsigned NOT NULL DEFAULT 0,
			output_tokens bigint(20) unsigned NOT NULL DEFAULT 0,
			provider varchar(50) NOT NULL,
			model varchar(100) NOT NULL,
			effort varchar(20) NOT NULL DEFAULT '',
			transcript longtext NULL,
			tree_fingerprint char(64) NOT NULL,
			inspected_files longtext NULL,
			lease_token varchar(64) NULL,
			lease_expires_at datetime NULL,
			retry_count smallint(5) unsigned NOT NULL DEFAULT 0,
			last_error text NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY job_id (job_id),
			KEY status_lease (status,lease_expires_at)
		) $charset;";

		$sql[] = 'CREATE TABLE ' . self::table( 'agent_steps' ) . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			run_id bigint(20) unsigned NOT NULL,
			sequence int(10) unsigned NOT NULL,
			kind varchar(20) NOT NULL,
			tool_name varchar(50) NULL,
			path varchar(500) NULL,
			payload longtext NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY run_sequence (run_id,sequence),
			KEY run_kind (run_id,kind)
		) $charset;";

		$sql[] = 'CREATE TABLE ' . self::table( 'code_runs' ) . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			job_id bigint(20) unsigned NOT NULL,
			plan_job_id bigint(20) unsigned NOT NULL,
			revision_id bigint(20) unsigned NULL,
			parent_revision_id bigint(20) unsigned NULL,
			status varchar(20) NOT NULL DEFAULT 'active',
			mode varchar(20) NOT NULL DEFAULT 'generate',
			phase varchar(20) NOT NULL DEFAULT 'files',
			outcome varchar(20) NULL,
			generation int(10) unsigned NOT NULL DEFAULT 0,
			next_file_index smallint(5) unsigned NOT NULL DEFAULT 0,
			provider varchar(50) NOT NULL,
			model varchar(100) NOT NULL,
			effort varchar(20) NOT NULL DEFAULT '',
			prompt_slug varchar(100) NOT NULL,
			prompt_version int(10) unsigned NOT NULL,
			lease_token varchar(64) NULL,
			lease_expires_at datetime NULL,
			retry_count smallint(5) unsigned NOT NULL DEFAULT 0,
			input_tokens bigint(20) unsigned NOT NULL DEFAULT 0,
			output_tokens bigint(20) unsigned NOT NULL DEFAULT 0,
			target_manifest longtext NULL,
			change_instructions longtext NULL,
			answer_content longtext NULL,
			change_summary longtext NULL,
			last_error text NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY job_id (job_id),
			KEY revision_id (revision_id),
			KEY mode_status (mode,status),
			KEY status_lease (status,lease_expires_at)
		) $charset;";

		$sql[] = 'CREATE TABLE ' . self::table( 'code_run_files' ) . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			run_id bigint(20) unsigned NOT NULL,
			sequence smallint(5) unsigned NOT NULL,
			path varchar(500) NOT NULL,
			type varchar(10) NOT NULL,
			description text NULL,
			operation varchar(10) NOT NULL DEFAULT 'add',
			status varchar(20) NOT NULL DEFAULT 'pending',
			content longtext NULL,
			content_hash char(64) NULL,
			error_metadata longtext NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY run_path (run_id,path(191)),
			UNIQUE KEY run_sequence (run_id,sequence),
			KEY run_status (run_id,status)
		) $charset;";

		$sql[] = 'CREATE TABLE ' . self::table( 'review_reports' ) . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			job_id bigint(20) unsigned NOT NULL,
			workspace_id bigint(20) unsigned NOT NULL,
			revision_id bigint(20) unsigned NOT NULL,
			parent_report_id bigint(20) unsigned NULL,
			mode varchar(20) NOT NULL DEFAULT 'initial',
			verdict varchar(30) NOT NULL,
			summary longtext NOT NULL,
			tests longtext NULL,
			provider varchar(50) NOT NULL,
			model varchar(100) NOT NULL,
			effort varchar(20) NOT NULL DEFAULT '',
			prompt_slug varchar(100) NOT NULL,
			prompt_version int(10) unsigned NOT NULL,
			created_by bigint(20) unsigned NOT NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY job_id (job_id),
			KEY workspace_revision (workspace_id,revision_id),
			KEY parent_report_id (parent_report_id)
		) $charset;";

		$sql[] = 'CREATE TABLE ' . self::table( 'review_findings' ) . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			workspace_id bigint(20) unsigned NOT NULL,
			created_report_id bigint(20) unsigned NOT NULL,
			latest_report_id bigint(20) unsigned NOT NULL,
			status varchar(30) NOT NULL DEFAULT 'open',
			priority varchar(2) NOT NULL,
			category varchar(30) NOT NULL,
			title varchar(255) NOT NULL,
			body longtext NOT NULL,
			suggested_fix longtext NULL,
			path varchar(500) NULL,
			side varchar(10) NULL,
			start_line int(10) unsigned NULL,
			end_line int(10) unsigned NULL,
			anchor_hash char(64) NULL,
			addressed_by_revision_id bigint(20) unsigned NULL,
			dismissed_by bigint(20) unsigned NULL,
			dismissed_at datetime NULL,
			created_by bigint(20) unsigned NOT NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY workspace_status (workspace_id,status),
			KEY latest_report_id (latest_report_id),
			KEY addressed_revision (addressed_by_revision_id)
		) $charset;";

		$sql[] = 'CREATE TABLE ' . self::table( 'review_finding_events' ) . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			finding_id bigint(20) unsigned NOT NULL,
			report_id bigint(20) unsigned NULL,
			revision_id bigint(20) unsigned NOT NULL,
			job_id bigint(20) unsigned NULL,
			event varchar(30) NOT NULL,
			actor varchar(20) NOT NULL,
			message text NULL,
			snapshot longtext NULL,
			created_by bigint(20) unsigned NOT NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY finding_id (finding_id),
			KEY report_id (report_id),
			KEY revision_id (revision_id)
		) $charset;";

		$sql[] = 'CREATE TABLE ' . self::table( 'release_packages' ) . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			job_id bigint(20) unsigned NOT NULL,
			workspace_id bigint(20) unsigned NOT NULL,
			revision_id bigint(20) unsigned NOT NULL,
			review_report_id bigint(20) unsigned NULL,
			mode varchar(20) NOT NULL,
			status varchar(20) NOT NULL DEFAULT 'queued',
			artifact_kind varchar(20) NOT NULL DEFAULT 'plugin',
			target_ref varchar(500) NULL,
			slug varchar(100) NOT NULL,
			plugin_file varchar(500) NULL,
			temp_path text NULL,
			sha256 char(64) NULL,
			size bigint(20) unsigned NOT NULL DEFAULT 0,
			source_fingerprint char(64) NULL,
			artifact_fingerprint char(64) NULL,
			header_transforms longtext NULL,
			review_override tinyint(1) unsigned NOT NULL DEFAULT 0,
			error_message text NULL,
			expires_at datetime NULL,
			created_by bigint(20) unsigned NOT NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY job_id (job_id),
			KEY workspace_revision (workspace_id,revision_id),
			KEY status_expires (status,expires_at)
		) $charset;";

		$sql[] = 'CREATE TABLE ' . self::table( 'promotions' ) . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			job_id bigint(20) unsigned NOT NULL,
			workspace_id bigint(20) unsigned NOT NULL,
			revision_id bigint(20) unsigned NOT NULL,
			review_report_id bigint(20) unsigned NULL,
			mode varchar(30) NOT NULL,
			status varchar(30) NOT NULL DEFAULT 'queued',
			artifact_kind varchar(20) NOT NULL DEFAULT 'plugin',
			source_target_ref varchar(500) NULL,
			destination_target_ref varchar(500) NULL,
			source_plugin_file varchar(500) NULL,
			destination_plugin_file varchar(500) NULL,
			destination_slug varchar(100) NULL,
			target_fingerprint char(64) NULL,
			header_transforms longtext NULL,
			created_directories longtext NULL,
			active_before tinyint(1) unsigned NOT NULL DEFAULT 0,
			active_after tinyint(1) unsigned NOT NULL DEFAULT 0,
			review_override tinyint(1) unsigned NOT NULL DEFAULT 0,
			error_message text NULL,
			created_by bigint(20) unsigned NOT NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			finished_at datetime NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY job_id (job_id),
			KEY workspace_revision (workspace_id,revision_id),
			KEY destination_status (destination_plugin_file(191),status)
		) $charset;";

		$sql[] = 'CREATE TABLE ' . self::table( 'promotion_files' ) . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			promotion_id bigint(20) unsigned NOT NULL,
			path varchar(500) NOT NULL,
			operation varchar(10) NOT NULL,
			base_exists tinyint(1) unsigned NOT NULL DEFAULT 0,
			base_content longtext NULL,
			base_hash char(64) NULL,
			promoted_exists tinyint(1) unsigned NOT NULL DEFAULT 0,
			promoted_content longtext NULL,
			promoted_hash char(64) NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY promotion_path (promotion_id,path(191))
		) $charset;";

		dbDelta( $sql );

		/*
		 * dbDelta can miss an empty-string-default column on an existing table.
		 * Keep the declarative schema above for clean installs and explicitly
		 * repair this additive column before marking the migration complete.
		 */
		$agent_runs = self::table( 'agent_runs' );
		maybe_add_column(
			$agent_runs,
			'effort',
			"ALTER TABLE $agent_runs ADD effort varchar(20) NOT NULL DEFAULT '' AFTER model"
		);

		$revisions        = self::table( 'revisions' );
		$revision_columns = [
			'origin'                    => "ALTER TABLE $revisions ADD origin varchar(20) NOT NULL DEFAULT 'ai' AFTER summary",
			'plan_job_id'               => "ALTER TABLE $revisions ADD plan_job_id bigint(20) unsigned NULL AFTER origin",
			'source_job_id'             => "ALTER TABLE $revisions ADD source_job_id bigint(20) unsigned NULL AFTER plan_job_id",
			'parent_revision_id'        => "ALTER TABLE $revisions ADD parent_revision_id bigint(20) unsigned NULL AFTER source_job_id",
			'restored_from_revision_id' => "ALTER TABLE $revisions ADD restored_from_revision_id bigint(20) unsigned NULL AFTER parent_revision_id",
			'project_manifest'          => "ALTER TABLE $revisions ADD project_manifest longtext NULL AFTER restored_from_revision_id",
		];
		foreach ( $revision_columns as $column => $alter ) {
			maybe_add_column( $revisions, $column, $alter );
		}

		$revision_ready        = ! array_filter(
			array_keys( $revision_columns ),
			static fn( string $column ): bool => ! self::column_exists( $revisions, $column )
		);
		$revision_files        = self::table( 'revision_files' );
		$revision_file_columns = [
			'base_content'      => "ALTER TABLE $revision_files ADD base_content longtext NULL AFTER content_hash",
			'base_content_hash' => "ALTER TABLE $revision_files ADD base_content_hash char(64) NULL AFTER base_content",
		];
		foreach ( $revision_file_columns as $column => $alter ) {
			maybe_add_column( $revision_files, $column, $alter );
		}
		$revision_file_ready = ! array_filter(
			array_keys( $revision_file_columns ),
			static fn( string $column ): bool => ! self::column_exists( $revision_files, $column )
		);
		$jobs                = self::table( 'jobs' );
		$job_columns         = [
			'global_instructions'      => "ALTER TABLE $jobs ADD global_instructions longtext NULL AFTER payload",
			'global_instructions_hash' => "ALTER TABLE $jobs ADD global_instructions_hash char(64) NULL AFTER global_instructions",
		];
		foreach ( $job_columns as $column => $alter ) {
			maybe_add_column( $jobs, $column, $alter );
		}
		$job_ready = ! array_filter(
			array_keys( $job_columns ),
			static fn( string $column ): bool => ! self::column_exists( $jobs, $column )
		);
		$code_runs = self::table( 'code_runs' );
		maybe_add_column(
			$code_runs,
			'revision_id',
			"ALTER TABLE $code_runs ADD revision_id bigint(20) unsigned NULL AFTER plan_job_id"
		);
		$code_run_columns = [
			'mode'                => "ALTER TABLE $code_runs ADD mode varchar(20) NOT NULL DEFAULT 'generate' AFTER status",
			'phase'               => "ALTER TABLE $code_runs ADD phase varchar(20) NOT NULL DEFAULT 'files' AFTER mode",
			'outcome'             => "ALTER TABLE $code_runs ADD outcome varchar(20) NULL AFTER phase",
			'target_manifest'     => "ALTER TABLE $code_runs ADD target_manifest longtext NULL AFTER output_tokens",
			'change_instructions' => "ALTER TABLE $code_runs ADD change_instructions longtext NULL AFTER target_manifest",
			'answer_content'      => "ALTER TABLE $code_runs ADD answer_content longtext NULL AFTER change_instructions",
			'change_summary'      => "ALTER TABLE $code_runs ADD change_summary longtext NULL AFTER answer_content",
		];
		foreach ( $code_run_columns as $column => $alter ) {
			maybe_add_column( $code_runs, $column, $alter );
		}
		if ( self::column_exists( $code_runs, 'revision_id' ) && ! self::index_exists( $code_runs, 'revision_id' ) ) {
			$wpdb->query( "ALTER TABLE $code_runs ADD KEY revision_id (revision_id)" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Internal allow-listed table.
		}
		if ( self::column_exists( $code_runs, 'mode' ) && self::column_exists( $code_runs, 'status' ) && ! self::index_exists( $code_runs, 'mode_status' ) ) {
			$wpdb->query( "ALTER TABLE $code_runs ADD KEY mode_status (mode,status)" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Internal allow-listed table.
		}

		$code_run_files = self::table( 'code_run_files' );
		maybe_add_column(
			$code_run_files,
			'operation',
			"ALTER TABLE $code_run_files ADD operation varchar(10) NOT NULL DEFAULT 'add' AFTER description"
		);
		$code_run_ready          = ! array_filter(
			array_keys( $code_run_columns ),
			static fn( string $column ): bool => ! self::column_exists( $code_runs, $column )
		);
		$release_packages        = self::table( 'release_packages' );
		$release_package_columns = [
			'artifact_kind'        => "ALTER TABLE $release_packages ADD artifact_kind varchar(20) NOT NULL DEFAULT 'plugin' AFTER status",
			'target_ref'           => "ALTER TABLE $release_packages ADD target_ref varchar(500) NULL AFTER artifact_kind",
			'source_fingerprint'   => "ALTER TABLE $release_packages ADD source_fingerprint char(64) NULL AFTER size",
			'artifact_fingerprint' => "ALTER TABLE $release_packages ADD artifact_fingerprint char(64) NULL AFTER source_fingerprint",
			'header_transforms'    => "ALTER TABLE $release_packages ADD header_transforms longtext NULL AFTER artifact_fingerprint",
		];
		foreach ( $release_package_columns as $column => $alter ) {
			maybe_add_column( $release_packages, $column, $alter );
		}
		$release_package_ready = ! array_filter(
			array_keys( $release_package_columns ),
			static fn( string $column ): bool => ! self::column_exists( $release_packages, $column )
		);
		$promotions            = self::table( 'promotions' );
		$promotion_columns     = [
			'artifact_kind'          => "ALTER TABLE $promotions ADD artifact_kind varchar(20) NOT NULL DEFAULT 'plugin' AFTER status",
			'source_target_ref'      => "ALTER TABLE $promotions ADD source_target_ref varchar(500) NULL AFTER artifact_kind",
			'destination_target_ref' => "ALTER TABLE $promotions ADD destination_target_ref varchar(500) NULL AFTER source_target_ref",
		];
		foreach ( $promotion_columns as $column => $alter ) {
			maybe_add_column( $promotions, $column, $alter );
		}
		if ( 'varchar(30)' !== self::column_type( $promotions, 'mode' ) ) {
			$wpdb->query( "ALTER TABLE $promotions MODIFY mode varchar(30) NOT NULL" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Internal allow-listed table.
		}
		$promotion_ready         = ! array_filter(
			array_keys( $promotion_columns ),
			static fn( string $column ): bool => ! self::column_exists( $promotions, $column )
		) && 'varchar(30)' === self::column_type( $promotions, 'mode' );
		$review_release_tables   = [ 'review_reports', 'review_findings', 'review_finding_events', 'release_packages', 'promotions', 'promotion_files' ];
		$review_release_ready    = ! array_filter(
			$review_release_tables,
			static fn( string $table ): bool => ! self::table_exists( self::table( $table ) )
		);
		$prompt_attachment_ready = self::table_exists( self::table( 'prompt_attachments' ) ) && self::table_exists( self::table( 'job_prompt_attachments' ) );
		if ( self::column_exists( $agent_runs, 'effort' ) && $revision_ready && $revision_file_ready && $job_ready && self::table_exists( $code_runs ) && self::column_exists( $code_runs, 'revision_id' ) && self::index_exists( $code_runs, 'revision_id' ) && self::index_exists( $code_runs, 'mode_status' ) && $code_run_ready && self::table_exists( $code_run_files ) && self::column_exists( $code_run_files, 'operation' ) && $review_release_ready && $release_package_ready && $promotion_ready && $prompt_attachment_ready ) {
			update_option( self::OPTION_NAME, self::SCHEMA_VERSION, false );
		}
	}

	/**
	 * Check whether an additive migration column is present.
	 */
	private static function column_exists( string $table, string $column ): bool {
		global $wpdb;

		$query = $wpdb->prepare( "SHOW COLUMNS FROM $table LIKE %s", $column ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Internal allow-listed table.
		return null !== $wpdb->get_var( $query ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Prepared immediately above.
	}

	private static function column_type( string $table, string $column ): string {
		global $wpdb;

		$query = $wpdb->prepare( "SHOW COLUMNS FROM $table LIKE %s", $column ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Internal allow-listed table.
		$row   = $wpdb->get_row( $query, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Prepared immediately above.
		return strtolower( (string) ( $row['Type'] ?? '' ) );
	}

	private static function table_exists( string $table ): bool {
		global $wpdb;

		return $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
	}

	private static function index_exists( string $table, string $index ): bool {
		global $wpdb;

		$query = $wpdb->prepare( "SHOW INDEX FROM $table WHERE Key_name = %s", $index ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Internal allow-listed table.
		return $index === $wpdb->get_var( $query, 2 ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Prepared immediately above.
	}

	/**
	 * Add privacy-conscious defaults without overwriting existing choices.
	 */
	private static function add_defaults(): void {
		if ( false === get_option( 'wp_autoplugin_custom_instructions', false ) ) {
			add_option( 'wp_autoplugin_custom_instructions', '', '', false );
		}

		wp_set_option_autoload_values(
			[
				'wp_autoplugin_openai_api_key'           => false,
				'wp_autoplugin_anthropic_api_key'        => false,
				'wp_autoplugin_google_api_key'           => false,
				'wp_autoplugin_xai_api_key'              => false,
				'wp_autoplugin_custom_models'            => false,
				'wp_autoplugin_custom_instructions'      => false,
				'wp_autoplugin_default_model_effort'     => false,
				'wp_autoplugin_planner_model_effort'     => false,
				'wp_autoplugin_coder_model_effort'       => false,
				'wp_autoplugin_reviewer_model_effort'    => false,
				'_wp_autoplugin_chatgpt_oauth_tokens'    => false,
				'_wp_autoplugin_chatgpt_oauth_lock'      => false,
				'_wp_autoplugin_chatgpt_oauth_poll_lock' => false,
				'_wp_autoplugin_chatgpt_models_lock'     => false,
				'wp_autoplugin_chatgpt_model_cache'      => false,
				'wp_autoplugin_v2_planner_model'         => false,
				'wp_autoplugin_v2_coder_model'           => false,
				'wp_autoplugin_v2_reviewer_model'        => false,
				'wp_autoplugin_v2_planner_model_effort'  => false,
				'wp_autoplugin_v2_coder_model_effort'    => false,
				'wp_autoplugin_v2_reviewer_model_effort' => false,
			]
		);
	}
}
