<?php

namespace WP_Autoplugin\V2\Infrastructure\AI;

use WP_Autoplugin\Admin\Admin;
use WP_Autoplugin\V2\Domain\AI\Direct_Transport;
use WP_Autoplugin\V2\Domain\AI\Model_Effort;

/** Selects a v2 transport for a direct request without source tools. */
final class Direct_Transport_Factory {
	/** @return array{available:bool,provider:string,model:string,effort:string,message:string} */
	public function capability( string $stage = 'plan' ): array {
		$role   = 'code' === $stage ? 'coder' : ( in_array( $stage, [ 'explain', 'review' ], true ) ? 'reviewer' : 'planner' );
		$model  = Model_Effort::selected_model( $role );
		$effort = Model_Effort::for_role( $role );
		$models = Admin::get_models();
		foreach ( (array) get_option( 'wp_autoplugin_custom_models', [] ) as $custom ) {
			if ( $model === (string) ( $custom['name'] ?? '' ) ) {
				$available = '' !== (string) ( $custom['url'] ?? '' ) && '' !== (string) ( $custom['apiKey'] ?? '' );
				$resolved_model = trim( (string) ( $custom['modelParameter'] ?? '' ) ) ?: $model;
				return [
					'available' => $available,
					'provider'  => 'custom',
					'model'     => $resolved_model,
					'effort'    => '',
					'message'   => $available ? __( 'Direct v2 generation is available.', 'wp-autoplugin' ) : __( 'Complete the selected custom model configuration.', 'wp-autoplugin' ),
				];
			}
		}
		$providers = [
			'openai'    => [ 'catalog' => 'OpenAI', 'option' => 'wp_autoplugin_openai_api_key' ],
			'anthropic' => [ 'catalog' => 'Anthropic', 'option' => 'wp_autoplugin_anthropic_api_key' ],
			'google'    => [ 'catalog' => 'Google', 'option' => 'wp_autoplugin_google_api_key' ],
			'xai'       => [ 'catalog' => 'xAI', 'option' => 'wp_autoplugin_xai_api_key' ],
		];
		foreach ( $providers as $provider => $config ) {
			if ( isset( $models[ $config['catalog'] ][ $model ] ) ) {
				$available = '' !== (string) get_option( $config['option'] );
				return [
					'available' => $available,
					'provider'  => $provider,
					'model'     => $model,
					'effort'    => in_array( $provider, [ 'openai', 'anthropic' ], true ) ? $effort : '',
					'message'   => $available
						? __( 'Direct v2 generation is available.', 'wp-autoplugin' )
						: __( 'Configure the API key for the selected model role.', 'wp-autoplugin' ),
				];
			}
		}
		return [
			'available' => false,
			'provider'  => '',
			'model'     => $model,
			'effort'    => '',
			'message'   => __( 'The selected model is not available for this direct v2 task.', 'wp-autoplugin' ),
		];
	}

	/** @return Direct_Transport|\WP_Error */
	public function create( string $stage = 'plan' ) {
		$capability = $this->capability( $stage );
		if ( ! $capability['available'] ) {
			return new \WP_Error( 'direct_transport_unavailable', $capability['message'] );
		}

		$transport = $this->create_for( $capability['provider'], $capability['model'], $capability['effort'] );
		return $transport instanceof Direct_Transport
			? $transport
			: new \WP_Error( 'direct_transport_unavailable', __( 'The selected provider does not support direct v2 requests.', 'wp-autoplugin' ) );
	}

	/** @return Direct_Transport|\WP_Error */
	public function create_for( string $provider, string $model, string $effort ) {
		if ( 'openai' === $provider || 'anthropic' === $provider ) {
			return ( new Agent_Transport_Factory() )->create_for( $provider, $model, $effort );
		}
		if ( 'google' === $provider ) {
			return new Google_Direct_Transport( (string) get_option( 'wp_autoplugin_google_api_key' ), $model );
		}
		if ( 'xai' === $provider ) {
			return new OpenAI_Compatible_Direct_Transport( 'xai', 'https://api.x.ai/v1/chat/completions', (string) get_option( 'wp_autoplugin_xai_api_key' ), $model );
		}
		if ( 'custom' === $provider ) {
			foreach ( (array) get_option( 'wp_autoplugin_custom_models', [] ) as $custom ) {
				if ( $model !== (string) ( $custom['name'] ?? '' ) && $model !== (string) ( $custom['modelParameter'] ?? '' ) ) {
					continue;
				}
				return new OpenAI_Compatible_Direct_Transport(
					'custom',
					(string) ( $custom['url'] ?? '' ),
					(string) ( $custom['apiKey'] ?? '' ),
					trim( (string) ( $custom['modelParameter'] ?? '' ) ) ?: $model,
					$this->custom_headers( (array) ( $custom['headers'] ?? [] ) )
				);
			}
		}

		return new \WP_Error( 'direct_transport_unavailable', __( 'The selected provider does not support direct v2 requests.', 'wp-autoplugin' ) );
	}

	/** @param array<int, string> $lines @return array<string, string> */
	private function custom_headers( array $lines ): array {
		$headers = [];
		foreach ( $lines as $line ) {
			if ( ! is_string( $line ) || ! str_contains( $line, '=' ) ) {
				continue;
			}
			[ $name, $value ] = array_map( 'trim', explode( '=', $line, 2 ) );
			if ( '' !== $name && '' !== $value ) {
				$headers[ $name ] = $value;
			}
		}

		return $headers;
	}
}
