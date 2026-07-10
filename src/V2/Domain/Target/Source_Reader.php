<?php

namespace WP_Autoplugin\V2\Domain\Target;

/**
 * Builds bounded read-only context for direct-mode provider adapters.
 */
final class Source_Reader {
	private const EXTENSIONS = [ 'php', 'js', 'jsx', 'ts', 'tsx', 'css', 'scss', 'json', 'md', 'xml', 'html' ];
	private const SKIPPED    = [ '.git', 'node_modules', 'vendor', 'tests' ];
	private const MAX_BYTES  = 500000;

	/**
	 * @param array<string, mixed> $target Target metadata snapshot.
	 * @return array<string, string>
	 */
	public function read( array $target ): array {
		$kind = $target['kind'] ?? '';
		$ref  = $target['ref'] ?? '';

		if ( 'plugin' === $kind ) {
			$directory = dirname( (string) $ref );
			if ( '.' === $directory ) {
				return $this->read_single( WP_PLUGIN_DIR . '/' . $ref, basename( (string) $ref ) );
			}
			return $this->read_tree( WP_PLUGIN_DIR . '/' . $directory );
		}

		if ( 'theme' === $kind ) {
			$theme = wp_get_theme( (string) $ref );
			return $theme->exists() ? $this->read_tree( $theme->get_stylesheet_directory() ) : [];
		}

		return [];
	}

	/**
	 * @return array<string, string>
	 */
	private function read_single( string $path, string $relative ): array {
		$real = realpath( $path );
		if ( false === $real || ! is_file( $real ) || filesize( $real ) > self::MAX_BYTES ) {
			return [];
		}

		$content = file_get_contents( $real ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Read-only local source inspection.
		return false === $content ? [] : [ $relative => $content ];
	}

	/**
	 * @return array<string, string>
	 */
	private function read_tree( string $root ): array {
		$root_real = realpath( $root );
		if ( false === $root_real || ! is_dir( $root_real ) ) {
			return [];
		}

		$root_real = trailingslashit( wp_normalize_path( $root_real ) );
		$files     = [];
		$bytes     = 0;
		$directory = new \RecursiveDirectoryIterator( $root_real, \FilesystemIterator::SKIP_DOTS );
		$filter    = new \RecursiveCallbackFilterIterator(
			$directory,
			static function ( \SplFileInfo $file ): bool {
				return ! $file->isDir() || ! in_array( $file->getFilename(), self::SKIPPED, true );
			}
		);

		foreach ( new \RecursiveIteratorIterator( $filter ) as $file ) {
			if ( ! $file->isFile() || $file->isLink() || $file->getSize() > 262144 ) {
				continue;
			}
			if ( ! in_array( strtolower( $file->getExtension() ), self::EXTENSIONS, true ) ) {
				continue;
			}

			$path = wp_normalize_path( $file->getRealPath() ?: '' );
			if ( ! str_starts_with( $path, $root_real ) || $bytes + $file->getSize() > self::MAX_BYTES ) {
				continue;
			}

			$content = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Read-only local source inspection.
			if ( false !== $content ) {
				$files[ ltrim( substr( $path, strlen( $root_real ) ), '/' ) ] = $content;
				$bytes += strlen( $content );
			}
		}

		return $files;
	}
}
