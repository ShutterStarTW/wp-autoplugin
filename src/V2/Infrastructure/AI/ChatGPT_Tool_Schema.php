<?php

namespace WP_Autoplugin\V2\Infrastructure\AI;

/** Normalizes function schemas for the stricter account-backed Codex endpoint. */
final class ChatGPT_Tool_Schema {
	private const MAX_DEPTH = 32;

	/** @param array<int, array<string, mixed>> $tools @return array<int, array<string, mixed>> */
	public static function sanitize( array $tools ): array {
		foreach ( $tools as $index => $tool ) {
			if ( ! is_array( $tool['parameters'] ?? null ) ) {
				continue;
			}
			$parameters = self::node( $tool['parameters'], 0 );
			$parameters['type'] = 'object';
			foreach ( [ 'allOf', 'anyOf', 'oneOf', 'enum', 'not' ] as $keyword ) {
				unset( $parameters[ $keyword ] );
			}
			$tools[ $index ]['parameters'] = $parameters;
		}
		return $tools;
	}

	/** @param array<mixed> $node @return array<mixed> */
	private static function node( array $node, int $depth ): array {
		if ( $depth >= self::MAX_DEPTH ) {
			return $node;
		}
		if ( isset( $node['type'] ) || isset( $node['allOf'] ) || isset( $node['anyOf'] ) || isset( $node['oneOf'] ) ) {
			unset( $node['pattern'], $node['format'] );
		}
		if ( is_array( $node['enum'] ?? null ) ) {
			foreach ( $node['enum'] as $value ) {
				if ( is_string( $value ) && str_contains( $value, '/' ) ) {
					unset( $node['enum'] );
					break;
				}
			}
		}
		if ( isset( $node['$ref'] ) ) {
			unset( $node['default'] );
		}
		foreach ( $node as $key => $value ) {
			if ( is_array( $value ) ) {
				$node[ $key ] = self::node( $value, $depth + 1 );
			}
		}
		return $node;
	}
}
