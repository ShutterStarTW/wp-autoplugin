<?php

namespace WP_Autoplugin\V2\Release;

/** Defines the server-enforced relationship between revision artifacts and release modes. */
final class Release_Matrix {
	public static function allows( string $resource, string $scope, string $artifact_kind, string $mode ): bool {
		$matrix = [
			'package'   => [
				'project:plugin' => [ 'project' ],
				'changes:plugin' => [ 'fork', 'replacement' ],
				'changes:theme'  => [ 'theme_replacement' ],
			],
			'promotion' => [
				'project:plugin' => [ 'install_project' ],
				'changes:plugin' => [ 'install_fork', 'modify_original' ],
				'changes:theme'  => [ 'install_theme_copy', 'modify_theme_original' ],
			],
		];

		$key = $scope . ':' . $artifact_kind;
		return in_array( $mode, $matrix[ $resource ][ $key ] ?? [], true );
	}
}
