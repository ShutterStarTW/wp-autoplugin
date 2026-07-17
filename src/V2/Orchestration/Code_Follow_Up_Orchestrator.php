<?php

namespace WP_Autoplugin\V2\Orchestration;

use WP_Autoplugin\V2\Domain\AI\Code_Follow_Up_Response;
use WP_Autoplugin\V2\Domain\AI\Prompts\New_Plugin_Code_Follow_Up_Prompt;
use WP_Autoplugin\V2\Domain\Revision\Code_Validator;
use WP_Autoplugin\V2\Infrastructure\AI\Direct_Transport_Factory;
use WP_Autoplugin\V2\Infrastructure\Database\Code_Run_Repository;
use WP_Autoplugin\V2\Infrastructure\Database\Job_Repository;
use WP_Autoplugin\V2\Infrastructure\Database\Revision_Repository;
use WP_Autoplugin\V2\Infrastructure\Database\Usage_Repository;
use WP_Autoplugin\V2\Infrastructure\Database\Workspace_Repository;
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
		if ( null !== $result || ! Job_Repository::is_code_work( $job ) || 'conversation' !== ( $job['task'] ?? '' ) ) {
			return $result;
		}
		$workspace = ( new Workspace_Repository() )->find( (int) $job['workspace_id'] );
		if ( ! $workspace || 'create' !== ( $workspace['operation'] ?? '' ) || 'new_plugin' !== ( $workspace['target_kind'] ?? '' ) ) {
			return new \WP_Error( 'code_follow_up_workspace', __( 'Code follow-ups are available only for new-plugin workspaces.', 'wp-autoplugin' ) );
		}

		$jobs      = new Job_Repository();
		$runs      = new Code_Run_Repository();
		$revisions = new Revision_Repository();
		$run       = $runs->find_by_job( (int) $job['id'] );
		$base_id   = (int) ( $job['payload']['revision_id'] ?? 0 );
		$base      = $revisions->find( $base_id );
		if ( ! $base || (int) $base['workspace_id'] !== (int) $workspace['id'] || $base_id !== (int) ( $job['payload']['expected_latest_revision_id'] ?? 0 ) ) {
			return new \WP_Error( 'code_follow_up_revision', __( 'The Code follow-up must be anchored to the current latest revision.', 'wp-autoplugin' ) );
		}
		if ( ! is_array( $base['project_manifest'] ?? null ) ) {
			return new \WP_Error( 'code_follow_up_manifest', __( 'The current revision does not have a usable project manifest.', 'wp-autoplugin' ) );
		}

		if ( ! $run ) {
			if ( $revisions->latest_id( (int) $workspace['id'] ) !== $base_id ) {
				return $this->conflict();
			}
			$capability = ( new Direct_Transport_Factory() )->capability( 'code' );
			if ( ! $capability['available'] ) {
				return new \WP_Error( 'code_follow_up_transport', $capability['message'] );
			}
			$run = $runs->create_follow_up(
				(int) $job['id'],
				(int) $base['plan_job_id'],
				$base_id,
				$capability['provider'],
				$capability['model'],
				$capability['effort'],
				New_Plugin_Code_Follow_Up_Prompt::SLUG,
				New_Plugin_Code_Follow_Up_Prompt::VERSION
			);
			$jobs->event(
				(int) $job['id'],
				'code_follow_up_initialized',
				__( 'Analyzing the Code follow-up against the latest staged revision.', 'wp-autoplugin' ),
				[ 'revision_id' => $base_id, 'phase' => 'analysis', 'provider' => $run['provider'], 'model' => $run['model'] ]
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
		if ( 'files' !== $run['phase'] ) {
			$runs->release( (int) $run['id'], $token );
			return new \WP_Error( 'code_follow_up_phase', __( 'The Code follow-up has an invalid durable phase.', 'wp-autoplugin' ) );
		}
		return $this->generate_file( $job, $workspace, $base, $run, $token, $jobs, $runs );
	}

	private function analyze( array $job, array $workspace, array $base, array $run, string $token, Job_Repository $jobs, Code_Run_Repository $runs ) {
		$source = $this->source( $base['files'] );
		if ( is_wp_error( $source ) ) {
			$runs->release( (int) $run['id'], $token );
			return $source;
		}
		$prompt    = new New_Plugin_Code_Follow_Up_Prompt();
		$transport = ( new Direct_Transport_Factory() )->create_for( $run['provider'], $run['model'], $run['effort'] );
		if ( is_wp_error( $transport ) ) {
			$runs->release( (int) $run['id'], $token );
			return $transport;
		}
		$jobs->event( (int) $job['id'], 'code_follow_up_analysis_started', __( 'Analyzing whether the message is a question or a Code change.', 'wp-autoplugin' ), [ 'phase' => 'analysis', 'revision_id' => (int) $base['id'] ] );
		$response = $transport->complete(
			$prompt->analysis_instructions(),
			$prompt->analysis_input(
				(string) $workspace['request'],
				$base['project_manifest'],
				$source,
				$this->history( (int) $job['workspace_id'], (int) $job['id'] ),
				(string) $job['payload']['message'],
				(int) $run['retry_count'] ? substr( (string) $run['last_error'], 0, 500 ) : ''
			),
			[ 'max_output_tokens' => 8192, 'json' => true ]
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
		if ( ( new Revision_Repository() )->latest_id( (int) $job['workspace_id'] ) !== (int) $base['id'] ) {
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
			if ( ! $runs->complete_answer( (int) $run['id'], $token, $parsed['content'], $usage ) ) {
				return new \WP_Error( 'code_follow_up_answer_save', __( 'Could not persist the Code answer.', 'wp-autoplugin' ) );
			}
			$jobs->event( (int) $job['id'], 'code_follow_up_answered', __( 'Answered without changing the staged revision.', 'wp-autoplugin' ), [ 'phase' => 'completed', 'revision_id' => (int) $base['id'] ] );
			$completed = $runs->find_by_job( (int) $job['id'] );
			return $completed ? $this->completed_result( $completed ) : new \WP_Error( 'code_follow_up_state', __( 'Could not reload the completed Code answer.', 'wp-autoplugin' ) );
		}

		$runs->complete_analysis_changes( (int) $run['id'], $token, $parsed['manifest'], $parsed['change_set'], $parsed['content'], $parsed['files'], $usage );
		$jobs->update( (int) $job['id'], [ 'progress' => $parsed['files'] ? 15 : 90 ] );
		$jobs->event(
			(int) $job['id'],
			'code_follow_up_changes_planned',
			$parsed['files'] ? __( 'The change was validated and file generation is starting.', 'wp-autoplugin' ) : __( 'The delete-only change was validated and is ready to stage.', 'wp-autoplugin' ),
			array_merge( [ 'phase' => 'files', 'files_count' => count( $parsed['files'] ) ], $parsed['change_set'] )
		);
		$run = $runs->find_by_job( (int) $job['id'] );
		if ( ! $run ) {
			return new \WP_Error( 'code_follow_up_state', __( 'Could not reload Code follow-up state.', 'wp-autoplugin' ) );
		}
		if ( ! $parsed['files'] ) {
			return $this->stage( $job, $base, $run );
		}
		( new Queue() )->dispatch( (int) $job['id'], (int) $run['generation'], true );
		return [ '_continuation' => true ];
	}

	private function generate_file( array $job, array $workspace, array $base, array $run, string $token, Job_Repository $jobs, Code_Run_Repository $runs ) {
		$files = $runs->files( (int) $run['id'] );
		$index = (int) $run['next_file_index'];
		if ( $index >= count( $files ) ) {
			$runs->release( (int) $run['id'], $token );
			return $this->stage( $job, $base, $run );
		}

		$current  = $files[ $index ];
		$feedback = (array) ( $current['error_metadata']['issues'] ?? [] );
		$runs->mark_generating( (int) $run['id'], $index, $token, $feedback );
		$jobs->event(
			(int) $job['id'],
			'code_follow_up_file_started',
			sprintf( __( 'Generating %1$d of %2$d: %3$s', 'wp-autoplugin' ), $index + 1, count( $files ), $current['path'] ),
			[ 'phase' => 'files', 'path' => $current['path'], 'operation' => $current['operation'], 'index' => $index + 1, 'total' => count( $files ) ]
		);

		$base_source = $this->source( $base['files'] );
		if ( is_wp_error( $base_source ) ) {
			$runs->release( (int) $run['id'], $token );
			return $base_source;
		}
		$effective = $this->effective_source( $base_source, $files, $run['target_manifest'] );
		$transport = ( new Direct_Transport_Factory() )->create_for( $run['provider'], $run['model'], $run['effort'] );
		if ( is_wp_error( $transport ) ) {
			$runs->release( (int) $run['id'], $token );
			return $transport;
		}
		$prompt   = new New_Plugin_Code_Follow_Up_Prompt();
		$response = $transport->complete(
			$prompt->file_instructions(),
			$prompt->file_input(
				(string) $workspace['request'],
				(string) $job['payload']['message'],
				$base_source,
				$run['target_manifest'],
				$effective,
				$current,
				$feedback
			),
			[ 'max_output_tokens' => 16384, 'json' => true ]
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
		if ( ( new Revision_Repository() )->latest_id( (int) $job['workspace_id'] ) !== (int) $base['id'] ) {
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
		$parsed = ( new Code_Validator() )->response( $response['content'], $current, (string) $run['target_manifest']['main_file'] );
		if ( is_wp_error( $parsed ) ) {
			$runs->account_usage( (int) $run['id'], $token, $usage );
			return $this->retry_file_or_fail( $parsed, $job, $run, $current, $index, $token, $jobs, $runs );
		}
		if ( 'update' === $current['operation'] && $this->base_content( $base['files'], $current['path'] ) === $parsed['content'] ) {
			$runs->account_usage( (int) $run['id'], $token, $usage );
			return $this->retry_file_or_fail( new \WP_Error( 'code_follow_up_identical', __( 'A file selected for modification was returned unchanged.', 'wp-autoplugin' ), [ 'retryable' => true ] ), $job, $run, $current, $index, $token, $jobs, $runs );
		}

		$runs->complete_file( (int) $run['id'], $index, $token, $parsed['content'], $usage );
		$completed = $index + 1;
		$jobs->update( (int) $job['id'], [ 'progress' => min( 95, 15 + (int) floor( 80 * $completed / count( $files ) ) ) ] );
		$jobs->event( (int) $job['id'], 'code_follow_up_file_completed', sprintf( __( 'Completed %s.', 'wp-autoplugin' ), $current['path'] ), [ 'phase' => 'files', 'path' => $current['path'], 'operation' => $current['operation'], 'completed' => $completed, 'total' => count( $files ) ] );

		$next_generation = (int) $run['generation'] + 1;
		$run = $runs->find_by_job( (int) $job['id'] );
		if ( $run && $completed === count( $files ) ) {
			return $this->stage( $job, $base, $run );
		}
		( new Queue() )->dispatch( (int) $job['id'], (int) ( $run['generation'] ?? $next_generation ), true );
		return [ '_continuation' => true ];
	}

	private function stage( array $job, array $base, array $run ) {
		$jobs   = new Job_Repository();
		$latest = $jobs->find( (int) $job['id'] );
		if ( ! $latest || $latest['cancel_requested'] ) {
			( new Code_Run_Repository() )->terminate_by_job( (int) $job['id'], 'cancelled' );
			$jobs->update( (int) $job['id'], [ 'status' => 'cancelled', 'finished_at' => current_time( 'mysql', true ) ] );
			$jobs->event( (int) $job['id'], 'cancelled', __( 'Code follow-up cancelled before staging. No partial revision was created.', 'wp-autoplugin' ) );
			return [ '_continuation' => true ];
		}
		$generated = array_column( ( new Code_Run_Repository() )->files( (int) $run['id'] ), 'content', 'path' );
		$base_map  = array_column( $base['files'], 'content', 'path' );
		$files     = [];
		foreach ( $run['target_manifest']['files'] as $file ) {
			$content = array_key_exists( $file['path'], $generated ) && null !== $generated[ $file['path'] ]
				? (string) $generated[ $file['path'] ]
				: (string) ( $base_map[ $file['path'] ] ?? '' );
			$files[] = [ 'path' => $file['path'], 'type' => $file['type'], 'change_type' => 'add', 'content' => $content ];
		}
		$revision = ( new Revision_Repository() )->stage_code_follow_up(
			$run,
			$run['target_manifest'],
			$files,
			(int) $job['workspace_id'],
			(int) $job['created_by'],
			(int) $base['id'],
			(string) $run['change_summary']
		);
		if ( is_wp_error( $revision ) ) {
			return $revision;
		}
		$jobs->event(
			(int) $job['id'],
			'code_follow_up_staged',
			sprintf( __( 'Created staged revision %d.', 'wp-autoplugin' ), $revision['revision_number'] ),
			array_merge( [ 'phase' => 'completed', 'revision_id' => (int) $revision['id'] ], (array) $run['change_instructions'] )
		);
		$run['revision_id'] = (int) $revision['id'];
		$run['outcome']     = 'revision';
		return $this->completed_result( $run );
	}

	private function completed_result( array $run ): array {
		$usage = [ 'input_tokens' => (int) $run['input_tokens'], 'output_tokens' => (int) $run['output_tokens'] ];
		$base  = (int) $run['parent_revision_id'];
		if ( 'answer' === $run['outcome'] ) {
			return [
				'outcome'          => 'answer',
				'content'          => (string) $run['answer_content'],
				'base_revision_id' => $base,
				'provider'         => $run['provider'],
				'model'            => $run['model'],
				'effort'           => $run['effort'],
				'usage'            => $usage,
			];
		}
		$changes = (array) $run['change_instructions'];
		return [
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
			'prompt'           => [ 'slug' => $run['prompt_slug'], 'version' => (int) $run['prompt_version'] ],
		];
	}

	private function retry_analysis_or_fail( \WP_Error $error, array $job, array $run, string $token, Job_Repository $jobs, Code_Run_Repository $runs ) {
		$data      = (array) $error->get_error_data();
		$retryable = empty( $data['ambiguous'] ) && ( ! array_key_exists( 'retryable', $data ) || ! empty( $data['retryable'] ) );
		if ( $retryable && (int) $run['retry_count'] < 2 ) {
			$runs->retry_analysis( (int) $run['id'], $token, $error->get_error_message() );
			$jobs->update( (int) $job['id'], [ 'status' => 'retrying' ] );
			$jobs->event( (int) $job['id'], 'code_follow_up_analysis_retry', __( 'Retrying the Code follow-up analysis with bounded feedback.', 'wp-autoplugin' ), [ 'phase' => 'analysis', 'attempt' => (int) $run['retry_count'] + 2 ], 'warning' );
			( new Queue() )->schedule( (int) $job['id'], (int) $run['generation'] + 1, 2 ** (int) $run['retry_count'] );
			return [ '_continuation' => true ];
		}
		$runs->release( (int) $run['id'], $token );
		return $error;
	}

	private function retry_file_or_fail( \WP_Error $error, array $job, array $run, array $current, int $index, string $token, Job_Repository $jobs, Code_Run_Repository $runs ) {
		$data      = (array) $error->get_error_data();
		$retryable = empty( $data['ambiguous'] ) && ( ! array_key_exists( 'retryable', $data ) || ! empty( $data['retryable'] ) );
		$issues    = array_slice( (array) ( $data['issues'] ?? [] ), 0, 5 );
		if ( $retryable && ! $issues ) {
			$issues[] = [ 'path' => $current['path'], 'line' => 0, 'code' => sanitize_key( $error->get_error_code() ), 'message' => substr( $error->get_error_message(), 0, 500 ) ];
		}
		if ( $retryable && (int) $run['retry_count'] < 2 ) {
			$runs->retry_file( (int) $run['id'], $index, $token, $error->get_error_message(), $issues );
			$jobs->update( (int) $job['id'], [ 'status' => 'retrying' ] );
			$jobs->event( (int) $job['id'], 'code_follow_up_file_retry', sprintf( __( 'Retrying %s.', 'wp-autoplugin' ), $current['path'] ), [ 'phase' => 'files', 'path' => $current['path'], 'operation' => $current['operation'], 'attempt' => (int) $run['retry_count'] + 2 ], 'warning' );
			( new Queue() )->schedule( (int) $job['id'], (int) $run['generation'] + 1, 2 ** (int) $run['retry_count'] );
			return [ '_continuation' => true ];
		}
		$runs->release( (int) $run['id'], $token );
		return $error;
	}

	private function cancel( array $run, string $token, Job_Repository $jobs, Code_Run_Repository $runs ): array {
		$runs->release( (int) $run['id'], $token );
		$runs->terminate_by_job( (int) $run['job_id'], 'cancelled' );
		$jobs->update( (int) $run['job_id'], [ 'status' => 'cancelled', 'finished_at' => current_time( 'mysql', true ) ] );
		$jobs->event( (int) $run['job_id'], 'cancelled', __( 'Code follow-up cancelled. No partial revision was created.', 'wp-autoplugin' ) );
		return [ '_continuation' => true ];
	}

	/** @param array<int, array<string, mixed>> $files */
	private function source( array $files ) {
		$source = [];
		$total  = 0;
		foreach ( $files as $file ) {
			$content = (string) ( $file['content'] ?? '' );
			$total  += strlen( $content );
			$source[] = [ 'path' => (string) $file['path'], 'content' => $content ];
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
				$effective[] = [ 'path' => $file['path'], 'content' => $content[ $file['path'] ] ];
			}
		}
		return $effective;
	}

	/** Return recent Code conversation context without source bodies. */
	private function history( int $workspace_id, int $before_job_id ): array {
		$history = [];
		foreach ( array_reverse( ( new Job_Repository() )->list_for_workspace( $workspace_id ) ) as $previous ) {
			if ( (int) $previous['id'] >= $before_job_id || 'conversation' !== $previous['task'] || 'code' !== ( $previous['payload']['stage'] ?? '' ) ) {
				continue;
			}
			$history[] = [
				'revision_id' => (int) ( $previous['payload']['revision_id'] ?? 0 ),
				'message'     => substr( (string) ( $previous['payload']['message'] ?? '' ), 0, 4096 ),
				'status'      => $previous['status'],
				'outcome'     => $previous['result']['outcome'] ?? null,
				'content'     => substr( (string) ( $previous['result']['content'] ?? $previous['error_message'] ?? '' ), 0, 4096 ),
			];
			if ( 8 === count( $history ) ) {
				break;
			}
		}
		return array_reverse( $history );
	}

	/** @param array<int, array<string, mixed>> $files */
	private function base_content( array $files, string $path ): string {
		foreach ( $files as $file ) {
			if ( $path === $file['path'] ) {
				return (string) $file['content'];
			}
		}
		return '';
	}

	private function conflict(): \WP_Error {
		return new \WP_Error( 'revision_conflict', __( 'A newer revision exists. Reload the latest revision before retrying.', 'wp-autoplugin' ), [ 'status' => 409 ] );
	}
}
