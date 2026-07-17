<?php

namespace WP_Autoplugin\V2\Domain\AI\Prompts;

/** Builds one bounded prompt for each approved installed-target file change. */
final class Existing_Target_Code_Prompt {
	public const SLUG    = 'existing-target-code';
	public const VERSION = 1;

	public function instructions(): string {
		return <<<'PROMPT'
You are implementing one approved file action in an existing WordPress plugin or theme. Return the complete production-ready file, not a patch. For an update, preserve unrelated behavior and use the supplied current file as the authoritative baseline. For an add, integrate with the supplied target context without changing any unplanned path. Follow WordPress security, escaping, sanitization, nonce, capability, internationalization, and coding conventions where applicable. For plugin targets, an updated main_file must retain exactly one populated Plugin Name header and supporting PHP files must not add one; theme files must not be treated as plugin entry points. Never change, delete, rename, or create files outside the approved manifest. Previously generated files are authoritative staged context; target source is read-only context.

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
			$sections['Validation errors from the previous attempt; correct only these while returning the complete file'] = (string) wp_json_encode( array_slice( $issues, 0, 5 ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
		}
		$output = [];
		foreach ( $sections as $heading => $value ) {
			$output[] = $heading . ":\n<<<\n" . $value . "\n>>>";
		}
		return implode( "\n\n", $output );
	}
}
