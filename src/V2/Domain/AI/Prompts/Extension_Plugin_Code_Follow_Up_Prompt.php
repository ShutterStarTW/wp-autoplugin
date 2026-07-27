<?php

namespace WP_Autoplugin\V2\Domain\AI\Prompts;

use WP_Autoplugin\V2\Domain\Target\Plugin_Instructions;

/** Versioned Code follow-ups for a staged extension plugin. */
final class Extension_Plugin_Code_Follow_Up_Prompt {
	public const SLUG    = 'extension-plugin-code-follow-up';
	public const VERSION = 9;

	public function analysis_instructions(): string {
		$runtime_constraints = WordPress_Runtime_Constraints::instructions();
		$plugin_instructions = Plugin_Instructions::prompt_policy( true );
		$priority            = Code_Follow_Up_Priority::instructions();

		return <<<PROMPT
You are the coding agent for a staged WordPress extension plugin that integrates with an installed plugin or theme through approved hooks and public APIs. Classify the administrator's newest message as either a question about the staged extension code or a concrete request to change that extension plugin.

$priority

$plugin_instructions

$runtime_constraints

Return exactly one valid JSON object and no Markdown fences.

For a question return:
{"outcome":"answer","content":"A concise Markdown answer grounded only in the supplied Plan and staged extension project."}

For a change request return:
{"outcome":"changes","content":"A concise Markdown change summary.","resolved_request":"One concrete statement of the newest requested result, with contextual references resolved.","acceptance_criteria":["Observable requirement the generated revision must satisfy."],"manifest":{"plugin_name":"...","main_file":"root-file.php","files":[{"path":"relative/path.php","type":"php","description":"Purpose."}]},"changes":[{"path":"relative/path.php","instruction":"Specific bounded implementation instruction that directly satisfies the resolved request."}]}

For a change, provide one resolved_request and one to eight concrete, testable acceptance_criteria derived from the newest message and its clear conversational antecedent. The manifest is the complete desired extension plugin after the change. Omitting an existing extension path deletes it. A renamed or moved extension file is a deletion plus a new file. Every new file must have one change instruction. Include retained files in changes only when their complete contents must be regenerated. Retained unlisted files are copied unchanged. Use only safe relative PHP, JavaScript, CSS, JSON, HTML, SVG, XML, Markdown, and plain-text paths. Keep 1-20 files, exactly one root-level PHP main_file, and one Plugin Name header in that main file only. The extension must remain a separate plugin: never propose, copy, edit, replace, rename, or delete a file in the inspected target or its read-only parent_theme. Do not propose build artifacts, dependencies, binaries, or filesystem operations. A change request must materially alter extension content or topology.
PROMPT;
	}

	public function analysis_input( string $workspace_request, string $plan, array $target, array $manifest, array $source, array $history, string $message, string $feedback = '' ): string {
		return wp_json_encode(
			[
				'json_contract'                => 'Return one JSON object with outcome answer or changes.',
				'original_workspace_request'   => $workspace_request,
				'reference_plan'               => $plan,
				'inspected_target'             => $target,
				'current_manifest'             => $manifest,
				'current_source'               => $source,
				'recent_code_conversation'     => $history,
				'retry_feedback'               => $feedback,
				'authoritative_latest_request' => $message,
			],
			JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
		) ?: '{}';
	}

	public function file_instructions(): string {
		$runtime_constraints = WordPress_Runtime_Constraints::instructions();
		$plugin_instructions = Plugin_Instructions::prompt_policy( true );
		$priority            = Code_Follow_Up_Priority::generation_instructions();

		return <<<PROMPT
Generate one complete file for a staged WordPress extension plugin change. Return exactly one valid JSON object with exactly these keys: {"path":"the/exact/requested-path.ext","content":"complete file contents"}. Do not wrap the JSON response in a Markdown fence; Markdown file content may contain fenced examples encoded inside the JSON string. The path must match exactly. Preserve established extension conventions and implement the supplied instruction. The output must be a complete non-empty PHP, JavaScript, CSS, JSON, HTML, SVG, XML, Markdown, or plain-text file under 64 KiB. PHP must parse without execution, JSON must be syntactically valid, SVG and XML must be well-formed XML, and HTML may be a complete document or fragment. The desired extension main file must contain exactly one Plugin Name header and an Author header; supporting PHP files must contain none. Unless applicable administrator, project, or site-wide instructions specify different plugin metadata, use `Author: WP-Autoplugin` only as the fallback Author value. Never write or reproduce a file from the inspected target plugin or theme.

$priority

$plugin_instructions

$runtime_constraints
PROMPT;
	}

	public function file_input( string $message, array $history, string $resolved_request, array $acceptance_criteria, array $target, array $base_source, array $desired_manifest, array $effective_source, array $file, array $feedback ): string {
		return wp_json_encode(
			[
				'json_contract'                => 'Return one JSON object containing exact path and complete content.',
				'recent_code_conversation'     => $history,
				'resolved_request'             => $resolved_request,
				'acceptance_criteria'          => $acceptance_criteria,
				'inspected_target'             => $target,
				'base_extension'               => $base_source,
				'desired_manifest'             => $desired_manifest,
				'effective_extension'          => $effective_source,
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
