<?php

/**
 * PHPUnit bootstrap for the WP-Autoplugin integration test suite.
 */

$test_autoloader = __DIR__ . '/vendor/autoload.php';
if ( ! is_file( $test_autoloader ) ) {
	fwrite( STDERR, "Test dependencies are missing. Run `composer test:install` first.\n" );
	exit( 1 );
}

require_once $test_autoloader;

$tests_dir = getenv( 'WP_TESTS_DIR' ) ?: getenv( 'WP_PHPUNIT__DIR' );
if ( ! $tests_dir || ! is_file( $tests_dir . '/includes/functions.php' ) ) {
	fwrite( STDERR, "The WordPress PHPUnit library is unavailable. Run `composer test:install` first.\n" );
	exit( 1 );
}

require_once $tests_dir . '/includes/functions.php';

tests_add_filter(
	'muplugins_loaded',
	static function (): void {
		require dirname( __DIR__ ) . '/wp-autoplugin.php';

		global $wpdb;
		$pattern = $wpdb->esc_like( $wpdb->prefix . 'wp_autoplugin_' ) . '%';
		$tables  = $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $pattern ) );
		foreach ( $tables as $table ) {
			if ( preg_match( '/^' . preg_quote( $wpdb->prefix . 'wp_autoplugin_', '/' ) . '[a-z_]+$/', $table ) ) {
				$wpdb->query( "DROP TABLE IF EXISTS `$table`" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Test-only tables constrained by the disposable prefix.
			}
		}
	}
);

require $tests_dir . '/includes/bootstrap.php';

require_once __DIR__ . '/Support/IntegrationTestCase.php';
