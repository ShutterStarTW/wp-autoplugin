<?php

namespace WP_Autoplugin\V2\Domain\AI\Prompts;

/** Builds the versioned direct prompts for a new-plugin Plan. */
final class New_Plugin_Plan_Prompt {
	public const SLUG    = 'new-plugin-plan';
	public const VERSION = 2;

	public function initial_instructions(): string {
		return <<<'PROMPT'
You are a WordPress plugin implementation planning agent. Prepare a complete implementation Plan for a new plugin from the administrator's request. This is planning work only: never claim to write, install, activate, execute, promote, or modify files. Keep the proposed architecture minimal, cohesive, secure, and consistent with WordPress coding and internationalization practices.

Return only one valid JSON object with no Markdown fence in this shape:
{"outcome":"artifact","content":"complete Plan in Markdown","structured":{"plugin_name":"Name of the new plugin","main_file":"plugin-slug.php","project_structure":{"directories":["relative/directory/"],"files":[{"path":"plugin-slug.php","type":"php","description":"brief purpose","action":"add"}]}}}

The Markdown Plan must describe the intended behavior, architecture, data flow, security considerations, and implementation steps in enough detail for a later coding task. The project structure must be minimal but complete. main_file must name one root-level PHP file in project_structure.files. It must contain at least one PHP file, every action must be "add", file type must be "php", "js", or "css", and every path must be relative to the new plugin root and unique. Do not include source code.
PROMPT;
	}

	public function initial_input( string $request ): string {
		return "Administrator's request for the new WordPress plugin:\n\n{$request}";
	}

	public function follow_up_instructions(): string {
		return <<<'PROMPT'
You are continuing the Plan for a new WordPress plugin. This is planning work only: never claim to write, install, activate, execute, promote, or modify files.

For a question, request for explanation, or ambiguity, return only:
{"outcome":"answer","content":"concise Markdown answer"}

Only when the administrator clearly requests a change to the Plan or its implementation requirements, return a complete replacement artifact using only:
{"outcome":"artifact","content":"complete replacement Plan in Markdown","structured":{"plugin_name":"Name of the new plugin","main_file":"plugin-slug.php","project_structure":{"directories":["relative/directory/"],"files":[{"path":"plugin-slug.php","type":"php","description":"brief purpose","action":"add"}]}}}

A replacement artifact must contain the entire updated Plan and file map, not a patch or partial response. main_file must name one root-level PHP file in the file map. It must contain at least one PHP file, every action must be "add", file type must be "php", "js", or "css", and every path must be relative to the new plugin root and unique. Do not include source code or a Markdown fence around the JSON.
PROMPT;
	}

	public function follow_up_input( string $request, string $artifact, string $history, string $message ): string {
		return <<<PROMPT
Original workspace request:
"""
$request
"""

Current Plan artifact:
"""
$artifact
"""

Recent Plan conversation (oldest first):
"""
$history
"""

The administrator's new message is:
"""
$message
"""
PROMPT;
	}

	public function structure_instructions(): string {
		return <<<'PROMPT'
You are regenerating the validated file map for an administrator-edited Plan for a new WordPress plugin. This is planning work only: do not write code or claim that files were created.

Return only one valid JSON object with no Markdown fence in this shape:
{"outcome":"artifact","content":"the complete administrator-edited Plan in Markdown","structured":{"plugin_name":"Name of the new plugin","main_file":"plugin-slug.php","project_structure":{"directories":["relative/directory/"],"files":[{"path":"plugin-slug.php","type":"php","description":"brief purpose","action":"add"}]}}}

Preserve the supplied Markdown Plan as the artifact content. Derive a minimal but complete file map from it. main_file must name one root-level PHP file in the file map. The structure must contain at least one PHP file, every action must be "add", file type must be "php", "js", or "css", and every path must be relative to the new plugin root and unique. Do not include source code.
PROMPT;
	}

	public function structure_input( string $request, string $plan, array $prior_structure ): string {
		$prior = (string) wp_json_encode( $prior_structure, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );

		return <<<PROMPT
Original workspace request:
"""
$request
"""

Administrator-edited Plan in Markdown:
"""
$plan
"""

Prior validated Plan metadata, for reference only:
"""
$prior
"""
PROMPT;
	}
}
