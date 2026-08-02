<?php

/**
 * Configuration for the disposable WordPress integration-test database.
 *
 * Override any WP_TESTS_* value in the environment when the defaults do not
 * match the local development site.
 */

$wp_tests_core_dir = getenv( 'WP_CORE_DIR' );
if ( ! $wp_tests_core_dir ) {
	$wp_tests_core_dir = dirname( __DIR__, 4 );
}

define( 'ABSPATH', rtrim( $wp_tests_core_dir, '/\\' ) . '/' );

$wp_tests_db_name     = getenv( 'WP_TESTS_DB_NAME' ) ?: 'local';
$wp_tests_db_user     = getenv( 'WP_TESTS_DB_USER' ) ?: 'root';
$wp_tests_db_password = getenv( 'WP_TESTS_DB_PASSWORD' ) ?: 'root';
$wp_tests_db_host     = getenv( 'WP_TESTS_DB_HOST' ) ?: 'localhost';

if ( 'localhost' === $wp_tests_db_host && 'Darwin' === PHP_OS_FAMILY ) {
	$local_socket_pattern = getenv( 'HOME' ) . '/Library/Application Support/Local/run/*/mysql/mysqld.sock';
	foreach ( glob( $local_socket_pattern ) ?: [] as $local_socket ) {
		mysqli_report( MYSQLI_REPORT_OFF );
		$connection = mysqli_connect(
			'localhost',
			$wp_tests_db_user,
			$wp_tests_db_password,
			$wp_tests_db_name,
			null,
			$local_socket
		);
		if ( $connection ) {
			mysqli_close( $connection );
			$wp_tests_db_host = 'localhost:' . $local_socket;
			break;
		}
	}
}

define( 'DB_NAME', $wp_tests_db_name );
define( 'DB_USER', $wp_tests_db_user );
define( 'DB_PASSWORD', $wp_tests_db_password );
define( 'DB_HOST', $wp_tests_db_host );
define( 'DB_CHARSET', 'utf8mb4' );
define( 'DB_COLLATE', '' );

$table_prefix = 'wptests_';

define( 'WP_DEBUG', true );
define( 'WP_TESTS_DOMAIN', 'example.org' );
define( 'WP_TESTS_EMAIL', 'admin@example.org' );
define( 'WP_TESTS_TITLE', 'WP-Autoplugin Tests' );
define( 'WP_PHP_BINARY', PHP_BINARY );
define( 'WPLANG', '' );
