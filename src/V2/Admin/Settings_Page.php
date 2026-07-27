<?php

namespace WP_Autoplugin\V2\Admin;

use WP_Autoplugin\V2\Domain\AI\Global_Instructions;
use WP_Autoplugin\V2\Domain\AI\Model_Catalog;
use WP_Autoplugin\V2\Domain\AI\Model_Effort;
use WP_Autoplugin\V2\Domain\AI\Model_Registry;
use WP_Autoplugin\V2\Infrastructure\AI\ChatGPT_Model_Service;

/**
 * Renders the native v2 settings screen.
 */
final class Settings_Page {
	/** @var array<int, array<string, mixed>>|null */
	private ?array $chatgpt_models = null;

	public function render(): void {
		$planner_model          = (string) get_option( 'wp_autoplugin_planner_model', '' );
		$coder_model            = (string) get_option( 'wp_autoplugin_coder_model', '' );
		$reviewer_model         = (string) get_option( 'wp_autoplugin_reviewer_model', '' );
		$has_specialized_models = '' !== $planner_model || '' !== $coder_model || '' !== $reviewer_model;
		$model_state            = ( new ChatGPT_Model_Service() )->state();
		$custom_models          = $this->custom_models();
		?>
		<div class="wrap wp-autoplugin-v2-settings">
			<h1><?php esc_html_e( 'WP-Autoplugin Settings', 'wp-autoplugin' ); ?></h1>
			<?php settings_errors(); ?>
			<form method="post" action="options.php">
				<?php settings_fields( Settings::GROUP ); ?>

				<h2><?php esc_html_e( 'AI Providers', 'wp-autoplugin' ); ?></h2>
				<p><?php esc_html_e( 'Configure at least one provider used by your selected models.', 'wp-autoplugin' ); ?></p>
				<table class="form-table" role="presentation">
					<?php $this->render_secret_row( 'wp_autoplugin_openai_api_key', __( 'OpenAI API Key', 'wp-autoplugin' ), 'openai' ); ?>
					<tr id="wp-autoplugin-chatgpt-provider" data-model-synced-at="<?php echo esc_attr( (string) $model_state['last_synced_at'] ); ?>">
						<th scope="row"><?php esc_html_e( 'ChatGPT Subscription (experimental)', 'wp-autoplugin' ); ?></th>
						<td>
							<div class="wp-autoplugin-chatgpt-controls">
								<strong class="wp-autoplugin-chatgpt-status" data-state="loading" aria-live="polite"><?php esc_html_e( 'Checking…', 'wp-autoplugin' ); ?></strong>
								<span class="wp-autoplugin-chatgpt-account" hidden></span>
								<button type="button" class="button wp-autoplugin-chatgpt-connect"><?php esc_html_e( 'Connect', 'wp-autoplugin' ); ?></button>
								<button type="button" class="button wp-autoplugin-chatgpt-cancel" hidden><?php esc_html_e( 'Cancel connection', 'wp-autoplugin' ); ?></button>
								<button type="button" class="button wp-autoplugin-chatgpt-disconnect" hidden><?php esc_html_e( 'Disconnect', 'wp-autoplugin' ); ?></button>
								<button type="button" class="button wp-autoplugin-chatgpt-refresh" hidden><?php esc_html_e( 'Refresh models', 'wp-autoplugin' ); ?></button>
							</div>
							<div class="wp-autoplugin-chatgpt-device" hidden>
								<code class="wp-autoplugin-chatgpt-code"></code>
								<button type="button" class="button wp-autoplugin-chatgpt-copy"><?php esc_html_e( 'Copy code', 'wp-autoplugin' ); ?></button>
								<a class="button wp-autoplugin-chatgpt-open" href="#" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Open verification page', 'wp-autoplugin' ); ?></a>
							</div>
							<p class="description wp-autoplugin-chatgpt-notice" role="status" hidden></p>
						</td>
					</tr>
					<?php $this->render_secret_row( 'wp_autoplugin_anthropic_api_key', __( 'Anthropic API Key', 'wp-autoplugin' ), 'anthropic' ); ?>
					<?php $this->render_secret_row( 'wp_autoplugin_google_api_key', __( 'Google Gemini API Key', 'wp-autoplugin' ), 'google' ); ?>
					<?php $this->render_secret_row( 'wp_autoplugin_xai_api_key', __( 'xAI API Key', 'wp-autoplugin' ), 'xai' ); ?>
				</table>

				<h2><?php esc_html_e( 'Models', 'wp-autoplugin' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="wp_autoplugin_model"><?php esc_html_e( 'Default Model', 'wp-autoplugin' ); ?></label></th>
						<td>
							<div class="wp-autoplugin-model-setting">
								<?php $this->render_model_select( 'wp_autoplugin_model', (string) get_option( 'wp_autoplugin_model', '' ), false, 'default' ); ?>
								<?php $this->render_effort_dropdown( 'default' ); ?>
							</div>
							<p>
								<button type="button" id="toggle-specialized-models" class="button-link" aria-expanded="<?php echo $has_specialized_models ? 'true' : 'false'; ?>">
									<span class="wp-autoplugin-toggle-label"><?php echo esc_html( $has_specialized_models ? __( 'Hide specialized model settings', 'wp-autoplugin' ) : __( 'Show specialized model settings', 'wp-autoplugin' ) ); ?></span>
									<span class="dashicons <?php echo esc_attr( $has_specialized_models ? 'dashicons-arrow-up-alt2' : 'dashicons-arrow-down-alt2' ); ?>" aria-hidden="true"></span>
								</button>
							</p>
						</td>
					</tr>
				</table>

				<div class="wp-autoplugin-per-step-models" <?php echo $has_specialized_models ? '' : 'hidden'; ?>>
					<table class="form-table" role="presentation">
						<?php
						$this->render_role_row( 'planner', __( 'Planner Model', 'wp-autoplugin' ), $planner_model, __( 'Used for plans and source analysis. Falls back to Default Model if not set.', 'wp-autoplugin' ) );
						$this->render_role_row( 'coder', __( 'Coder Model', 'wp-autoplugin' ), $coder_model, __( 'Used for generating and revising code. Falls back to Default Model if not set.', 'wp-autoplugin' ) );
						$this->render_role_row( 'reviewer', __( 'Reviewer Model', 'wp-autoplugin' ), $reviewer_model, __( 'Used for explanations and code review. Falls back to Default Model if not set.', 'wp-autoplugin' ) );
						?>
					</table>
				</div>

				<h2><?php esc_html_e( 'Custom instructions', 'wp-autoplugin' ); ?></h2>
				<p><?php esc_html_e( 'Set site-wide guidance for coding conventions, architecture preferences, and other instructions that should apply to future AI jobs.', 'wp-autoplugin' ); ?></p>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="<?php echo esc_attr( Global_Instructions::OPTION_NAME ); ?>"><?php esc_html_e( 'Custom instructions', 'wp-autoplugin' ); ?></label></th>
						<td>
							<textarea name="<?php echo esc_attr( Global_Instructions::OPTION_NAME ); ?>" id="<?php echo esc_attr( Global_Instructions::OPTION_NAME ); ?>" rows="12" class="large-text code"><?php echo esc_textarea( (string) get_option( Global_Instructions::OPTION_NAME, '' ) ); ?></textarea>
							<p class="description">
								<?php esc_html_e( 'Applied to Plan, Explain, Code, Review, follow-up, and Review-fix jobs queued after you save. Running jobs and automatic retries keep their original snapshot. A plugin-root AGENTS.md and the current request take precedence.', 'wp-autoplugin' ); ?>
							</p>
							<p class="description">
								<?php esc_html_e( 'Instructions are stored with each job and sent to the selected AI provider. Do not include passwords, API keys, or other secrets. Maximum size: 64 KiB.', 'wp-autoplugin' ); ?>
							</p>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Custom Models', 'wp-autoplugin' ); ?></h2>
				<p><?php esc_html_e( 'Add OpenAI-compatible endpoints. Custom model changes are persisted when you save this settings form.', 'wp-autoplugin' ); ?></p>
				<div id="custom-models-list">
					<div class="custom-models-items" aria-live="polite"></div>
				</div>
				<div id="add-custom-model-form">
					<label class="screen-reader-text" for="custom-model-name"><?php esc_html_e( 'Custom model name', 'wp-autoplugin' ); ?></label>
					<input type="text" id="custom-model-name" placeholder="<?php esc_attr_e( 'Model Name (User-defined Label)', 'wp-autoplugin' ); ?>" class="large-text">
					<label class="screen-reader-text" for="custom-model-url"><?php esc_html_e( 'Custom model endpoint URL', 'wp-autoplugin' ); ?></label>
					<input type="url" id="custom-model-url" placeholder="<?php esc_attr_e( 'API Endpoint URL', 'wp-autoplugin' ); ?>" class="large-text">
					<label class="screen-reader-text" for="custom-model-parameter"><?php esc_html_e( 'Custom model parameter', 'wp-autoplugin' ); ?></label>
					<input type="text" id="custom-model-parameter" placeholder="<?php esc_attr_e( '"model" Parameter Value', 'wp-autoplugin' ); ?>" class="large-text">
					<label class="screen-reader-text" for="custom-model-api-key"><?php esc_html_e( 'Custom model API key', 'wp-autoplugin' ); ?></label>
					<input type="password" id="custom-model-api-key" placeholder="<?php esc_attr_e( 'API Key', 'wp-autoplugin' ); ?>" class="large-text" autocomplete="off">
					<label class="screen-reader-text" for="custom-model-headers"><?php esc_html_e( 'Custom model headers', 'wp-autoplugin' ); ?></label>
					<textarea id="custom-model-headers" placeholder="<?php esc_attr_e( 'Additional Headers (one per line, name=value)', 'wp-autoplugin' ); ?>" rows="4" class="large-text"></textarea>
					<div class="wp-autoplugin-add-custom-model-action">
						<button type="button" id="add-custom-model" class="button"><?php esc_html_e( 'Add Custom Model', 'wp-autoplugin' ); ?></button>
					</div>
				</div>
				<input type="hidden" name="wp_autoplugin_custom_models" id="wp_autoplugin_custom_models" value="<?php echo esc_attr( wp_json_encode( $custom_models ) ); ?>">

				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}

	private function render_secret_row( string $option, string $label, string $provider ): void {
		?>
		<tr>
			<th scope="row"><label for="<?php echo esc_attr( $option ); ?>"><?php echo esc_html( $label ); ?></label></th>
			<td>
				<div class="wp-autoplugin-api-key-field">
					<input type="password" name="<?php echo esc_attr( $option ); ?>" id="<?php echo esc_attr( $option ); ?>" value="<?php echo esc_attr( (string) get_option( $option, '' ) ); ?>" class="regular-text" autocomplete="off">
					<button type="button" class="button wp-autoplugin-test-api-key" data-provider="<?php echo esc_attr( $provider ); ?>" aria-live="polite"><?php esc_html_e( 'Test', 'wp-autoplugin' ); ?></button>
				</div>
			</td>
		</tr>
		<?php
	}

	private function render_role_row( string $role, string $label, string $selected, string $description ): void {
		$option = 'wp_autoplugin_' . $role . '_model';
		?>
		<tr>
			<th scope="row"><label for="<?php echo esc_attr( $option ); ?>"><?php echo esc_html( $label ); ?></label></th>
			<td>
				<div class="wp-autoplugin-model-setting">
					<?php $this->render_model_select( $option, $selected, true, $role ); ?>
					<?php $this->render_effort_dropdown( $role ); ?>
				</div>
				<p class="description"><?php echo esc_html( $description ); ?></p>
			</td>
		</tr>
		<?php
	}

	private function render_model_select( string $option, string $selected, bool $allow_inherit, string $role ): void {
		$known = [];
		?>
		<select name="<?php echo esc_attr( $option ); ?>" id="<?php echo esc_attr( $option ); ?>" data-model-select data-model-role="<?php echo esc_attr( $role ); ?>">
			<?php if ( $allow_inherit ) : ?>
				<option value="" <?php selected( $selected, '' ); ?>><?php esc_html_e( 'Use Default Model', 'wp-autoplugin' ); ?></option>
			<?php endif; ?>
			<?php foreach ( Model_Registry::all() as $provider => $models ) : ?>
				<?php if ( is_array( $models ) ) : ?>
					<optgroup label="<?php echo esc_attr( (string) $provider ); ?>">
						<?php foreach ( $models as $model => $label ) : ?>
							<?php $known[] = (string) $model; ?>
							<option value="<?php echo esc_attr( (string) $model ); ?>" <?php selected( $selected, (string) $model ); ?>><?php echo esc_html( (string) $label ); ?></option>
						<?php endforeach; ?>
					</optgroup>
				<?php endif; ?>
			<?php endforeach; ?>
			<?php if ( $this->chatgpt_models() ) : ?>
				<optgroup label="<?php esc_attr_e( 'ChatGPT Subscription (Experimental)', 'wp-autoplugin' ); ?>">
					<?php foreach ( $this->chatgpt_models() as $model ) : ?>
						<?php
						$id          = (string) $model['id'];
						$is_selected = $selected === $id;
						$is_disabled = empty( $model['available'] ) && ! $is_selected;
						$known[]     = $id;
						$label       = (string) $model['label'] . ( ! empty( $model['available'] ) ? '' : ' — ' . __( 'Unavailable', 'wp-autoplugin' ) );
						?>
						<option value="<?php echo esc_attr( $id ); ?>" <?php selected( $is_selected ); ?> <?php disabled( $is_disabled ); ?> title="<?php echo esc_attr( (string) ( $model['availability_message'] ?? '' ) ); ?>"><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</optgroup>
			<?php endif; ?>
			<?php if ( $this->custom_models() ) : ?>
				<optgroup label="<?php esc_attr_e( 'Custom Models', 'wp-autoplugin' ); ?>" data-custom-model-group>
					<?php foreach ( $this->custom_models() as $model ) : ?>
						<?php $known[] = (string) $model['name']; ?>
						<option value="<?php echo esc_attr( (string) $model['name'] ); ?>" <?php selected( $selected, (string) $model['name'] ); ?>><?php echo esc_html( (string) $model['name'] ); ?></option>
					<?php endforeach; ?>
				</optgroup>
			<?php endif; ?>
			<?php if ( '' !== $selected && ! in_array( $selected, $known, true ) ) : ?>
				<optgroup label="<?php esc_attr_e( 'Unavailable selection', 'wp-autoplugin' ); ?>">
					<option value="<?php echo esc_attr( $selected ); ?>" selected><?php echo esc_html( $selected ); ?></option>
				</optgroup>
			<?php endif; ?>
		</select>
		<?php
	}

	private function render_effort_dropdown( string $role ): void {
		$option = Model_Effort::option_name( $role );
		$value  = (string) get_option( $option, '' );
		?>
		<span class="wp-autoplugin-model-effort" data-effort-role="<?php echo esc_attr( $role ); ?>" hidden>
			<label for="<?php echo esc_attr( $option ); ?>"><?php esc_html_e( 'Reasoning:', 'wp-autoplugin' ); ?></label>
			<select class="wp-autoplugin-model-effort-select" name="<?php echo esc_attr( $option ); ?>" id="<?php echo esc_attr( $option ); ?>">
				<option value="<?php echo esc_attr( $value ); ?>" selected><?php echo esc_html( $value ); ?></option>
			</select>
		</span>
		<?php
	}

	/** @return array<int, array<string, mixed>> */
	private function chatgpt_models(): array {
		if ( null === $this->chatgpt_models ) {
			$this->chatgpt_models = array_values(
				array_filter(
					( new Model_Catalog() )->catalog(),
					static fn( array $model ): bool => 'chatgpt' === (string) ( $model['provider'] ?? '' )
				)
			);
		}

		return $this->chatgpt_models;
	}

	/** @return array<int, array<string, mixed>> */
	private function custom_models(): array {
		$models = get_option( 'wp_autoplugin_custom_models', [] );
		return is_array( $models ) ? array_values( array_filter( $models, 'is_array' ) ) : [];
	}
}
