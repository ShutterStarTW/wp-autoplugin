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

		wp_enqueue_script( 'wp-autoplugin-marked', WP_AUTOPLUGIN_URL . 'assets/v2/vendor/marked.min.js', [], WP_AUTOPLUGIN_VERSION, true );
		wp_enqueue_script( 'wp-autoplugin-purify', WP_AUTOPLUGIN_URL . 'assets/v2/vendor/purify.min.js', [], WP_AUTOPLUGIN_VERSION, true );
		$php_editor  = wp_enqueue_code_editor( [ 'type' => 'text/x-php' ] );
		$js_editor   = wp_enqueue_code_editor( [ 'type' => 'text/javascript' ] );
		$css_editor  = wp_enqueue_code_editor( [ 'type' => 'text/css' ] );
		$json_editor = wp_enqueue_code_editor( [ 'type' => 'application/json' ] );
		$html_editor = wp_enqueue_code_editor( [ 'type' => 'text/html' ] );
		$xml_editor  = wp_enqueue_code_editor( [ 'type' => 'application/xml' ] );
		$text_editor = wp_enqueue_code_editor( [ 'type' => 'text/plain' ] );
		$editor_settings = [
			'php'  => $php_editor,
			'js'   => $js_editor,
			'jsx'  => $js_editor,
			'ts'   => $js_editor,
			'tsx'  => $js_editor,
			'css'  => $css_editor,
			'scss' => $css_editor,
			'json' => $json_editor,
			'html' => $html_editor,
			'xml'  => $xml_editor,
			'md'   => $text_editor,
			'txt'  => $text_editor,
		];
		$editor_settings = array_filter( $editor_settings, 'is_array' );
		$editor_dependencies = $editor_settings ? [ 'code-editor' ] : [];

		$asset_file = WP_AUTOPLUGIN_DIR . 'assets/v2/build/index.tsx.asset.php';
		$asset      = file_exists( $asset_file ) ? include $asset_file : [
			'dependencies' => [ 'react-jsx-runtime', 'wp-api-fetch', 'wp-components', 'wp-element', 'wp-i18n' ],
			'version'      => WP_AUTOPLUGIN_VERSION,
		];

		wp_enqueue_script(
			self::HANDLE,
			WP_AUTOPLUGIN_URL . 'assets/v2/build/index.tsx.js',
			array_merge( $asset['dependencies'], [ 'wp-autoplugin-marked', 'wp-autoplugin-purify' ], $editor_dependencies ),
			$asset['version'],
			true
		);
		wp_enqueue_style(
			self::HANDLE,
			WP_AUTOPLUGIN_URL . 'assets/v2/build/style-index.tsx.css',
			[ 'wp-components', 'dashicons' ],
			$asset['version']
		);
		wp_style_add_data( self::HANDLE, 'rtl', 'replace' );
		wp_set_script_translations( self::HANDLE, 'wp-autoplugin', WP_AUTOPLUGIN_DIR . 'languages' );
		wp_add_inline_script(
			self::HANDLE,
			'window.wpAutopluginV2 = ' . wp_json_encode(
				[
					'restPath'    => '/wp-autoplugin/v2',
					'settingsUrl' => admin_url( 'admin.php?page=wp-autoplugin-settings' ),
					'codeEditorSettings' => $editor_settings,
				]
			) . ';',
			'before'
		);
	}
}
