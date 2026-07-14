<?php

namespace WP_Autoplugin\V2\Infrastructure\AI;

use WP_Autoplugin\Admin\Admin;
use WP_Autoplugin\Admin\Api_Handler;
use WP_Autoplugin\V2\Domain\AI\Agent_Transport;

/** Selects only v2 transports with validated native read-tool support. */
final class Agent_Transport_Factory {
	/** @return array{available:bool,provider:string,model:string,message:string} */
	public function capability( string $stage = 'explain' ): array {
		$is_plan = 'plan' === $stage;
		$handler = new Api_Handler();
		$model   = (string) ( $is_plan ? $handler->get_planner_model() : $handler->get_reviewer_model() );
		$models = Admin::get_models();
		if ( isset( $models['OpenAI'][ $model ] ) ) {
			$available = '' !== (string) get_option( 'wp_autoplugin_openai_api_key' );
			return [
				'available' => $available,
				'provider'  => 'openai',
				'model'     => $model,
				'message'   => $available
					? sprintf( /* translators: %s: workspace stage. */ __( 'Agentic %s is available.', 'wp-autoplugin' ), $is_plan ? __( 'Plan', 'wp-autoplugin' ) : __( 'Explain', 'wp-autoplugin' ) )
					: sprintf( /* translators: %s: configured model role. */ __( 'Configure the OpenAI API key for the selected %s model.', 'wp-autoplugin' ), $is_plan ? __( 'planner', 'wp-autoplugin' ) : __( 'reviewer', 'wp-autoplugin' ) ),
			];
		}
		if ( isset( $models['Anthropic'][ $model ] ) ) {
			$available = '' !== (string) get_option( 'wp_autoplugin_anthropic_api_key' );
			return [
				'available' => $available,
				'provider'  => 'anthropic',
				'model'     => $model,
				'message'   => $available
					? sprintf( /* translators: %s: workspace stage. */ __( 'Agentic %s is available.', 'wp-autoplugin' ), $is_plan ? __( 'Plan', 'wp-autoplugin' ) : __( 'Explain', 'wp-autoplugin' ) )
					: sprintf( /* translators: %s: configured model role. */ __( 'Configure the Anthropic API key for the selected %s model.', 'wp-autoplugin' ), $is_plan ? __( 'planner', 'wp-autoplugin' ) : __( 'reviewer', 'wp-autoplugin' ) ),
			];
		}
		return [
			'available' => false,
			'provider'  => '',
			'model'     => $model,
			'message'   => $is_plan
				? __( 'The selected planner model does not support v2 agentic Plan yet. Choose an OpenAI or Anthropic model.', 'wp-autoplugin' )
				: __( 'The selected reviewer model does not support v2 agentic Explain yet. Choose an OpenAI or Anthropic model.', 'wp-autoplugin' ),
		];
	}

	/** @return Agent_Transport|\WP_Error */
	public function create( string $stage = 'explain' ) {
		$capability = $this->capability( $stage );
		if ( ! $capability['available'] ) {
			return new \WP_Error( 'agent_transport_unavailable', $capability['message'] );
		}
		return $this->create_for( $capability['provider'], $capability['model'] );
	}

	/** @return Agent_Transport|\WP_Error */
	public function create_for( string $provider, string $model ) {
		if ( 'openai' === $provider ) {
			$key = (string) get_option( 'wp_autoplugin_openai_api_key' );
			return '' !== $key ? new OpenAI_Agent_Transport( $key, $model ) : new \WP_Error( 'agent_transport_unavailable', __( 'The OpenAI API key used by this agent job is no longer configured.', 'wp-autoplugin' ) );
		}
		if ( 'anthropic' === $provider ) {
			$key = (string) get_option( 'wp_autoplugin_anthropic_api_key' );
			return '' !== $key ? new Anthropic_Agent_Transport( $key, $model ) : new \WP_Error( 'agent_transport_unavailable', __( 'The Anthropic API key used by this agent job is no longer configured.', 'wp-autoplugin' ) );
		}
		return new \WP_Error( 'agent_transport_unavailable', __( 'This agent job uses a provider that does not support native read tools.', 'wp-autoplugin' ) );
	}
}
