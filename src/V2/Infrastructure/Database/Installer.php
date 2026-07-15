<?php

namespace WP_Autoplugin\V2\Infrastructure\Database;

/**
 * Creates and upgrades v2 operational tables.
 */
final class Installer {
	public const SCHEMA_VERSION = '5';
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
			'diagnostic_logs',
			'prompt_templates',
			'agent_runs',
			'agent_steps',
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

		$sql = [];
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
			created_by bigint(20) unsigned NOT NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY workspace_revision (workspace_id,revision_number),
			KEY status (status)
		) $charset;";

		$sql[] = 'CREATE TABLE ' . self::table( 'revision_files' ) . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			revision_id bigint(20) unsigned NOT NULL,
			path varchar(500) NOT NULL,
			change_type varchar(20) NOT NULL,
			content longtext NULL,
			patch longtext NULL,
			content_hash char(64) NULL,
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

		$sql[] = 'CREATE TABLE ' . self::table( 'diagnostic_logs' ) . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			job_id bigint(20) unsigned NULL,
			level varchar(20) NOT NULL,
			code varchar(100) NOT NULL,
			message text NOT NULL,
			metadata longtext NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY job_id (job_id),
			KEY code (code)
		) $charset;";

		$sql[] = 'CREATE TABLE ' . self::table( 'prompt_templates' ) . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			slug varchar(100) NOT NULL,
			version int(10) unsigned NOT NULL,
			task varchar(30) NOT NULL,
			template longtext NOT NULL,
			input_schema longtext NULL,
			output_schema longtext NULL,
			is_active tinyint(1) unsigned NOT NULL DEFAULT 1,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY slug_version (slug,version),
			KEY task_active (task,is_active)
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

		if ( self::column_exists( $agent_runs, 'effort' ) ) {
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

	/**
	 * Add privacy-conscious defaults without overwriting existing choices.
	 */
	private static function add_defaults(): void {
		if ( false === get_option( 'wp_autoplugin_v2_log_mode', false ) ) {
			add_option( 'wp_autoplugin_v2_log_mode', 'metadata', '', false );
		}

		wp_set_option_autoload_values(
			[
				'wp_autoplugin_openai_api_key'    => false,
				'wp_autoplugin_anthropic_api_key' => false,
				'wp_autoplugin_google_api_key'    => false,
				'wp_autoplugin_xai_api_key'       => false,
				'wp_autoplugin_custom_models'      => false,
				'wp_autoplugin_default_model_effort'  => false,
				'wp_autoplugin_planner_model_effort'  => false,
				'wp_autoplugin_coder_model_effort'    => false,
				'wp_autoplugin_reviewer_model_effort' => false,
			]
		);
	}
}
