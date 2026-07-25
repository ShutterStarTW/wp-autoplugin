<?php

namespace WP_Autoplugin\V2\Domain\AI\Prompts;

use WP_Autoplugin\V2\Domain\Target\Plugin_Instructions;

/** Builds one bounded prompt for each file in a separate hook-based extension plugin. */
final class Extension_Plugin_Code_Prompt {
	public const SLUG    = 'extension-plugin-code';
	public const VERSION = 5;

	public function instructions(): string {
		$runtime_constraints = WordPress_Runtime_Constraints::instructions();
		$plugin_instructions = Plugin_Instructions::prompt_policy();

		return <<<PROMPT
You are implementing one PHP, JavaScript, CSS, Markdown, or plain-text file in a new WordPress extension plugin from an approved, source-verified Plan. Return the complete production-ready file, not a patch. The extension must integrate through the hooks and public APIs described by the Plan and must never copy, edit, replace, or delete files in the inspected target plugin or theme. Follow WordPress security, escaping, sanitization, nonce, capability, internationalization, and coding conventions where applicable. For Markdown and plain-text files, produce the complete supporting documentation or text requested by the Plan. Do not create behavior or files outside the supplied Plan. Previously generated files are authoritative extension-plugin context. The main extension file must contain exactly one valid Plugin Name header and the exact header `Author: WP-Autoplugin`; supporting PHP files must contain none.

$plugin_instructions

$runtime_constraints

Return only one valid JSON object with exactly this shape. Do not wrap the JSON response in a Markdown fence; Markdown file content may contain fenced examples encoded inside the JSON string:
{"path":"the exact requested relative path","content":"complete file contents"}
PROMPT;
	}

	/** @param array<string, mixed> $target @param array<string, mixed> $manifest @param array<string, mixed> $current @param array<int, array<string, string>> $generated @param array<int, array<string, mixed>> $issues */
	public function input( string $request, string $plan, array $target, array $manifest, array $current, array $generated, array $issues = [] ): string {
		$sections = [
			'Original administrator request' => $request,
			'Approved source-verified Plan Markdown' => $plan,
			'Inspected target metadata'       => (string) wp_json_encode( $target, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ),
			'Normalized extension-plugin manifest' => (string) wp_json_encode( $manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ),
			'Current extension file to generate' => (string) wp_json_encode( $current, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ),
			'Previously generated extension files' => (string) wp_json_encode( $generated, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ),
		];
		if ( $issues ) {
			$sections['Validation errors from the previous attempt; correct only these while returning the complete file'] = (string) wp_json_encode( array_slice( $issues, 0, 5 ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
		}
		$output = [];
		foreach ( $sections as $heading => $value ) {
			$output[] = $heading . ":\n<<<\n" . $value . "\n>>>";
		}
		return implode( "\n\n", $output );
	}
}
