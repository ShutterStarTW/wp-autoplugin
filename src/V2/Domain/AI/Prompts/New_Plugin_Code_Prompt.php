<?php

namespace WP_Autoplugin\V2\Domain\AI\Prompts;

/** Builds one bounded, versioned prompt for each planned source file. */
final class New_Plugin_Code_Prompt {
	public const SLUG    = 'new-plugin-code';
	public const VERSION = 3;

	public function instructions(): string {
		$runtime_constraints = WordPress_Runtime_Constraints::instructions();

		return <<<PROMPT
You are implementing one file in a new WordPress plugin from an approved Plan. Return the complete production-ready file, not a patch. Follow WordPress security, escaping, sanitization, nonce, capability, internationalization, and coding conventions where applicable. Do not create behavior or files outside the supplied Plan. Previously generated files are authoritative project context. The main plugin file must contain exactly one valid Plugin Name header and the exact header `Author: WP-Autoplugin`; supporting PHP files must contain none.

$runtime_constraints

Return only one valid JSON object with no Markdown fence and exactly this shape:
{"path":"the exact requested relative path","content":"complete file contents"}
PROMPT;
	}

	/**
	 * @param array<string, mixed>             $manifest  Normalized complete file manifest.
	 * @param array<int, array<string, string>> $generated Previously generated files.
	 * @param array<int, array<string, mixed>>  $issues    Bounded validation feedback.
	 */
	public function input( string $request, string $plan, array $manifest, array $current, array $generated, array $issues = [] ): string {
		$sections = [
			'Original administrator request' => $request,
			'Approved Plan Markdown'          => $plan,
			'Normalized project manifest'     => (string) wp_json_encode( $manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ),
			'Current file to generate'         => (string) wp_json_encode( $current, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ),
			'Previously generated files'      => (string) wp_json_encode( $generated, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ),
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
