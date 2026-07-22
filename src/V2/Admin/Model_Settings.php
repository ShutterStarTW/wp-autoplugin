<?php

namespace WP_Autoplugin\V2\Admin;

use WP_Autoplugin\V2\Domain\AI\Model_Effort;

/** Registers and renders v2 model-effort settings. */
final class Model_Settings {
	public function register(): void {
		add_action( 'admin_init', [ $this, 'register_settings' ] );
	}

	public function register_settings(): void {
		foreach ( Model_Effort::option_names() as $option ) {
			register_setting(
				'wp_autoplugin_settings',
				$option,
				[
					'type'              => 'string',
					'sanitize_callback' => [ Model_Effort::class, 'sanitize' ],
					'default'           => '',
				]
			);
		}
		foreach ( Model_Effort::v2_option_names() as $option ) {
			register_setting( 'wp_autoplugin_settings', $option, [ 'type' => 'string', 'sanitize_callback' => [ Model_Effort::class, 'sanitize' ], 'default' => '' ] );
		}
	}

	public static function render_effort_dropdown( string $role ): void {
		$option = Model_Effort::option_name( $role );
		if ( '' === $option ) {
			return;
		}

		$value = (string) get_option( $option, '' );
		?>
		<span class="wp-autoplugin-model-effort" data-effort-role="<?php echo esc_attr( $role ); ?>" hidden>
			<label for="<?php echo esc_attr( $option ); ?>"><?php esc_html_e( 'Reasoning:', 'wp-autoplugin' ); ?></label>
			<select class="wp-autoplugin-model-effort-select" name="<?php echo esc_attr( $option ); ?>" id="<?php echo esc_attr( $option ); ?>" aria-label="<?php esc_attr_e( 'Model effort', 'wp-autoplugin' ); ?>">
				<option value="<?php echo esc_attr( $value ); ?>" selected><?php echo esc_html( $value ); ?></option>
			</select>
		</span>
		<?php
	}
}
