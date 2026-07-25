<?php

namespace WP_Autoplugin\V2\Domain\AI\Prompts;

use WP_Autoplugin\V2\Domain\Target\Plugin_Instructions;

/** Builds one bounded prompt for each approved installed-target file change. */
final class Existing_Target_Code_Prompt {
	public const SLUG    = 'existing-target-code';
	public const VERSION = 4;

	public function instructions( string $operation = 'add' ): string {
		$runtime_constraints = WordPress_Runtime_Constraints::instructions();
		$plugin_instructions = Plugin_Instructions::prompt_policy();

		if ( 'update' === $operation ) {
			return <<<PROMPT
You are implementing one approved targeted edit in an existing WordPress plugin or theme. Use the supplied current file as the authoritative baseline. Return only the smallest exact search/replace operations needed to implement the approved Plan; never return, rewrite, or reproduce the complete file. Every search string must be copied byte-for-byte from the supplied current source, contain enough surrounding context to occur exactly once, and must not cover the entire file. All searches are matched against the original file and therefore must not overlap. Preserve unrelated behavior, formatting, comments, line endings, and code. Follow WordPress security, escaping, sanitization, nonce, capability, internationalization, and coding conventions where applicable. For plugin targets, the main_file must retain exactly one populated Plugin Name header and supporting PHP files must not add one. Never change any unplanned path.

$plugin_instructions

$runtime_constraints

Return only one valid JSON object with no Markdown fence and exactly this shape:
{"path":"the exact requested relative path","replacements":[{"search":"exact existing source block","replace":"replacement source block"}]}
PROMPT;
		}

		return <<<PROMPT
You are implementing one approved new file in an existing WordPress plugin or theme. Return the complete production-ready added file. Integrate with the supplied target context without changing any unplanned path. Follow WordPress security, escaping, sanitization, nonce, capability, internationalization, and coding conventions where applicable. Added PHP files in plugin targets must not contain a Plugin Name header; theme files must not be treated as plugin entry points. Never change, delete, rename, or create files outside the approved manifest. Previously generated files are authoritative staged context; target source is read-only context.

$plugin_instructions

$runtime_constraints

Return only one valid JSON object with no Markdown fence and exactly this shape:
{"path":"the exact requested relative path","content":"complete file contents"}
PROMPT;
	}

	/**
	 * @param array<string, mixed>              $target    Public target metadata.
	 * @param array<string, mixed>              $manifest  Normalized change manifest.
	 * @param array<string, mixed>              $current   Current file action.
	 * @param array<int, array<string, mixed>>  $source    Current planned target source.
	 * @param array<int, array<string, string>> $generated Previously generated changes.
	 * @param array<int, array<string, mixed>>  $issues    Bounded validation feedback.
	 */
	public function input( string $request, string $plan, array $target, array $manifest, array $current, array $source, array $generated, array $issues = [] ): string {
		$sections = [
			'Original administrator request' => $request,
			'Approved Plan Markdown'          => $plan,
			'Target metadata'                 => (string) wp_json_encode( $target, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ),
			'Normalized planned change set'   => (string) wp_json_encode( $manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ),
			'Current file action'              => (string) wp_json_encode( $current, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ),
			'Current read-only target source for planned existing files' => (string) wp_json_encode( $source, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ),
			'Previously generated staged files' => (string) wp_json_encode( $generated, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ),
		];
		if ( $issues ) {
			$sections['Validation errors from the previous attempt; correct only these in the required response format'] = (string) wp_json_encode( array_slice( $issues, 0, 5 ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
		}
		$output = [];
		foreach ( $sections as $heading => $value ) {
			$output[] = $heading . ":\n<<<\n" . $value . "\n>>>";
		}
		return implode( "\n\n", $output );
	}
}
