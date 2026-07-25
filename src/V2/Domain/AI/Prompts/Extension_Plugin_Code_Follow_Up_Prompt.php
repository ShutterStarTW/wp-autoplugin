<?php

namespace WP_Autoplugin\V2\Domain\AI\Prompts;

use WP_Autoplugin\V2\Domain\Target\Plugin_Instructions;

/** Versioned Code follow-ups for a staged extension plugin. */
final class Extension_Plugin_Code_Follow_Up_Prompt {
	public const SLUG    = 'extension-plugin-code-follow-up';
	public const VERSION = 3;

	public function analysis_instructions(): string {
		$runtime_constraints = WordPress_Runtime_Constraints::instructions();
		$plugin_instructions = Plugin_Instructions::prompt_policy();

		return <<<PROMPT
You are the coding agent for a staged WordPress extension plugin that integrates with an installed plugin or theme through approved hooks and public APIs. Classify the administrator's newest message as either a question about the staged extension code or a concrete request to change that extension plugin.

$plugin_instructions

$runtime_constraints

Return exactly one valid JSON object and no Markdown fences.

For a question return:
{"outcome":"answer","content":"A concise Markdown answer grounded only in the supplied Plan and staged extension project."}

For a change request return:
{"outcome":"changes","content":"A concise Markdown change summary.","manifest":{"plugin_name":"...","main_file":"root-file.php","files":[{"path":"relative/path.php","type":"php","description":"Purpose."}]},"changes":[{"path":"relative/path.php","instruction":"Specific bounded implementation instruction."}]}

The manifest is the complete desired extension plugin after the change. Omitting an existing extension path deletes it. A renamed or moved extension file is a deletion plus a new file. Every new file must have one change instruction. Include retained files in changes only when their complete contents must be regenerated. Retained unlisted files are copied unchanged. Use only safe relative PHP, JavaScript, and CSS paths. Keep 1-20 files, exactly one root-level PHP main_file, and one Plugin Name header in that main file only. The extension must remain a separate plugin: never propose, copy, edit, replace, rename, or delete a file in the inspected target. Do not propose build artifacts, dependencies, binaries, or filesystem operations. A change request must materially alter extension content or topology.
PROMPT;
	}

	public function analysis_input( string $workspace_request, string $plan, array $target, array $manifest, array $source, array $history, string $message, string $feedback = '' ): string {
		return wp_json_encode(
			[
				'json_contract'     => 'Return one JSON object with outcome answer or changes.',
				'workspace_request' => $workspace_request,
				'approved_plan'     => $plan,
				'inspected_target'  => $target,
				'current_manifest'  => $manifest,
				'current_source'    => $source,
				'recent_history'    => $history,
				'message'           => $message,
				'retry_feedback'    => $feedback,
			],
			JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
		) ?: '{}';
	}

	public function file_instructions(): string {
		$runtime_constraints = WordPress_Runtime_Constraints::instructions();
		$plugin_instructions = Plugin_Instructions::prompt_policy();

		return <<<PROMPT
Generate one complete file for a staged WordPress extension plugin change. Return exactly one valid JSON object with exactly these keys: {"path":"the/exact/requested-path.ext","content":"complete file contents"}. Do not use Markdown fences. The path must match exactly. Preserve established extension conventions and implement the supplied instruction. The output must be a complete non-empty PHP, JavaScript, or CSS file under 64 KiB. PHP must parse without execution. The desired extension main file must contain exactly one Plugin Name header and the exact header `Author: WP-Autoplugin`; supporting PHP files must contain none. Never write or reproduce a file from the inspected target plugin or theme.

$plugin_instructions

$runtime_constraints
PROMPT;
	}

	public function file_input( string $workspace_request, string $message, string $plan, array $target, array $base_source, array $desired_manifest, array $effective_source, array $file, array $feedback ): string {
		return wp_json_encode(
			[
				'json_contract'     => 'Return one JSON object containing exact path and complete content.',
				'workspace_request' => $workspace_request,
				'follow_up_message' => $message,
				'approved_plan'     => $plan,
				'inspected_target'  => $target,
				'base_extension'    => $base_source,
				'desired_manifest'  => $desired_manifest,
				'effective_extension' => $effective_source,
				'current_file'      => [
					'path'        => $file['path'],
					'type'        => $file['type'],
					'operation'   => $file['operation'],
					'instruction' => $file['description'],
				],
				'retry_feedback'    => array_slice( $feedback, 0, 5 ),
			],
			JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
		) ?: '{}';
	}
}
