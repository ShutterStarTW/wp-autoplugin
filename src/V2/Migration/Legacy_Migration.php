<?php

namespace WP_Autoplugin\V2\Migration;

use WP_Autoplugin\V2\Domain\Target\Target_Scanner;

/**
 * Previews and imports legacy tracking data without modifying target files.
 */
final class Legacy_Migration {
	private const IMPORT_OPTION = 'wp_autoplugin_v2_legacy_import';

	/**
	 * @return array<string, mixed>
	 */
	public function preview(): array {
		$tracked = array_values( array_unique( array_filter( (array) get_option( 'wp_autoplugins', [] ), 'is_string' ) ) );
		$models  = [
			'default'  => (string) get_option( 'wp_autoplugin_model', '' ),
			'planner'  => (string) get_option( 'wp_autoplugin_planner_model', '' ),
			'coder'    => (string) get_option( 'wp_autoplugin_coder_model', '' ),
			'reviewer' => (string) get_option( 'wp_autoplugin_reviewer_model', '' ),
		];

		return [
			'completed'       => false !== get_option( self::IMPORT_OPTION, false ),
			'tracked_plugins' => $tracked,
			'tracked_count'   => count( $tracked ),
			'models'          => array_filter( $models ),
			'credentials'     => [
				'openai'   => '' !== (string) get_option( 'wp_autoplugin_openai_api_key', '' ),
				'anthropic'=> '' !== (string) get_option( 'wp_autoplugin_anthropic_api_key', '' ),
				'google'   => '' !== (string) get_option( 'wp_autoplugin_google_api_key', '' ),
				'xai'      => '' !== (string) get_option( 'wp_autoplugin_xai_api_key', '' ),
			],
			'changes_files'   => false,
		];
	}

	/**
	 * Import tracked plugin references into v2 targets/projects.
	 *
	 * @return array<string, int>
	 */
	public function import( int $user_id ): array {
		global $wpdb;

		$preview = $this->preview();
		$targets = ( new Target_Scanner() )->all();
		$by_ref  = [];
		foreach ( $targets as $target ) {
			if ( 'plugin' === $target['kind'] ) {
				$by_ref[ $target['ref'] ] = $target;
			}
		}

		$target_table  = $wpdb->prefix . 'wp_autoplugin_targets';
		$project_table = $wpdb->prefix . 'wp_autoplugin_projects';
		$imported      = 0;
		$missing       = 0;
		$now           = current_time( 'mysql', true );

		foreach ( $preview['tracked_plugins'] as $ref ) {
			if ( ! isset( $by_ref[ $ref ] ) ) {
				++$missing;
				continue;
			}

			$target = $by_ref[ $ref ];
			$wpdb->query(
				$wpdb->prepare(
					"INSERT INTO $target_table (kind, ref, name, metadata, created_at, updated_at)
					VALUES (%s, %s, %s, %s, %s, %s)
					ON DUPLICATE KEY UPDATE name = VALUES(name), metadata = VALUES(metadata), updated_at = VALUES(updated_at)",
					'plugin',
					$ref,
					$target['name'],
					wp_json_encode( $target ),
					$now,
					$now
				)
			); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Internal table names.

			$target_id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $target_table WHERE kind = %s AND ref = %s", 'plugin', $ref ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Internal table name.
			$exists    = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $project_table WHERE target_id = %d", $target_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Internal table name.

			if ( ! $exists ) {
				$wpdb->insert(
					$project_table,
					[
						'target_id'  => $target_id,
						'name'       => $target['name'],
						'status'     => 'active',
						'created_by' => $user_id,
						'created_at' => $now,
						'updated_at' => $now,
					]
				);
				++$imported;
			}
		}

		update_option(
			self::IMPORT_OPTION,
			[ 'imported_at' => $now, 'imported' => $imported, 'missing' => $missing ],
			false
		);

		return [ 'imported' => $imported, 'missing' => $missing ];
	}
}
