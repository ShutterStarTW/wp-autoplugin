<?php
/**
 * GitHub updater registration.
 *
 * @package WP_Autoplugin
 */

namespace WP_Autoplugin\V2\Admin;

use WP_Autoplugin\V2\Infrastructure\Update\GitHub_Updater;

/**
 * Registers the plugin's GitHub update channel.
 */
final class Updater {
	private const REPOSITORY = 'WP-Autoplugin/wp-autoplugin';
	private const UPDATE_URI = 'https://wp-autoplugin.com/updates/wp-autoplugin';

	/**
	 * Register the updater on every request, including WordPress cron.
	 */
	public function register(): void {
		$updater = new GitHub_Updater(
			WP_AUTOPLUGIN_DIR . 'wp-autoplugin.php',
			self::REPOSITORY,
			self::UPDATE_URI
		);
		$updater->register();
	}
}
