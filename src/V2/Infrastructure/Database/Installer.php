<?php

namespace WP_Autoplugin\V2\Infrastructure\Database;

/**
 * Creates the v2 operational schema.
 *
 * V2 has not shipped, so this is intentionally a clean baseline rather than a
 * chain of compatibility migrations. Development databases must be reset when
 * this schema changes incompatibly.
 */
final class Installer {
	public const SCHEMA_VERSION = '15';
	private const OPTION_NAME   = 'wp_autoplugin_v2_schema_version';

	/**
	 * Run activation tasks.
	 */
	public static function activate(): void {
		self::install_schema();
		self::add_defaults();
	}

	/**
	 * Install the current schema when plugin files are newer than the database.
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
	 * Install the declarative baseline schema.
	 */
	private static function install_schema(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset = $wpdb->get_charset_collate();
		$sql     = [];

		$sql[] = 'CREATE TABLE ' . self::table( 'projects' ) . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			name varchar(255) NOT NULL,
			target_kind varchar(20) NOT NULL,
			target_ref varchar(255) NOT NULL,
			target_snapshot longtext NULL,
			operation varchar(30) NOT NULL,
			is_closed tinyint(1) unsigned NOT NULL DEFAULT 0,
			request longtext NULL,
			created_by bigint(20) unsigned NOT NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			closed_at datetime NULL,
			PRIMARY KEY  (id),
			KEY target (target_kind,target_ref(191)),
			KEY user_open (created_by,is_closed)
		) $charset;";

		$sql[] = 'CREATE TABLE ' . self::table( 'plans' ) . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			project_id bigint(20) unsigned NOT NULL,
			plan_number int(10) unsigned NOT NULL,
			parent_plan_id bigint(20) unsigned NULL,
			source_job_id bigint(20) unsigned NULL,
			status varchar(20) NOT NULL DEFAULT 'ready',
			origin varchar(20) NOT NULL DEFAULT 'ai',
			content longtext NOT NULL,
			structured longtext NULL,
			created_by bigint(20) unsigned NOT NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY project_plan (project_id,plan_number),
			KEY parent_plan_id (parent_plan_id),
			UNIQUE KEY source_job_id (source_job_id),
			KEY project_status (project_id,status)
		) $charset;";

		$sql[] = 'CREATE TABLE ' . self::table( 'revisions' ) . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			project_id bigint(20) unsigned NOT NULL,
			revision_number int(10) unsigned NOT NULL,
			summary text NULL,
			origin varchar(20) NOT NULL DEFAULT 'ai',
			plan_id bigint(20) unsigned NULL,
			source_job_id bigint(20) unsigned NULL,
			parent_revision_id bigint(20) unsigned NULL,
			restored_from_revision_id bigint(20) unsigned NULL,
			project_manifest longtext NULL,
			created_by bigint(20) unsigned NOT NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY project_revision (project_id,revision_number),
			KEY parent_revision_id (parent_revision_id),
			KEY plan_id (plan_id)
		) $charset;";

		$sql[] = 'CREATE TABLE ' . self::table( 'revision_files' ) . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			revision_id bigint(20) unsigned NOT NULL,
			path varchar(500) NOT NULL,
			change_type varchar(20) NOT NULL,
			content longtext NULL,
			content_hash char(64) NULL,
			base_content longtext NULL,
			base_content_hash char(64) NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY revision_path (revision_id,path(191))
		) $charset;";

		$sql[] = 'CREATE TABLE ' . self::table( 'jobs' ) . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			project_id bigint(20) unsigned NOT NULL,
			task varchar(30) NOT NULL,
			status varchar(20) NOT NULL DEFAULT 'queued',
			progress smallint(5) unsigned NOT NULL DEFAULT 0,
			cancel_requested tinyint(1) unsigned NOT NULL DEFAULT 0,
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
			KEY project_id (project_id),
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

		$sql[] = 'CREATE TABLE ' . self::table( 'code_runs' ) . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			job_id bigint(20) unsigned NOT NULL,
			plan_id bigint(20) unsigned NOT NULL,
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
			KEY plan_id (plan_id),
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

		$sql[] = 'CREATE TABLE ' . self::table( 'prompt_attachments' ) . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			project_id bigint(20) unsigned NOT NULL,
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
			KEY project_id (project_id),
			KEY created_by (created_by),
			KEY project_hash (project_id,sha256)
		) $charset;";

		$sql[] = 'CREATE TABLE ' . self::table( 'job_prompt_attachments' ) . " (
			job_id bigint(20) unsigned NOT NULL,
			attachment_id bigint(20) unsigned NOT NULL,
			sequence smallint(5) unsigned NOT NULL,
			PRIMARY KEY  (job_id,attachment_id),
			UNIQUE KEY job_sequence (job_id,sequence),
			KEY attachment_id (attachment_id)
		) $charset;";

		$sql[] = 'CREATE TABLE ' . self::table( 'review_reports' ) . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			job_id bigint(20) unsigned NOT NULL,
			project_id bigint(20) unsigned NOT NULL,
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
			KEY project_revision (project_id,revision_id),
			KEY parent_report_id (parent_report_id)
		) $charset;";

		$sql[] = 'CREATE TABLE ' . self::table( 'review_findings' ) . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			project_id bigint(20) unsigned NOT NULL,
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
			KEY project_status (project_id,status),
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
			project_id bigint(20) unsigned NOT NULL,
			revision_id bigint(20) unsigned NOT NULL,
			review_report_id bigint(20) unsigned NULL,
			mode varchar(20) NOT NULL,
			status varchar(20) NOT NULL DEFAULT 'queued',
			artifact_kind varchar(20) NOT NULL DEFAULT 'plugin',
			target_ref varchar(500) NULL,
			slug varchar(100) NOT NULL,
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
			KEY project_revision (project_id,revision_id),
			KEY status_expires (status,expires_at)
		) $charset;";

		$sql[] = 'CREATE TABLE ' . self::table( 'promotions' ) . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			job_id bigint(20) unsigned NOT NULL,
			project_id bigint(20) unsigned NOT NULL,
			revision_id bigint(20) unsigned NOT NULL,
			review_report_id bigint(20) unsigned NULL,
			mode varchar(30) NOT NULL,
			status varchar(30) NOT NULL DEFAULT 'queued',
			artifact_kind varchar(20) NOT NULL DEFAULT 'plugin',
			source_target_ref varchar(500) NULL,
			destination_target_ref varchar(500) NULL,
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
			KEY project_revision (project_id,revision_id),
			KEY destination_status (destination_target_ref(191),status)
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
		update_option( self::OPTION_NAME, self::SCHEMA_VERSION, false );
	}

	/**
	 * Add privacy-conscious defaults without overwriting existing choices.
	 */
	private static function add_defaults(): void {
		if ( false === get_option( 'wp_autoplugin_custom_instructions', false ) ) {
			add_option( 'wp_autoplugin_custom_instructions', '', '', false );
		}
		if ( false === get_option( Uninstaller::OPTION_NAME, false ) ) {
			add_option( Uninstaller::OPTION_NAME, 1, '', false );
		}

		wp_set_option_autoload_values(
			[
				'wp_autoplugin_openai_api_key'           => false,
				'wp_autoplugin_anthropic_api_key'        => false,
				'wp_autoplugin_google_api_key'           => false,
				'wp_autoplugin_xai_api_key'              => false,
				'wp_autoplugin_custom_models'            => false,
				'wp_autoplugin_custom_instructions'      => false,
				'wp_autoplugin_delete_data_on_uninstall' => false,
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
