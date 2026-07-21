<?php

namespace WP_Autoplugin\V2\Orchestration;

use WP_Autoplugin\V2\Domain\AI\Plan_Response;
use WP_Autoplugin\V2\Domain\AI\Prompts\New_Plugin_Plan_Prompt;
use WP_Autoplugin\V2\Infrastructure\AI\Direct_Transport_Factory;
use WP_Autoplugin\V2\Infrastructure\Database\Job_Repository;
use WP_Autoplugin\V2\Infrastructure\Database\Usage_Repository;
use WP_Autoplugin\V2\Infrastructure\Database\Workspace_Repository;
use WP_Autoplugin\V2\Infrastructure\Database\Prompt_Attachment_Repository;

/** Executes direct v2 Plan requests for workspaces that have no source target. */
final class Direct_Planner {
	public function register(): void {
		add_filter( 'wp_autoplugin_v2_execute_job', [ $this, 'execute' ], 7, 2 );
	}

	/**
	 * @param array<string, mixed>|null $result Previous adapter result.
	 * @param array<string, mixed>      $job    Durable job.
	 * @return array<string, mixed>|\WP_Error|null
	 */
	public function execute( $result, array $job ) {
		if ( null !== $result || ! $this->supports( $job ) ) {
			return $result;
		}

		$workspace = ( new Workspace_Repository() )->find( (int) $job['workspace_id'] );
		if ( ! $workspace ) {
			return new \WP_Error( 'workspace_not_found', __( 'Workspace not found.', 'wp-autoplugin' ) );
		}
		if ( 'new_plugin' !== ( $workspace['target_metadata']['kind'] ?? $workspace['target_kind'] ?? '' ) ) {
			return $result;
		}

		$model     = (array) ( $job['payload']['prompt_model'] ?? [] );
		$factory   = new Direct_Transport_Factory();
		$transport = ! empty( $model['provider'] ) && ! empty( $model['model'] )
			? $factory->create_for( (string) $model['provider'], (string) $model['model'], (string) ( $model['effort'] ?? '' ) )
			: $factory->create( 'plan' );
		if ( is_wp_error( $transport ) ) {
			return $transport;
		}

		$jobs   = new Job_Repository();
		$prompt = new New_Plugin_Plan_Prompt();
		$jobs->update( (int) $job['id'], [ 'progress' => 25 ] );
		$jobs->event(
			(int) $job['id'],
			'provider_request',
			__( 'Sending a plan request to the selected provider.', 'wp-autoplugin' ),
			[
				'model'          => $transport->model(),
				'effort'         => $transport->effort(),
				'task'           => $job['task'],
				'prompt_slug'    => New_Plugin_Plan_Prompt::SLUG,
				'prompt_version' => New_Plugin_Plan_Prompt::VERSION,
			]
		);

		$prepared = $this->prepare( $workspace, $job, $jobs, $prompt );
		if ( is_wp_error( $prepared ) ) {
			return $prepared;
		}

		$input   = $prepared['input'];
		$images  = ( new Prompt_Attachment_Repository() )->for_job( (int) $job['id'], true );
		$parsed  = null;
		$usage   = [ 'input_tokens' => 0, 'output_tokens' => 0 ];
		for ( $attempt = 1; $attempt <= 3; $attempt++ ) {
			$response = $transport->complete( $prepared['instructions'], $input, [ 'prompt_images' => $images ] );
			if ( is_wp_error( $response ) ) {
				return $response;
			}
			$attempt_usage = (array) ( $response['usage'] ?? [] );
			$usage['input_tokens']  += (int) ( $attempt_usage['input_tokens'] ?? 0 );
			$usage['output_tokens'] += (int) ( $attempt_usage['output_tokens'] ?? 0 );
			( new Usage_Repository() )->record( (int) $job['id'], $transport->provider(), $transport->model(), 'plan', $attempt_usage );

			$parsed = 'final' === ( $response['type'] ?? '' ) && is_string( $response['content'] ?? null )
				? ( new Plan_Response() )->parse(
					$response['content'],
					'plan' !== $job['task'],
					(int) ( $prepared['artifact_job_id'] ?? 0 ),
					'create'
				)
				: new \WP_Error( 'direct_plan_response_invalid', __( 'The provider did not return a final Plan response.', 'wp-autoplugin' ) );
			if ( ! is_wp_error( $parsed ) ) {
				break;
			}
			if ( 3 === $attempt ) {
				return $parsed;
			}
			$jobs->event( (int) $job['id'], 'plan_retry', __( 'The Plan response failed validation; retrying.', 'wp-autoplugin' ), [ 'attempt' => $attempt + 1 ], 'warning' );
			$input .= "\n\nThe previous response failed strict validation. Return the complete exact Plan JSON contract again.";
		}

		if ( 'plan_structure' === $job['task'] ) {
			$parsed['content']             = $prepared['artifact_content'];
			$parsed['artifact']['content'] = $prepared['artifact_content'];
		}

		return array_merge(
			$parsed,
			[
				'model'    => $transport->model(),
				'provider' => $transport->provider(),
				'effort'   => $transport->effort(),
				'usage'    => [
					'input_tokens'  => (int) ( $usage['input_tokens'] ?? 0 ),
					'output_tokens' => (int) ( $usage['output_tokens'] ?? 0 ),
				],
				'prompt'   => [
					'slug'    => New_Plugin_Plan_Prompt::SLUG,
					'version' => New_Plugin_Plan_Prompt::VERSION,
				],
			]
		);
	}

	/** @param array<string, mixed> $job */
	private function supports( array $job ): bool {
		if ( in_array( (string) ( $job['task'] ?? '' ), [ 'plan', 'plan_structure' ], true ) ) {
			return true;
		}

		return 'conversation' === ( $job['task'] ?? '' ) && 'plan' === ( $job['payload']['stage'] ?? '' );
	}

	/**
	 * @param array<string, mixed> $workspace
	 * @param array<string, mixed> $job
	 * @return array<string, mixed>|\WP_Error
	 */
	private function prepare( array $workspace, array $job, Job_Repository $jobs, New_Plugin_Plan_Prompt $prompt ) {
		$request = trim( (string) $workspace['request'] );
		if ( 'plan' === $job['task'] ) {
			return [
				'instructions' => $prompt->initial_instructions(),
				'input'        => $prompt->initial_input( $request ),
			];
		}

		$artifact_id = (int) ( $job['payload']['artifact_job_id'] ?? 0 );
		$artifact    = $artifact_id ? $jobs->find( $artifact_id ) : null;
		if ( ! $artifact || (int) $workspace['id'] !== (int) $artifact['workspace_id'] || ! $jobs->is_plan_artifact( $artifact ) ) {
			return new \WP_Error( 'direct_plan_artifact_missing', __( 'A completed Plan artifact is required for this request.', 'wp-autoplugin' ) );
		}
		$artifact_content = $this->artifact_content( $artifact );

		if ( 'plan_structure' === $job['task'] ) {
			return [
				'instructions'     => $prompt->structure_instructions(),
				'input'            => $prompt->structure_input( $request, $artifact_content, (array) ( $artifact['result']['structured'] ?? [] ) ),
				'artifact_job_id'  => $artifact_id,
				'artifact_content' => $artifact_content,
			];
		}

		return [
			'instructions'    => $prompt->follow_up_instructions(),
			'input'           => $prompt->follow_up_input(
				$request,
				$artifact_content,
				$this->history( $jobs->list_for_workspace( (int) $workspace['id'] ), (int) $job['id'] ),
				trim( (string) ( $job['payload']['message'] ?? '' ) )
			),
			'artifact_job_id' => $artifact_id,
		];
	}

	/** @param array<string, mixed> $artifact */
	private function artifact_content( array $artifact ): string {
		return (string) ( $artifact['result']['artifact']['content'] ?? $artifact['result']['content'] ?? '' );
	}

	/** @param array<int, array<string, mixed>> $jobs */
	private function history( array $jobs, int $current_job_id ): string {
		$messages = [];
		foreach ( $jobs as $history_job ) {
			if ( (int) $history_job['id'] === $current_job_id || 'conversation' !== $history_job['task'] || 'plan' !== ( $history_job['payload']['stage'] ?? '' ) ) {
				continue;
			}
			if ( ! empty( $history_job['payload']['message'] ) ) {
				$messages[] = 'Administrator: ' . $history_job['payload']['message'];
			}
			if ( 'completed' === $history_job['status'] && 'artifact' !== ( $history_job['result']['outcome'] ?? '' ) && ! empty( $history_job['result']['content'] ) ) {
				$messages[] = 'Assistant: ' . $history_job['result']['content'];
			}
		}

		return $messages ? implode( "\n\n", array_slice( $messages, -8 ) ) : __( 'No earlier messages.', 'wp-autoplugin' );
	}
}
