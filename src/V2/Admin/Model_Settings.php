<?php

namespace WP_Autoplugin\V2\Admin;

use WP_Autoplugin\V2\Domain\AI\Model_Effort;

/** Registers and renders v2 model-effort settings. */
final class Model_Settings {
	public function register(): void {
		add_action( 'admin_init', [ $this, 'register_settings' ] );
	}

	public function register_settings(): void {
		$this->migrate_chatgpt_overrides();
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
	}

	/** Move the earlier hidden v2-only ChatGPT choices into the visible shared settings. */
	private function migrate_chatgpt_overrides(): void {
		foreach ( [ 'planner', 'coder', 'reviewer' ] as $role ) {
			$model_option = 'wp_autoplugin_v2_' . $role . '_model';
			$effort_option = 'wp_autoplugin_v2_' . $role . '_model_effort';
			$model = (string) get_option( $model_option, '' );
			if ( str_starts_with( $model, 'chatgpt:' ) ) {
				update_option( 'wp_autoplugin_' . $role . '_model', $model, false );
				update_option( 'wp_autoplugin_' . $role . '_model_effort', Model_Effort::sanitize( get_option( $effort_option, '' ) ), false );
			}
			delete_option( $model_option );
			delete_option( $effort_option );
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
