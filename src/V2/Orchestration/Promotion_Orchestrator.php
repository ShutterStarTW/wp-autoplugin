<?php

namespace WP_Autoplugin\V2\Orchestration;

use WP_Autoplugin\V2\Infrastructure\Database\Job_Repository;
use WP_Autoplugin\V2\Infrastructure\Database\Release_Repository;
use WP_Autoplugin\V2\Infrastructure\Database\Revision_Repository;
use WP_Autoplugin\V2\Infrastructure\Database\Workspace_Repository;
use WP_Autoplugin\V2\Release\Promotion_Service;
use WP_Autoplugin\V2\Release\Release_Matrix;
use WP_Autoplugin\V2\Release\Theme_Promotion_Service;

/** Runs one install/fork/in-place promotion as durable artifact work. */
final class Promotion_Orchestrator {
	public function register(): void {
		add_filter( 'wp_autoplugin_v2_execute_job', [ $this, 'execute' ], 10, 2 );
	}

	public function execute( $result, array $job ) {
		if ( null !== $result || 'promotion' !== ( $job['task'] ?? '' ) ) {
			return $result;
		}
		$action = sanitize_key( (string) ( $job['payload']['action'] ?? '' ) );
		if ( in_array( $action, [ 'activate', 'rollback' ], true ) ) {
			$release   = new Release_Repository();
			$promotion = $release->promotion( absint( $job['payload']['promotion_id'] ?? 0 ) );
			if ( ! $promotion || (int) $promotion['workspace_id'] !== (int) $job['workspace_id'] ) {
				return new \WP_Error( 'promotion_action_missing', __( 'The promotion for this action is unavailable.', 'wp-autoplugin' ) );
			}
			$jobs = new Job_Repository();
			$kind = (string) ( $promotion['artifact_kind'] ?? 'plugin' );
			if ( 'activate' === $action && 'theme' === $kind ) {
				return new \WP_Error( 'theme_promotion_activation', __( 'Theme switching is not performed by WP-Autoplugin.', 'wp-autoplugin' ) );
			}
			$jobs->event( (int) $job['id'], 'promotion_action_started', 'activate' === $action ? __( 'Plugin activation started.', 'wp-autoplugin' ) : __( 'Conflict-safe file rollback started.', 'wp-autoplugin' ), [ 'promotion_id' => (int) $promotion['id'], 'action' => $action, 'artifact_kind' => $kind ] );
			if ( 'activate' === $action ) {
				$operation = ( new Promotion_Service() )->activate( $promotion );
			} else {
				$operation = 'theme' === $kind
					? ( new Theme_Promotion_Service() )->rollback( $promotion )
					: ( new Promotion_Service() )->rollback( $promotion );
			}
			if ( is_wp_error( $operation ) ) {
				return $operation;
			}
			$jobs->event( (int) $job['id'], 'promotion_action_completed', 'activate' === $action ? __( 'Plugin activation completed.', 'wp-autoplugin' ) : __( 'File rollback completed.', 'wp-autoplugin' ), [ 'promotion_id' => (int) $promotion['id'], 'action' => $action, 'status' => $operation['status'] ] );
			return array_merge(
				[
					'outcome'       => 'promotion_action',
					'promotion_id'  => (int) $promotion['id'],
					'action'        => $action,
					'artifact_kind' => $kind,
					'target_ref'    => $operation['target_ref'] ?? $operation['plugin_file'] ?? $promotion['destination_target_ref'] ?? null,
				],
				$operation
			);
		}
		$revisions = new Revision_Repository();
		$workspace = ( new Workspace_Repository() )->find( (int) $job['workspace_id'] );
		$revision  = $revisions->find( absint( $job['payload']['revision_id'] ?? 0 ) );
		if ( ! $workspace || ! $revision || (int) $revision['workspace_id'] !== (int) $workspace['id'] || (int) $revision['id'] !== $revisions->latest_id( (int) $workspace['id'] ) ) {
			return new \WP_Error( 'promotion_revision_conflict', __( 'Only the latest staged revision can be promoted.', 'wp-autoplugin' ) );
		}
		$mode = sanitize_key( (string) ( $job['payload']['mode'] ?? '' ) );
		if ( ! in_array( $mode, [ 'install_project', 'install_fork', 'modify_original', 'install_theme_copy', 'modify_theme_original' ], true ) ) {
			return new \WP_Error( 'promotion_mode', __( 'The requested promotion mode is invalid.', 'wp-autoplugin' ) );
		}
		$kind = (string) ( $revision['project_manifest']['artifact_kind'] ?? 'plugin' );
		if ( ! Release_Matrix::allows( 'promotion', (string) ( $revision['project_manifest']['scope'] ?? '' ), $kind, $mode ) ) {
			return new \WP_Error( 'promotion_matrix', __( 'That promotion mode is not valid for this revision artifact.', 'wp-autoplugin' ) );
		}
		if ( in_array( $mode, [ 'modify_original', 'modify_theme_original' ], true ) && (string) ( $job['payload']['target_confirmation'] ?? '' ) !== (string) $workspace['target_ref'] ) {
			return new \WP_Error( 'promotion_confirmation', __( 'Direct modification requires the exact target reference as confirmation.', 'wp-autoplugin' ) );
		}
		$release   = new Release_Repository();
		$promotion = $release->promotion_by_job( (int) $job['id'] );
		$slug      = sanitize_title( (string) ( $job['payload']['destination_slug'] ?? '' ) );
		$source    = 'install_project' === $mode ? null : (string) $workspace['target_ref'];
		$destination = in_array( $mode, [ 'modify_original', 'modify_theme_original' ], true ) ? (string) $workspace['target_ref'] : null;
		if ( ! $promotion ) {
			$promotion = $release->create_promotion( $job, $revision, $mode, $source, $destination, $slug ?: null, ! empty( $job['payload']['review_override'] ), $kind );
		}
		$jobs = new Job_Repository();
		$jobs->update( (int) $job['id'], [ 'progress' => 15 ] );
		$jobs->event( (int) $job['id'], 'promotion_started', __( 'The release promotion preflight started.', 'wp-autoplugin' ), [ 'promotion_id' => (int) $promotion['id'], 'mode' => $mode, 'revision_id' => (int) $revision['id'], 'artifact_kind' => $kind ] );

		$service = 'theme' === $kind ? new Theme_Promotion_Service() : new Promotion_Service();
		try {
			if ( 'modify_original' === $mode || 'modify_theme_original' === $mode ) {
				$operation = $service->modify( $promotion, $workspace, $revision );
			} elseif ( 'install_theme_copy' === $mode ) {
				$operation = $service->install_copy( $promotion, $workspace, $revision, $slug );
			} else {
				$operation = $service->install( $promotion, $workspace, $revision, 'install_fork' === $mode ? 'fork' : 'project', $slug );
			}
		} catch ( \Throwable $error ) {
			$release->update_promotion( (int) $promotion['id'], [ 'status' => 'failed', 'error_message' => $error->getMessage(), 'finished_at' => current_time( 'mysql', true ) ] );
			return new \WP_Error( 'promotion_failed', $error->getMessage() );
		}
		if ( is_wp_error( $operation ) ) {
			$current = $release->promotion( (int) $promotion['id'] );
			if ( $current && 'running' === $current['status'] ) {
				$release->update_promotion( (int) $promotion['id'], [ 'status' => 'failed', 'error_message' => $operation->get_error_message(), 'finished_at' => current_time( 'mysql', true ) ] );
			}
			return $operation;
		}
		$operation['artifact_kind'] = $kind;
		$operation['target_ref'] = $operation['target_ref'] ?? $operation['plugin_file'] ?? $destination;
		$jobs->event( (int) $job['id'], 'promotion_completed', __( 'The release promotion completed.', 'wp-autoplugin' ), [ 'promotion_id' => (int) $promotion['id'], 'status' => $operation['status'], 'target_ref' => $operation['target_ref'] ?? $operation['plugin_file'] ?? '', 'artifact_kind' => $kind ] );
		return array_merge( [ 'outcome' => 'promotion', 'promotion_id' => (int) $promotion['id'], 'revision_id' => (int) $revision['id'], 'mode' => $mode, 'artifact_kind' => $kind ], $operation );
	}
}
