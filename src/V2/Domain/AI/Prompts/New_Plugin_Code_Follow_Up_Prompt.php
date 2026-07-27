<?php

namespace WP_Autoplugin\V2\Domain\AI\Prompts;

/** Versioned prompts for questions and topology-aware Code follow-ups. */
final class New_Plugin_Code_Follow_Up_Prompt {
	public const SLUG    = 'new-plugin-code-follow-up';
	public const VERSION = 7;

	public function analysis_instructions(): string {
		$runtime_constraints = WordPress_Runtime_Constraints::instructions();
		$priority            = Code_Follow_Up_Priority::instructions();

		return <<<PROMPT
You are the coding agent for a staged WordPress plugin project. Classify the administrator's newest message as either a question about the current code or a concrete request to change it.

$priority

$runtime_constraints

Return exactly one valid JSON object and no Markdown fences.

For a question return:
{"outcome":"answer","content":"A concise Markdown answer grounded only in the supplied project."}

For a change request return:
{"outcome":"changes","content":"A concise Markdown change summary.","resolved_request":"One concrete statement of the newest requested result, with contextual references resolved.","acceptance_criteria":["Observable requirement the generated revision must satisfy."],"manifest":{"plugin_name":"...","main_file":"root-file.php","files":[{"path":"relative/path.php","type":"php","description":"Purpose."}]},"changes":[{"path":"relative/path.php","instruction":"Specific bounded implementation instruction that directly satisfies the resolved request."}]}

For a change, provide one resolved_request and one to eight concrete, testable acceptance_criteria derived from the newest message and its clear conversational antecedent. The manifest is the complete desired live project after the change. Omitting an existing path deletes it. A renamed or moved file is a deletion plus a new file. Every new file must have one change instruction. Include retained files in changes only when their complete contents must be regenerated. Retained unlisted files are copied unchanged. Use only safe relative PHP, JavaScript, CSS, JSON, HTML, SVG, XML, Markdown, and plain-text paths. Keep 1-20 files, exactly one root-level PHP main_file, and one Plugin Name header in that main file only. Do not propose build artifacts, dependencies, binaries, or filesystem operations. A change request must materially alter content or topology.
PROMPT;
	}

	/**
	 * @param array<string, mixed>             $manifest Normalized current manifest.
	 * @param array<int, array<string, string>> $source   Complete current source.
	 * @param array<int, array<string, mixed>> $history  Recent Code messages/results.
	 */
	public function analysis_input( string $workspace_request, array $manifest, array $source, array $history, string $message, string $feedback = '' ): string {
		return wp_json_encode(
			[
				'json_contract'                  => 'Return one JSON object with outcome answer or changes.',
				'original_workspace_request'     => $workspace_request,
				'current_manifest'               => $manifest,
				'current_source'                 => $source,
				'recent_code_conversation'       => $history,
				'retry_feedback'                 => $feedback,
				'authoritative_latest_request'   => $message,
			],
			JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
		) ?: '{}';
	}

	public function file_instructions(): string {
		$runtime_constraints = WordPress_Runtime_Constraints::instructions();
		$priority            = Code_Follow_Up_Priority::generation_instructions();

		return <<<PROMPT
Generate one complete file for a staged WordPress plugin change. Return exactly one valid JSON object with exactly these keys: {"path":"the/exact/requested-path.ext","content":"complete file contents"}. Do not wrap the JSON response in a Markdown fence; Markdown file content may contain fenced examples encoded inside the JSON string. The path must match exactly. Preserve established project conventions and implement the supplied instruction. The output must be a complete non-empty PHP, JavaScript, CSS, JSON, HTML, SVG, XML, Markdown, or plain-text file under 64 KiB. PHP must parse without execution, JSON must be syntactically valid, SVG and XML must be well-formed XML, and HTML may be a complete document or fragment. The desired main file must contain exactly one Plugin Name header and the exact header `Author: WP-Autoplugin`; supporting PHP files must contain none.

$priority

$runtime_constraints
PROMPT;
	}

	/**
	 * @param array<int, array<string, string>> $base_source      Complete parent source.
	 * @param array<string, mixed>              $desired_manifest Normalized target manifest.
	 * @param array<int, array<string, string>> $effective_source Parent source overlaid with files already generated.
	 * @param array<string, mixed>              $file             Current run file.
	 * @param array<int, array<string, mixed>>  $feedback         Bounded retry issues.
	 */
	public function file_input( string $message, array $history, string $resolved_request, array $acceptance_criteria, array $base_source, array $desired_manifest, array $effective_source, array $file, array $feedback ): string {
		return wp_json_encode(
			[
				'json_contract'                => 'Return one JSON object containing exact path and complete content.',
				'recent_code_conversation'     => $history,
				'resolved_request'             => $resolved_request,
				'acceptance_criteria'          => $acceptance_criteria,
				'base_project'                 => $base_source,
				'desired_manifest'             => $desired_manifest,
				'effective_project'            => $effective_source,
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
