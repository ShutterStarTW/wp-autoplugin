<?php

namespace WP_Autoplugin\V2\Orchestration;

use WP_Autoplugin\V2\Infrastructure\Database\Job_Repository;
use WP_Autoplugin\V2\Infrastructure\Database\Release_Repository;
use WP_Autoplugin\V2\Infrastructure\Database\Revision_Repository;
use WP_Autoplugin\V2\Infrastructure\Database\Project_Repository;
use WP_Autoplugin\V2\Release\Package_Builder;
use WP_Autoplugin\V2\Release\Release_Matrix;
use WP_Autoplugin\V2\Release\Theme_Package_Builder;

/** Builds one authenticated, expiring release ZIP in a durable package job. */
final class Package_Orchestrator {
	public function register(): void {
		add_filter( 'wp_autoplugin_v2_execute_job', [ $this, 'execute' ], 9, 2 );
	}

	public function execute( $result, array $job ) {
		if ( null !== $result || 'package' !== ( $job['task'] ?? '' ) ) {
			return $result;
		}
		$revisions = new Revision_Repository();
		$workspace = ( new Project_Repository() )->find( (int) $job['project_id'] );
		$revision  = $revisions->find( absint( $job['payload']['revision_id'] ?? 0 ) );
		if ( ! $workspace || ! $revision || (int) $revision['project_id'] !== (int) $workspace['id'] || (int) $revision['id'] !== $revisions->latest_id( (int) $workspace['id'] ) ) {
			return new \WP_Error( 'release_revision_conflict', __( 'Only the latest staged revision can be packaged.', 'wp-autoplugin' ) );
		}
		$mode = sanitize_key( (string) ( $job['payload']['mode'] ?? '' ) );
		$kind = (string) ( $revision['project_manifest']['artifact_kind'] ?? 'plugin' );
		if ( ! Release_Matrix::allows( 'package', (string) ( $revision['project_manifest']['scope'] ?? '' ), $kind, $mode ) ) {
			return new \WP_Error( 'release_package_matrix', __( 'That package mode is not valid for this revision artifact.', 'wp-autoplugin' ) );
		}
		$slug     = sanitize_title( (string) ( $job['payload']['destination_slug'] ?? $revision['project_manifest']['plugin_name'] ?? $workspace['target_ref'] ?? '' ) );
		$release  = new Release_Repository();
		$existing = $release->package_by_job( (int) $job['id'] );
		if ( $existing && 'ready' === $existing['status'] ) {
			return $this->result( $existing );
		}
		$target_ref = 'project' === $mode ? null : (string) $workspace['target_ref'];
		$package    = $existing ?: $release->create_package( $job, $revision, $mode, $slug, $target_ref, ! empty( $job['payload']['review_override'] ), $kind );
		$jobs       = new Job_Repository();
		$jobs->update( (int) $job['id'], [ 'progress' => 20 ] );
		$jobs->event(
			(int) $job['id'],
			'package_started',
			__( 'Building a private revision-bound release package.', 'wp-autoplugin' ),
			[
				'package_id'    => (int) $package['id'],
				'mode'          => $mode,
				'revision_id'   => (int) $revision['id'],
				'artifact_kind' => $kind,
			]
		);
		$built = 'theme' === $kind
			? ( new Theme_Package_Builder() )->build( $workspace, $revision, 'replacement', $slug )
			: ( new Package_Builder() )->build( $workspace, $revision, $mode, $slug );
		if ( is_wp_error( $built ) ) {
			$release->fail_package( (int) $package['id'], $built->get_error_message() );
			return $built;
		}
		$latest = $jobs->find( (int) $job['id'] );
		if ( ! $latest || $latest['cancel_requested'] ) {
			wp_delete_file( $built['path'] );
			$release->cancel_package( (int) $package['id'] );
			$jobs->update(
				(int) $job['id'],
				[
					'status'      => 'cancelled',
					'finished_at' => current_time( 'mysql', true ),
				]
			);
			$jobs->event( (int) $job['id'], 'cancelled', __( 'Package creation was cancelled before publishing the private artifact.', 'wp-autoplugin' ) );
			return [ '_continuation' => true ];
		}
		if ( ! $release->complete_package( (int) $package['id'], $built['path'], $built['sha256'], (int) $built['size'], $built ) ) {
			wp_delete_file( $built['path'] );
			$release->fail_package( (int) $package['id'], __( 'The completed package metadata could not be saved.', 'wp-autoplugin' ) );
			return new \WP_Error( 'release_package_save', __( 'The completed package metadata could not be saved.', 'wp-autoplugin' ) );
		}
		$package = $release->package( (int) $package['id'] );
		$jobs->event(
			(int) $job['id'],
			'package_ready',
			__( 'The private release ZIP is ready for authenticated download.', 'wp-autoplugin' ),
			[
				'package_id'    => (int) $package['id'],
				'sha256'        => $package['sha256'],
				'size'          => (int) $package['size'],
				'artifact_kind' => $kind,
			]
		);
		return $this->result( $package );
	}

	private function result( array $package ): array {
		return [
			'outcome'           => 'package',
			'package_id'        => (int) $package['id'],
			'revision_id'       => (int) $package['revision_id'],
			'mode'              => $package['mode'],
			'status'            => $package['status'],
			'artifact_kind'     => $package['artifact_kind'],
			'slug'              => $package['slug'],
			'target_ref'        => $package['target_ref'],
			'sha256'            => $package['sha256'],
			'size'              => (int) $package['size'],
			'header_transforms' => $package['header_transforms'],
			'expires_at'        => $package['expires_at'],
		];
	}
}
