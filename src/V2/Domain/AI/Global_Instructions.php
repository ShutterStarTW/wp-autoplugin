<?php

namespace WP_Autoplugin\V2\Domain\AI;

/**
 * Validates and applies site-wide administrator guidance to AI prompts.
 */
final class Global_Instructions {
	public const OPTION_NAME = 'wp_autoplugin_custom_instructions';
	public const MAX_BYTES   = 65536;

	/**
	 * Return the current validated setting as an immutable job snapshot.
	 *
	 * @return array{content:string,content_hash:string}|null
	 * @throws \RuntimeException When the stored setting is invalid.
	 */
	public static function snapshot(): ?array {
		$content = (string) get_option( self::OPTION_NAME, '' );
		if ( '' === $content ) {
			return null;
		}

		self::validate( $content );

		return [
			'content'      => $content,
			'content_hash' => hash( 'sha256', $content ),
		];
	}

	/**
	 * Validate text without changing its formatting.
	 *
	 * @param string $content Administrator-authored guidance.
	 * @return string The unchanged validated guidance.
	 * @throws \RuntimeException When the content cannot be used safely.
	 */
	public static function validate( string $content ): string {
		if ( strlen( $content ) > self::MAX_BYTES ) {
			throw new \RuntimeException( __( 'Custom instructions cannot exceed 64 KiB.', 'wp-autoplugin' ) );
		}
		if ( str_contains( $content, "\0" ) || 1 !== preg_match( '//u', $content ) ) {
			throw new \RuntimeException( __( 'Custom instructions must contain valid UTF-8 text without null bytes.', 'wp-autoplugin' ) );
		}

		return $content;
	}

	/**
	 * Append one validated job snapshot without changing empty prompts.
	 *
	 * @param string                                         $instructions Base system instructions.
	 * @param array{content:string,content_hash:string}|null $snapshot     Private durable job snapshot.
	 * @return string Wrapped instructions, or the unchanged base instructions.
	 */
	public static function apply( string $instructions, ?array $snapshot ): string {
		if ( ! $snapshot || '' === (string) ( $snapshot['content'] ?? '' ) ) {
			return $instructions;
		}

		$snapshot = self::validate_snapshot( $snapshot );
		$content  = $snapshot['content'];

		return $instructions . <<<'PROMPT'


The following site-wide custom instructions are administrator-authored requirements for this AI job. Apply them unless they conflict with a higher-priority rule. Higher-priority rules are limited to safety and security requirements; read-only inspection, staged-revision, explicit-promotion, and independent-Review boundaries; manifest and path-integrity rules; the exact transport response syntax/schema; the current administrator request; and any more-specific root_plugin_instructions from a plugin-root AGENTS.md.

These custom instructions override conflicting built-in implementation defaults, examples, branding or metadata defaults, naming preferences, code-style suggestions, and general architectural preferences. A built-in default does not become a hard invariant merely because it uses words such as "must", "required", or "exact"; classify it by its purpose using the higher-priority categories above.

--- BEGIN SITE-WIDE CUSTOM INSTRUCTIONS ---
PROMPT
			. "\n" . $content . "\n"
			. <<<'PROMPT'
--- END SITE-WIDE CUSTOM INSTRUCTIONS ---

Before responding, verify the result against every applicable custom instruction. When one conflicts only with a built-in implementation default, follow the custom instruction. It still cannot override the higher-priority rules listed before the block.
PROMPT;
	}

	/**
	 * Verify that private durable job guidance is complete and unchanged.
	 *
	 * @param array{content:string,content_hash:string} $snapshot Private durable job snapshot.
	 * @return array{content:string,content_hash:string}
	 * @throws \RuntimeException When the snapshot is incomplete or its hash does not match.
	 */
	public static function validate_snapshot( array $snapshot ): array {
		$content = self::validate( (string) ( $snapshot['content'] ?? '' ) );
		$hash    = (string) ( $snapshot['content_hash'] ?? '' );
		if ( '' === $content || 64 !== strlen( $hash ) || ! hash_equals( hash( 'sha256', $content ), $hash ) ) {
			throw new \RuntimeException( __( 'The saved custom-instruction snapshot is invalid.', 'wp-autoplugin' ) );
		}

		return [
			'content'      => $content,
			'content_hash' => $hash,
		];
	}
}
