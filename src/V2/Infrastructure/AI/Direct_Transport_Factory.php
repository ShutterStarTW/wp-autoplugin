<?php

namespace WP_Autoplugin\V2\Infrastructure\AI;

use WP_Autoplugin\V2\Domain\AI\Direct_Transport;
use WP_Autoplugin\V2\Domain\AI\Model_Catalog;

/** Selects a v2 transport for a direct request without source tools. */
final class Direct_Transport_Factory {
	/** @return array{available:bool,provider:string,model:string,effort:string,message:string,images:bool} */
	public function capability( string $stage = 'plan' ): array {
		$role      = 'code' === $stage ? 'coder' : ( in_array( $stage, [ 'explain', 'review' ], true ) ? 'reviewer' : 'planner' );
		$selection = ( new Model_Catalog() )->selection( $role );
		if ( $selection ) {
			$available = ! empty( $selection['configured'] ) && ! empty( $selection['direct'] );
			return [
				'available' => $available,
				'provider'  => (string) $selection['provider'],
				'model'     => (string) $selection['model'],
				'effort'    => (string) $selection['effort'],
				'message'   => $available
					? __( 'Direct v2 generation is available.', 'wp-autoplugin' )
					: __( 'Configure the selected model provider before starting this task.', 'wp-autoplugin' ),
				'images'    => (bool) $selection['images'],
			];
		}
		return [
			'available' => false,
			'provider'  => '',
			'model'     => '',
			'effort'    => '',
			'message'   => __( 'The selected model is not available for this direct v2 task.', 'wp-autoplugin' ),
			'images'    => false,
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
				if ( $model !== (string) ( $custom['name'] ?? '' ) ) {
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
			// Compatibility for durable runs created before custom endpoint names
			// were retained as the model snapshot.
			foreach ( (array) get_option( 'wp_autoplugin_custom_models', [] ) as $custom ) {
				if ( $model !== (string) ( $custom['modelParameter'] ?? '' ) ) {
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
