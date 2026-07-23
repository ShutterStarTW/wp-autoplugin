<?php

namespace WP_Autoplugin\V2\Admin;

use WP_Autoplugin\V2\Infrastructure\Update\GitHub_Updater;

/**
 * Keeps the plugin's existing GitHub update channel on the v2 bootstrap.
 */
final class Updater {
	public function register(): void {
		add_action( 'init', [ $this, 'initialize' ] );
	}

	public function initialize(): void {
		if ( ! is_admin() ) {
			return;
		}

		new GitHub_Updater(
			[
				'slug'               => plugin_basename( WP_AUTOPLUGIN_DIR . 'wp-autoplugin.php' ),
				'proper_folder_name' => dirname( plugin_basename( WP_AUTOPLUGIN_DIR . 'wp-autoplugin.php' ) ),
				'api_url'            => 'https://api.github.com/repos/WP-Autoplugin/wp-autoplugin',
				'raw_url'            => 'https://raw.githubusercontent.com/WP-Autoplugin/wp-autoplugin/main/',
				'github_url'         => 'https://github.com/WP-Autoplugin/wp-autoplugin',
				'zip_url'            => 'https://github.com/WP-Autoplugin/wp-autoplugin/archive/refs/heads/main.zip',
				'requires'           => '6.0',
				'tested'             => '6.6.2',
				'description'        => esc_html__( 'A plugin that generates other plugins on-demand using AI.', 'wp-autoplugin' ),
				'homepage'           => 'https://github.com/WP-Autoplugin/wp-autoplugin',
				'version'            => WP_AUTOPLUGIN_VERSION,
			]
		);
	}
}
