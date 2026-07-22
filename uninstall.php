<?php
/** Targeted cleanup for WP-Autoplugin data that is not part of durable v2 workspaces. */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

$options = [
	'_wp_autoplugin_chatgpt_oauth_tokens',
	'_wp_autoplugin_chatgpt_oauth_lock',
	'_wp_autoplugin_chatgpt_oauth_poll_lock',
	'_wp_autoplugin_chatgpt_models_lock',
	'wp_autoplugin_chatgpt_model_cache',
	'wp_autoplugin_v2_planner_model',
	'wp_autoplugin_v2_coder_model',
	'wp_autoplugin_v2_reviewer_model',
	'wp_autoplugin_v2_planner_model_effort',
	'wp_autoplugin_v2_coder_model_effort',
	'wp_autoplugin_v2_reviewer_model_effort',
];

foreach ( $options as $option ) {
	delete_option( $option );
}
delete_transient( 'wp_autoplugin_chatgpt_oauth_session' );
