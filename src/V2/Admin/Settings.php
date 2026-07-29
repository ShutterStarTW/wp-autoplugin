<?php

namespace WP_Autoplugin\V2\Admin;

use WP_Autoplugin\V2\Domain\AI\Global_Instructions;
use WP_Autoplugin\V2\Domain\AI\Model_Effort;
use WP_Autoplugin\V2\Domain\AI\Model_Registry;
use WP_Autoplugin\V2\Infrastructure\AI\ChatGPT_Config;
use WP_Autoplugin\V2\Infrastructure\Database\Uninstaller;

/**
 * Registers the v2 settings contract while retaining existing option names.
 */
final class Settings {
	public const GROUP              = 'wp_autoplugin_settings';
	public const DELETE_DATA_OPTION = Uninstaller::OPTION_NAME;
	private const MAX_CUSTOM_MODELS = 50;
	private const SECRET_OPTIONS    = [
		'wp_autoplugin_openai_api_key',
		'wp_autoplugin_anthropic_api_key',
		'wp_autoplugin_google_api_key',
		'wp_autoplugin_xai_api_key',
	];
	private const ROLE_OPTIONS      = [
		'wp_autoplugin_planner_model',
		'wp_autoplugin_coder_model',
		'wp_autoplugin_reviewer_model',
	];

	public function register(): void {
		add_action( 'admin_init', [ $this, 'register_settings' ] );
	}

	public function register_settings(): void {
		$this->migrate_chatgpt_overrides();
		if ( false === get_option( Global_Instructions::OPTION_NAME, false ) ) {
			add_option( Global_Instructions::OPTION_NAME, '', '', false );
		}
		if ( false === get_option( self::DELETE_DATA_OPTION, false ) ) {
			add_option( self::DELETE_DATA_OPTION, 1, '', false );
		}

		register_setting(
			self::GROUP,
			Global_Instructions::OPTION_NAME,
			[
				'type'              => 'string',
				'sanitize_callback' => [ $this, 'sanitize_custom_instructions' ],
				'default'           => '',
			]
		);

		register_setting(
			self::GROUP,
			self::DELETE_DATA_OPTION,
			[
				'type'              => 'boolean',
				'sanitize_callback' => [ $this, 'sanitize_checkbox' ],
				'default'           => true,
			]
		);

		register_setting(
			self::GROUP,
			'wp_autoplugin_custom_models',
			[
				'type'              => 'array',
				'sanitize_callback' => [ $this, 'sanitize_custom_models' ],
				'default'           => [],
			]
		);

		foreach ( self::SECRET_OPTIONS as $option ) {
			register_setting(
				self::GROUP,
				$option,
				[
					'type'              => 'string',
					'sanitize_callback' => [ $this, 'sanitize_secret' ],
					'default'           => '',
				]
			);
		}

		register_setting(
			self::GROUP,
			'wp_autoplugin_model',
			[
				'type'              => 'string',
				'sanitize_callback' => [ $this, 'sanitize_default_model' ],
				'default'           => '',
			]
		);

		foreach ( self::ROLE_OPTIONS as $option ) {
			register_setting(
				self::GROUP,
				$option,
				[
					'type'              => 'string',
					'sanitize_callback' => fn( $value ): string => $this->sanitize_model( $value, true, $option ),
					'default'           => '',
				]
			);
		}

		foreach ( Model_Effort::option_names() as $option ) {
			register_setting(
				self::GROUP,
				$option,
				[
					'type'              => 'string',
					'sanitize_callback' => [ Model_Effort::class, 'sanitize' ],
					'default'           => '',
				]
			);
		}
	}

	public function sanitize_secret( $value ): string {
		$value = preg_replace( '/[\x00-\x1F\x7F]/', '', trim( (string) $value ) );
		return is_string( $value ) ? $value : '';
	}

	public function sanitize_checkbox( $value ): int {
		return empty( $value ) ? 0 : 1;
	}

	/**
	 * Preserve administrator-authored Markdown and code while enforcing bounds.
	 *
	 * @param mixed $value Submitted setting value.
	 */
	public function sanitize_custom_instructions( $value ): string {
		$value = str_replace( [ "\r\n", "\r" ], "\n", (string) $value );
		if ( '' === trim( $value ) ) {
			return '';
		}

		try {
			return Global_Instructions::validate( $value );
		} catch ( \RuntimeException $error ) {
			$this->settings_error( $error->getMessage() );
			return (string) get_option( Global_Instructions::OPTION_NAME, '' );
		}
	}

	public function sanitize_default_model( $value ): string {
		return $this->sanitize_model( $value, false, 'wp_autoplugin_model' );
	}

	/**
	 * Validate custom OpenAI-compatible endpoints submitted as JSON by the UI.
	 *
	 * @return array<int, array{name:string,url:string,modelParameter:string,apiKey:string,headers:array<int,string>}>
	 */
	public function sanitize_custom_models( $value ): array {
		if ( is_string( $value ) ) {
			$decoded = json_decode( $value, true );
			if ( ! is_array( $decoded ) ) {
				$this->settings_error( __( 'Custom models could not be saved because their data was invalid.', 'wp-autoplugin' ) );
				return $this->stored_custom_models();
			}
			$value = $decoded;
		}

		if ( ! is_array( $value ) || count( $value ) > self::MAX_CUSTOM_MODELS ) {
			$this->settings_error( __( 'Custom models could not be saved because the list was invalid or too large.', 'wp-autoplugin' ) );
			return $this->stored_custom_models();
		}

		$models  = [];
		$known   = array_fill_keys( array_merge( $this->built_in_model_ids(), $this->chatgpt_model_ids() ), true );
		$invalid = false;
		foreach ( $value as $candidate ) {
			if ( ! is_array( $candidate ) ) {
				$invalid = true;
				break;
			}

			$name      = sanitize_text_field( (string) ( $candidate['name'] ?? '' ) );
			$url       = esc_url_raw( trim( (string) ( $candidate['url'] ?? '' ) ), [ 'http', 'https' ] );
			$model     = sanitize_text_field( (string) ( $candidate['modelParameter'] ?? '' ) );
			$api_key   = $this->sanitize_secret( $candidate['apiKey'] ?? '' );
			$url_parts = wp_parse_url( $url );
			$valid_url = is_array( $url_parts )
				&& in_array( strtolower( (string) ( $url_parts['scheme'] ?? '' ) ), [ 'http', 'https' ], true )
				&& '' !== (string) ( $url_parts['host'] ?? '' )
				&& ! isset( $url_parts['user'] )
				&& ! isset( $url_parts['pass'] );

			if ( '' === $name || '' === $url || '' === $api_key || ! $valid_url || isset( $known[ $name ] ) ) {
				$invalid = true;
				break;
			}

			$headers = [];
			foreach ( (array) ( $candidate['headers'] ?? [] ) as $line ) {
				if ( ! is_string( $line ) ) {
					continue;
				}
				$line = trim( preg_replace( '/[\r\n]+/', '', $line ) ?? '' );
				if ( '' !== $line ) {
					$headers[] = sanitize_text_field( $line );
				}
			}

			$known[ $name ] = true;
			$models[]       = [
				'name'           => $name,
				'url'            => $url,
				'modelParameter' => $model,
				'apiKey'         => $api_key,
				'headers'        => array_slice( $headers, 0, 64 ),
			];
		}

		if ( $invalid ) {
			$this->settings_error( __( 'Each custom model needs a unique name, a valid endpoint URL, and an API key. Names cannot duplicate built-in models.', 'wp-autoplugin' ) );
			return $this->stored_custom_models();
		}

		return $models;
	}

	private function sanitize_model( $value, bool $allow_empty, string $fallback_option ): string {
		$model = sanitize_text_field( (string) $value );
		if ( '' === $model && $allow_empty ) {
			return '';
		}
		if ( in_array( $model, $this->known_model_ids(), true ) ) {
			return $model;
		}

		$this->settings_error( __( 'The selected model is not part of the configured model catalog.', 'wp-autoplugin' ) );
		return (string) get_option( $fallback_option, '' );
	}

	/** @return array<int, string> */
	private function known_model_ids(): array {
		$models = array_merge( $this->built_in_model_ids(), $this->chatgpt_model_ids() );
		foreach ( $this->stored_custom_models() as $custom ) {
			$models[] = (string) $custom['name'];
		}

		return array_values( array_unique( $models ) );
	}

	/** @return array<int, string> */
	private function built_in_model_ids(): array {
		$models = [];
		foreach ( Model_Registry::all() as $provider_models ) {
			if ( is_array( $provider_models ) ) {
				$models = array_merge( $models, array_map( 'strval', array_keys( $provider_models ) ) );
			}
		}

		return array_values( array_unique( $models ) );
	}

	/** @return array<int, string> */
	private function chatgpt_model_ids(): array {
		return array_map(
			static fn( string $slug ): string => ChatGPT_Config::catalog_id( $slug ),
			array_map( 'strval', array_keys( ChatGPT_Config::models() ) )
		);
	}

	/** @return array<int, array<string, mixed>> */
	private function stored_custom_models(): array {
		$models = get_option( 'wp_autoplugin_custom_models', [] );
		return is_array( $models ) ? array_values( array_filter( $models, 'is_array' ) ) : [];
	}

	private function settings_error( string $message ): void {
		add_settings_error( self::GROUP, 'wp_autoplugin_settings_invalid', $message, 'error' );
	}

	/** Move earlier hidden v2-only ChatGPT choices into the visible shared settings. */
	private function migrate_chatgpt_overrides(): void {
		foreach ( [ 'planner', 'coder', 'reviewer' ] as $role ) {
			$model_option  = 'wp_autoplugin_v2_' . $role . '_model';
			$effort_option = 'wp_autoplugin_v2_' . $role . '_model_effort';
			$model         = (string) get_option( $model_option, '' );
			if ( str_starts_with( $model, 'chatgpt:' ) ) {
				update_option( 'wp_autoplugin_' . $role . '_model', $model, false );
				update_option( 'wp_autoplugin_' . $role . '_model_effort', Model_Effort::sanitize( get_option( $effort_option, '' ) ), false );
			}
			delete_option( $model_option );
			delete_option( $effort_option );
		}
	}
}
