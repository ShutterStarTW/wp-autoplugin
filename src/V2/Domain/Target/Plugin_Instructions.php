<?php

namespace WP_Autoplugin\V2\Domain\Target;

/**
 * Reads the optional root-level instructions for an installed plugin.
 */
final class Plugin_Instructions {
	public const FILE_NAME = 'AGENTS.md';
	public const MAX_BYTES = 65536;

	/**
	 * Read the root instruction file when the target is a directory plugin.
	 *
	 * @param array<string, mixed> $target Target metadata snapshot.
	 * @param string               $root   Resolved target root.
	 * @return array{path:string,content:string,bytes:int,content_hash:string}|null
	 * @throws \RuntimeException When a present instruction file cannot be read safely and completely.
	 */
	public function read( array $target, string $root ): ?array {
		if ( 'plugin' !== ( $target['kind'] ?? '' ) ) {
			return null;
		}

		$real_root = realpath( $root );
		if ( false === $real_root ) {
			throw new \RuntimeException( __( 'The installed plugin root is no longer available.', 'wp-autoplugin' ) );
		}
		$root = wp_normalize_path( $real_root );
		if ( is_file( $root ) ) {
			return null;
		}

		$candidate = trailingslashit( $root ) . self::FILE_NAME;
		if ( ! file_exists( $candidate ) && ! is_link( $candidate ) ) {
			return null;
		}
		if ( is_link( $candidate ) || ! is_file( $candidate ) ) {
			throw new \RuntimeException( __( 'The plugin root AGENTS.md file is not a safe regular file.', 'wp-autoplugin' ) );
		}

		$real = realpath( $candidate );
		$real = false === $real ? '' : wp_normalize_path( $real );
		if ( '' === $real || dirname( $real ) !== $root ) {
			throw new \RuntimeException( __( 'The plugin root AGENTS.md file resolves outside the plugin root.', 'wp-autoplugin' ) );
		}

		$bytes = filesize( $real );
		if ( false === $bytes || $bytes > self::MAX_BYTES ) {
			throw new \RuntimeException( __( 'The plugin root AGENTS.md file exceeds the 64 KiB instruction limit.', 'wp-autoplugin' ) );
		}

		$content = file_get_contents( $real ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Read-only constrained plugin instruction file.
		if ( false === $content ) {
			throw new \RuntimeException( __( 'The plugin root AGENTS.md file could not be read.', 'wp-autoplugin' ) );
		}
		if ( strlen( $content ) > self::MAX_BYTES ) {
			throw new \RuntimeException( __( 'The plugin root AGENTS.md file exceeds the 64 KiB instruction limit.', 'wp-autoplugin' ) );
		}
		if ( str_contains( $content, "\0" ) || 1 !== preg_match( '//u', $content ) ) {
			throw new \RuntimeException( __( 'The plugin root AGENTS.md file must contain valid UTF-8 text.', 'wp-autoplugin' ) );
		}

		return [
			'path'         => self::FILE_NAME,
			'content'      => $content,
			'bytes'        => strlen( $content ),
			'content_hash' => hash( 'sha256', $content ),
		];
	}

	/**
	 * Return the shared model precedence and mutation policy.
	 */
	public static function prompt_policy(): string {
		return 'When root_plugin_instructions are supplied from the installed plugin\'s root AGENTS.md, follow them as project-specific instructions. They remain subordinate to the current administrator request and to all safety, read-only, approved-Plan, manifest, review-integrity, and exact-output constraints in this prompt. Never add, update, delete, or reproduce AGENTS.md unless a higher-priority prompt explicitly allows it.';
	}
}
