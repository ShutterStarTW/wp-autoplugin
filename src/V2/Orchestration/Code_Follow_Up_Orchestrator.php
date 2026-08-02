<?php

namespace WP_Autoplugin\V2\Orchestration;

use WP_Autoplugin\V2\Domain\AI\Code_Follow_Up_Compliance_Response;
use WP_Autoplugin\V2\Domain\AI\Code_Follow_Up_Response;
use WP_Autoplugin\V2\Domain\AI\Global_Instructions;
use WP_Autoplugin\V2\Domain\AI\Prompts\Code_Follow_Up_Compliance_Prompt;
use WP_Autoplugin\V2\Domain\AI\Prompts\Existing_Target_Code_Follow_Up_Prompt;
use WP_Autoplugin\V2\Domain\AI\Prompts\Extension_Plugin_Code_Follow_Up_Prompt;
use WP_Autoplugin\V2\Domain\AI\Prompts\New_Plugin_Code_Follow_Up_Prompt;
use WP_Autoplugin\V2\Domain\Revision\Code_Validator;
use WP_Autoplugin\V2\Domain\Target\Source_Tools;
use WP_Autoplugin\V2\Infrastructure\AI\Direct_Transport_Factory;
use WP_Autoplugin\V2\Infrastructure\Database\Code_Run_Repository;
use WP_Autoplugin\V2\Infrastructure\Database\Job_Repository;
use WP_Autoplugin\V2\Infrastructure\Database\Plan_Repository;
use WP_Autoplugin\V2\Infrastructure\Database\Revision_Repository;
use WP_Autoplugin\V2\Infrastructure\Database\Usage_Repository;
use WP_Autoplugin\V2\Infrastructure\Database\Project_Repository;
use WP_Autoplugin\V2\Infrastructure\Database\Prompt_Attachment_Repository;
use WP_Autoplugin\V2\Infrastructure\Queue\Queue;

/** Runs a durable question-or-change conversation against the latest staged Code. */
final class Code_Follow_Up_Orchestrator {
	public function register(): void {
		add_filter( 'wp_autoplugin_v2_execute_job', [ $this, 'execute' ], 6, 3 );
	}

	/**
	 * @param array<string, mixed>|null $result Previous adapter result.
	 * @param array<string, mixed>      $job    Durable job.
	 */
	public function execute( $result, array $job, int $generation = 0 ) {
		if ( null !== $result || ! Job_Repository::is_code_work( $job ) || ! in_array( (string) ( $job['task'] ?? '' ), [ 'conversation', 'review_fix' ], true ) ) {
			return $result;
		}
		$workspace = ( new Project_Repository() )->find( (int) $job['project_id'] );
		if ( ! $workspace || ! $this->supports( $workspace ) ) {
			return new \WP_Error( 'code_follow_up_workspace', __( 'Code follow-ups are not available for this workspace operation.', 'wp-autoplugin' ) );
		}

		$jobs      = new Job_Repository();
		$runs      = new Code_Run_Repository();
		$revisions = new Revision_Repository();
		$run       = $runs->find_by_job( (int) $job['id'] );
		$base_id   = (int) ( $job['payload']['revision_id'] ?? 0 );
		$base      = $revisions->find( $base_id );
		if ( ! $base || (int) $base['project_id'] !== (int) $workspace['id'] || $base_id !== (int) ( $job['payload']['expected_latest_revision_id'] ?? 0 ) ) {
			return new \WP_Error( 'code_follow_up_revision', __( 'The Code follow-up must be anchored to the current latest revision.', 'wp-autoplugin' ) );
		}
		if ( ! is_array( $base['project_manifest'] ?? null ) ) {
			return new \WP_Error( 'code_follow_up_manifest', __( 'The current revision does not have a usable project manifest.', 'wp-autoplugin' ) );
		}

		if ( ! $run ) {
			if ( $revisions->latest_id( (int) $workspace['id'] ) !== $base_id ) {
				return $this->conflict();
			}
			$capability = (array) ( $job['payload']['prompt_model'] ?? [] );
			if ( empty( $capability['provider'] ) || empty( $capability['model'] ) ) {
				$capability = ( new Direct_Transport_Factory() )->capability( 'code' );
			} else {
				$capability['available'] = true;
			}
			if ( ! $capability['available'] ) {
				return new \WP_Error( 'code_follow_up_transport', $capability['message'] );
			}
			$prompt = $this->prompt_metadata( $workspace, $base['project_manifest'] );
			$run    = $runs->create_follow_up(
				(int) $job['id'],
				(int) $base['plan_id'],
				$base_id,
				$capability['provider'],
				$capability['model'],
				$capability['effort'],
				$prompt['slug'],
				$prompt['version']
			);
			$jobs->event(
				(int) $job['id'],
				'code_follow_up_initialized',
				__( 'Analyzing the Code follow-up against the latest staged revision.', 'wp-autoplugin' ),
				[
					'revision_id' => $base_id,
					'phase'       => 'analysis',
					'provider'    => $run['provider'],
					'model'       => $run['model'],
				]
			);
		}
		if ( 'completed' === $run['status'] && $run['outcome'] ) {
			return $this->completed_result( $run );
		}

		$token = wp_generate_password( 40, false, false );
		if ( ! $runs->acquire( (int) $run['id'], $generation, $token ) ) {
			return [ '_continuation' => true ];
		}
		$latest_job = $jobs->find( (int) $job['id'] );
		if ( ! $latest_job || $latest_job['cancel_requested'] ) {
			return $this->cancel( $run, $token, $jobs, $runs );
		}
		if ( $revisions->latest_id( (int) $workspace['id'] ) !== $base_id ) {
			$runs->release( (int) $run['id'], $token );
			return $this->conflict();
		}

		if ( 'analysis' === $run['phase'] ) {
			return $this->analyze( $job, $workspace, $base, $run, $token, $jobs, $runs );
		}
		if ( 'files' === $run['phase'] ) {
			return $this->generate_file( $job, $workspace, $base, $run, $token, $jobs, $runs );
		}
		if ( 'compliance' === $run['phase'] ) {
			return $this->verify_compliance( $job, $workspace, $base, $run, $token, $jobs, $runs );
		}
		if ( ! in_array( $run['phase'], [ 'analysis', 'files', 'compliance' ], true ) ) {
			$runs->release( (int) $run['id'], $token );
			return new \WP_Error( 'code_follow_up_phase', __( 'The Code follow-up has an invalid durable phase.', 'wp-autoplugin' ) );
		}
		return [ '_continuation' => true ];
	}

	private function analyze( array $job, array $workspace, array $base, array $run, string $token, Job_Repository $jobs, Code_Run_Repository $runs ) {
		$prompt = $this->analysis_prompt( $job, $workspace, $base, $run );
		if ( is_wp_error( $prompt ) ) {
			$runs->release( (int) $run['id'], $token );
			return $prompt;
		}
		$transport = ( new Direct_Transport_Factory() )->create_for( $run['provider'], $run['model'], $run['effort'] );
		if ( is_wp_error( $transport ) ) {
			$runs->release( (int) $run['id'], $token );
			return $transport;
		}
		$jobs->event(
			(int) $job['id'],
			'code_follow_up_analysis_started',
			__( 'Analyzing whether the message is a question or a Code change.', 'wp-autoplugin' ),
			[
				'phase'       => 'analysis',
				'revision_id' => (int) $base['id'],
			]
		);
		$response = $transport->complete(
			Global_Instructions::apply( $prompt['instructions'], $jobs->global_instructions( (int) $job['id'] ) ),
			$prompt['input'],
			[
				'max_output_tokens' => 8192,
				'json'              => true,
				'prompt_images'     => ( new Prompt_Attachment_Repository() )->for_job( (int) $job['id'], true ),
			]
		);

		$latest = $jobs->find( (int) $job['id'] );
		if ( ! $latest || $latest['cancel_requested'] ) {
			if ( ! is_wp_error( $response ) ) {
				$cancel_usage = (array) ( $response['usage'] ?? [] );
				( new Usage_Repository() )->record( (int) $job['id'], $transport->provider(), $transport->model(), 'code', $cancel_usage );
				$runs->account_usage( (int) $run['id'], $token, $cancel_usage );
			}
			return $this->cancel( $run, $token, $jobs, $runs );
		}
		if ( ( new Revision_Repository() )->latest_id( (int) $job['project_id'] ) !== (int) $base['id'] ) {
			$runs->release( (int) $run['id'], $token );
			return $this->conflict();
		}
		if ( is_wp_error( $response ) ) {
			return $this->retry_analysis_or_fail( $response, $job, $run, $token, $jobs, $runs );
		}
		$usage = (array) ( $response['usage'] ?? [] );
		( new Usage_Repository() )->record( (int) $job['id'], $transport->provider(), $transport->model(), 'code', $usage );
		if ( 'final' !== ( $response['type'] ?? '' ) || ! is_string( $response['content'] ?? null ) ) {
			$runs->account_usage( (int) $run['id'], $token, $usage );
			return $this->retry_analysis_or_fail( new \WP_Error( 'code_follow_up_response', __( 'The provider did not return a complete Code follow-up analysis.', 'wp-autoplugin' ), [ 'retryable' => true ] ), $job, $run, $token, $jobs, $runs );
		}
		$parsed = ( new Code_Follow_Up_Response() )->parse( $response['content'], $base['project_manifest'] );
		if ( is_wp_error( $parsed ) ) {
			$runs->account_usage( (int) $run['id'], $token, $usage );
			return $this->retry_analysis_or_fail( $parsed, $job, $run, $token, $jobs, $runs );
		}

		if ( 'answer' === $parsed['outcome'] ) {
			if ( 'changes' === ( $base['project_manifest']['scope'] ?? '' ) ) {
				$consistent = $this->target_consistent( $workspace, $base['project_manifest'] );
				if ( is_wp_error( $consistent ) ) {
					$runs->account_usage( (int) $run['id'], $token, $usage );
					return $this->retry_analysis_or_fail( $consistent, $job, $run, $token, $jobs, $runs );
				}
			}
			if ( ! $runs->complete_answer( (int) $run['id'], $token, $parsed['content'], $usage ) ) {
				return new \WP_Error( 'code_follow_up_answer_save', __( 'Could not persist the Code answer.', 'wp-autoplugin' ) );
			}
			$jobs->event(
				(int) $job['id'],
				'code_follow_up_answered',
				__( 'Answered without changing the staged revision.', 'wp-autoplugin' ),
				[
					'phase'       => 'completed',
					'revision_id' => (int) $base['id'],
				]
			);
			$completed = $runs->find_by_job( (int) $job['id'] );
			return $completed ? $this->completed_result( $completed ) : new \WP_Error( 'code_follow_up_state', __( 'Could not reload the completed Code answer.', 'wp-autoplugin' ) );
		}
		if ( 'changes' === ( $parsed['manifest']['scope'] ?? '' ) ) {
			$prepared = $this->prepare_change_manifest( $workspace, $base, $parsed['manifest'] );
			if ( is_wp_error( $prepared ) ) {
				$runs->account_usage( (int) $run['id'], $token, $usage );
				return $this->retry_analysis_or_fail( $prepared, $job, $run, $token, $jobs, $runs );
			}
			$parsed['manifest'] = $prepared;
		}

		$change_metadata = array_merge(
			$parsed['change_set'],
			[
				'resolved_request'    => $parsed['resolved_request'],
				'acceptance_criteria' => $parsed['acceptance_criteria'],
				'compliance_attempts' => 0,
			]
		);
		$runs->complete_analysis_changes( (int) $run['id'], $token, $parsed['manifest'], $change_metadata, $parsed['content'], $parsed['files'], $usage );
		$jobs->update( (int) $job['id'], [ 'progress' => $parsed['files'] ? 15 : 90 ] );
		$jobs->event(
			(int) $job['id'],
			'code_follow_up_changes_planned',
			$parsed['files'] ? __( 'The change was validated and file generation is starting.', 'wp-autoplugin' ) : __( 'The change was validated and is ready to stage without file generation.', 'wp-autoplugin' ),
			array_merge(
				[
					'phase'       => 'files',
					'files_count' => count( $parsed['files'] ),
				],
				$parsed['change_set']
			)
		);
		$run = $runs->find_by_job( (int) $job['id'] );
		if ( ! $run ) {
			return new \WP_Error( 'code_follow_up_state', __( 'Could not reload Code follow-up state.', 'wp-autoplugin' ) );
		}
		( new Queue() )->dispatch( (int) $job['id'], (int) $run['generation'], true );
		return [ '_continuation' => true ];
	}

	private function generate_file( array $job, array $workspace, array $base, array $run, string $token, Job_Repository $jobs, Code_Run_Repository $runs ) {
		$files = $runs->files( (int) $run['id'] );
		$index = (int) $run['next_file_index'];
		if ( $index >= count( $files ) ) {
			$next_generation = (int) $run['generation'] + 1;
			if ( ! $runs->begin_compliance( (int) $run['id'], $token ) ) {
				return new \WP_Error( 'code_follow_up_compliance_state', __( 'Could not start the Code request-compliance check.', 'wp-autoplugin' ) );
			}
			$jobs->update( (int) $job['id'], [ 'progress' => 96 ] );
			$jobs->event( (int) $job['id'], 'code_follow_up_compliance_queued', __( 'Checking the generated Code against the latest request.', 'wp-autoplugin' ), [ 'phase' => 'compliance' ] );
			$run = $runs->find_by_job( (int) $job['id'] );
			( new Queue() )->dispatch( (int) $job['id'], (int) ( $run['generation'] ?? $next_generation ), true );
			return [ '_continuation' => true ];
		}

		$current  = $files[ $index ];
		$feedback = (array) ( $current['error_metadata']['issues'] ?? [] );
		$runs->mark_generating( (int) $run['id'], $index, $token, $feedback );
		$jobs->event(
			(int) $job['id'],
			'code_follow_up_file_started',
			sprintf( __( 'Generating %1$d of %2$d: %3$s', 'wp-autoplugin' ), $index + 1, count( $files ), $current['path'] ),
			[
				'phase'     => 'files',
				'path'      => $current['path'],
				'operation' => $current['operation'],
				'index'     => $index + 1,
				'total'     => count( $files ),
			]
		);

		$prompt = $this->file_prompt( $job, $workspace, $base, $run, $files, $current, $feedback );
		if ( is_wp_error( $prompt ) ) {
			$runs->release( (int) $run['id'], $token );
			return $prompt;
		}
		$transport = ( new Direct_Transport_Factory() )->create_for( $run['provider'], $run['model'], $run['effort'] );
		if ( is_wp_error( $transport ) ) {
			$runs->release( (int) $run['id'], $token );
			return $transport;
		}
		$response = $transport->complete(
			Global_Instructions::apply( $prompt['instructions'], $jobs->global_instructions( (int) $job['id'] ) ),
			$prompt['input'],
			[
				'max_output_tokens' => 16384,
				'json'              => true,
				'prompt_images'     => ( new Prompt_Attachment_Repository() )->for_job( (int) $job['id'], true ),
			]
		);

		$latest = $jobs->find( (int) $job['id'] );
		if ( ! $latest || $latest['cancel_requested'] ) {
			if ( ! is_wp_error( $response ) ) {
				$cancel_usage = (array) ( $response['usage'] ?? [] );
				( new Usage_Repository() )->record( (int) $job['id'], $transport->provider(), $transport->model(), 'code', $cancel_usage );
				$runs->account_usage( (int) $run['id'], $token, $cancel_usage );
			}
			return $this->cancel( $run, $token, $jobs, $runs );
		}
		if ( ( new Revision_Repository() )->latest_id( (int) $job['project_id'] ) !== (int) $base['id'] ) {
			$runs->release( (int) $run['id'], $token );
			return $this->conflict();
		}
		if ( is_wp_error( $response ) ) {
			return $this->retry_file_or_fail( $response, $job, $run, $current, $index, $token, $jobs, $runs );
		}
		$usage = (array) ( $response['usage'] ?? [] );
		( new Usage_Repository() )->record( (int) $job['id'], $transport->provider(), $transport->model(), 'code', $usage );
		if ( 'final' !== ( $response['type'] ?? '' ) || ! is_string( $response['content'] ?? null ) ) {
			$runs->account_usage( (int) $run['id'], $token, $usage );
			return $this->retry_file_or_fail( new \WP_Error( 'code_follow_up_file_response', __( 'The provider did not return a complete Code file.', 'wp-autoplugin' ), [ 'retryable' => true ] ), $job, $run, $current, $index, $token, $jobs, $runs );
		}
		$validator = new Code_Validator();
		$parsed    = 'changes' === ( $run['target_manifest']['scope'] ?? '' ) && 'update' === $current['operation']
			? $validator->update_response( $response['content'], $current, $run['target_manifest'], (string) $prompt['current_content'] )
			: $validator->response( $response['content'], $current, $run['target_manifest'] );
		if ( is_wp_error( $parsed ) ) {
			$runs->account_usage( (int) $run['id'], $token, $usage );
			return $this->retry_file_or_fail( $parsed, $job, $run, $current, $index, $token, $jobs, $runs );
		}
		if ( '' !== (string) $prompt['current_content'] && (string) $prompt['current_content'] === $parsed['content'] ) {
			$runs->account_usage( (int) $run['id'], $token, $usage );
			return $this->retry_file_or_fail( new \WP_Error( 'code_follow_up_identical', __( 'A file selected for modification was returned unchanged.', 'wp-autoplugin' ), [ 'retryable' => true ] ), $job, $run, $current, $index, $token, $jobs, $runs );
		}

		$runs->complete_file( (int) $run['id'], $index, $token, $parsed['content'], $usage );
		$completed = $index + 1;
		$jobs->update( (int) $job['id'], [ 'progress' => min( 95, 15 + (int) floor( 80 * $completed / count( $files ) ) ) ] );
		$jobs->event(
			(int) $job['id'],
			'code_follow_up_file_completed',
			sprintf( __( 'Completed %s.', 'wp-autoplugin' ), $current['path'] ),
			[
				'phase'     => 'files',
				'path'      => $current['path'],
				'operation' => $current['operation'],
				'completed' => $completed,
				'total'     => count( $files ),
			]
		);

		$next_generation = (int) $run['generation'] + 1;
		$run             = $runs->find_by_job( (int) $job['id'] );
		( new Queue() )->dispatch( (int) $job['id'], (int) ( $run['generation'] ?? $next_generation ), true );
		return [ '_continuation' => true ];
	}

	private function verify_compliance( array $job, array $workspace, array $base, array $run, string $token, Job_Repository $jobs, Code_Run_Repository $runs ) {
		$candidate = 'changes' === ( $run['target_manifest']['scope'] ?? '' )
			? $this->change_set_files( $workspace, $base, $run )
			: $this->project_files( $base, $run );
		if ( is_wp_error( $candidate ) ) {
			$runs->release( (int) $run['id'], $token );
			return $candidate;
		}
		$target = $this->target_metadata( $workspace, (array) $run['target_manifest'] );
		if ( is_wp_error( $target ) ) {
			$runs->release( (int) $run['id'], $token );
			return $target;
		}

		$metadata  = (array) $run['change_instructions'];
		$prompt    = new Code_Follow_Up_Compliance_Prompt();
		$run_files = $runs->files( (int) $run['id'] );
		$topology  = $this->topology_diff( (array) $base['project_manifest'], (array) $run['target_manifest'], $run_files );
		$source    = array_map(
			static fn( array $file ): array => [
				'path'      => (string) $file['path'],
				'type'      => (string) $file['type'],
				'operation' => (string) ( $file['change_type'] ?? 'add' ),
				'content'   => (string) $file['content'],
			],
			$candidate
		);
		$transport = ( new Direct_Transport_Factory() )->create_for( $run['provider'], $run['model'], $run['effort'] );
		if ( is_wp_error( $transport ) ) {
			$runs->release( (int) $run['id'], $token );
			return $transport;
		}

		$jobs->event( (int) $job['id'], 'code_follow_up_compliance_started', __( 'Verifying that the generated Code satisfies the latest administrator request.', 'wp-autoplugin' ), [ 'phase' => 'compliance' ] );
		$response = $transport->complete(
			Global_Instructions::apply( $prompt->instructions(), $jobs->global_instructions( (int) $job['id'] ) ),
			$prompt->input(
				(string) $job['payload']['message'],
				$this->history( (int) $job['project_id'], (int) $job['id'] ),
				(string) ( $metadata['resolved_request'] ?? '' ),
				(array) ( $metadata['acceptance_criteria'] ?? [] ),
				$target,
				(array) $base['project_manifest'],
				(array) $run['target_manifest'],
				$topology,
				$source
			),
			[
				'max_output_tokens' => 4096,
				'json'              => true,
				'prompt_images'     => ( new Prompt_Attachment_Repository() )->for_job( (int) $job['id'], true ),
			]
		);

		$latest = $jobs->find( (int) $job['id'] );
		if ( ! $latest || $latest['cancel_requested'] ) {
			if ( ! is_wp_error( $response ) ) {
				$cancel_usage = (array) ( $response['usage'] ?? [] );
				( new Usage_Repository() )->record( (int) $job['id'], $transport->provider(), $transport->model(), 'code', $cancel_usage );
				$runs->account_usage( (int) $run['id'], $token, $cancel_usage );
			}
			return $this->cancel( $run, $token, $jobs, $runs );
		}
		if ( ( new Revision_Repository() )->latest_id( (int) $job['project_id'] ) !== (int) $base['id'] ) {
			$runs->release( (int) $run['id'], $token );
			return $this->conflict();
		}
		if ( is_wp_error( $response ) ) {
			return $this->retry_compliance_check_or_fail( $response, $job, $run, $token, $jobs, $runs );
		}

		$usage = (array) ( $response['usage'] ?? [] );
		( new Usage_Repository() )->record( (int) $job['id'], $transport->provider(), $transport->model(), 'code', $usage );
		if ( 'final' !== ( $response['type'] ?? '' ) || ! is_string( $response['content'] ?? null ) ) {
			$runs->account_usage( (int) $run['id'], $token, $usage );
			return $this->retry_compliance_check_or_fail( new \WP_Error( 'code_follow_up_compliance_response', __( 'The provider did not return a complete Code compliance result.', 'wp-autoplugin' ), [ 'retryable' => true ] ), $job, $run, $token, $jobs, $runs );
		}
		$parsed = ( new Code_Follow_Up_Compliance_Response() )->parse( $response['content'], (array) ( $run['target_manifest']['files'] ?? [] ) );
		if ( is_wp_error( $parsed ) ) {
			$runs->account_usage( (int) $run['id'], $token, $usage );
			return $this->retry_compliance_check_or_fail( $parsed, $job, $run, $token, $jobs, $runs );
		}
		if ( 'pass' === $parsed['outcome'] ) {
			$runs->account_usage( (int) $run['id'], $token, $usage );
			$jobs->event( (int) $job['id'], 'code_follow_up_compliance_passed', __( 'The generated Code satisfies the latest request.', 'wp-autoplugin' ), [ 'phase' => 'compliance' ] );
			$run = $runs->find_by_job( (int) $job['id'] );
			return $run ? $this->stage( $job, $base, $run ) : new \WP_Error( 'code_follow_up_state', __( 'Could not reload the verified Code follow-up.', 'wp-autoplugin' ) );
		}

		$run_files  = $runs->files( (int) $run['id'] );
		$sequences  = array_column( $run_files, 'sequence', 'path' );
		$repairable = (int) ( $metadata['compliance_attempts'] ?? 0 ) < 1;
		$from       = null;
		foreach ( $parsed['issues'] as $issue ) {
			$path = (string) $issue['path'];
			if ( '' === $path || ! array_key_exists( $path, $sequences ) ) {
				$repairable = false;
				break;
			}
			$from = null === $from ? (int) $sequences[ $path ] : min( $from, (int) $sequences[ $path ] );
		}
		if ( $repairable && null !== $from ) {
			$metadata['compliance_attempts'] = (int) ( $metadata['compliance_attempts'] ?? 0 ) + 1;
			$next_generation                 = (int) $run['generation'] + 1;
			$runs->retry_compliance( (int) $run['id'], $token, $from, $metadata, $parsed['issues'], $usage, $parsed['content'] );
			$jobs->update(
				(int) $job['id'],
				[
					'status'   => 'retrying',
					'progress' => 15,
				]
			);
			$jobs->event(
				(int) $job['id'],
				'code_follow_up_compliance_retry',
				__( 'The generated Code missed the latest request. Regenerating the affected files once with corrective feedback.', 'wp-autoplugin' ),
				[
					'phase' => 'files',
					'paths' => array_values( array_unique( array_filter( array_column( $parsed['issues'], 'path' ) ) ) ),
				],
				'warning'
			);
			$run = $runs->find_by_job( (int) $job['id'] );
			( new Queue() )->dispatch( (int) $job['id'], (int) ( $run['generation'] ?? $next_generation ), true );
			return [ '_continuation' => true ];
		}

		$runs->account_usage( (int) $run['id'], $token, $usage );
		$runs->release( (int) $run['id'], $token );
		return new \WP_Error(
			'code_follow_up_request_mismatch',
			sprintf(
				/* translators: %s: bounded compliance mismatch. */
				__( 'The generated Code did not satisfy the latest request, so no revision was created: %s', 'wp-autoplugin' ),
				$parsed['content']
			)
		);
	}

	private function stage( array $job, array $base, array $run ) {
		$jobs   = new Job_Repository();
		$latest = $jobs->find( (int) $job['id'] );
		if ( ! $latest || $latest['cancel_requested'] ) {
			( new Code_Run_Repository() )->terminate_by_job( (int) $job['id'], 'cancelled' );
			$jobs->update(
				(int) $job['id'],
				[
					'status'      => 'cancelled',
					'finished_at' => current_time( 'mysql', true ),
				]
			);
			$jobs->event( (int) $job['id'], 'cancelled', __( 'Code follow-up cancelled before staging. No partial revision was created.', 'wp-autoplugin' ) );
			return [ '_continuation' => true ];
		}
		$workspace = ( new Project_Repository() )->find( (int) $job['project_id'] );
		if ( ! $workspace ) {
			return new \WP_Error( 'code_follow_up_workspace', __( 'The Code follow-up workspace is unavailable.', 'wp-autoplugin' ) );
		}
		$files = 'changes' === ( $run['target_manifest']['scope'] ?? '' )
			? $this->change_set_files( $workspace, $base, $run )
			: $this->project_files( $base, $run );
		if ( is_wp_error( $files ) ) {
			return $files;
		}
		$revision = ( new Revision_Repository() )->stage_code_follow_up(
			$run,
			$run['target_manifest'],
			$files,
			(int) $job['project_id'],
			(int) $job['created_by'],
			(int) $base['id'],
			(string) $run['change_summary'],
			'review_fix' === ( $job['task'] ?? '' ) ? 'review_fix' : 'ai'
		);
		if ( is_wp_error( $revision ) ) {
			return $revision;
		}
		if ( 'review_fix' === ( $job['task'] ?? '' ) ) {
			$addressed = ( new \WP_Autoplugin\V2\Infrastructure\Database\Review_Repository() )->address(
				(int) $job['project_id'],
				(array) ( $job['payload']['finding_ids'] ?? [] ),
				(int) $revision['id'],
				(int) $job['id'],
				(int) $job['created_by']
			);
			if ( ! $addressed ) {
				return new \WP_Error( 'review_fix_finding_conflict', __( 'The successor revision was staged, but the selected Review finding state changed unexpectedly.', 'wp-autoplugin' ) );
			}
		}
		$jobs->event(
			(int) $job['id'],
			'code_follow_up_staged',
			sprintf( __( 'Created staged revision %d.', 'wp-autoplugin' ), $revision['revision_number'] ),
			array_merge(
				[
					'phase'       => 'completed',
					'revision_id' => (int) $revision['id'],
				],
				(array) $run['change_instructions']
			)
		);
		$run['revision_id'] = (int) $revision['id'];
		$run['outcome']     = 'revision';
		return $this->completed_result( $run );
	}

	/** @return array{instructions:string,input:string}|\WP_Error */
	private function analysis_prompt( array $job, array $workspace, array $base, array $run ) {
		$history  = $this->history( (int) $job['project_id'], (int) $job['id'] );
		$message  = (string) $job['payload']['message'];
		$feedback = (int) $run['retry_count'] ? substr( (string) $run['last_error'], 0, 500 ) : '';
		$plan     = $this->plan_content( (int) $base['plan_id'] );
		$target   = $this->target_metadata( $workspace, (array) $base['project_manifest'] );
		if ( is_wp_error( $target ) ) {
			return $target;
		}
		if ( 'changes' === ( $base['project_manifest']['scope'] ?? '' ) ) {
			$context = $this->target_analysis_context( $job, $workspace, $base );
			if ( is_wp_error( $context ) ) {
				return $context;
			}
			$prompt = new Existing_Target_Code_Follow_Up_Prompt();
			return [
				'instructions' => $prompt->analysis_instructions(),
				'input'        => $prompt->analysis_input(
					(string) $workspace['request'],
					$plan,
					$target,
					$base['project_manifest'],
					$context['staged'],
					$context['tree'],
					$context['focused'],
					$history,
					$message,
					$feedback
				),
			];
		}

		$source = $this->source( $base['files'] );
		if ( is_wp_error( $source ) ) {
			return $source;
		}
		if ( 'hook_extension' === ( $workspace['operation'] ?? '' ) ) {
			$prompt = new Extension_Plugin_Code_Follow_Up_Prompt();
			return [
				'instructions' => $prompt->analysis_instructions(),
				'input'        => $prompt->analysis_input( (string) $workspace['request'], $plan, $target, $base['project_manifest'], $source, $history, $message, $feedback ),
			];
		}

		$prompt = new New_Plugin_Code_Follow_Up_Prompt();
		return [
			'instructions' => $prompt->analysis_instructions(),
			'input'        => $prompt->analysis_input( (string) $workspace['request'], $base['project_manifest'], $source, $history, $message, $feedback ),
		];
	}

	/** @return array{instructions:string,input:string,current_content:string}|\WP_Error */
	private function file_prompt( array $job, array $workspace, array $base, array $run, array $run_files, array $current, array $feedback ) {
		$target   = $this->target_metadata( $workspace, (array) $run['target_manifest'] );
		$history  = $this->history( (int) $job['project_id'], (int) $job['id'] );
		$metadata = (array) $run['change_instructions'];
		$message  = (string) $job['payload']['message'];
		if ( is_wp_error( $target ) ) {
			return $target;
		}
		if ( 'changes' === ( $run['target_manifest']['scope'] ?? '' ) ) {
			$context = $this->change_file_context( $workspace, $base, $run, $run_files );
			if ( is_wp_error( $context ) ) {
				return $context;
			}
			$prompt = new Existing_Target_Code_Follow_Up_Prompt();
			return [
				'instructions'    => $prompt->file_instructions( (string) $current['operation'] ),
				'input'           => $prompt->file_input(
					$message,
					$history,
					(string) ( $metadata['resolved_request'] ?? '' ),
					(array) ( $metadata['acceptance_criteria'] ?? [] ),
					$target,
					$run['target_manifest'],
					$context['effective'],
					$context['generated'],
					$current,
					$feedback
				),
				'current_content' => (string) ( $context['content'][ $current['path'] ] ?? '' ),
			];
		}

		$base_source = $this->source( $base['files'] );
		if ( is_wp_error( $base_source ) ) {
			return $base_source;
		}
		$effective = $this->effective_source( $base_source, $run_files, $run['target_manifest'] );
		if ( 'hook_extension' === ( $workspace['operation'] ?? '' ) ) {
			$prompt = new Extension_Plugin_Code_Follow_Up_Prompt();
			return [
				'instructions'    => $prompt->file_instructions(),
				'input'           => $prompt->file_input( $message, $history, (string) ( $metadata['resolved_request'] ?? '' ), (array) ( $metadata['acceptance_criteria'] ?? [] ), $target, $base_source, $run['target_manifest'], $effective, $current, $feedback ),
				'current_content' => $this->source_content( $base_source, (string) $current['path'] ),
			];
		}

		$prompt = new New_Plugin_Code_Follow_Up_Prompt();
		return [
			'instructions'    => $prompt->file_instructions(),
			'input'           => $prompt->file_input( $message, $history, (string) ( $metadata['resolved_request'] ?? '' ), (array) ( $metadata['acceptance_criteria'] ?? [] ), $base_source, $run['target_manifest'], $effective, $current, $feedback ),
			'current_content' => $this->source_content( $base_source, (string) $current['path'] ),
		];
	}

	/** @return array{staged:array<int,array<string,mixed>>,tree:array<string,mixed>,focused:?array}|\WP_Error */
	private function target_analysis_context( array $job, array $workspace, array $base ) {
		try {
			$tools = new Source_Tools( (array) $workspace['target_metadata'] );
		} catch ( \Throwable $error ) {
			return new \WP_Error( 'code_follow_up_target_unavailable', __( 'The installed target is unavailable for this Code follow-up.', 'wp-autoplugin' ), [ 'retryable' => false ] );
		}
		$tree     = $tools->code_follow_up_tree();
		$expected = (string) ( $base['project_manifest']['target_fingerprint'] ?? '' );
		if ( '' === $expected || $expected !== $tree['tree_fingerprint'] ) {
			return $this->target_changed();
		}

		$staged = [];
		$total  = 0;
		foreach ( $base['files'] as $file ) {
			$content  = 'delete' === $file['change_type'] ? (string) ( $file['base_content'] ?? '' ) : (string) $file['content'];
			$total   += strlen( $content );
			$staged[] = [
				'path'      => (string) $file['path'],
				'type'      => strtolower( (string) pathinfo( $file['path'], PATHINFO_EXTENSION ) ),
				'operation' => (string) $file['change_type'],
				'content'   => $content,
			];
		}
		if ( $total > Code_Validator::MAX_PROJECT_BYTES ) {
			return new \WP_Error( 'code_follow_up_source_large', __( 'The effective staged target changes exceed the Code follow-up context limit.', 'wp-autoplugin' ), [ 'retryable' => false ] );
		}

		$focused = null;
		$path    = (string) ( $job['payload']['focused_path'] ?? '' );
		if ( '' !== $path ) {
			foreach ( $staged as $file ) {
				if ( $path === $file['path'] ) {
					$focused = array_merge( $file, [ 'source' => 'staged_revision' ] );
					break;
				}
			}
			if ( ! $focused ) {
				$focused = $tools->revision_file( $path );
				if ( is_wp_error( $focused ) ) {
					return new \WP_Error( 'code_follow_up_focus_unavailable', __( 'The selected target file is no longer available for this Code follow-up.', 'wp-autoplugin' ), [ 'retryable' => false ] );
				}
				if ( (int) $focused['size'] > Code_Validator::MAX_FILE_BYTES || $total + (int) $focused['size'] > Code_Validator::MAX_PROJECT_BYTES ) {
					return new \WP_Error( 'code_follow_up_focus_large', __( 'The selected target file exceeds the Code follow-up context limit.', 'wp-autoplugin' ), [ 'retryable' => false ] );
				}
				$focused['source'] = 'installed_target';
				unset( $focused['content_hash'] );
			}
		}

		unset( $tree['tree_fingerprint'] );
		return [
			'staged'  => $staged,
			'tree'    => $tree,
			'focused' => $focused,
		];
	}

	/** @return array<string, mixed>|\WP_Error */
	private function prepare_change_manifest( array $workspace, array $base, array $manifest ) {
		try {
			$tools = new Source_Tools( (array) $workspace['target_metadata'] );
		} catch ( \Throwable $error ) {
			return new \WP_Error( 'code_follow_up_target_unavailable', __( 'The installed target is unavailable for this Code follow-up.', 'wp-autoplugin' ), [ 'retryable' => false ] );
		}
		if ( (string) ( $base['project_manifest']['target_fingerprint'] ?? '' ) !== $tools->tree_fingerprint() ) {
			return $this->target_changed();
		}
		$snapshot = $tools->code_snapshot( $manifest['files'] );
		if ( is_wp_error( $snapshot ) ) {
			return new \WP_Error(
				$snapshot->get_error_code(),
				$snapshot->get_error_message(),
				[
					'retryable' => true,
					'ambiguous' => false,
				]
			);
		}
		$manifest['target_fingerprint'] = $snapshot['target_fingerprint'];
		$manifest['base_hashes']        = $snapshot['base_hashes'];
		$manifest                       = ( new Code_Validator() )->manifest( $manifest );
		return is_wp_error( $manifest )
			? new \WP_Error(
				'code_follow_up_manifest',
				$manifest->get_error_message(),
				[
					'retryable' => true,
					'ambiguous' => false,
				]
			)
			: $manifest;
	}

	/** @return true|\WP_Error */
	private function target_consistent( array $workspace, array $manifest ) {
		$snapshot = $this->target_snapshot( $workspace, $manifest );
		return is_wp_error( $snapshot ) ? $snapshot : true;
	}

	/** @return array{effective:array<int,array<string,mixed>>,generated:array<int,array<string,mixed>>,content:array<string,string>}|\WP_Error */
	private function change_file_context( array $workspace, array $base, array $run, array $run_files ) {
		$snapshot = $this->target_snapshot( $workspace, $run['target_manifest'] );
		if ( is_wp_error( $snapshot ) ) {
			return $snapshot;
		}
		$content = array_column( $snapshot['source_files'], 'content', 'path' );
		foreach ( $base['files'] as $file ) {
			$content[ $file['path'] ] = 'delete' === $file['change_type'] ? (string) ( $file['base_content'] ?? '' ) : (string) $file['content'];
		}
		$generated = [];
		foreach ( $run_files as $file ) {
			if ( 'completed' === $file['status'] && null !== $file['content'] ) {
				$content[ $file['path'] ] = (string) $file['content'];
				$generated[]              = [
					'path'      => $file['path'],
					'operation' => $file['operation'],
					'content'   => (string) $file['content'],
				];
			}
		}
		$effective = [];
		foreach ( $run['target_manifest']['files'] as $file ) {
			if ( 'delete' !== $file['operation'] && array_key_exists( $file['path'], $content ) ) {
				$effective[] = [
					'path'      => $file['path'],
					'operation' => $file['operation'],
					'content'   => $content[ $file['path'] ],
				];
			}
		}
		return [
			'effective' => $effective,
			'generated' => $generated,
			'content'   => $content,
		];
	}

	/** @return array<int,array<string,mixed>> */
	private function project_files( array $base, array $run ): array {
		$generated = array_column( ( new Code_Run_Repository() )->files( (int) $run['id'] ), 'content', 'path' );
		$base_map  = array_column( $base['files'], 'content', 'path' );
		$files     = [];
		foreach ( $run['target_manifest']['files'] as $file ) {
			$content = array_key_exists( $file['path'], $generated ) && null !== $generated[ $file['path'] ]
				? (string) $generated[ $file['path'] ]
				: (string) ( $base_map[ $file['path'] ] ?? '' );
			$files[] = [
				'path'        => $file['path'],
				'type'        => $file['type'],
				'change_type' => 'add',
				'content'     => $content,
			];
		}
		return $files;
	}

	/**
	 * Build a source-free manifest topology diff for the independent compliance pass.
	 *
	 * @param array<string, mixed>             $parent_manifest
	 * @param array<string, mixed>             $candidate_manifest
	 * @param array<int, array<string, mixed>> $run_files
	 * @return array<string, mixed>
	 */
	private function topology_diff( array $parent_manifest, array $candidate_manifest, array $run_files ): array {
		$parent    = array_column( (array) ( $parent_manifest['files'] ?? [] ), null, 'path' );
		$candidate = array_column( (array) ( $candidate_manifest['files'] ?? [] ), null, 'path' );
		$changed   = [];
		foreach ( array_intersect( array_keys( $parent ), array_keys( $candidate ) ) as $path ) {
			if (
				(string) ( $parent[ $path ]['type'] ?? '' ) !== (string) ( $candidate[ $path ]['type'] ?? '' )
				|| (string) ( $parent[ $path ]['operation'] ?? 'add' ) !== (string) ( $candidate[ $path ]['operation'] ?? 'add' )
			) {
				$changed[] = $path;
			}
		}

		return [
			'manifest_added_paths'   => array_values( array_diff( array_keys( $candidate ), array_keys( $parent ) ) ),
			'manifest_removed_paths' => array_values( array_diff( array_keys( $parent ), array_keys( $candidate ) ) ),
			'action_changed_paths'   => $changed,
			'main_file_changed'      => (string) ( $parent_manifest['main_file'] ?? '' ) !== (string) ( $candidate_manifest['main_file'] ?? '' ),
			'generated_paths'        => array_values( array_unique( array_map( static fn( array $file ): string => (string) ( $file['path'] ?? '' ), $run_files ) ) ),
		];
	}

	/** @return array<int,array<string,mixed>>|\WP_Error */
	private function change_set_files( array $workspace, array $base, array $run ) {
		$snapshot = $this->target_snapshot( $workspace, $run['target_manifest'] );
		if ( is_wp_error( $snapshot ) ) {
			return $snapshot;
		}
		$target    = array_column( $snapshot['source_files'], 'content', 'path' );
		$generated = array_column( ( new Code_Run_Repository() )->files( (int) $run['id'] ), 'content', 'path' );
		$base_map  = array_column( $base['files'], null, 'path' );
		$files     = [];
		foreach ( $run['target_manifest']['files'] as $file ) {
			$operation = $file['operation'];
			$content   = '';
			if ( 'delete' !== $operation ) {
				if ( array_key_exists( $file['path'], $generated ) && null !== $generated[ $file['path'] ] ) {
					$content = (string) $generated[ $file['path'] ];
				} elseif ( isset( $base_map[ $file['path'] ] ) && 'delete' !== $base_map[ $file['path'] ]['change_type'] ) {
					$content = (string) $base_map[ $file['path'] ]['content'];
				} else {
					return new \WP_Error( 'code_follow_up_file_missing', sprintf( __( 'The staged content for %s is unavailable.', 'wp-autoplugin' ), $file['path'] ) );
				}
			}
			$base_content = in_array( $operation, [ 'update', 'delete' ], true ) ? (string) ( $target[ $file['path'] ] ?? '' ) : null;
			$files[]      = [
				'path'              => $file['path'],
				'type'              => $file['type'],
				'change_type'       => $operation,
				'content'           => $content,
				'base_content'      => $base_content,
				'base_content_hash' => null === $base_content ? null : hash( 'sha256', $base_content ),
			];
		}
		return $files;
	}

	/** @return array<string,mixed>|\WP_Error */
	private function target_snapshot( array $workspace, array $manifest ) {
		try {
			$snapshot = ( new Source_Tools( (array) $workspace['target_metadata'] ) )->code_snapshot( $manifest['files'] );
		} catch ( \Throwable $error ) {
			return new \WP_Error(
				'code_follow_up_target_unavailable',
				__( 'The installed target is unavailable for this Code follow-up.', 'wp-autoplugin' ),
				[
					'retryable' => false,
					'ambiguous' => false,
				]
			);
		}
		if ( is_wp_error( $snapshot ) ) {
			return new \WP_Error(
				$snapshot->get_error_code(),
				$snapshot->get_error_message(),
				[
					'retryable' => false,
					'ambiguous' => false,
				]
			);
		}
		if ( (string) ( $manifest['target_fingerprint'] ?? '' ) !== (string) $snapshot['target_fingerprint'] || (array) ( $manifest['base_hashes'] ?? [] ) !== (array) $snapshot['base_hashes'] ) {
			return $this->target_changed();
		}
		return $snapshot;
	}

	/** @param array<int,array<string,string>> $source */
	private function source_content( array $source, string $path ): string {
		foreach ( $source as $file ) {
			if ( $path === (string) ( $file['path'] ?? '' ) ) {
				return (string) ( $file['content'] ?? '' );
			}
		}
		return '';
	}

	private function plan_content( int $plan_id ): string {
		$plan = ( new Plan_Repository() )->find( $plan_id );
		return (string) ( $plan['content'] ?? '' );
	}

	/** @return array<string,mixed>|\WP_Error */
	private function target_metadata( array $workspace, array $manifest ) {
		$target = array_intersect_key(
			(array) ( $workspace['target_metadata'] ?? [] ),
			array_flip( [ 'kind', 'ref', 'name', 'version', 'author', 'description', 'active', 'source_files', 'lines', 'tokens', 'hooks', 'stylesheet', 'template', 'is_child', 'is_block_theme', 'parent_ref', 'parent_available', 'parent_name', 'parent_version', 'parent_theme', 'active_as_stylesheet', 'active_as_template', 'in_use' ] )
		);
		if ( ! in_array( (string) ( $workspace['target_kind'] ?? '' ), [ 'plugin', 'theme' ], true ) ) {
			return $target;
		}
		try {
			$tools    = new Source_Tools( (array) $workspace['target_metadata'] );
			$expected = 'changes' === ( $manifest['scope'] ?? '' )
				? (string) ( $manifest['target_fingerprint'] ?? '' )
				: (string) ( $manifest['integration_target_fingerprint'] ?? '' );
			$current  = 'changes' === ( $manifest['scope'] ?? '' ) ? $tools->tree_fingerprint() : $tools->inspection_fingerprint();
			if ( '' !== $expected && ! hash_equals( $expected, $current ) ) {
				return $this->target_changed();
			}
			$instructions = 'plugin' === ( $workspace['target_kind'] ?? '' ) ? $tools->plugin_instructions() : null;
		} catch ( \Throwable $error ) {
			return new \WP_Error(
				'code_target_context_unavailable',
				$error->getMessage(),
				[
					'retryable' => false,
					'ambiguous' => false,
				]
			);
		}
		if ( $instructions ) {
			$target['root_plugin_instructions'] = [
				'path'    => $instructions['path'],
				'content' => $instructions['content'],
			];
		}
		return $target;
	}

	/** @return array{slug:string,version:int} */
	private function prompt_metadata( array $workspace, array $manifest ): array {
		if ( 'changes' === ( $manifest['scope'] ?? '' ) ) {
			return [
				'slug'    => Existing_Target_Code_Follow_Up_Prompt::SLUG,
				'version' => Existing_Target_Code_Follow_Up_Prompt::VERSION,
			];
		}
		if ( 'hook_extension' === ( $workspace['operation'] ?? '' ) ) {
			return [
				'slug'    => Extension_Plugin_Code_Follow_Up_Prompt::SLUG,
				'version' => Extension_Plugin_Code_Follow_Up_Prompt::VERSION,
			];
		}
		return [
			'slug'    => New_Plugin_Code_Follow_Up_Prompt::SLUG,
			'version' => New_Plugin_Code_Follow_Up_Prompt::VERSION,
		];
	}

	private function supports( array $workspace ): bool {
		$operation = (string) ( $workspace['operation'] ?? '' );
		$kind      = (string) ( $workspace['target_kind'] ?? '' );
		return ( 'create' === $operation && 'new_plugin' === $kind )
			|| ( 'hook_extension' === $operation && in_array( $kind, [ 'plugin', 'theme' ], true ) )
			|| ( in_array( $operation, [ 'modify', 'fix' ], true ) && in_array( $kind, [ 'plugin', 'theme' ], true ) );
	}

	private function target_changed(): \WP_Error {
		return new \WP_Error(
			'code_target_changed',
			__( 'The installed target changed after this revision was staged. Regenerate Code before sending another follow-up.', 'wp-autoplugin' ),
			[
				'retryable' => false,
				'ambiguous' => false,
			]
		);
	}

	private function completed_result( array $run ): array {
		$usage = [
			'input_tokens'  => (int) $run['input_tokens'],
			'output_tokens' => (int) $run['output_tokens'],
		];
		$base  = (int) $run['parent_revision_id'];
		$job   = ( new Job_Repository() )->find( (int) $run['job_id'] );
		if ( 'answer' === $run['outcome'] ) {
			$result = [
				'outcome'          => $job && 'review_fix' === $job['task'] ? 'blocked' : 'answer',
				'content'          => (string) $run['answer_content'],
				'base_revision_id' => $base,
				'provider'         => $run['provider'],
				'model'            => $run['model'],
				'effort'           => $run['effort'],
				'usage'            => $usage,
			];
			if ( $job && 'review_fix' === $job['task'] ) {
				$result['finding_ids']      = array_values( array_map( 'absint', (array) ( $job['payload']['finding_ids'] ?? [] ) ) );
				$result['review_report_id'] = (int) ( $job['payload']['review_report_id'] ?? 0 );
			}
			return $result;
		}
		$changes = (array) $run['change_instructions'];
		$result  = [
			'outcome'          => 'revision',
			'content'          => (string) $run['change_summary'],
			'base_revision_id' => $base,
			'revision_id'      => (int) $run['revision_id'],
			'added_paths'      => array_values( (array) ( $changes['added_paths'] ?? [] ) ),
			'updated_paths'    => array_values( (array) ( $changes['updated_paths'] ?? [] ) ),
			'deleted_paths'    => array_values( (array) ( $changes['deleted_paths'] ?? [] ) ),
			'provider'         => $run['provider'],
			'model'            => $run['model'],
			'effort'           => $run['effort'],
			'usage'            => $usage,
			'prompt'           => [
				'slug'    => $run['prompt_slug'],
				'version' => (int) $run['prompt_version'],
			],
		];
		if ( $job && 'review_fix' === $job['task'] ) {
			$result['finding_ids']      = array_values( array_map( 'absint', (array) ( $job['payload']['finding_ids'] ?? [] ) ) );
			$result['review_report_id'] = (int) ( $job['payload']['review_report_id'] ?? 0 );
			$result['auto_re_review']   = ! empty( $job['payload']['auto_re_review'] );
		}
		return $result;
	}

	private function retry_analysis_or_fail( \WP_Error $error, array $job, array $run, string $token, Job_Repository $jobs, Code_Run_Repository $runs ) {
		$data = (array) $error->get_error_data();
		if ( ! empty( $data['ambiguous'] ) ) {
			$runs->release( (int) $run['id'], $token );
			return $this->timeout_error();
		}
		$retryable = empty( $data['ambiguous'] ) && ( ! array_key_exists( 'retryable', $data ) || ! empty( $data['retryable'] ) );
		if ( $retryable && (int) $run['retry_count'] < 2 ) {
			$runs->retry_analysis( (int) $run['id'], $token, $error->get_error_message() );
			$jobs->update( (int) $job['id'], [ 'status' => 'retrying' ] );
			$jobs->event(
				(int) $job['id'],
				'code_follow_up_analysis_retry',
				__( 'Retrying the Code follow-up analysis with bounded feedback.', 'wp-autoplugin' ),
				[
					'phase'   => 'analysis',
					'attempt' => (int) $run['retry_count'] + 2,
				],
				'warning'
			);
			( new Queue() )->schedule( (int) $job['id'], (int) $run['generation'] + 1, 2 ** (int) $run['retry_count'] );
			return [ '_continuation' => true ];
		}
		$runs->release( (int) $run['id'], $token );
		return $error;
	}

	private function retry_compliance_check_or_fail( \WP_Error $error, array $job, array $run, string $token, Job_Repository $jobs, Code_Run_Repository $runs ) {
		$data = (array) $error->get_error_data();
		if ( ! empty( $data['ambiguous'] ) ) {
			$runs->release( (int) $run['id'], $token );
			return $this->timeout_error();
		}
		$retryable = ! array_key_exists( 'retryable', $data ) || ! empty( $data['retryable'] );
		if ( $retryable && (int) $run['retry_count'] < 2 ) {
			$runs->retry_analysis( (int) $run['id'], $token, $error->get_error_message() );
			$jobs->update( (int) $job['id'], [ 'status' => 'retrying' ] );
			$jobs->event(
				(int) $job['id'],
				'code_follow_up_compliance_response_retry',
				__( 'Retrying the Code request-compliance check with bounded format feedback.', 'wp-autoplugin' ),
				[
					'phase'   => 'compliance',
					'attempt' => (int) $run['retry_count'] + 2,
				],
				'warning'
			);
			( new Queue() )->schedule( (int) $job['id'], (int) $run['generation'] + 1, 2 ** (int) $run['retry_count'] );
			return [ '_continuation' => true ];
		}
		$runs->release( (int) $run['id'], $token );
		return $error;
	}

	private function retry_file_or_fail( \WP_Error $error, array $job, array $run, array $current, int $index, string $token, Job_Repository $jobs, Code_Run_Repository $runs ) {
		$data = (array) $error->get_error_data();
		if ( ! empty( $data['ambiguous'] ) ) {
			$runs->release( (int) $run['id'], $token );
			return $this->timeout_error();
		}
		$retryable = empty( $data['ambiguous'] ) && ( ! array_key_exists( 'retryable', $data ) || ! empty( $data['retryable'] ) );
		$issues    = array_slice( (array) ( $data['issues'] ?? [] ), 0, 5 );
		if ( $retryable && ! $issues ) {
			$issues[] = [
				'path'    => $current['path'],
				'line'    => 0,
				'code'    => sanitize_key( $error->get_error_code() ),
				'message' => substr( $error->get_error_message(), 0, 500 ),
			];
		}
		if ( $retryable && (int) $run['retry_count'] < 2 ) {
			$runs->retry_file( (int) $run['id'], $index, $token, $error->get_error_message(), $issues );
			$jobs->update( (int) $job['id'], [ 'status' => 'retrying' ] );
			$jobs->event(
				(int) $job['id'],
				'code_follow_up_file_retry',
				sprintf( __( 'Retrying %s.', 'wp-autoplugin' ), $current['path'] ),
				[
					'phase'     => 'files',
					'path'      => $current['path'],
					'operation' => $current['operation'],
					'attempt'   => (int) $run['retry_count'] + 2,
				],
				'warning'
			);
			( new Queue() )->schedule( (int) $job['id'], (int) $run['generation'] + 1, 2 ** (int) $run['retry_count'] );
			return [ '_continuation' => true ];
		}
		$runs->release( (int) $run['id'], $token );
		return $error;
	}

	private function cancel( array $run, string $token, Job_Repository $jobs, Code_Run_Repository $runs ): array {
		$runs->release( (int) $run['id'], $token );
		$runs->terminate_by_job( (int) $run['job_id'], 'cancelled' );
		$jobs->update(
			(int) $run['job_id'],
			[
				'status'      => 'cancelled',
				'finished_at' => current_time( 'mysql', true ),
			]
		);
		$jobs->event( (int) $run['job_id'], 'cancelled', __( 'Code follow-up cancelled. No partial revision was created.', 'wp-autoplugin' ) );
		return [ '_continuation' => true ];
	}

	/** @param array<int, array<string, mixed>> $files */
	private function source( array $files ) {
		$source = [];
		$total  = 0;
		foreach ( $files as $file ) {
			$content  = (string) ( $file['content'] ?? '' );
			$total   += strlen( $content );
			$source[] = [
				'path'    => (string) $file['path'],
				'content' => $content,
			];
		}
		if ( $total > Code_Validator::MAX_PROJECT_BYTES ) {
			return new \WP_Error( 'code_follow_up_source_large', __( 'The current staged project exceeds the Code follow-up context limit.', 'wp-autoplugin' ) );
		}
		return $source;
	}

	/** @param array<int, array<string, mixed>> $run_files @param array<string, mixed> $manifest */
	private function effective_source( array $base_source, array $run_files, array $manifest ): array {
		$content = array_column( $base_source, 'content', 'path' );
		foreach ( $run_files as $file ) {
			if ( 'completed' === $file['status'] ) {
				$content[ $file['path'] ] = (string) $file['content'];
			}
		}
		$effective = [];
		foreach ( $manifest['files'] as $file ) {
			if ( array_key_exists( $file['path'], $content ) ) {
				$effective[] = [
					'path'    => $file['path'],
					'content' => $content[ $file['path'] ],
				];
			}
		}
		return $effective;
	}

	/** Return recent Code conversation context without source bodies. */
	private function history( int $project_id, int $before_job_id ): array {
		$history = [];
		foreach ( array_reverse( ( new Job_Repository() )->list_for_workspace( $project_id ) ) as $previous ) {
			if ( (int) $previous['id'] >= $before_job_id || 'conversation' !== $previous['task'] || 'code' !== ( $previous['payload']['stage'] ?? '' ) ) {
				continue;
			}
			$history[] = [
				'revision_id'  => (int) ( $previous['payload']['revision_id'] ?? 0 ),
				'focused_path' => substr( (string) ( $previous['payload']['focused_path'] ?? '' ), 0, 1024 ),
				'message'      => substr( (string) ( $previous['payload']['message'] ?? '' ), 0, 4096 ),
				'status'       => $previous['status'],
				'outcome'      => $previous['result']['outcome'] ?? null,
				'content'      => substr( (string) ( $previous['result']['content'] ?? $previous['error_message'] ?? '' ), 0, 4096 ),
			];
			if ( 8 === count( $history ) ) {
				break;
			}
		}
		return array_reverse( $history );
	}

	private function conflict(): \WP_Error {
		return new \WP_Error( 'revision_conflict', __( 'A newer revision exists. Reload the latest revision before retrying.', 'wp-autoplugin' ), [ 'status' => 409 ] );
	}

	private function timeout_error(): \WP_Error {
		return new \WP_Error(
			'code_provider_timeout',
			__( 'The provider request timed out before WordPress received a response. It was not retried automatically because its completion and billing state are unknown. Send the Code message again manually.', 'wp-autoplugin' )
		);
	}
}
