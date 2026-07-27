<?php

use WP_Autoplugin\V2\Admin\Settings;
use WP_Autoplugin\V2\Domain\AI\Global_Instructions;

/** Coverage for the v2-only Settings API contract. */
final class SettingsTest extends WP_UnitTestCase {
	private const OPTIONS = [
		'wp_autoplugin_custom_instructions',
		'wp_autoplugin_custom_models',
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
		$this->assertArrayHasKey( 'wp_autoplugin_model', $registered );
		$this->assertArrayHasKey( 'wp_autoplugin_planner_model', $registered );
		$this->assertArrayNotHasKey( 'wp_autoplugin_plugin_mode', $registered );
		$this->assertArrayNotHasKey( 'wp_autoplugin_ai_language', $registered );
	}

	public function test_custom_models_are_sanitized_for_settings_persistence(): void {
		$settings = new Settings();
		$models   = $settings->sanitize_custom_models(
			wp_json_encode(
				[
					[
						'name'           => 'private-endpoint',
						'url'            => 'https://example.test/v1/chat/completions',
						'modelParameter' => 'remote-model',
						'apiKey'         => 'secret',
						'headers'        => [ "X-Trace=enabled\r\nInjected=true", '' ],
					],
				]
			)
		);

		$this->assertSame( 'private-endpoint', $models[0]['name'] );
		$this->assertSame( 'https://example.test/v1/chat/completions', $models[0]['url'] );
		$this->assertSame( [ 'X-Trace=enabledInjected=true' ], $models[0]['headers'] );
		$this->assertSame( 'key%2Fvalue', $settings->sanitize_secret( " key%2Fvalue\n" ) );
	}

	public function test_custom_model_names_cannot_shadow_native_catalog_ids(): void {
		$settings = new Settings();
		$models   = $settings->sanitize_custom_models(
			[
				[
					'name'           => 'gpt-5.4',
					'url'            => 'https://example.test/v1/chat/completions',
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
