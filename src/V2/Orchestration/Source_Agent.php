<?php

namespace WP_Autoplugin\V2\Orchestration;

use WP_Autoplugin\V2\Domain\AI\Agent_Task;
use WP_Autoplugin\V2\Domain\AI\Plan_Response;
use WP_Autoplugin\V2\Domain\AI\Prompts\WordPress_Runtime_Constraints;
use WP_Autoplugin\V2\Domain\Target\Source_Tools;
use WP_Autoplugin\V2\Infrastructure\AI\Agent_Transport_Factory;
use WP_Autoplugin\V2\Infrastructure\Database\Agent_Run_Repository;
use WP_Autoplugin\V2\Infrastructure\Database\Job_Repository;
use WP_Autoplugin\V2\Infrastructure\Database\Usage_Repository;
use WP_Autoplugin\V2\Infrastructure\Database\Workspace_Repository;
use WP_Autoplugin\V2\Infrastructure\Queue\Queue;

/** Executes one durable, read-only Plan or Explain agent turn per callback. */
final class Source_Agent {
	private const MAX_MODEL_TURNS = 8;
	private const MAX_TOOL_CALLS  = 20;
	private const MAX_TOOL_BATCH  = 10;
	private const MAX_SOURCE_BYTES = 500000;
	private const MAX_RETRIES = 2;

	public function register(): void {
		add_filter( 'wp_autoplugin_v2_execute_job', [ $this, 'execute' ], 5, 3 );
	}

	/**
	 * @param array<string, mixed>|null $result Previous adapter result.
	 * @param array<string, mixed>      $job    Durable job.
	 * @return array<string, mixed>|\WP_Error|null
	 */
	public function execute( $result, array $job, int $generation = 0 ) {
		$stage = Agent_Task::stage( $job );
		if ( null !== $result || null === $stage ) {
			return $result;
		}

		$workspace = ( new Workspace_Repository() )->find( (int) $job['workspace_id'] );
		if ( ! $workspace ) {
			return new \WP_Error( 'workspace_not_found', __( 'Workspace not found.', 'wp-autoplugin' ) );
		}
		if ( ! Agent_Task::uses_source_tools( $job, $workspace ) ) {
			return $result;
		}
		$runs      = new Agent_Run_Repository();
		$run       = $runs->find_by_job( (int) $job['id'] );
		$factory   = new Agent_Transport_Factory();
		$transport = $run ? $factory->create_for( (string) $run['provider'], (string) $run['model'], (string) $run['effort'] ) : $factory->create( $stage );
		if ( is_wp_error( $transport ) ) {
			return $transport;
		}

		try {
			$tools = new Source_Tools( (array) $workspace['target_metadata'] );
		} catch ( \Throwable $error ) {
			return new \WP_Error( 'agent_target_unavailable', $error->getMessage() );
		}
		$jobs = new Job_Repository();

		if ( ! $run ) {
			try {
				$bootstrap = $tools->bootstrap();
				if ( 'hook_extension' === (string) $workspace['operation'] ) {
					$hooks = $tools->execute( 'list_hooks', [ 'offset' => 0, 'limit' => 25 ] );
					if ( ! empty( $hooks['error'] ) ) {
						throw new \RuntimeException( __( 'The target hooks could not be inspected.', 'wp-autoplugin' ) );
					}
					$bootstrap['content'] .= "\n\nDiscovered target hook contexts (first bounded page):\n" . $hooks['content'];
					$bootstrap['inspected'] = array_merge( $bootstrap['inspected'], $hooks['inspected'] );
					$bootstrap['audit']['hooks'] = $hooks['audit'];
				}
				$run = $runs->create(
					(int) $job['id'],
					$transport->provider(),
					$transport->model(),
					$transport->effort(),
					[ [ 'role' => 'user', 'content' => $this->initial_message( $workspace, $job, $bootstrap['content'] ) ] ],
					$bootstrap['tree_fingerprint'],
					$bootstrap['inspected'],
					strlen( $bootstrap['content'] )
				);
				$jobs->event(
					(int) $job['id'],
					'agent_bootstrap',
					'hook_extension' === (string) $workspace['operation']
						? __( 'Provided target metadata, source structure, main entry file, and discovered hook contexts to the model.', 'wp-autoplugin' )
						: __( 'Provided target metadata, the source structure, and the main entry file to the model.', 'wp-autoplugin' ),
					(array) $bootstrap['audit']
				);
			} catch ( \Throwable $error ) {
				return new \WP_Error( 'agent_initialization_failed', $error->getMessage() );
			}
		}

		if ( (int) $run['generation'] !== $generation ) {
			return [ '_continuation' => true ];
		}
		$token = wp_generate_uuid4();
		if ( ! $runs->acquire( (int) $run['id'], $generation, $token ) ) {
			return [ '_continuation' => true ];
		}

		try {
			if ( self::MAX_MODEL_TURNS <= (int) $run['model_turns'] ) {
				throw new \RuntimeException( sprintf( /* translators: %s: workspace stage. */ __( '%s stopped after reaching its model-turn limit.', 'wp-autoplugin' ), $this->label( $stage ) ) );
			}
			if ( self::MAX_TOOL_CALLS <= (int) $run['tool_calls'] || self::MAX_SOURCE_BYTES <= (int) $run['source_bytes'] ) {
				throw new \RuntimeException( sprintf( /* translators: %s: workspace stage. */ __( '%s stopped after reaching its source-inspection limit.', 'wp-autoplugin' ), $this->label( $stage ) ) );
			}
			if ( $run['tree_fingerprint'] !== $tools->tree_fingerprint() || ! $tools->inspected_unchanged( (array) $run['inspected_files'] ) ) {
				throw new \RuntimeException( sprintf( /* translators: %s: workspace stage. */ __( 'The target changed during inspection. Start %s again to inspect a consistent version.', 'wp-autoplugin' ), $this->label( $stage ) ) );
			}

			$jobs->event( (int) $job['id'], 'agent_turn', sprintf( /* translators: 1: workspace stage, 2: agent model turn. */ __( 'Running %1$s agent turn %2$d.', 'wp-autoplugin' ), $this->label( $stage ), (int) $run['model_turns'] + 1 ), [ 'turn' => (int) $run['model_turns'] + 1, 'model' => $transport->model(), 'effort' => $transport->effort(), 'stage' => $stage ] );
			$response = $transport->send( $this->instructions( $run, $stage, 'conversation' === $job['task'], (string) $workspace['operation'] ), (array) $run['transcript'], $tools->definitions() );
			if ( is_wp_error( $response ) ) {
				return $this->provider_failure( $response, $job, $run, $token, $runs );
			}

			$usage = (array) ( $response['usage'] ?? [] );
			( new Usage_Repository() )->record( (int) $job['id'], $transport->provider(), $transport->model(), $stage, $usage );
			$model_turns   = (int) $run['model_turns'] + 1;
			$input_tokens  = (int) $run['input_tokens'] + (int) ( $usage['input_tokens'] ?? 0 );
			$output_tokens = (int) $run['output_tokens'] + (int) ( $usage['output_tokens'] ?? 0 );
			$runs->step( (int) $run['id'], 'model', [ 'type' => $response['type'], 'request_id' => (string) ( $response['request_id'] ?? '' ), 'usage' => $usage, 'response' => $response ] );

			if ( 'final' === ( $response['type'] ?? '' ) ) {
				if ( 'plan' === $stage ) {
					$task_result = ( new Plan_Response() )->parse(
						(string) $response['content'],
						'conversation' === $job['task'],
						(int) ( $job['payload']['artifact_job_id'] ?? 0 ),
						(string) $workspace['operation']
					);
					if ( is_wp_error( $task_result ) ) {
						throw new \RuntimeException( $this->with_debug_response( $task_result->get_error_message(), $response ) );
					}
				} else {
					$task_result = [ 'content' => (string) $response['content'], 'outcome' => 'answer' ];
				}
				$runs->checkpoint( (int) $run['id'], $token, [ 'model_turns' => $model_turns, 'input_tokens' => $input_tokens, 'output_tokens' => $output_tokens, 'last_error' => null ] );
				$run = $runs->find_by_job( (int) $job['id'] ) ?: $run;
				$runs->terminate_by_job( (int) $job['id'], 'completed' );
				return array_merge( $task_result, [
					'model'    => $transport->model(),
					'provider' => $transport->provider(),
					'effort'   => $transport->effort(),
					'usage'    => [ 'input_tokens' => $input_tokens, 'output_tokens' => $output_tokens ],
					'agent'    => [ 'model_turns' => $model_turns, 'tool_calls' => (int) $run['tool_calls'], 'source_bytes' => (int) $run['source_bytes'] ],
				] );
			}

			$calls = (array) ( $response['tool_calls'] ?? [] );
			if ( ! $calls ) {
				throw new \RuntimeException( __( 'The model returned a tool-call response without any tool calls.', 'wp-autoplugin' ) );
			}
			$requested = count( $calls );
			$remaining = self::MAX_TOOL_CALLS - (int) $run['tool_calls'];
			if ( $requested > self::MAX_TOOL_BATCH || $requested > $remaining ) {
				$names = array_values( array_filter( array_map( static fn( array $call ): string => sanitize_key( (string) ( $call['name'] ?? '' ) ), $calls ) ) );
				$message = $requested > self::MAX_TOOL_BATCH
					? sprintf(
						/* translators: 1: requested tool count, 2: per-turn tool limit. */
						__( 'The model requested %1$d source tools in one turn; the per-turn limit is %2$d.', 'wp-autoplugin' ),
						$requested,
						self::MAX_TOOL_BATCH
					)
					: sprintf(
						/* translators: 1: requested tool count, 2: remaining tool count, 3: workspace stage. */
						__( 'The model requested %1$d source tools, but only %2$d remain in this %3$s job.', 'wp-autoplugin' ),
						$requested,
						max( 0, $remaining ),
						$this->label( $stage )
					);
				$jobs->event(
					(int) $job['id'],
					'agent_tool_limit',
					$message,
					[ 'requested' => $requested, 'per_turn_limit' => self::MAX_TOOL_BATCH, 'remaining_job_calls' => max( 0, $remaining ), 'tools' => $names ],
					'warning'
				);
				throw new \RuntimeException( $this->with_debug_response( $message, $response ) );
			}
			$transcript   = (array) $run['transcript'];
			$transcript[] = [ 'role' => 'assistant', 'content' => (string) ( $response['text'] ?? '' ), 'tool_calls' => $calls ];
			$inspected    = (array) $run['inspected_files'];
			$source_bytes = (int) $run['source_bytes'];
			foreach ( $calls as $call ) {
				if ( empty( $call['id'] ) || empty( $call['name'] ) || ! is_array( $call['arguments'] ?? null ) ) {
					throw new \RuntimeException( __( 'The model returned an invalid source-tool request.', 'wp-autoplugin' ) );
				}
				$tool_result = $tools->execute( (string) $call['name'], $call['arguments'] );
				$source_bytes += (int) $tool_result['bytes'];
				if ( $source_bytes > self::MAX_SOURCE_BYTES ) {
					throw new \RuntimeException( sprintf( /* translators: %s: workspace stage. */ __( '%s stopped after reaching its source-inspection byte limit.', 'wp-autoplugin' ), $this->label( $stage ) ) );
				}
				$inspected = array_merge( $inspected, $tool_result['inspected'] );
				$transcript[] = [ 'role' => 'tool', 'call_id' => (string) $call['id'], 'name' => (string) $call['name'], 'content' => $tool_result['content'] ];
				$runs->step( (int) $run['id'], 'tool', [ 'arguments' => $call['arguments'], 'content' => $tool_result['content'], 'bytes' => $tool_result['bytes'], 'hashes' => $tool_result['inspected'] ], (string) $call['name'], (string) $tool_result['path'] );
				$tool_failed = ! empty( $tool_result['error'] );
				$jobs->event(
					(int) $job['id'],
					$tool_failed ? 'agent_tool_error' : 'agent_tool',
					$tool_failed ? __( 'A source-tool request was rejected; the agent can correct it on the next turn.', 'wp-autoplugin' ) : $this->tool_message( (string) $call['name'], (string) $tool_result['path'] ),
					array_merge(
						[ 'tool' => (string) $call['name'], 'call_id' => (string) $call['id'] ],
						(array) $tool_result['audit']
					),
					$tool_failed ? 'warning' : 'info'
				);
			}

			$next_generation = $generation + 1;
			$runs->checkpoint(
				(int) $run['id'],
				$token,
				[
					'generation'      => $next_generation,
					'model_turns'     => $model_turns,
					'tool_calls'      => (int) $run['tool_calls'] + count( $calls ),
					'source_bytes'    => $source_bytes,
					'input_tokens'    => $input_tokens,
					'output_tokens'   => $output_tokens,
					'transcript'      => $transcript,
					'inspected_files' => $inspected,
					'retry_count'     => 0,
					'last_error'      => null,
				]
			);
			( new Queue() )->dispatch( (int) $job['id'], $next_generation, true );
			$jobs->update( (int) $job['id'], [ 'progress' => min( 90, 10 + (int) floor( $model_turns / self::MAX_MODEL_TURNS * 80 ) ) ] );
			return [ '_continuation' => true ];
		} catch ( \Throwable $error ) {
			$runs->terminate_by_job( (int) $job['id'], 'failed' );
			return new \WP_Error( 'agent_execution_failed', $error->getMessage() );
		}
	}

	/** @param array<string, mixed> $run */
	private function instructions( array $run, string $stage, bool $follow_up, string $operation ): string {
		$remaining_calls = max( 0, self::MAX_TOOL_CALLS - (int) $run['tool_calls'] );
		$turn_limit      = min( self::MAX_TOOL_BATCH, $remaining_calls );
		$remaining_bytes = max( 0, self::MAX_SOURCE_BYTES - (int) $run['source_bytes'] );
		$budget = sprintf(
			'In this turn you may request at most %1$d tool calls, with %2$d calls and approximately %3$d source-result bytes remaining for the whole job. Never exceed the per-turn tool-call limit; split additional inspection across later turns.',
			$turn_limit,
			$remaining_calls,
			$remaining_bytes
		);
		if ( 'explain' === $stage ) {
			return 'You are a read-only WordPress source-code explanation agent. Inspect only what is needed to answer confidently. Use the provided tools to examine relevant files; never claim to write, execute, install, activate, or modify code. Prefer searches and targeted line-range reads. Cite relative file paths and line numbers when they support the answer. When sufficient evidence is available, return a clear Markdown answer instead of requesting more tools. ' . $budget;
		}

		$runtime_constraints = WordPress_Runtime_Constraints::instructions();
		$outcomes            = $follow_up
			? 'For a question or ambiguity, return {"outcome":"answer","content":"concise Markdown answer"} and omit structured. Only when the administrator clearly requests a Plan change, use outcome "artifact" with the complete replacement Plan and file map.'
			: 'The initial Plan must use outcome "artifact".';
		if ( 'hook_extension' === $operation ) {
			return 'You are a read-only WordPress extension-plugin planning agent. Plan a whole new plugin that extends the inspected target; never propose editing, deleting, replacing, or copying files inside the target plugin or theme. ' . $runtime_constraints . ' The initial context includes the first bounded page of statically discovered target hooks with file, line, and surrounding source context. Use list_hooks to inspect additional pages when available, and use targeted searches and line-range reads to understand the relevant hook call sites, arguments, return values, timing, and surrounding control flow. You may also use established WordPress core hooks and public APIs where appropriate, but clearly distinguish them from hooks verified in the target source and never invent a target hook. Determine whether the administrator\'s request is technically feasible using the discovered target hooks and/or WordPress core hooks without changing the target. Cite relevant target paths and line numbers in the Markdown Plan. If feasible, explain the new extension plugin architecture, name every hook it will use and how callbacks interact with the observed context, and return only add actions for paths relative to the new extension plugin root. The extension plugin root is implicit: never prepend a plugin slug or wrapping root directory to project_structure paths. If infeasible, clearly explain the technical blocker and which required interception point is absent, and return an empty project structure. Never write code, change files, execute code, install, activate, or claim implementation occurred. When inspection is sufficient, return only one valid JSON object with no Markdown fence in this shape: {"outcome":"artifact","content":"complete feasible Plan or infeasibility explanation in Markdown","structured":{"technically_feasible":true,"plugin_name":"Name of the new extension plugin","main_file":"extension-plugin.php","hooks":["verified_target_hook_or_wordpress_core_hook"],"project_structure":{"directories":["relative/directory/"],"files":[{"path":"extension-plugin.php","type":"php","description":"brief purpose","action":"add"}]}}}. For an infeasible result, technically_feasible must be false, plugin_name and main_file may be empty, hooks may be empty, and directories and files must both be empty. A feasible result requires a non-empty plugin_name, a root-level PHP main_file naming an exact file in the map, at least one hook, and at least one file; every file action must be add. File type must be php, js, or css. Paths must start directly at the new extension plugin root and be unique. ' . $outcomes . ' ' . $budget;
		}
		return 'You are a read-only WordPress implementation planning agent. ' . $runtime_constraints . ' Inspect the existing plugin or theme until you have enough evidence to plan the requested fix or modification accurately. Prefer searches and targeted line-range reads. Cite relevant relative paths and line numbers in the Plan. Never write code, change files, execute code, install, activate, or claim that implementation has occurred. Keep the proposed change set minimal and consistent with the target architecture. When inspection is sufficient, return only one valid JSON object with no Markdown fence in this shape: {"outcome":"artifact","content":"complete Plan in Markdown","structured":{"project_structure":{"directories":["relative/directory/"],"files":[{"path":"relative/file.php","type":"php","description":"brief purpose","action":"update"}]}}}. File type must be php, js, or css. File action must be add, update, or delete. Paths must be relative to the target root and unique. ' . $outcomes . ' ' . $budget;
	}

	/** @param array<string, mixed> $response */
	private function with_debug_response( string $message, array $response ): string {
		$display = defined( 'WP_DEBUG' ) && WP_DEBUG && defined( 'WP_DEBUG_DISPLAY' ) && WP_DEBUG_DISPLAY;
		if ( ! $display ) {
			return $message;
		}
		$encoded = (string) wp_json_encode( $response, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
		if ( strlen( $encoded ) > 12000 ) {
			$encoded = substr( $encoded, 0, 12000 ) . "\n[Debug response truncated]";
		}
		return $message . "\n\nDebug model response:\n" . $encoded;
	}

	/** @param array<string, mixed> $workspace @param array<string, mixed> $job */
	private function initial_message( array $workspace, array $job, string $bootstrap ): string {
		$stage   = Agent_Task::stage( $job ) ?: 'explain';
		$message = 'conversation' === $job['task'] ? trim( (string) ( $job['payload']['message'] ?? '' ) ) : trim( (string) $workspace['request'] );
		$history = $this->history( (int) $workspace['id'], (int) $job['id'], $stage );
		if ( 'explain' === $stage ) {
			return "Question:\n{$message}\n\nRecent Explain conversation:\n{$history}\n\nThe initial target inspection follows. Do not assume unshown file contents.\n\n{$bootstrap}";
		}

		$artifact = '';
		if ( 'conversation' === $job['task'] ) {
			$artifact_id = (int) ( $job['payload']['artifact_job_id'] ?? 0 );
			$parent      = $artifact_id ? ( new Job_Repository() )->find( $artifact_id ) : null;
			if ( ! $parent || (int) $workspace['id'] !== (int) $parent['workspace_id'] || ! ( new Job_Repository() )->is_plan_artifact( $parent ) ) {
				throw new \RuntimeException( __( 'A completed Plan artifact is required for this follow-up.', 'wp-autoplugin' ) );
			}
			$artifact = (string) ( $parent['result']['artifact']['content'] ?? $parent['result']['content'] ?? '' );
		}

		$operation = (string) $workspace['operation'];
		$request   = trim( (string) $workspace['request'] );
		$current   = $artifact ? "\n\nCurrent Plan artifact:\n{$artifact}" : '';
		$follow_up = 'conversation' === $job['task'] ? "\n\nAdministrator's new message:\n{$message}" : '';
		return "Original workspace request:\n{$request}\n\nOperation:\n{$operation}{$current}\n\nRecent Plan conversation:\n{$history}{$follow_up}\n\nThe initial target inspection follows. Do not assume unshown file contents.\n\n{$bootstrap}";
	}

	private function history( int $workspace_id, int $current_job_id, string $stage ): string {
		$messages = [];
		foreach ( ( new Job_Repository() )->list_for_workspace( $workspace_id ) as $item ) {
			if ( (int) $item['id'] === $current_job_id || $stage !== Agent_Task::stage( $item ) || ( 'plan' === $stage && 'conversation' !== $item['task'] ) ) {
				continue;
			}
			if ( ! empty( $item['payload']['message'] ) ) {
				$messages[] = 'Administrator: ' . $item['payload']['message'];
			}
			$is_current_plan_artifact = 'plan' === $stage && 'artifact' === ( $item['result']['outcome'] ?? '' );
			if ( ! $is_current_plan_artifact && 'completed' === $item['status'] && ! empty( $item['result']['content'] ) ) {
				$messages[] = 'Assistant: ' . $item['result']['content'];
			}
		}
		return $messages ? implode( "\n\n", array_slice( $messages, -8 ) ) : 'No earlier messages.';
	}

	/** @param array<string, mixed> $job @param array<string, mixed> $run */
	private function provider_failure( \WP_Error $error, array $job, array $run, string $token, Agent_Run_Repository $runs ) {
		$data = (array) $error->get_error_data();
		if ( ! empty( $data['retryable'] ) && empty( $data['ambiguous'] ) && (int) $run['retry_count'] < self::MAX_RETRIES ) {
			$retry = (int) $run['retry_count'] + 1;
			$next_generation = (int) $run['generation'] + 1;
			$runs->checkpoint( (int) $run['id'], $token, [ 'generation' => $next_generation, 'retry_count' => $retry, 'last_error' => $error->get_error_message() ] );
			( new Job_Repository() )->update( (int) $job['id'], [ 'status' => 'retrying' ] );
			( new Job_Repository() )->event( (int) $job['id'], 'agent_retry', sprintf( /* translators: %d: retry number. */ __( 'Provider request will be retried (attempt %d).', 'wp-autoplugin' ), $retry ), [ 'retry' => $retry ], 'warning' );
			( new Queue() )->schedule( (int) $job['id'], $next_generation, min( 60, 5 * ( 2 ** ( $retry - 1 ) ) ) );
			return [ '_continuation' => true ];
		}
		$runs->terminate_by_job( (int) $job['id'], 'failed' );
		if ( ! empty( $data['ambiguous'] ) ) {
			return new \WP_Error( 'agent_provider_timeout', __( 'The provider request timed out with an unknown completion state. Retry the job manually to avoid automatic duplicate billing.', 'wp-autoplugin' ) );
		}
		return $error;
	}

	private function tool_message( string $tool, string $path ): string {
		if ( 'read_file' === $tool && $path ) {
			return sprintf( /* translators: %s: relative source file. */ __( 'Inspecting %s.', 'wp-autoplugin' ), $path );
		}
		return match ( $tool ) {
			'list_files' => __( 'Inspecting the target file structure.', 'wp-autoplugin' ),
			'search_code' => __( 'Searching the target source.', 'wp-autoplugin' ),
			'list_hooks' => __( 'Inspecting discovered target hooks and their source context.', 'wp-autoplugin' ),
			default => __( 'Inspecting target metadata.', 'wp-autoplugin' ),
		};
	}

	private function label( string $stage ): string {
		return 'plan' === $stage ? __( 'Plan', 'wp-autoplugin' ) : __( 'Explain', 'wp-autoplugin' );
	}
}
