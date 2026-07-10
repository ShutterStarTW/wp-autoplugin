<?php

namespace WP_Autoplugin\V2\Domain\AI;

/**
 * Declares orchestration features separately from provider transports.
 */
final class Capability_Matrix {
	/**
	 * Return conservative capabilities for the currently bundled transports.
	 *
	 * @return array<string, bool|int|string>
	 */
	public function for_model( string $provider, string $model ): array {
		$capabilities = [
			'provider'            => sanitize_key( $provider ),
			'model'               => sanitize_text_field( $model ),
			'direct_mode'         => true,
			'native_read_tools'   => false,
			'unified_patch'       => false,
			'images'              => in_array( $model, \WP_Autoplugin\AI_Utils::get_supported_image_models(), true ),
			'max_tool_iterations' => 0,
		];

		/**
		 * Filter model capabilities when a transport implements bounded tool use.
		 * Writers and arbitrary command tools are never valid v2 capabilities.
		 *
		 * @param array  $capabilities Capability declaration.
		 * @param string $provider     Provider identifier.
		 * @param string $model        Model identifier.
		 */
		return (array) apply_filters( 'wp_autoplugin_v2_model_capabilities', $capabilities, $provider, $model );
	}
}
