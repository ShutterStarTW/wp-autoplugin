<?php

namespace WP_Autoplugin\V2\Domain\AI\Prompts;

/** Versioned prompts for questions and topology-aware Code follow-ups. */
final class New_Plugin_Code_Follow_Up_Prompt {
	public const SLUG    = 'new-plugin-code-follow-up';
	public const VERSION = 2;

	public function analysis_instructions(): string {
		$runtime_constraints = WordPress_Runtime_Constraints::instructions();

		return <<<PROMPT
You are the coding agent for a staged WordPress plugin project. Classify the administrator's newest message as either a question about the current code or a concrete request to change it.

$runtime_constraints

Return exactly one valid JSON object and no Markdown fences.

For a question return:
{"outcome":"answer","content":"A concise Markdown answer grounded only in the supplied project."}

For a change request return:
{"outcome":"changes","content":"A concise Markdown change summary.","manifest":{"plugin_name":"...","main_file":"root-file.php","files":[{"path":"relative/path.php","type":"php","description":"Purpose."}]},"changes":[{"path":"relative/path.php","instruction":"Specific bounded implementation instruction."}]}

The manifest is the complete desired live project after the change. Omitting an existing path deletes it. A renamed or moved file is a deletion plus a new file. Every new file must have one change instruction. Include retained files in changes only when their complete contents must be regenerated. Retained unlisted files are copied unchanged. Use only safe relative PHP, JavaScript, and CSS paths. Keep 1-20 files, exactly one root-level PHP main_file, and one Plugin Name header in that main file only. Do not propose build artifacts, dependencies, binaries, or filesystem operations. A change request must materially alter content or topology.
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
				'json_contract'    => 'Return one JSON object with outcome answer or changes.',
				'workspace_request'=> $workspace_request,
				'current_manifest' => $manifest,
				'current_source'   => $source,
				'recent_history'   => $history,
				'message'          => $message,
				'retry_feedback'   => $feedback,
			],
			JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
		) ?: '{}';
	}

	public function file_instructions(): string {
		$runtime_constraints = WordPress_Runtime_Constraints::instructions();

		return <<<PROMPT
Generate one complete file for a staged WordPress plugin change. Return exactly one valid JSON object with exactly these keys: {"path":"the/exact/requested-path.ext","content":"complete file contents"}. Do not use Markdown fences. The path must match exactly. Preserve established project conventions and implement the supplied instruction. The output must be a complete non-empty PHP, JavaScript, or CSS file under 64 KiB. PHP must parse without execution. The desired main file must contain exactly one Plugin Name header; supporting PHP files must contain none.

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
	public function file_input( string $workspace_request, string $message, array $base_source, array $desired_manifest, array $effective_source, array $file, array $feedback ): string {
		return wp_json_encode(
			[
				'json_contract'    => 'Return one JSON object containing exact path and complete content.',
				'workspace_request'=> $workspace_request,
				'follow_up_message'=> $message,
				'base_project'     => $base_source,
				'desired_manifest' => $desired_manifest,
				'effective_project'=> $effective_source,
				'current_file'     => [
					'path'        => $file['path'],
					'type'        => $file['type'],
					'operation'   => $file['operation'],
					'instruction' => $file['description'],
				],
				'retry_feedback'   => array_slice( $feedback, 0, 5 ),
			],
			JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
		) ?: '{}';
	}
}
