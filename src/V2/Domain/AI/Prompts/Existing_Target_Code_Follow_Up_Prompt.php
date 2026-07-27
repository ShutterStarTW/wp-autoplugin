<?php

namespace WP_Autoplugin\V2\Domain\AI\Prompts;

use WP_Autoplugin\V2\Domain\Target\Plugin_Instructions;

/** Versioned questions and staged A/M/D follow-ups for an installed target. */
final class Existing_Target_Code_Follow_Up_Prompt {
	public const SLUG    = 'existing-target-code-follow-up';
	public const VERSION = 7;

	public function analysis_instructions(): string {
		$runtime_constraints = WordPress_Runtime_Constraints::instructions();
		$plugin_instructions = Plugin_Instructions::prompt_policy( true );
		$priority            = Code_Follow_Up_Priority::instructions();

		return <<<PROMPT
You are the coding agent for a staged, target-relative WordPress plugin or theme change set. Classify the administrator's newest message as either a question about the effective staged code or a concrete request to change the staged Add/Update/Delete set.

$priority

$plugin_instructions

$runtime_constraints

Return exactly one valid JSON object and no Markdown fences.

For a question return:
{"outcome":"answer","content":"A concise Markdown answer grounded only in the supplied target and staged context."}

For a change request return:
{"outcome":"changes","content":"A concise Markdown change summary.","resolved_request":"One concrete statement of the newest requested result, with contextual references resolved.","acceptance_criteria":["Observable requirement the generated revision must satisfy."],"manifest":{"files":[{"path":"target/relative.php","type":"php","description":"Purpose of this staged action.","operation":"add|update|delete"}]},"changes":[{"path":"target/relative.php","instruction":"Specific bounded implementation instruction that directly satisfies the resolved request."}]}

For a change, provide one resolved_request and one to eight concrete, testable acceptance_criteria derived from the newest message and its clear conversational antecedent. The manifest is the complete desired staged change set, not the complete installed target. Omitting a currently staged path unstages that action and does not delete the target file. Deleting a live target file requires an explicit operation:"delete" row. Add is only for a path absent from the target; update and delete are only for paths present in the target. Every new or changed add/update action needs one instruction. A retained add/update action may omit its instruction to copy its current staged content unchanged. Delete actions must not have generation instructions. Use only paths present in the supplied target tree or safe new relative paths, and only PHP, JavaScript, CSS, JSON, HTML, SVG, XML, Markdown, or plain text. If target metadata includes parent_theme, it is inherited read-only context and none of its files belongs to the editable target tree. Keep 1-20 staged actions. Never write to the target, change an unlisted path, delete the target plugin main file, propose a parent-theme path, or propose build artifacts, dependencies, binaries, or filesystem operations. A change request must materially alter staged content or actions.
PROMPT;
	}

	public function analysis_input( string $workspace_request, string $plan, array $target, array $manifest, array $staged, array $tree, ?array $focused, array $history, string $message, string $feedback = '' ): string {
		return wp_json_encode(
			[
				'json_contract'          => 'Return one JSON object with outcome answer or changes.',
				'original_workspace_request' => $workspace_request,
				'reference_plan'         => $plan,
				'target'                 => $target,
				'current_manifest'       => $manifest,
				'current_staged_changes' => $staged,
				'target_tree'            => $tree,
				'focused_file'           => $focused,
				'recent_code_conversation' => $history,
				'retry_feedback'         => $feedback,
				'authoritative_latest_request' => $message,
			],
			JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
		) ?: '{}';
	}

	public function file_instructions( string $operation ): string {
		$runtime_constraints = WordPress_Runtime_Constraints::instructions();
		$plugin_instructions = Plugin_Instructions::prompt_policy( true );
		$priority            = Code_Follow_Up_Priority::generation_instructions();
		if ( 'update' === $operation ) {
			return <<<PROMPT
Implement one bounded update to a staged WordPress target file. Return exactly one valid JSON object with exactly these keys: {"path":"the/exact/requested-path.ext","replacements":[{"search":"exact existing block","replace":"replacement block"}]}. Return 1-20 unique, non-overlapping exact replacements. Do not wrap the JSON response in a Markdown fence; Markdown replacement content may contain fenced examples encoded inside JSON strings. Each search must occur exactly once in the supplied effective content. Do not replace the whole file. Preserve all unrelated content. PHP must remain parseable without execution, JSON must remain syntactically valid, and SVG and XML must remain well-formed XML. Never modify any other path.

$priority

$plugin_instructions

$runtime_constraints
PROMPT;
		}

		return <<<PROMPT
Generate one complete new file for a staged WordPress target change. Return exactly one valid JSON object with exactly these keys: {"path":"the/exact/requested-path.ext","content":"complete file contents"}. Do not wrap the JSON response in a Markdown fence; Markdown file content may contain fenced examples encoded inside the JSON string. The path must match exactly. The output must be a complete non-empty PHP, JavaScript, CSS, JSON, HTML, SVG, XML, Markdown, or plain-text file under 64 KiB. PHP must parse without execution, JSON must be syntactically valid, SVG and XML must be well-formed XML, and HTML may be a complete document or fragment. A supporting PHP file in a plugin target must not contain a Plugin Name header. Never modify any other target path.

$priority

$plugin_instructions

$runtime_constraints
PROMPT;
	}

	public function file_input( string $message, array $history, string $resolved_request, array $acceptance_criteria, array $target, array $manifest, array $effective_source, array $generated, array $file, array $feedback ): string {
		return wp_json_encode(
			[
				'json_contract'                => 'Return exact replacements for update or exact path/content for add.',
				'recent_code_conversation'     => $history,
				'resolved_request'             => $resolved_request,
				'acceptance_criteria'          => $acceptance_criteria,
				'target'                       => $target,
				'desired_change_set'           => $manifest,
				'effective_source'             => $effective_source,
				'generated_changes'            => $generated,
				'current_file'                 => [
					'path'        => $file['path'],
					'type'        => $file['type'],
					'operation'   => $file['operation'],
					'instruction' => $file['description'],
				],
				'retry_feedback'               => array_slice( $feedback, 0, 5 ),
				'authoritative_latest_request' => $message,
			],
			JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
		) ?: '{}';
	}
}
