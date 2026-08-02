<?php

use WP_Autoplugin\V2\Admin\Settings;
use WP_Autoplugin\V2\Domain\AI\Custom_Endpoint_Security;
use WP_Autoplugin\V2\Domain\AI\Global_Instructions;

/** Coverage for the v2-only Settings API contract. */
final class SettingsTest extends WP_UnitTestCase {
	private const OPTIONS = [
		'wp_autoplugin_custom_instructions',
		'wp_autoplugin_custom_models',
		'wp_autoplugin_delete_data_on_uninstall',
		'wp_autoplugin_model',
		'wp_autoplugin_planner_model',
		'wp_autoplugin_plugin_mode',
		'wp_autoplugin_ai_language',
	];

	public function set_up(): void {
		parent::set_up();
		foreach ( self::OPTIONS as $option ) {
			delete_option( $option );
			unregister_setting( Settings::GROUP, $option );
		}
	}

	public function tear_down(): void {
		foreach ( self::OPTIONS as $option ) {
			delete_option( $option );
			unregister_setting( Settings::GROUP, $option );
		}
		parent::tear_down();
	}

	public function test_registers_v2_options_without_retired_mode_or_language(): void {
		( new Settings() )->register_settings();
		$registered = get_registered_settings();

		$this->assertArrayHasKey( 'wp_autoplugin_custom_models', $registered );
		$this->assertArrayHasKey( Global_Instructions::OPTION_NAME, $registered );
		$this->assertArrayHasKey( Settings::DELETE_DATA_OPTION, $registered );
		$this->assertArrayHasKey( 'wp_autoplugin_model', $registered );
		$this->assertArrayHasKey( 'wp_autoplugin_planner_model', $registered );
		$this->assertArrayNotHasKey( 'wp_autoplugin_plugin_mode', $registered );
		$this->assertArrayNotHasKey( 'wp_autoplugin_ai_language', $registered );
	}

	public function test_uninstall_cleanup_is_enabled_by_default_and_accepts_an_unchecked_value(): void {
		$settings = new Settings();
		$settings->register_settings();

		$this->assertSame( '1', (string) get_option( Settings::DELETE_DATA_OPTION ) );
		$this->assertSame( 1, $settings->sanitize_checkbox( '1' ) );
		$this->assertSame( 0, $settings->sanitize_checkbox( null ) );
	}

	public function test_custom_models_are_sanitized_for_settings_persistence(): void {
		$settings = new Settings();
		$models   = $settings->sanitize_custom_models(
			wp_json_encode(
				[
					[
						'name'           => 'private-endpoint',
						'url'            => 'https://8.8.8.8/v1/chat/completions',
						'modelParameter' => 'remote-model',
						'apiKey'         => 'secret',
						'headers'        => [ 'X-Trace=enabled', '' ],
					],
				]
			)
		);

		$this->assertSame( 'private-endpoint', $models[0]['name'] );
		$this->assertSame( 'https://8.8.8.8/v1/chat/completions', $models[0]['url'] );
		$this->assertSame( [ 'X-Trace=enabled' ], $models[0]['headers'] );
		$this->assertSame( 'key%2Fvalue', $settings->sanitize_secret( " key%2Fvalue\n" ) );
		$this->assertSame( 4096, strlen( $settings->sanitize_secret( str_repeat( 's', 5000 ) ) ) );
		$this->assertSame( '', $settings->sanitize_secret( "invalid\xFFkey" ) );
		$this->assertCount( 64, Custom_Endpoint_Security::headers( array_map( static fn( int $index ): string => 'X-Test-' . $index . '=yes', range( 1, 65 ) ) ) );
	}

	public function test_custom_models_reject_unsafe_urls_and_routing_headers_without_replacing_stored_models(): void {
		$settings = new Settings();
		$stored   = [
			[
				'name'           => 'existing-model',
				'url'            => 'https://8.8.8.8/v1/chat/completions',
				'modelParameter' => 'remote-model',
				'apiKey'         => 'secret',
				'headers'        => [],
			],
		];
		update_option( 'wp_autoplugin_custom_models', $stored, false );

		foreach (
			[
				[ 'url' => 'http://8.8.8.8/v1/chat/completions', 'headers' => [] ],
				[ 'url' => 'https://127.0.0.1/v1/chat/completions', 'headers' => [] ],
				[ 'url' => 'https://8.8.8.8/v1/chat/completions', 'headers' => [ 'Host=internal.example' ] ],
				[ 'url' => 'https://8.8.8.8/v1/chat/completions', 'headers' => [ "X-Trace=enabled\r\nInjected=true" ] ],
				[ 'url' => 'https://8.8.8.8/v1/chat/completions', 'headers' => array_fill( 0, 5, 'X-Trace=' . str_repeat( 'a', 2000 ) ) ],
			] as $unsafe
		) {
			$result = $settings->sanitize_custom_models(
				[
					[
						'name'           => 'unsafe-model',
						'url'            => $unsafe['url'],
						'modelParameter' => 'remote-model',
						'apiKey'         => 'secret',
						'headers'        => $unsafe['headers'],
					],
				]
			);
			$this->assertSame( $stored, $result );
		}
	}

	public function test_custom_model_names_cannot_shadow_native_catalog_ids(): void {
		$settings = new Settings();
		$models   = $settings->sanitize_custom_models(
			[
				[
					'name'           => 'gpt-5.4',
					'url'            => 'https://8.8.8.8/v1/chat/completions',
					'modelParameter' => 'remote-model',
					'apiKey'         => 'secret',
					'headers'        => [],
				],
			]
		);

		$this->assertSame( [], $models );
	}

	public function test_custom_instructions_preserve_formatting_and_normalize_newlines(): void {
		$settings = new Settings();
		$content  = "# Style\r\n\r\nUse tabs.\r`<div data-example=\"yes\">`";

		$this->assertSame(
			"# Style\n\nUse tabs.\n`<div data-example=\"yes\">`",
			$settings->sanitize_custom_instructions( $content )
		);
		$this->assertSame( '', $settings->sanitize_custom_instructions( " \r\n\t" ) );
		$this->assertSame( str_repeat( 'a', Global_Instructions::MAX_BYTES ), $settings->sanitize_custom_instructions( str_repeat( 'a', Global_Instructions::MAX_BYTES ) ) );
	}

	public function test_invalid_custom_instructions_do_not_replace_the_stored_value(): void {
		$settings = new Settings();
		update_option( Global_Instructions::OPTION_NAME, "Keep this value.\n", false );

		$this->assertSame( "Keep this value.\n", $settings->sanitize_custom_instructions( "Invalid \xFF" ) );
		$this->assertSame( "Keep this value.\n", $settings->sanitize_custom_instructions( "Invalid\0text" ) );
		$this->assertSame( "Keep this value.\n", $settings->sanitize_custom_instructions( str_repeat( 'a', Global_Instructions::MAX_BYTES + 1 ) ) );
	}

	public function test_custom_instructions_option_is_created_without_autoloading(): void {
		global $wpdb;

		( new Settings() )->register_settings();
		$autoload = $wpdb->get_var(
			$wpdb->prepare( "SELECT autoload FROM $wpdb->options WHERE option_name = %s", Global_Instructions::OPTION_NAME )
		);

		$this->assertNotContains( $autoload, [ 'yes', 'on', 'auto', 'auto-on' ], 'Custom instructions must not be loaded on every WordPress request.' );
	}
}
