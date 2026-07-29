<?php

namespace WP_Autoplugin\V2\Infrastructure\Database;

/**
 * Removes site-local WP-Autoplugin data when the administrator opted in.
 */
final class Uninstaller {
	public const OPTION_NAME = 'wp_autoplugin_delete_data_on_uninstall';

	/**
	 * Clean every site when a network installation is uninstalled.
	 */
	public static function uninstall(): void {
		if ( ! is_multisite() ) {
			self::cleanup_site();
			return;
		}

		$site_ids = get_sites(
			[
				'fields' => 'ids',
				'number' => 0,
			]
		);

		foreach ( $site_ids as $site_id ) {
			switch_to_blog( (int) $site_id );
			try {
				self::cleanup_site();
			} finally {
				restore_current_blog();
			}
		}
	}

	/**
	 * Remove the current site's custom tables, options, and transients.
	 */
	public static function cleanup_site(): void {
		if ( ! (bool) get_option( self::OPTION_NAME, true ) ) {
			return;
		}

		self::drop_tables();
		self::delete_options();
	}

	/**
	 * Drop current and retired tables owned by the plugin.
	 */
	private static function drop_tables(): void {
		global $wpdb;

		$table_prefix = $wpdb->prefix . 'wp_autoplugin_';
		$tables       = $wpdb->get_col(
			$wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table_prefix ) . '%' )
		);

		foreach ( $tables as $table ) {
			if ( ! is_string( $table ) || ! str_starts_with( $table, $table_prefix ) ) {
				continue;
			}

			$suffix = substr( $table, strlen( $table_prefix ) );
			if ( '' === $suffix || 1 !== preg_match( '/^[a-z0-9_]+$/D', $suffix ) ) {
				continue;
			}

			$wpdb->query( "DROP TABLE IF EXISTS `$table`" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- The database-returned identifier is constrained to the plugin-owned prefix.
		}
	}

	/**
	 * Delete current and retired options and transients owned by the plugin.
	 */
	private static function delete_options(): void {
		global $wpdb;

		$patterns = [
			$wpdb->esc_like( 'wp_autoplugin_' ) . '%',
			$wpdb->esc_like( '_wp_autoplugin_' ) . '%',
			$wpdb->esc_like( '_transient_wp_autoplugin_' ) . '%',
			$wpdb->esc_like( '_transient_timeout_wp_autoplugin_' ) . '%',
			$wpdb->esc_like( '_site_transient_wp_autoplugin_' ) . '%',
			$wpdb->esc_like( '_site_transient_timeout_wp_autoplugin_' ) . '%',
		];
		$where    = implode( ' OR ', array_fill( 0, count( $patterns ), 'option_name LIKE %s' ) );
		$options  = $wpdb->get_col(
			$wpdb->prepare( "SELECT option_name FROM $wpdb->options WHERE $where", ...$patterns ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- The table and placeholder-only clause are generated from trusted values.
		);

		foreach ( $options as $option ) {
			delete_option( (string) $option );
		}
	}
}
