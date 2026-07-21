<?php

namespace WP_Autoplugin\V2\Orchestration;

use WP_Autoplugin\Admin\Api_Handler;
use WP_Autoplugin\AI_Utils;
use WP_Autoplugin\Anthropic_API;
use WP_Autoplugin\OpenAI_API;
use WP_Autoplugin\Plugin_Explainer;
use WP_Autoplugin\Plugin_Extender;
use WP_Autoplugin\Plugin_Fixer;
use WP_Autoplugin\Plugin_Generator;
use WP_Autoplugin\V2\Domain\AI\Model_Effort;
use WP_Autoplugin\V2\Domain\AI\Plan_Response;
use WP_Autoplugin\V2\Domain\AI\Prompts\WordPress_Runtime_Constraints;
use WP_Autoplugin\V2\Domain\Target\Source_Reader;
use WP_Autoplugin\V2\Infrastructure\Database\Job_Repository;
use WP_Autoplugin\V2\Infrastructure\Database\Usage_Repository;
use WP_Autoplugin\V2\Infrastructure\Database\Workspace_Repository;

/**
 * Compatibility adapter that runs existing BYOK transports in durable jobs.
 *
 * It performs plan/explain and read-only conversation requests only. Generated
 * changes remain durable staged artifacts and never write target files.
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

		if ( ! in_array( $job['task'], [ 'plan', 'plan_structure', 'explain', 'conversation' ], true ) ) {
			return new \WP_Error( 'task_not_implemented', __( 'This task requires the v2 staged-revision generator, which is not registered.', 'wp-autoplugin' ) );
		}

		$stage   = 'conversation' === $job['task'] ? (string) ( $job['payload']['stage'] ?? '' ) : ( 'plan_structure' === $job['task'] ? 'plan' : $job['task'] );
		if ( ! in_array( $stage, [ 'plan', 'explain' ], true ) ) {
			return new \WP_Error( 'conversation_stage_unavailable', __( 'This workspace stage does not support follow-up messages yet.', 'wp-autoplugin' ) );
		}

		$handler       = new Api_Handler();
		$model_snapshot = (array) ( $job['payload']['prompt_model'] ?? [] );
		$model         = sanitize_text_field( (string) ( $model_snapshot['model'] ?? '' ) );
		if ( '' === $model ) {
			$model = 'explain' === $stage ? $handler->get_reviewer_model() : $handler->get_planner_model();
		}
		$api = $handler->get_api( $model );
		if ( ! $api ) {
			return new \WP_Error( 'provider_not_configured', __( 'Configure an API key and model for this task before starting the job.', 'wp-autoplugin' ) );
		}
		$effort = array_key_exists( 'effort', $model_snapshot )
			? Model_Effort::normalize( $model, (string) $model_snapshot['effort'] )
			: Model_Effort::for_role( 'explain' === $stage ? 'reviewer' : 'planner' );
		if ( '' !== $effort && $api instanceof OpenAI_API ) {
			$api->set_reasoning_effort( $effort );
		} elseif ( '' !== $effort && $api instanceof Anthropic_API ) {
			$api->set_effort( $effort );
		}

		$jobs = new Job_Repository();
		$jobs->update( (int) $job['id'], [ 'progress' => 25 ] );
		$jobs->event( (int) $job['id'], 'provider_request', __( 'Sending a redacted task request to the selected provider.', 'wp-autoplugin' ), [ 'model' => $model, 'effort' => $effort, 'task' => $job['task'] ] );

		$target  = (array) $workspace['target_metadata'];
		$files   = ( new Source_Reader() )->read( $target );
		$message = trim( (string) ( $job['payload']['message'] ?? '' ) );
		$request = 'explain' === $job['task'] && '' !== $message
			? $message
			: (string) $workspace['request'];
		$type    = 'theme' === ( $target['kind'] ?? '' ) ? 'theme' : 'plugin';
		$conversation = [];
		$regeneration = [];

		if ( 'plan_structure' === $job['task'] ) {
			$regeneration = $this->regenerate_plan_structure( $api, $workspace, $job, $files, $jobs );
			if ( is_wp_error( $regeneration ) ) {
				return $regeneration;
			}
			$content = $regeneration['content'];
		} elseif ( 'conversation' === $job['task'] ) {
			$conversation = $this->follow_up( $api, $workspace, $job, $files, $jobs );
			if ( is_wp_error( $conversation ) ) {
				return $conversation;
			}
			$content = $conversation['content'];
		} elseif ( 'explain' === $job['task'] ) {
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
		$content_was_json = is_array( $structured );
		if ( isset( $regeneration['structured'] ) && is_array( $regeneration['structured'] ) ) {
			$structured = $regeneration['structured'];
		}

		/*
		 * Legacy planners use JSON so their project structure remains
		 * deterministic. Persist that metadata separately, while making the
		 * visible v2 Plan artifact editable Markdown.
		 */
		if ( 'plan' === $stage && isset( $conversation['structured'] ) && is_array( $conversation['structured'] ) ) {
			$structured = $conversation['structured'];
		}
		if ( 'plan' === $stage && is_array( $structured ) && ( 'plan' === $job['task'] || ( 'artifact' === ( $conversation['outcome'] ?? '' ) && $content_was_json ) ) ) {
			$content = $this->plan_markdown( $structured );
			if ( 'artifact' === ( $conversation['outcome'] ?? '' ) ) {
				$conversation['content']              = $content;
				$conversation['artifact']['content'] = $content;
			}
		}

		$result = [
			'content'    => (string) $content,
			'structured' => is_array( $structured ) ? $structured : null,
			'model'      => $model,
			'provider'   => $provider,
			'effort'     => $effort,
			'usage'      => [
				'input_tokens'  => (int) ( $usage['input_tokens'] ?? 0 ),
				'output_tokens' => (int) ( $usage['output_tokens'] ?? 0 ),
			],
		];
		if ( 'plan_structure' === $job['task'] ) {
			$result['artifact'] = [
				'type'          => 'plan',
				'content'       => (string) $content,
				'parent_job_id' => (int) $regeneration['artifact_job_id'],
			];
		}

		return array_merge( $result, $conversation );
	}

	/**
	 * Regenerate a static file map from an administrator-edited Markdown Plan.
	 *
	 * @param array<string, mixed>  $workspace Workspace record.
	 * @param array<string, mixed>  $job       Structure-regeneration job.
	 * @param array<string, string> $files     Bounded target source.
	 * @return array<string, mixed>|\WP_Error
	 */
	private function regenerate_plan_structure( $api, array $workspace, array $job, array $files, Job_Repository $jobs ) {
		$artifact_id = (int) ( $job['payload']['artifact_job_id'] ?? 0 );
		$artifact    = $artifact_id ? $jobs->find( $artifact_id ) : null;
		if ( ! $artifact || (int) $workspace['id'] !== (int) $artifact['workspace_id'] || ! $jobs->is_plan_artifact( $artifact ) ) {
			return new \WP_Error( 'plan_structure_artifact_missing', __( 'A completed Plan artifact is required to regenerate its file structure.', 'wp-autoplugin' ) );
		}

		$plan                = $this->artifact_content( $artifact );
		$source_context      = empty( $files ) ? __( 'No target source is available for this workspace.', 'wp-autoplugin' ) : AI_Utils::build_code_context( $files );
		$is_extension        = 'hook_extension' === (string) $workspace['operation'];
		$runtime_constraints = WordPress_Runtime_Constraints::instructions();
		if ( $is_extension ) {
			$prior_structure = (string) wp_json_encode( (array) ( $artifact['result']['structured'] ?? [] ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
			$prompt = <<<PROMPT
You are regenerating structured metadata for an administrator-edited Plan for a separate WordPress extension plugin. This is read-only planning work: do not write code or claim to modify files. The extension must not modify, delete, replace, or copy files in the inspected target.

$runtime_constraints

Original workspace request:
"""
{$workspace['request']}
"""

Administrator-edited Plan in Markdown:
"""
$plan
"""

Prior validated extension metadata:
"""
$prior_structure
"""

Bounded read-only target source context:
"""
$source_context
"""

Return only a valid JSON object in this exact shape:
{"technically_feasible":true,"plugin_name":"Name of the new extension plugin","main_file":"extension-plugin.php","hooks":["verified_target_hook_or_wordpress_core_hook"],"project_structure":{"directories":["relative/directory/"],"files":[{"path":"extension-plugin.php","type":"php","description":"brief purpose","action":"add"}]}}

If the edited Plan is feasible without changing the target, use technically_feasible true, list every target or WordPress core hook needed, include a root-level PHP main_file naming an exact file in the map, and include a minimal non-empty file map containing only add actions. The extension plugin root is implicit: paths must start directly at that root and must not include a plugin slug or wrapping root directory. If it is infeasible, use technically_feasible false, use an empty main_file, and return empty hooks, directories, and files. `type` must be `php`, `js`, or `css`. Do not include code or Markdown.
PROMPT;
		} else {
			$prompt = <<<PROMPT
You are preparing the file map for a WordPress development Plan. This is read-only planning work: do not write code or claim to modify files.

$runtime_constraints

Original workspace request:
"""
{$workspace['request']}
"""

Administrator-edited Plan in Markdown:
"""
$plan
"""

Bounded read-only target source context:
"""
$source_context
"""

Return only a valid JSON object in this exact shape:
{"project_structure":{"directories":["relative/directory/"],"files":[{"path":"relative/file.php","type":"php","description":"brief purpose","action":"update"}]}}

List only files that must be added, updated, or deleted to implement the Plan. `type` must be `php`, `js`, or `css`; `action` must be `add`, `update`, or `delete`. Keep the structure minimal. Paths must be relative to the target root. Do not include code or Markdown.
PROMPT;
		}
		$response       = $api->send_prompt( $prompt, '', [ 'response_format' => [ 'type' => 'json_object' ] ] );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$decoded = json_decode( AI_Utils::strip_code_fences( trim( (string) $response ), 'json' ), true );
		if ( ! is_array( $decoded ) || ! is_array( $decoded['project_structure'] ?? null ) || ! is_array( $decoded['project_structure']['files'] ?? null ) ) {
			return new \WP_Error( 'plan_structure_response_invalid', __( 'The provider returned an invalid project structure. The Plan was not changed.', 'wp-autoplugin' ) );
		}

		if ( $is_extension ) {
			$validation = ( new Plan_Response() )->parse(
				(string) wp_json_encode( [ 'outcome' => 'artifact', 'content' => $plan, 'structured' => $decoded ] ),
				false,
				0,
				'hook_extension'
			);
			if ( is_wp_error( $validation ) ) {
				return $validation;
			}
			$structured = $validation['structured'];
		} else {
			$structured                      = is_array( $artifact['result']['structured'] ?? null ) ? $artifact['result']['structured'] : [];
			$structured['project_structure'] = $decoded['project_structure'];
		}

		return [
			'content'         => $plan,
			'structured'      => $structured,
			'artifact_job_id' => $artifact_id,
		];
	}

	/**
	 * Convert a legacy JSON plan to a durable Markdown artifact.
	 *
	 * Project structure remains separately structured data. This preserves a
	 * static file overview for future code generation rather than mixing it
	 * into editable plan prose.
	 *
	 * @param array<string, mixed> $plan Legacy planner response.
	 */
	private function plan_markdown( array $plan ): string {
		$sections = [];
		$title    = isset( $plan['plugin_name'] ) && is_scalar( $plan['plugin_name'] )
			? trim( (string) $plan['plugin_name'] )
			: __( 'Implementation plan', 'wp-autoplugin' );

		$sections[] = '# ' . $title;
		foreach ( $plan as $key => $value ) {
			if ( 'plugin_name' === $key || 'project_structure' === $key || ! is_scalar( $value ) || '' === trim( (string) $value ) ) {
				continue;
			}

			$sections[] = '## ' . ucwords( str_replace( '_', ' ', (string) $key ) ) . "\n\n" . trim( (string) $value );
		}

		return implode( "\n\n", $sections );
	}

	/**
	 * Run one persisted, stage-aware follow-up turn.
	 *
	 * @param array<string, mixed>  $workspace Workspace record.
	 * @param array<string, mixed>  $job       Conversation job.
	 * @param array<string, string> $files     Bounded target files.
	 * @return array<string, mixed>|\WP_Error
	 */
	private function follow_up( $api, array $workspace, array $job, array $files, Job_Repository $jobs ) {
		$stage       = (string) $job['payload']['stage'];
		$message     = trim( (string) $job['payload']['message'] );
		$artifact_id = (int) ( $job['payload']['artifact_job_id'] ?? 0 );
		$artifact    = $artifact_id ? $jobs->find( $artifact_id ) : null;

		if ( 'plan' === $stage && ( ! $artifact || ! $jobs->is_plan_artifact( $artifact ) ) ) {
			return new \WP_Error( 'conversation_artifact_missing', __( 'A completed Plan artifact is required for this follow-up.', 'wp-autoplugin' ) );
		}
		if ( 'explain' === $stage && empty( $files ) ) {
			return new \WP_Error( 'empty_target', __( 'There is no source to explain for this target.', 'wp-autoplugin' ) );
		}

		$current_artifact    = $this->artifact_content( $artifact );
		$history             = $this->conversation_history( $jobs->list_for_workspace( (int) $workspace['id'] ), $stage, (int) $job['id'] );
		$source_context      = empty( $files ) ? __( 'No target source is available for this workspace.', 'wp-autoplugin' ) : AI_Utils::build_code_context( $files );
		$artifact_label      = 'plan' === $stage ? __( 'current Plan artifact', 'wp-autoplugin' ) : __( 'current Explain context', 'wp-autoplugin' );
		$runtime_constraints = 'plan' === $stage ? "\n\n" . WordPress_Runtime_Constraints::instructions() : '';
		$prompt              = <<<PROMPT
You are continuing a WordPress development workspace at the $stage stage.
$runtime_constraints

Original workspace request:
"""
{$workspace['request']}
"""

The $artifact_label is:
"""
$current_artifact
"""

Recent conversation messages (oldest first):
"""
$history
"""

Bounded read-only target source context:
"""
$source_context
"""

The administrator's new message is:
"""
$message
"""

Respond with only a valid JSON object. Use exactly one of these forms:
{"outcome":"answer","content":"A concise Markdown answer that does not alter any artifact."}
{"outcome":"artifact","content":"The complete replacement Plan artifact in Markdown or the existing Plan's JSON format."}

Choose "answer" for questions, requests for explanation, or ambiguity. Choose "artifact" only when the administrator clearly asks to change the Plan or its implementation requirements. Never claim to write, install, activate, execute, promote, or modify target files.
PROMPT;

		$response = $api->send_prompt( $prompt );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$decoded = json_decode( AI_Utils::strip_code_fences( trim( (string) $response ), 'json' ), true );
		if ( ! is_array( $decoded ) || ! in_array( $decoded['outcome'] ?? '', [ 'answer', 'artifact' ], true ) || ! is_string( $decoded['content'] ?? null ) || '' === trim( $decoded['content'] ) ) {
			return new \WP_Error( 'conversation_response_invalid', __( 'The provider returned an invalid follow-up response. No artifact was changed.', 'wp-autoplugin' ) );
		}

		$outcome = (string) $decoded['outcome'];
		if ( 'artifact' === $outcome && 'plan' !== $stage ) {
			return new \WP_Error( 'conversation_artifact_unavailable', __( 'This stage cannot create a replacement artifact yet.', 'wp-autoplugin' ) );
		}

		$result = [
			'content' => trim( $decoded['content'] ),
			'outcome' => $outcome,
		];
		if ( 'artifact' === $outcome ) {
			$artifact_structured = json_decode( AI_Utils::strip_code_fences( trim( (string) $decoded['content'] ), 'json' ), true );
			if ( ! is_array( $artifact_structured ) && is_array( $artifact['result']['structured'] ?? null ) ) {
				// Narrative Plan changes keep the existing static file map.
				$artifact_structured = $artifact['result']['structured'];
			}
			if ( is_array( $artifact_structured ) ) {
				$result['structured'] = $artifact_structured;
			}
			$result['artifact'] = [
				'type'          => 'plan',
				'content'       => trim( $decoded['content'] ),
				'parent_job_id' => $artifact_id,
			];
		}

		return $result;
	}

	/**
	 * @param array<string, mixed>|null $artifact Job used as the current artifact.
	 */
	private function artifact_content( ?array $artifact ): string {
		if ( ! $artifact || ! is_array( $artifact['result'] ?? null ) ) {
			return __( 'No prior artifact is available.', 'wp-autoplugin' );
		}

		return (string) ( $artifact['result']['artifact']['content'] ?? $artifact['result']['content'] ?? '' );
	}

	/**
	 * Return the eight most recent persisted user/assistant messages for a stage.
	 *
	 * @param array<int, array<string, mixed>> $jobs Workspace jobs.
	 */
	private function conversation_history( array $jobs, string $stage, int $current_job_id ): string {
		$messages = [];
		foreach ( $jobs as $history_job ) {
			if ( (int) $history_job['id'] === $current_job_id ) {
				continue;
			}

			$is_conversation = 'conversation' === $history_job['task'] && $stage === ( $history_job['payload']['stage'] ?? '' );
			$is_legacy_explain = 'explain' === $stage && 'explain' === $history_job['task'];
			if ( ! $is_conversation && ! $is_legacy_explain ) {
				continue;
			}

			$question = (string) ( $history_job['payload']['message'] ?? '' );
			if ( '' !== $question ) {
				$messages[] = 'Administrator: ' . $question;
			}
			if ( 'completed' === $history_job['status'] && ! empty( $history_job['result']['content'] ) ) {
				$messages[] = 'Assistant: ' . $history_job['result']['content'];
			}
		}

		$messages = array_slice( $messages, -8 );
		return empty( $messages ) ? __( 'No earlier messages.', 'wp-autoplugin' ) : implode( "\n\n", $messages );
	}
}
