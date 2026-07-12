<?php

namespace WP_Autoplugin\V2\Orchestration;

use WP_Autoplugin\Admin\Api_Handler;
use WP_Autoplugin\AI_Utils;
use WP_Autoplugin\Plugin_Explainer;
use WP_Autoplugin\Plugin_Extender;
use WP_Autoplugin\Plugin_Fixer;
use WP_Autoplugin\Plugin_Generator;
use WP_Autoplugin\V2\Domain\Target\Source_Reader;
use WP_Autoplugin\V2\Infrastructure\Database\Job_Repository;
use WP_Autoplugin\V2\Infrastructure\Database\Usage_Repository;
use WP_Autoplugin\V2\Infrastructure\Database\Workspace_Repository;

/**
 * Compatibility adapter that runs existing BYOK transports in durable jobs.
 *
 * It performs plan/explain requests only and never writes target files.
 */
final class Legacy_Orchestrator {
	public function register(): void {
		add_filter( 'wp_autoplugin_v2_execute_job', [ $this, 'execute' ], 10, 2 );
	}

	/**
	 * @param array<string, mixed>|null $result Previous adapter result.
	 * @param array<string, mixed>      $job    Job record.
	 * @return array<string, mixed>|\WP_Error|null
	 */
	public function execute( $result, array $job ) {
		if ( null !== $result ) {
			return $result;
		}

		$workspace = ( new Workspace_Repository() )->find( (int) $job['workspace_id'] );
		if ( ! $workspace ) {
			return new \WP_Error( 'workspace_not_found', __( 'Workspace not found.', 'wp-autoplugin' ) );
		}

		if ( ! in_array( $job['task'], [ 'plan', 'explain' ], true ) ) {
			return new \WP_Error( 'task_not_implemented', __( 'This task requires the v2 staged-revision generator, which is not registered.', 'wp-autoplugin' ) );
		}

		$handler = new Api_Handler();
		$api     = 'explain' === $job['task'] ? $handler->get_reviewer_api() : $handler->get_planner_api();
		$model   = 'explain' === $job['task'] ? $handler->get_reviewer_model() : $handler->get_planner_model();
		if ( ! $api ) {
			return new \WP_Error( 'provider_not_configured', __( 'Configure an API key and model for this task before starting the job.', 'wp-autoplugin' ) );
		}

		$jobs = new Job_Repository();
		$jobs->update( (int) $job['id'], [ 'progress' => 25 ] );
		$jobs->event( (int) $job['id'], 'provider_request', __( 'Sending a redacted task request to the selected provider.', 'wp-autoplugin' ), [ 'model' => $model, 'task' => $job['task'] ] );

		$target  = (array) $workspace['target_metadata'];
		$files   = ( new Source_Reader() )->read( $target );
		$message = trim( (string) ( $job['payload']['message'] ?? '' ) );
		$request = 'explain' === $job['task'] && '' !== $message
			? $message
			: (string) $workspace['request'];
		$type    = 'theme' === ( $target['kind'] ?? '' ) ? 'theme' : 'plugin';

		if ( 'explain' === $job['task'] ) {
			if ( empty( $files ) ) {
				return new \WP_Error( 'empty_target', __( 'There is no source to explain for this target.', 'wp-autoplugin' ) );
			}
			$content = ( new Plugin_Explainer( $api ) )->answer_plugin_question( $files, $request );
		} elseif ( 'new_plugin' === ( $target['kind'] ?? '' ) ) {
			$content = ( new Plugin_Generator( $api ) )->generate_plugin_plan( $request );
		} elseif ( 'fix' === $workspace['operation'] ) {
			$content = ( new Plugin_Fixer( $api ) )->identify_issue( $files, $request, [], $type );
		} else {
			$content = ( new Plugin_Extender( $api ) )->plan_plugin_extension( $files, $request, [], $type );
		}

		if ( is_wp_error( $content ) ) {
			return $content;
		}

		$usage    = (array) $api->get_last_token_usage();
		$provider = strtolower( preg_replace( '/_?api$/i', '', ( new \ReflectionClass( $api ) )->getShortName() ) );
		( new Usage_Repository() )->record( (int) $job['id'], $provider, $model, (string) $job['task'], $usage );

		$clean      = AI_Utils::strip_code_fences( trim( (string) $content ), 'json' );
		$structured = json_decode( $clean, true );

		return [
			'content'    => (string) $content,
			'structured' => is_array( $structured ) ? $structured : null,
			'model'      => $model,
			'provider'   => $provider,
			'usage'      => [
				'input_tokens'  => (int) ( $usage['input_tokens'] ?? 0 ),
				'output_tokens' => (int) ( $usage['output_tokens'] ?? 0 ),
			],
		];
	}
}
