<?php

use WP_Autoplugin\V2\Infrastructure\Database\Installer;
use WP_Autoplugin\V2\Infrastructure\Database\Project_Repository;
use WP_Autoplugin\V2\Infrastructure\Database\Uninstaller;

/** Coverage for opt-in uninstall cleanup and explicit retention. */
final class UninstallerTest extends WP_UnitTestCase {
	public function test_cleanup_is_enabled_by_default_and_removes_plugin_tables_options_and_transients(): void {
		global $wpdb;

		Installer::activate();
		delete_option( Uninstaller::OPTION_NAME );
		update_option( 'wp_autoplugin_test_setting', 'value', false );
		set_transient( 'wp_autoplugin_test_transient', 'value', MINUTE_IN_SECONDS );
		$user_id  = self::factory()->user->create();
		$meta_key = Project_Repository::tab_order_meta_key();
		update_user_meta( $user_id, $meta_key, [ 3, 1, 2 ] );
		$projects = Installer::table( 'projects' );
		$retired  = $wpdb->prefix . 'wp_autoplugin_retired_data';

		remove_filter( 'query', [ $this, '_create_temporary_tables' ] );
		remove_filter( 'query', [ $this, '_drop_temporary_tables' ] );
		try {
			$wpdb->query( "CREATE TABLE `$retired` (id bigint(20) unsigned NOT NULL)" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Disposable test table constrained to the test prefix.
			Uninstaller::cleanup_site();

			$this->assertFalse( get_option( 'wp_autoplugin_test_setting', false ) );
			$this->assertFalse( get_transient( 'wp_autoplugin_test_transient' ) );
			$this->assertFalse( get_option( 'wp_autoplugin_v2_schema_version', false ) );
			$this->assertSame( '', get_user_meta( $user_id, $meta_key, true ) );
			$this->assertNull( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $projects ) ) );
			$this->assertNull( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $retired ) ) );
		} finally {
			Installer::activate();
			add_filter( 'query', [ $this, '_create_temporary_tables' ] );
			add_filter( 'query', [ $this, '_drop_temporary_tables' ] );
		}
	}

	public function test_cleanup_preserves_all_data_when_disabled(): void {
		global $wpdb;

		Installer::activate();
		update_option( Uninstaller::OPTION_NAME, 0, false );
		update_option( 'wp_autoplugin_test_setting', 'value', false );
		$user_id  = self::factory()->user->create();
		$meta_key = Project_Repository::tab_order_meta_key();
		update_user_meta( $user_id, $meta_key, [ 2, 1 ] );
		$projects = Installer::table( 'projects' );

		try {
			Uninstaller::cleanup_site();

			$this->assertSame( $projects, $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $projects ) ) );
			$this->assertSame( 'value', get_option( 'wp_autoplugin_test_setting' ) );
			$this->assertSame( '0', (string) get_option( Uninstaller::OPTION_NAME ) );
			$this->assertSame( [ 2, 1 ], get_user_meta( $user_id, $meta_key, true ) );
		} finally {
			delete_option( 'wp_autoplugin_test_setting' );
			delete_user_meta( $user_id, $meta_key );
			update_option( Uninstaller::OPTION_NAME, 1, false );
		}
	}
}
