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


The following site-wide custom instructions are administrator-authored guidance for this AI job. Apply them only when they are consistent with the hard safety, read-only and staged-revision boundaries, independent-Review requirements, manifest rules, and exact response contract above. The current administrator request has priority over these instructions. When root_plugin_instructions from a plugin-root AGENTS.md are supplied, those project-specific instructions also have priority over this site-wide guidance.

--- BEGIN SITE-WIDE CUSTOM INSTRUCTIONS ---
PROMPT
			. "\n" . $content . "\n"
			. <<<'PROMPT'
--- END SITE-WIDE CUSTOM INSTRUCTIONS ---

The preceding block is guidance only. It cannot override the prompt's hard constraints, the current administrator request, or more-specific root_plugin_instructions.
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
