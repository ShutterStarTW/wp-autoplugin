<?php

namespace WP_Autoplugin\V2\Domain\Revision;

/** Removes one redundant wrapper directory from a complete new-plugin file map. */
final class Project_Root_Normalizer {
	/**
	 * @param array<string, mixed> $structure Normalized or raw project structure.
	 * @return array{structure:array<string, mixed>,main_file:string,unwrapped:bool}
	 */
	public function normalize( array $structure, string $main_file = '' ): array {
		$files = is_array( $structure['files'] ?? null ) ? $structure['files'] : [];
		$result = [
			'structure'  => $structure,
			'main_file'  => $this->path( $main_file ),
			'unwrapped'  => false,
		];
		if ( ! $files ) {
			return $result;
		}
		if ( '' === $result['main_file'] ) {
			$root_php = [];
			foreach ( $files as $file ) {
				$path = is_array( $file ) ? $this->path( (string) ( $file['path'] ?? '' ) ) : '';
				if ( 'php' === sanitize_key( (string) ( $file['type'] ?? '' ) ) && '' !== $path && ! str_contains( $path, '/' ) ) {
					$root_php[] = $path;
				}
			}
			if ( 1 === count( $root_php ) ) {
				$result['main_file'] = $root_php[0];
			}
		}

		$prefix = null;
		$paths  = [];
		foreach ( $files as $file ) {
			$path = is_array( $file ) ? $this->path( (string) ( $file['path'] ?? '' ) ) : '';
			$slash = strpos( $path, '/' );
			if ( '' === $path || false === $slash ) {
				return $result;
			}
			$first = substr( $path, 0, $slash );
			if ( null !== $prefix && $prefix !== $first ) {
				return $result;
			}
			$prefix  = $first;
			$paths[] = substr( $path, $slash + 1 );
		}

		$normalized_main = $this->path( $main_file );
		if ( '' !== $normalized_main && str_starts_with( $normalized_main, $prefix . '/' ) ) {
			$normalized_main = substr( $normalized_main, strlen( $prefix ) + 1 );
		}
		$root_php = [];
		foreach ( $files as $index => $file ) {
			if ( 'php' === sanitize_key( (string) ( $file['type'] ?? '' ) ) && ! str_contains( $paths[ $index ], '/' ) ) {
				$root_php[] = $paths[ $index ];
			}
		}
		if ( '' !== $normalized_main ) {
			$main_index = array_search( $normalized_main, $paths, true );
			if ( false === $main_index || str_contains( $normalized_main, '/' ) || 'php' !== sanitize_key( (string) ( $files[ $main_index ]['type'] ?? '' ) ) ) {
				return $result;
			}
		} else {
			$matches = array_values( array_intersect( $root_php, [ $prefix . '.php' ] ) );
			if ( 1 === count( $root_php ) ) {
				$normalized_main = $root_php[0];
			} elseif ( 1 === count( $matches ) ) {
				$normalized_main = $matches[0];
			} else {
				return $result;
			}
		}

		$unwrapped_files = [];
		foreach ( $files as $index => $file ) {
			$file['path']      = $paths[ $index ];
			$unwrapped_files[] = $file;
		}

		$directories = [];
		foreach ( is_array( $structure['directories'] ?? null ) ? $structure['directories'] : [] as $directory ) {
			$path = is_string( $directory ) ? $this->path( rtrim( $directory, '/' ) ) : '';
			if ( '' === $path || $prefix === $path ) {
				continue;
			}
			if ( str_starts_with( $path, $prefix . '/' ) ) {
				$path = substr( $path, strlen( $prefix ) + 1 );
			}
			$directories[] = $path . '/';
		}

		$result['structure'] = [
			'directories' => array_values( array_unique( $directories ) ),
			'files'       => $unwrapped_files,
		];
		$result['main_file'] = $normalized_main;
		$result['unwrapped'] = true;
		return $result;
	}

	private function path( string $path ): string {
		$path     = wp_normalize_path( trim( $path ) );
		$segments = explode( '/', $path );
		if ( '' === $path || str_starts_with( $path, '/' ) || preg_match( '/^[A-Za-z]:/', $path ) || preg_match( '/[\x00-\x1F]/', $path ) || array_intersect( [ '', '.', '..' ], $segments ) ) {
			return '';
		}
		return trim( $path, '/' );
	}
}
