<?php

namespace WP_Autoplugin\V2\Domain\AI;

use WP_Autoplugin\Admin\Admin;

/**
 * Provides one sanitized model catalog and role-selection contract for v2.
 */
final class Model_Catalog {
	private const ROLE_OPTIONS = [
		'planner'  => 'wp_autoplugin_planner_model',
		'coder'    => 'wp_autoplugin_coder_model',
		'reviewer' => 'wp_autoplugin_reviewer_model',
	];

	private const PROVIDERS = [
		'OpenAI'    => [ 'id' => 'openai', 'key_option' => 'wp_autoplugin_openai_api_key' ],
		'Anthropic' => [ 'id' => 'anthropic', 'key_option' => 'wp_autoplugin_anthropic_api_key' ],
		'Google'    => [ 'id' => 'google', 'key_option' => 'wp_autoplugin_google_api_key' ],
		'xAI'       => [ 'id' => 'xai', 'key_option' => 'wp_autoplugin_xai_api_key' ],
	];

	public static function valid_role( string $role ): bool {
		return isset( self::ROLE_OPTIONS[ $role ] );
	}

	/**
	 * Return the secret-free model catalog exposed to administrators.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function catalog(): array {
		return array_values(
			array_map(
				static function ( array $definition ): array {
					unset( $definition['transport_model'] );
					return $definition;
				},
				$this->definitions()
			)
		);
	}

	/** @return array<string, array<string, mixed>> */
	public function roles(): array {
		return [
			'planner'  => $this->selection( 'planner' ),
			'coder'    => $this->selection( 'coder' ),
			'reviewer' => $this->selection( 'reviewer' ),
		];
	}

	/** @return array{catalog:array<int, array<string, mixed>>,default:array<string, mixed>,roles:array<string, array<string, mixed>>} */
	public function state(): array {
		return [
			'catalog' => $this->catalog(),
			'default' => $this->default_selection(),
			'roles'   => $this->roles(),
		];
	}

	/** @return array<string, mixed> */
	public function default_selection(): array {
		$model      = (string) get_option( 'wp_autoplugin_model', '' );
		$definition = $this->definition( $model );
		return [
			'model'             => $model,
			'label'             => (string) ( $definition['label'] ?? $model ),
			'provider'          => (string) ( $definition['provider'] ?? '' ),
			'configured'        => (bool) ( $definition['configured'] ?? false ),
			'available'         => (bool) ( $definition['available'] ?? false ),
			'direct'            => (bool) ( $definition['direct'] ?? false ),
			'native_read_tools' => (bool) ( $definition['native_read_tools'] ?? false ),
			'images'            => (bool) ( $definition['images'] ?? false ),
			'effort'            => Model_Effort::for_role( 'default' ),
			'effort_levels'     => (array) ( $definition['effort_levels'] ?? [] ),
			'default_effort'    => (string) ( $definition['default_effort'] ?? '' ),
		];
	}

	/**
	 * Return a role's configured and effective selection without any credentials.
	 *
	 * @return array<string, mixed>
	 */
	public function selection( string $role ): array {
		if ( ! self::valid_role( $role ) ) {
			return [];
		}

		$configured_model = (string) get_option( self::ROLE_OPTIONS[ $role ], '' );
		$inherited        = '' === $configured_model;
		$model            = $inherited ? (string) get_option( 'wp_autoplugin_model', '' ) : $configured_model;
		$definition       = $this->definition( $model );
		$effort           = Model_Effort::for_role( $role );

		return [
			'role'              => $role,
			'configured_model'  => $configured_model,
			'inherits_default'  => $inherited,
			'model'             => $model,
			'label'             => (string) ( $definition['label'] ?? $model ),
			'provider'          => (string) ( $definition['provider'] ?? '' ),
			'configured'        => (bool) ( $definition['configured'] ?? false ),
			'available'         => (bool) ( $definition['available'] ?? false ),
			'direct'            => (bool) ( $definition['direct'] ?? false ),
			'native_read_tools' => (bool) ( $definition['native_read_tools'] ?? false ),
			'images'            => (bool) ( $definition['images'] ?? false ),
			'effort'            => $effort,
			'effort_levels'     => (array) ( $definition['effort_levels'] ?? [] ),
			'default_effort'    => (string) ( $definition['default_effort'] ?? '' ),
		];
	}

	/**
	 * Persist a specialized role selection in the existing global options.
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	public function update( string $role, string $model, string $effort ) {
		if ( ! self::valid_role( $role ) ) {
			return new \WP_Error( 'wp_autoplugin_model_role_invalid', __( 'The selected model role is invalid.', 'wp-autoplugin' ), [ 'status' => 400 ] );
		}

		$model = sanitize_text_field( $model );
		if ( '' === $model ) {
			update_option( self::ROLE_OPTIONS[ $role ], '' );
			update_option( Model_Effort::option_name( $role ), '' );
			return $this->selection( $role );
		}

		if ( ! $this->definition( $model ) ) {
			return new \WP_Error( 'wp_autoplugin_model_invalid', __( 'The selected model is not available in the configured catalog.', 'wp-autoplugin' ), [ 'status' => 400 ] );
		}

		$normalized_effort = Model_Effort::normalize( $model, $effort );
		update_option( self::ROLE_OPTIONS[ $role ], $model );
		update_option( Model_Effort::option_name( $role ), $normalized_effort );

		return $this->selection( $role );
	}

	/** @return array<string, mixed>|null */
	public function definition( string $model ): ?array {
		$definitions = $this->definitions();
		return $definitions[ $model ] ?? null;
	}

	/** Resolve the remote model parameter while retaining the catalog ID in durable records. */
	public function transport_model( string $provider, string $model ): string {
		$definition = $this->definition( $model );
		if ( $definition && $provider === $definition['provider'] ) {
			return (string) $definition['transport_model'];
		}

		return $model;
	}

	/** @return array<string, array<string, mixed>> */
	private function definitions(): array {
		$definitions = [];
		$efforts     = Model_Effort::capabilities();
		$matrix      = new Capability_Matrix();

		foreach ( Admin::get_models() as $provider_label => $models ) {
			$provider = self::PROVIDERS[ $provider_label ] ?? null;
			if ( ! $provider ) {
				continue;
			}
			$configured = '' !== (string) get_option( $provider['key_option'], '' );
			foreach ( $models as $model => $label ) {
				$effort     = $efforts[ $model ] ?? null;
				$capability = $matrix->for_model( $provider['id'], (string) $model );
				$direct     = (bool) ( $capability['direct_mode'] ?? false );
				$native     = (bool) ( $capability['native_read_tools'] ?? false );
				$definitions[ (string) $model ] = [
					'id'                => (string) $model,
					'label'             => (string) $label,
					'provider'          => (string) $provider['id'],
					'provider_label'    => (string) $provider_label,
					'configured'        => $configured,
					'available'         => $configured && ( $direct || $native ),
					'direct'            => $direct,
					'native_read_tools' => $native,
					'images'            => (bool) $capability['images'],
					'effort_levels'     => $effort ? array_values( $effort['levels'] ) : [],
					'default_effort'    => $effort ? (string) $effort['default'] : '',
					'transport_model'   => (string) $model,
				];
			}
		}

		foreach ( (array) get_option( 'wp_autoplugin_custom_models', [] ) as $custom ) {
			$id = sanitize_text_field( (string) ( $custom['name'] ?? '' ) );
			if ( '' === $id ) {
				continue;
			}
			$configured = '' !== trim( (string) ( $custom['url'] ?? '' ) ) && '' !== (string) ( $custom['apiKey'] ?? '' );
			$capability = $matrix->for_model( 'custom', $id );
			$direct     = (bool) ( $capability['direct_mode'] ?? false );
			$definitions[ $id ] = [
				'id'                => $id,
				'label'             => $id,
				'provider'          => 'custom',
				'provider_label'    => __( 'Custom Models', 'wp-autoplugin' ),
				'configured'        => $configured,
				'available'         => $configured && $direct,
				'direct'            => $direct,
				'native_read_tools' => false,
				'images'            => (bool) $capability['images'],
				'effort_levels'     => [],
				'default_effort'    => '',
				'transport_model'   => trim( (string) ( $custom['modelParameter'] ?? '' ) ) ?: $id,
			];
		}

		return $definitions;
	}
}
