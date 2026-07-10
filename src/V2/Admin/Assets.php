<?php

namespace WP_Autoplugin\V2\Admin;

/**
 * Loads the generated React application from WordPress build metadata.
 */
final class Assets {
	private const HANDLE = 'wp-autoplugin-v2';

	public function register(): void {
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue' ] );
	}

	public function enqueue( string $hook_suffix ): void {
		if ( 'toplevel_page_wp-autoplugin' !== $hook_suffix ) {
			return;
		}

		wp_enqueue_script( 'wp-autoplugin-marked', WP_AUTOPLUGIN_URL . 'assets/admin/js/marked.min.js', [], WP_AUTOPLUGIN_VERSION, true );
		wp_enqueue_script( 'wp-autoplugin-purify', WP_AUTOPLUGIN_URL . 'assets/admin/js/purify.min.js', [], WP_AUTOPLUGIN_VERSION, true );

		$asset_file = WP_AUTOPLUGIN_DIR . 'assets/v2/build/index.asset.php';
		$asset      = file_exists( $asset_file ) ? include $asset_file : [
			'dependencies' => [ 'wp-api-fetch', 'wp-components', 'wp-element', 'wp-i18n' ],
			'version'      => WP_AUTOPLUGIN_VERSION,
		];

		wp_enqueue_script(
			self::HANDLE,
			WP_AUTOPLUGIN_URL . 'assets/v2/build/index.js',
			array_merge( $asset['dependencies'], [ 'wp-autoplugin-marked', 'wp-autoplugin-purify' ] ),
			$asset['version'],
			true
		);
		wp_enqueue_style(
			self::HANDLE,
			WP_AUTOPLUGIN_URL . 'assets/v2/build/style-index.css',
			[ 'wp-components' ],
			$asset['version']
		);
		wp_set_script_translations( self::HANDLE, 'wp-autoplugin', WP_AUTOPLUGIN_DIR . 'languages' );
		wp_add_inline_script(
			self::HANDLE,
			'window.wpAutopluginV2 = ' . wp_json_encode(
				[
					'restPath'    => '/wp-autoplugin/v2',
					'settingsUrl' => admin_url( 'admin.php?page=wp-autoplugin-settings' ),
				]
			) . ';',
			'before'
		);
	}
}
