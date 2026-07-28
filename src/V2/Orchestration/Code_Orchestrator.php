<?php

namespace WP_Autoplugin\V2\Orchestration;

use WP_Autoplugin\V2\Domain\AI\Global_Instructions;
use WP_Autoplugin\V2\Domain\AI\Prompts\Existing_Target_Code_Prompt;
use WP_Autoplugin\V2\Domain\AI\Prompts\Extension_Plugin_Code_Prompt;
use WP_Autoplugin\V2\Domain\AI\Prompts\New_Plugin_Code_Prompt;
use WP_Autoplugin\V2\Domain\Revision\Code_Validator;
use WP_Autoplugin\V2\Domain\Target\Source_Tools;
use WP_Autoplugin\V2\Infrastructure\AI\Direct_Transport_Factory;
use WP_Autoplugin\V2\Infrastructure\Database\Code_Run_Repository;
use WP_Autoplugin\V2\Infrastructure\Database\Job_Repository;
use WP_Autoplugin\V2\Infrastructure\Database\Plan_Repository;
use WP_Autoplugin\V2\Infrastructure\Database\Revision_Repository;
use WP_Autoplugin\V2\Infrastructure\Database\Usage_Repository;
use WP_Autoplugin\V2\Infrastructure\Database\Project_Repository;
use WP_Autoplugin\V2\Infrastructure\Queue\Queue;
use WP_Autoplugin\V2\Release\Package_Builder;

/** Executes one bounded provider request per durable Code continuation. */
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
		$workspace = ( new Project_Repository() )->find( (int) $job['project_id'] );
		if ( ! $workspace || ! $this->supports( $workspace ) ) {
			return new \WP_Error( 'code_workspace_invalid', __( 'Code generation is not available for this workspace operation.', 'wp-autoplugin' ) );
		}

		$jobs      = new Job_Repository();
		$plans     = new Plan_Repository();
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
			$plan_id = (int) ( $job['payload']['plan_id'] ?? 0 );
			$plan    = $plan_id ? $plans->find( $plan_id ) : null;
			if ( ! $plan || (int) $plan['project_id'] !== (int) $workspace['id'] || ! $plans->is_ready( $plan ) ) {
				return new \WP_Error( 'code_plan_missing', __( 'A completed Plan artifact from this workspace is required.', 'wp-autoplugin' ) );
			}
			$manifest = $validator->plan( (array) ( $plan['structured'] ?? [] ), $workspace );
			if ( is_wp_error( $manifest ) ) {
				return $manifest;
			}
			if ( 'changes' === $manifest['scope'] ) {
				$snapshot = ( new Source_Tools( (array) $workspace['target_metadata'] ) )->code_snapshot( $manifest['files'] );
				if ( is_wp_error( $snapshot ) ) {
					return $snapshot;
				}
				$manifest['target_fingerprint'] = $snapshot['target_fingerprint'];
				$manifest['base_hashes']        = $snapshot['base_hashes'];
				if ( in_array( (string) ( $manifest['artifact_kind'] ?? '' ), [ 'plugin', 'theme' ], true ) ) {
					$complete = ( new Package_Builder() )->fingerprint_target(
						(string) $workspace['target_ref'],
						'theme' === (string) $manifest['artifact_kind'],
						(string) $manifest['artifact_kind']
					);
					if ( is_wp_error( $complete ) ) {
						return $complete;
					}
					$manifest['complete_target_fingerprint'] = $complete['fingerprint'];
				}
				$manifest = $validator->manifest( $manifest );
				if ( is_wp_error( $manifest ) ) {
					return $manifest;
				}
			}
			if ( 'hook_extension' === ( $workspace['operation'] ?? '' ) ) {
				try {
					$integration                                = new Source_Tools( (array) $workspace['target_metadata'] );
					$manifest['integration_target_kind']        = (string) $workspace['target_kind'];
					$manifest['integration_target_ref']         = (string) $workspace['target_ref'];
					$manifest['integration_target_fingerprint'] = $integration->inspection_fingerprint();
					$manifest                                   = $validator->manifest( $manifest );
				} catch ( \Throwable $error ) {
					return new \WP_Error( 'code_integration_target_unavailable', __( 'The extension integration target is unavailable.', 'wp-autoplugin' ) );
				}
				if ( is_wp_error( $manifest ) || empty( $manifest['integration_target_fingerprint'] ) ) {
					return is_wp_error( $manifest ) ? $manifest : new \WP_Error( 'code_integration_identity_invalid', __( 'The extension integration target identity could not be preserved.', 'wp-autoplugin' ) );
				}
			}
			$capability = (array) ( $job['payload']['prompt_model'] ?? [] );
			if ( empty( $capability['provider'] ) || empty( $capability['model'] ) ) {
				$capability = ( new Direct_Transport_Factory() )->capability( 'code' );
			} else {
				$capability['available'] = true;
				$capability['effort']    = (string) ( $capability['effort'] ?? '' );
			}
			if ( ! $capability['available'] ) {
				return new \WP_Error( 'code_transport_unavailable', $capability['message'] );
			}
			$parent = 'regenerate' === ( $job['payload']['mode'] ?? '' ) ? (int) ( $job['payload']['parent_revision_id'] ?? 0 ) : null;
			$prompt = $this->prompt_metadata( $workspace );
			$run    = $runs->create(
				(int) $job['id'],
				$plan_id,
				$parent ?: null,
				$capability['provider'],
				$capability['model'],
				$capability['effort'],
				$prompt['slug'],
				$prompt['version'],
				$manifest['files'],
				(string) ( $job['payload']['mode'] ?? 'generate' ),
				$manifest
			);
			$jobs->event(
				(int) $job['id'],
				'code_initialized',
				__( 'Code generation initialized from the approved Plan.', 'wp-autoplugin' ),
				[
					'files_count' => count( $manifest['files'] ),
					'provider'    => $run['provider'],
					'model'       => $run['model'],
					'effort'      => $run['effort'],
				]
			);
		} else {
			$plan     = $plans->find( (int) $run['plan_id'] );
			$manifest = is_array( $run['target_manifest'] ?? null ) ? $validator->manifest( $run['target_manifest'] ) : null;
			if ( ! $plan || is_wp_error( $manifest ) || ! is_array( $manifest ) ) {
				return is_wp_error( $manifest ) ? $manifest : new \WP_Error( 'code_plan_missing', __( 'The Code run Plan artifact or manifest is unavailable.', 'wp-autoplugin' ) );
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

		$source = $this->source_snapshot( $workspace, $manifest );
		if ( is_wp_error( $source ) ) {
			$runs->release( (int) $run['id'], $token );
			return $source;
		}
		$files = $runs->files( (int) $run['id'] );
		$index = $this->next_file_index( $files );
		if ( null === $index ) {
			$runs->release( (int) $run['id'], $token );
			return $this->stage( $job, $run, $manifest, $workspace );
		}

		$current  = $files[ $index ];
		$feedback = (array) ( $current['error_metadata']['issues'] ?? [] );
		$runs->mark_generating( (int) $run['id'], (int) $current['sequence'], $token, $feedback );
		$jobs->event(
			(int) $job['id'],
			'code_file_started',
			sprintf( __( 'Generating %1$d of %2$d: %3$s', 'wp-autoplugin' ), $index + 1, count( $files ), $current['path'] ),
			[
				'path'      => $current['path'],
				'operation' => $current['operation'],
				'index'     => $index + 1,
				'total'     => count( $files ),
			]
		);

		$transport = ( new Direct_Transport_Factory() )->create_for( $run['provider'], $run['model'], $run['effort'] );
		if ( is_wp_error( $transport ) ) {
			$runs->release( (int) $run['id'], $token );
			return $transport;
		}
		$generated = [];
		foreach ( $files as $file ) {
			if ( 'completed' === $file['status'] && 'delete' !== $file['operation'] && null !== $file['content'] ) {
				$generated[] = [
					'path'      => $file['path'],
					'operation' => $file['operation'],
					'content'   => (string) $file['content'],
				];
			}
		}
		$prompt = $this->prompt( $workspace, (string) $current['operation'] );
		$input  = $this->prompt_input( $prompt['instance'], $workspace, $plan, $manifest, $current, $source, $generated, $feedback );
		if ( is_wp_error( $input ) ) {
			$runs->release( (int) $run['id'], $token );
			return $input;
		}
		$response = $transport->complete(
			Global_Instructions::apply( $prompt['instructions'], $jobs->global_instructions( (int) $job['id'] ) ),
			$input,
			[
				'max_output_tokens' => 16384,
				'json'              => true,
			]
		);

		$latest = $jobs->find( (int) $job['id'] );
		if ( ! $latest || $latest['cancel_requested'] ) {
			return $this->cancel( $run, $token, $jobs, $runs );
		}
		if ( is_wp_error( $response ) ) {
			return $this->retry_or_fail( $response, $job, $run, $current, (int) $current['sequence'], $token, $runs, $jobs );
		}

		$usage = (array) ( $response['usage'] ?? [] );
		( new Usage_Repository() )->record( (int) $job['id'], $transport->provider(), $transport->model(), 'code', $usage );
		if ( 'final' !== ( $response['type'] ?? '' ) || ! is_string( $response['content'] ?? null ) ) {
			$runs->account_usage( (int) $run['id'], $token, $usage );
			$error = new \WP_Error(
				'code_response_invalid',
				__( 'The provider did not return a complete Code response.', 'wp-autoplugin' ),
				[
					'retryable' => true,
					'ambiguous' => false,
				]
			);
			return $this->retry_or_fail( $error, $job, $run, $current, (int) $current['sequence'], $token, $runs, $jobs );
		}
		$parsed = 'update' === $current['operation']
			? $validator->update_response( $response['content'], $current, $manifest, $this->source_content( $source, $current['path'] ) )
			: $validator->response( $response['content'], $current, $manifest );
		if ( is_wp_error( $parsed ) ) {
			$runs->account_usage( (int) $run['id'], $token, $usage );
			return $this->retry_or_fail( $parsed, $job, $run, $current, (int) $current['sequence'], $token, $runs, $jobs );
		}
		if ( 'update' === $current['operation'] && $this->source_content( $source, $current['path'] ) === $parsed['content'] ) {
			$runs->account_usage( (int) $run['id'], $token, $usage );
			$error = new \WP_Error(
				'code_update_unchanged',
				__( 'The updated file was byte-identical to the target source.', 'wp-autoplugin' ),
				[
					'retryable' => true,
					'issues'    => [
						[
							'path'    => $current['path'],
							'line'    => 0,
							'code'    => 'unchanged_update',
							'message' => __( 'Implement the approved change while preserving unrelated code.', 'wp-autoplugin' ),
						],
					],
				]
			);
			return $this->retry_or_fail( $error, $job, $run, $current, (int) $current['sequence'], $token, $runs, $jobs );
		}

		$runs->complete_file( (int) $run['id'], (int) $current['sequence'], $token, $parsed['content'], $usage );
		$completed = count( array_filter( $files, static fn( array $file ): bool => 'completed' === $file['status'] ) ) + 1;
		$jobs->update( (int) $job['id'], [ 'progress' => min( 95, 10 + (int) floor( 85 * $completed / count( $files ) ) ) ] );
		$jobs->event(
			(int) $job['id'],
			'code_file_completed',
			sprintf( __( 'Completed %s.', 'wp-autoplugin' ), $current['path'] ),
			[
				'path'      => $current['path'],
				'operation' => $current['operation'],
				'completed' => $completed,
				'total'     => count( $files ),
			]
		);

		$run = $runs->find_by_job( (int) $job['id'] );
		if ( $completed === count( $files ) && $run ) {
			return $this->stage( $job, $run, $manifest, $workspace );
		}
		( new Queue() )->dispatch( (int) $job['id'], (int) ( $run['generation'] ?? $generation + 1 ), true );
		return [ '_continuation' => true ];
	}

	private function stage( array $job, array $run, array $manifest, array $workspace ) {
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
			$jobs->event( (int) $job['id'], 'cancelled', __( 'Code generation cancelled before staging. No partial revision was created.', 'wp-autoplugin' ) );
			return [ '_continuation' => true ];
		}
		$expected = array_key_exists( 'expected_latest_revision_id', $job['payload'] ) && null !== $job['payload']['expected_latest_revision_id']
			? (int) $job['payload']['expected_latest_revision_id']
			: null;
		$source   = $this->source_snapshot( $workspace, $manifest );
		if ( is_wp_error( $source ) ) {
			return $source;
		}
		$revision = ( new Revision_Repository() )->stage_code_run( $run, $manifest, (int) $job['project_id'], (int) $job['created_by'], $expected, (array) ( $source['source_files'] ?? [] ) );
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
			'revision_id'          => (int) $run['revision_id'],
			'plan_id' => (int) $run['plan_id'],
			'parent_revision_id'   => $run['parent_revision_id'],
			'files_count'          => $files_count,
			'provider'             => $run['provider'],
			'model'                => $run['model'],
			'effort'               => $run['effort'],
			'usage'                => [
				'input_tokens'  => (int) $run['input_tokens'],
				'output_tokens' => (int) $run['output_tokens'],
			],
			'prompt'               => [
				'slug'    => $run['prompt_slug'],
				'version' => (int) $run['prompt_version'],
			],
		];
	}

	private function source_snapshot( array $workspace, array $manifest ) {
		if ( 'changes' !== ( $manifest['scope'] ?? '' ) ) {
			return [ 'source_files' => [] ];
		}
		$snapshot = ( new Source_Tools( (array) $workspace['target_metadata'] ) )->code_snapshot( $manifest['files'] );
		if ( is_wp_error( $snapshot ) ) {
			return $snapshot;
		}
		if ( (string) ( $manifest['target_fingerprint'] ?? '' ) !== (string) $snapshot['target_fingerprint'] || (array) ( $manifest['base_hashes'] ?? [] ) !== (array) $snapshot['base_hashes'] ) {
			return new \WP_Error( 'code_target_changed', __( 'The target changed after Code generation started. No revision was staged; regenerate the Plan before trying again.', 'wp-autoplugin' ) );
		}
		return $snapshot;
	}

	private function supports( array $workspace ): bool {
		$operation = (string) ( $workspace['operation'] ?? '' );
		$kind      = (string) ( $workspace['target_kind'] ?? '' );
		return ( 'create' === $operation && 'new_plugin' === $kind )
			|| ( 'hook_extension' === $operation && in_array( $kind, [ 'plugin', 'theme' ], true ) )
			|| ( in_array( $operation, [ 'modify', 'fix' ], true ) && in_array( $kind, [ 'plugin', 'theme' ], true ) );
	}

	/** @return array{slug:string,version:int} */
	private function prompt_metadata( array $workspace ): array {
		return match ( (string) $workspace['operation'] ) {
			'hook_extension' => [
				'slug'    => Extension_Plugin_Code_Prompt::SLUG,
				'version' => Extension_Plugin_Code_Prompt::VERSION,
			],
			'modify', 'fix'  => [
				'slug'    => Existing_Target_Code_Prompt::SLUG,
				'version' => Existing_Target_Code_Prompt::VERSION,
			],
			default          => [
				'slug'    => New_Plugin_Code_Prompt::SLUG,
				'version' => New_Plugin_Code_Prompt::VERSION,
			],
		};
	}

	/** @return array{instance:object,instructions:string} */
	private function prompt( array $workspace, string $operation = '' ): array {
		$instance = match ( (string) $workspace['operation'] ) {
			'hook_extension' => new Extension_Plugin_Code_Prompt(),
			'modify', 'fix'  => new Existing_Target_Code_Prompt(),
			default          => new New_Plugin_Code_Prompt(),
		};
		$instructions = $instance instanceof Existing_Target_Code_Prompt
			? $instance->instructions( $operation )
			: $instance->instructions();
		return [
			'instance'     => $instance,
			'instructions' => $instructions,
		];
	}

	/** @return string|\WP_Error */
	private function prompt_input( object $prompt, array $workspace, array $plan, array $manifest, array $current, array $source, array $generated, array $feedback ) {
		$request      = (string) $workspace['request'];
		$content      = $this->plan_content( $plan );
		$target       = array_intersect_key(
			(array) $workspace['target_metadata'],
			array_flip( [ 'kind', 'ref', 'name', 'version', 'author', 'description', 'active', 'source_files', 'lines', 'tokens', 'hooks', 'stylesheet', 'template', 'is_child', 'is_block_theme', 'parent_ref', 'parent_available', 'parent_name', 'parent_version', 'parent_theme', 'active_as_stylesheet', 'active_as_template', 'in_use' ] )
		);
		$instructions = $this->plugin_instructions( $workspace, $manifest );
		if ( is_wp_error( $instructions ) ) {
			return $instructions;
		}
		if ( $instructions ) {
			$target['root_plugin_instructions'] = [
				'path'    => $instructions['path'],
				'content' => $instructions['content'],
			];
		}
		$current = [
			'path'        => $current['path'],
			'type'        => $current['type'],
			'description' => $current['description'],
			'operation'   => $current['operation'],
		];
		if ( $prompt instanceof Existing_Target_Code_Prompt ) {
			return $prompt->input( $request, $content, $target, $manifest, $current, (array) ( $source['source_files'] ?? [] ), $generated, $feedback );
		}
		if ( $prompt instanceof Extension_Plugin_Code_Prompt ) {
			return $prompt->input( $request, $content, $target, $manifest, $current, $generated, $feedback );
		}
		return $prompt->input(
			$request,
			$content,
			[
				'main_file' => $manifest['main_file'],
				'files'     => $manifest['files'],
			],
			$current,
			$generated,
			$feedback
		);
	}

	/** @return array{path:string,content:string,bytes:int,content_hash:string}|null|\WP_Error */
	private function plugin_instructions( array $workspace, array $manifest ) {
		if ( ! in_array( (string) ( $workspace['target_kind'] ?? '' ), [ 'plugin', 'theme' ], true ) ) {
			return null;
		}
		try {
			$tools    = new Source_Tools( (array) $workspace['target_metadata'] );
			$expected = 'changes' === ( $manifest['scope'] ?? '' )
				? (string) ( $manifest['target_fingerprint'] ?? '' )
				: (string) ( $manifest['integration_target_fingerprint'] ?? '' );
			$current  = 'changes' === ( $manifest['scope'] ?? '' ) ? $tools->tree_fingerprint() : $tools->inspection_fingerprint();
			if ( '' !== $expected && ! hash_equals( $expected, $current ) ) {
				return new \WP_Error( 'code_target_changed', __( 'The installed target or its inspected parent theme changed after Code generation started. No revision was staged; regenerate the Plan before trying again.', 'wp-autoplugin' ) );
			}
			return 'plugin' === ( $workspace['target_kind'] ?? '' ) ? $tools->plugin_instructions() : null;
		} catch ( \Throwable $error ) {
			return new \WP_Error( 'code_target_context_unavailable', $error->getMessage() );
		}
	}

	private function next_file_index( array $files ): ?int {
		foreach ( $files as $index => $file ) {
			if ( 'completed' !== $file['status'] ) {
				return $index;
			}
		}
		return null;
	}

	private function source_content( array $snapshot, string $path ): string {
		foreach ( (array) ( $snapshot['source_files'] ?? [] ) as $file ) {
			if ( $path === (string) ( $file['path'] ?? '' ) ) {
				return (string) ( $file['content'] ?? '' );
			}
		}
		return '';
	}

	private function retry_or_fail( \WP_Error $error, array $job, array $run, array $current, int $index, string $token, Code_Run_Repository $runs, Job_Repository $jobs ) {
		$data      = (array) $error->get_error_data();
		$ambiguous = ! empty( $data['ambiguous'] );
		if ( $ambiguous ) {
			$runs->release( (int) $run['id'], $token );
			return new \WP_Error(
				'code_provider_timeout',
				__( 'The provider request timed out before WordPress received a response. It was not retried automatically because its completion and billing state are unknown. Start Code generation again manually.', 'wp-autoplugin' )
			);
		}
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
			$jobs->event(
				(int) $job['id'],
				'code_file_retry',
				sprintf( __( 'Retrying %s after a bounded validation or provider failure.', 'wp-autoplugin' ), $current['path'] ),
				[
					'path'      => $current['path'],
					'operation' => $current['operation'],
					'attempt'   => (int) $run['retry_count'] + 2,
				],
				'warning'
			);
			( new Queue() )->schedule( (int) $job['id'], $next_generation, 2 ** (int) $run['retry_count'] );
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
		$jobs->event( (int) $run['job_id'], 'cancelled', __( 'Code generation cancelled. No partial revision was created.', 'wp-autoplugin' ) );
		return [ '_continuation' => true ];
	}

	private function plan_content( array $plan ): string {
		return (string) ( $plan['content'] ?? '' );
	}
}
