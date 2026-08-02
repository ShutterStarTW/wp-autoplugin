<?php

namespace WP_Autoplugin\V2\Admin;

use WP_Autoplugin\V2\Domain\AI\Model_Effort;

/**
 * Loads the generated settings-page assets and translated runtime configuration.
 */
final class Settings_Assets {
	private const HANDLE = 'wp-autoplugin-v2-settings';

	public function register(): void {
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue' ] );
	}

	public function enqueue( string $hook_suffix ): void {
		if ( 'wp-autoplugin_page_wp-autoplugin-settings' !== $hook_suffix ) {
			return;
		}

		$asset_file = WP_AUTOPLUGIN_DIR . 'assets/v2/build/settings.ts.asset.php';
		$asset      = file_exists( $asset_file ) ? include $asset_file : [
			'dependencies' => [ 'wp-api-fetch' ],
			'version'      => WP_AUTOPLUGIN_VERSION,
		];

		wp_enqueue_script(
			self::HANDLE,
			WP_AUTOPLUGIN_URL . 'assets/v2/build/settings.ts.js',
			$asset['dependencies'],
			$asset['version'],
			true
		);
		wp_enqueue_style(
			self::HANDLE,
			WP_AUTOPLUGIN_URL . 'assets/v2/build/settings.ts.css',
			[ 'dashicons' ],
			$asset['version']
		);
		wp_style_add_data( self::HANDLE, 'rtl', 'replace' );
		wp_set_script_translations( self::HANDLE, 'wp-autoplugin', WP_AUTOPLUGIN_DIR . 'languages' );
		wp_add_inline_script(
			self::HANDLE,
			'window.wpAutopluginV2Settings = ' . wp_json_encode( $this->configuration() ) . ';',
			'before'
		);
	}

	/** @return array<string, mixed> */
	private function configuration(): array {
		$selected_efforts = [];
		foreach ( Model_Effort::option_names() as $role => $option ) {
			$selected_efforts[ $role ] = (string) get_option( $option, '' );
		}

		return [
			'apiKeyTestPath'     => '/wp-autoplugin/v2/providers',
			'chatgptPath'        => '/wp-autoplugin/v2/providers/chatgpt',
			'effortCapabilities' => Model_Effort::capabilities(),
			'selectedEfforts'    => $selected_efforts,
			'strings'            => [
				'showSpecialized'   => __( 'Show specialized model settings', 'wp-autoplugin' ),
				'hideSpecialized'   => __( 'Hide specialized model settings', 'wp-autoplugin' ),
				'modelDefault'      => __( 'model default', 'wp-autoplugin' ),
				'efforts'           => [
					'none'    => __( 'None', 'wp-autoplugin' ),
					'minimal' => __( 'Minimal', 'wp-autoplugin' ),
					'low'     => __( 'Low', 'wp-autoplugin' ),
					'medium'  => __( 'Medium', 'wp-autoplugin' ),
					'high'    => __( 'High', 'wp-autoplugin' ),
					'xhigh'   => __( 'Extra high', 'wp-autoplugin' ),
					'max'     => __( 'Maximum', 'wp-autoplugin' ),
					'ultra'   => __( 'Ultra', 'wp-autoplugin' ),
				],
				'customModels'      => __( 'Custom Models', 'wp-autoplugin' ),
				'details'           => __( 'Details', 'wp-autoplugin' ),
				'url'               => __( 'URL', 'wp-autoplugin' ),
				'modelParameter'    => __( 'Model Parameter', 'wp-autoplugin' ),
				'apiKey'            => __( 'API Key', 'wp-autoplugin' ),
				'headers'           => __( 'Headers', 'wp-autoplugin' ),
				'remove'            => __( 'Remove', 'wp-autoplugin' ),
				'fillOutFields'     => __( 'Please enter a unique model name, public HTTPS endpoint, API key, and valid additional headers.', 'wp-autoplugin' ),
				'removeModel'       => __( 'Are you sure you want to remove this model?', 'wp-autoplugin' ),
				'testing'           => __( 'Testing…', 'wp-autoplugin' ),
				'testOk'            => __( 'OK', 'wp-autoplugin' ),
				'testFail'          => __( 'Failed', 'wp-autoplugin' ),
				'connected'         => __( 'Connected', 'wp-autoplugin' ),
				'disconnected'      => __( 'Not connected', 'wp-autoplugin' ),
				'waiting'           => __( 'Waiting for authorization…', 'wp-autoplugin' ),
				'connecting'        => __( 'Starting connection…', 'wp-autoplugin' ),
				'connect'           => __( 'Connect', 'wp-autoplugin' ),
				'reconnect'         => __( 'Reconnect', 'wp-autoplugin' ),
				'reconnectRequired' => __( 'Reconnect required', 'wp-autoplugin' ),
				'copied'            => __( 'Verification code copied.', 'wp-autoplugin' ),
				'copyFailed'        => __( 'The verification code could not be copied.', 'wp-autoplugin' ),
				'confirmDisconnect' => __( 'Disconnect the ChatGPT subscription from WP-Autoplugin?', 'wp-autoplugin' ),
				'genericError'      => __( 'The request could not be completed.', 'wp-autoplugin' ),
			],
		];
	}
}
