<?php
/** Optional complete cleanup for WP-Autoplugin data. */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

require_once __DIR__ . '/vendor/autoload.php';

\WP_Autoplugin\V2\Infrastructure\Database\Uninstaller::uninstall();
