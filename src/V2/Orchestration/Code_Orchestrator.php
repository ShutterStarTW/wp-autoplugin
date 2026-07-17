<?php

namespace WP_Autoplugin\V2\Orchestration;

use WP_Autoplugin\V2\Domain\AI\Prompts\New_Plugin_Code_Prompt;
use WP_Autoplugin\V2\Domain\Revision\Code_Validator;
use WP_Autoplugin\V2\Infrastructure\AI\Direct_Transport_Factory;
use WP_Autoplugin\V2\Infrastructure\Database\Code_Run_Repository;
use WP_Autoplugin\V2\Infrastructure\Database\Job_Repository;
use WP_Autoplugin\V2\Infrastructure\Database\Revision_Repository;
use WP_Autoplugin\V2\Infrastructure\Database\Usage_Repository;
use WP_Autoplugin\V2\Infrastructure\Database\Workspace_Repository;
use WP_Autoplugin\V2\Infrastructure\Queue\Queue;

/** Executes one bounded provider request per durable new-plugin Code continuation. */
final class Code_Orchestrator {
	public function register(): void {
		add_filter( 'wp_autoplugin_v2_execute_job', [ $this, 'execute' ], 6, 3 );
	}

	/**
	 * @param array<string, mixed>|null $result Previous adapter result.
	 * @param array<string, mixed>      $job    Durable job.
	 */
	public function execute( $result, array $job, int $generation = 0 ) {
		if ( null !== $result || 'code' !== ( $job['task'] ?? '' ) ) {
			return $result;
		}
		$workspace = ( new Workspace_Repository() )->find( (int) $job['workspace_id'] );
		if ( ! $workspace || 'create' !== ( $workspace['operation'] ?? '' ) || 'new_plugin' !== ( $workspace['target_kind'] ?? '' ) ) {
			return new \WP_Error( 'code_workspace_invalid', __( 'Direct Code generation is currently available only for new-plugin workspaces.', 'wp-autoplugin' ) );
		}

		$jobs      = new Job_Repository();
		$runs      = new Code_Run_Repository();
		$validator = new Code_Validator();
		$run       = $runs->find_by_job( (int) $job['id'] );
		$plan      = null;
		$manifest  = null;
		if ( ! $run ) {
			$expected = array_key_exists( 'expected_latest_revision_id', $job['payload'] ) && null !== $job['payload']['expected_latest_revision_id']
				? (int) $job['payload']['expected_latest_revision_id']
				: null;
			if ( ( new Revision_Repository() )->latest_id( (int) $workspace['id'] ) !== $expected ) {
				return new \WP_Error( 'revision_conflict', __( 'A newer revision exists. Reload the latest revision before retrying.', 'wp-autoplugin' ), [ 'status' => 409 ] );
			}
			$plan_id = (int) ( $job['payload']['plan_artifact_job_id'] ?? 0 );
			$plan    = $plan_id ? $jobs->find( $plan_id ) : null;
			if ( ! $plan || (int) $plan['workspace_id'] !== (int) $workspace['id'] || ! $jobs->is_plan_artifact( $plan ) ) {
				return new \WP_Error( 'code_plan_missing', __( 'A completed Plan artifact from this workspace is required.', 'wp-autoplugin' ) );
			}
			$manifest = $validator->plan( (array) ( $plan['result']['structured'] ?? [] ) );
			if ( is_wp_error( $manifest ) ) {
				return $manifest;
			}
			$capability = ( new Direct_Transport_Factory() )->capability( 'code' );
			if ( ! $capability['available'] ) {
				return new \WP_Error( 'code_transport_unavailable', $capability['message'] );
			}
			$parent = 'regenerate' === ( $job['payload']['mode'] ?? '' ) ? (int) ( $job['payload']['parent_revision_id'] ?? 0 ) : null;
			$run    = $runs->create(
				(int) $job['id'],
				$plan_id,
				$parent ?: null,
				$capability['provider'],
				$capability['model'],
				$capability['effort'],
				New_Plugin_Code_Prompt::SLUG,
				New_Plugin_Code_Prompt::VERSION,
				$manifest['files'],
				(string) ( $job['payload']['mode'] ?? 'generate' ),
				$manifest
			);
			$jobs->event(
				(int) $job['id'],
				'code_initialized',
				__( 'Code generation initialized from the approved Plan.', 'wp-autoplugin' ),
				[ 'files_count' => count( $manifest['files'] ), 'provider' => $run['provider'], 'model' => $run['model'], 'effort' => $run['effort'] ]
			);
		} else {
			$plan     = $jobs->find( (int) $run['plan_job_id'] );
			$manifest = $plan ? $validator->plan( (array) ( $plan['result']['structured'] ?? [] ) ) : null;
			if ( ! $plan || is_wp_error( $manifest ) || ! is_array( $manifest ) ) {
				return is_wp_error( $manifest ) ? $manifest : new \WP_Error( 'code_plan_missing', __( 'The Code run Plan artifact is unavailable.', 'wp-autoplugin' ) );
			}
		}
		if ( 'completed' === $run['status'] && ! empty( $run['revision_id'] ) ) {
			return $this->completed_result( $run );
		}

		$token = wp_generate_password( 40, false, false );
		if ( ! $runs->acquire( (int) $run['id'], $generation, $token ) ) {
			return [ '_continuation' => true ];
		}
		$job = $jobs->find( (int) $job['id'] );
		if ( ! $job || $job['cancel_requested'] ) {
			return $this->cancel( $run, $token, $jobs, $runs );
		}

		$files = $runs->files( (int) $run['id'] );
		$index = (int) $run['next_file_index'];
		if ( $index >= count( $files ) ) {
			$runs->release( (int) $run['id'], $token );
			return $this->stage( $job, $run, $manifest );
		}

		$current  = $files[ $index ];
		$feedback = (array) ( $current['error_metadata']['issues'] ?? [] );
		$runs->mark_generating( (int) $run['id'], $index, $token, $feedback );
		$jobs->event(
			(int) $job['id'],
			'code_file_started',
			sprintf( __( 'Generating %1$d of %2$d: %3$s', 'wp-autoplugin' ), $index + 1, count( $files ), $current['path'] ),
			[ 'path' => $current['path'], 'index' => $index + 1, 'total' => count( $files ) ]
		);

		$transport = ( new Direct_Transport_Factory() )->create_for( $run['provider'], $run['model'], $run['effort'] );
		if ( is_wp_error( $transport ) ) {
			$runs->release( (int) $run['id'], $token );
			return $transport;
		}
		$generated = [];
		foreach ( $files as $file ) {
			if ( 'completed' === $file['status'] ) {
				$generated[] = [ 'path' => $file['path'], 'content' => (string) $file['content'] ];
			}
		}
		$prompt   = new New_Plugin_Code_Prompt();
		$response = $transport->complete(
			$prompt->instructions(),
			$prompt->input(
				(string) $workspace['request'],
				$this->plan_content( $plan ),
				[ 'main_file' => $manifest['main_file'], 'files' => $manifest['files'] ],
				[ 'path' => $current['path'], 'type' => $current['type'], 'description' => $current['description'] ],
				$generated,
				$feedback
			),
			[ 'max_output_tokens' => 16384, 'json' => true ]
		);

		$latest = $jobs->find( (int) $job['id'] );
		if ( ! $latest || $latest['cancel_requested'] ) {
			return $this->cancel( $run, $token, $jobs, $runs );
		}
		if ( is_wp_error( $response ) ) {
			return $this->retry_or_fail( $response, $job, $run, $current, $index, $token, $runs, $jobs );
		}

		$usage = (array) ( $response['usage'] ?? [] );
		( new Usage_Repository() )->record( (int) $job['id'], $transport->provider(), $transport->model(), 'code', $usage );
		if ( 'final' !== ( $response['type'] ?? '' ) || ! is_string( $response['content'] ?? null ) ) {
			$runs->account_usage( (int) $run['id'], $token, $usage );
			$error = new \WP_Error( 'code_response_invalid', __( 'The provider did not return a complete Code response.', 'wp-autoplugin' ), [ 'retryable' => true, 'ambiguous' => false ] );
			return $this->retry_or_fail( $error, $job, $run, $current, $index, $token, $runs, $jobs );
		}
		$parsed = $validator->response( $response['content'], $current, $manifest['main_file'] );
		if ( is_wp_error( $parsed ) ) {
			$runs->account_usage( (int) $run['id'], $token, $usage );
			return $this->retry_or_fail( $parsed, $job, $run, $current, $index, $token, $runs, $jobs );
		}

		$runs->complete_file( (int) $run['id'], $index, $token, $parsed['content'], $usage );
		$completed = $index + 1;
		$jobs->update( (int) $job['id'], [ 'progress' => min( 95, 10 + (int) floor( 85 * $completed / count( $files ) ) ) ] );
		$jobs->event( (int) $job['id'], 'code_file_completed', sprintf( __( 'Completed %s.', 'wp-autoplugin' ), $current['path'] ), [ 'path' => $current['path'], 'completed' => $completed, 'total' => count( $files ) ] );

		$run = $runs->find_by_job( (int) $job['id'] );
		if ( $completed === count( $files ) && $run ) {
			return $this->stage( $job, $run, $manifest );
		}
		( new Queue() )->dispatch( (int) $job['id'], $generation + 1, true );
		return [ '_continuation' => true ];
	}

	private function stage( array $job, array $run, array $manifest ) {
		$jobs   = new Job_Repository();
		$latest = $jobs->find( (int) $job['id'] );
		if ( ! $latest || $latest['cancel_requested'] ) {
			( new Code_Run_Repository() )->terminate_by_job( (int) $job['id'], 'cancelled' );
			$jobs->update( (int) $job['id'], [ 'status' => 'cancelled', 'finished_at' => current_time( 'mysql', true ) ] );
			$jobs->event( (int) $job['id'], 'cancelled', __( 'Code generation cancelled before staging. No partial revision was created.', 'wp-autoplugin' ) );
			return [ '_continuation' => true ];
		}
		$expected = array_key_exists( 'expected_latest_revision_id', $job['payload'] ) && null !== $job['payload']['expected_latest_revision_id']
			? (int) $job['payload']['expected_latest_revision_id']
			: null;
		$revision = ( new Revision_Repository() )->stage_code_run( $run, $manifest, (int) $job['workspace_id'], (int) $job['created_by'], $expected );
		if ( is_wp_error( $revision ) ) {
			return $revision;
		}
		$run['revision_id'] = (int) $revision['id'];
		return $this->completed_result( $run, (int) $revision['files_count'] );
	}

	private function completed_result( array $run, int $files_count = 0 ): array {
		if ( ! $files_count ) {
			$files_count = count( ( new Code_Run_Repository() )->files( (int) $run['id'] ) );
		}
		return [
			'revision_id'         => (int) $run['revision_id'],
			'plan_artifact_job_id'=> (int) $run['plan_job_id'],
			'parent_revision_id'  => $run['parent_revision_id'],
			'files_count'         => $files_count,
			'provider'            => $run['provider'],
			'model'               => $run['model'],
			'effort'              => $run['effort'],
			'usage'               => [ 'input_tokens' => (int) $run['input_tokens'], 'output_tokens' => (int) $run['output_tokens'] ],
			'prompt'              => [ 'slug' => $run['prompt_slug'], 'version' => (int) $run['prompt_version'] ],
		];
	}

	private function retry_or_fail( \WP_Error $error, array $job, array $run, array $current, int $index, string $token, Code_Run_Repository $runs, Job_Repository $jobs ) {
		$data      = (array) $error->get_error_data();
		$ambiguous = ! empty( $data['ambiguous'] );
		$retryable = ! $ambiguous && ( ! array_key_exists( 'retryable', $data ) || ! empty( $data['retryable'] ) );
		$issues    = array_slice( (array) ( $data['issues'] ?? [] ), 0, 5 );
		if ( $retryable && ! $issues ) {
			$issues[] = [
				'path'    => (string) $current['path'],
				'line'    => 0,
				'code'    => sanitize_key( $error->get_error_code() ),
				'message' => substr( $error->get_error_message(), 0, 500 ),
			];
		}
		if ( $retryable && (int) $run['retry_count'] < 2 ) {
			$runs->retry_file( (int) $run['id'], $index, $token, $error->get_error_message(), $issues );
			$next_generation = (int) $run['generation'] + 1;
			$jobs->update( (int) $job['id'], [ 'status' => 'retrying' ] );
			$jobs->event( (int) $job['id'], 'code_file_retry', sprintf( __( 'Retrying %s after a bounded validation or provider failure.', 'wp-autoplugin' ), $current['path'] ), [ 'path' => $current['path'], 'attempt' => (int) $run['retry_count'] + 2 ], 'warning' );
			( new Queue() )->schedule( (int) $job['id'], $next_generation, 2 ** (int) $run['retry_count'] );
			return [ '_continuation' => true ];
		}
		$runs->release( (int) $run['id'], $token );
		return $error;
	}

	private function cancel( array $run, string $token, Job_Repository $jobs, Code_Run_Repository $runs ): array {
		$runs->release( (int) $run['id'], $token );
		$runs->terminate_by_job( (int) $run['job_id'], 'cancelled' );
		$jobs->update( (int) $run['job_id'], [ 'status' => 'cancelled', 'finished_at' => current_time( 'mysql', true ) ] );
		$jobs->event( (int) $run['job_id'], 'cancelled', __( 'Code generation cancelled. No partial revision was created.', 'wp-autoplugin' ) );
		return [ '_continuation' => true ];
	}

	private function plan_content( array $plan ): string {
		return (string) ( $plan['result']['artifact']['content'] ?? $plan['result']['content'] ?? '' );
	}
}
