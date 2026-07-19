<?php

namespace WP_Autoplugin\V2\Orchestration;

use WP_Autoplugin\V2\Infrastructure\Database\Job_Repository;
use WP_Autoplugin\V2\Infrastructure\Database\Release_Repository;
use WP_Autoplugin\V2\Infrastructure\Database\Revision_Repository;
use WP_Autoplugin\V2\Infrastructure\Database\Workspace_Repository;
use WP_Autoplugin\V2\Release\Promotion_Service;

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
			$jobs->event( (int) $job['id'], 'promotion_action_started', 'activate' === $action ? __( 'Plugin activation started.', 'wp-autoplugin' ) : __( 'Conflict-safe file rollback started.', 'wp-autoplugin' ), [ 'promotion_id' => (int) $promotion['id'], 'action' => $action ] );
			$operation = 'activate' === $action ? ( new Promotion_Service() )->activate( $promotion ) : ( new Promotion_Service() )->rollback( $promotion );
			if ( is_wp_error( $operation ) ) {
				return $operation;
			}
			$jobs->event( (int) $job['id'], 'promotion_action_completed', 'activate' === $action ? __( 'Plugin activation completed.', 'wp-autoplugin' ) : __( 'File rollback completed.', 'wp-autoplugin' ), [ 'promotion_id' => (int) $promotion['id'], 'action' => $action, 'status' => $operation['status'] ] );
			return array_merge( [ 'outcome' => 'promotion_action', 'promotion_id' => (int) $promotion['id'], 'action' => $action ], $operation );
		}
		$revisions = new Revision_Repository();
		$workspace = ( new Workspace_Repository() )->find( (int) $job['workspace_id'] );
		$revision  = $revisions->find( absint( $job['payload']['revision_id'] ?? 0 ) );
		if ( ! $workspace || ! $revision || (int) $revision['workspace_id'] !== (int) $workspace['id'] || (int) $revision['id'] !== $revisions->latest_id( (int) $workspace['id'] ) ) {
			return new \WP_Error( 'promotion_revision_conflict', __( 'Only the latest staged revision can be promoted.', 'wp-autoplugin' ) );
		}
		$mode = sanitize_key( (string) ( $job['payload']['mode'] ?? '' ) );
		if ( ! in_array( $mode, [ 'install_project', 'install_fork', 'modify_original' ], true ) ) {
			return new \WP_Error( 'promotion_mode', __( 'The requested promotion mode is invalid.', 'wp-autoplugin' ) );
		}
		$release   = new Release_Repository();
		$promotion = $release->promotion_by_job( (int) $job['id'] );
		$slug      = sanitize_title( (string) ( $job['payload']['destination_slug'] ?? '' ) );
		$source    = 'install_project' === $mode ? null : (string) $workspace['target_ref'];
		$destination = 'modify_original' === $mode ? (string) $workspace['target_ref'] : null;
		if ( ! $promotion ) {
			$promotion = $release->create_promotion( $job, $revision, $mode, $source, $destination, $slug ?: null, ! empty( $job['payload']['review_override'] ) );
		}
		$jobs = new Job_Repository();
		$jobs->update( (int) $job['id'], [ 'progress' => 15 ] );
		$jobs->event( (int) $job['id'], 'promotion_started', __( 'The plugin promotion preflight started.', 'wp-autoplugin' ), [ 'promotion_id' => (int) $promotion['id'], 'mode' => $mode, 'revision_id' => (int) $revision['id'] ] );

		$service = new Promotion_Service();
		try {
			if ( 'modify_original' === $mode ) {
				$operation = $service->modify( $promotion, $workspace, $revision );
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
		$jobs->event( (int) $job['id'], 'promotion_completed', __( 'The plugin promotion completed.', 'wp-autoplugin' ), [ 'promotion_id' => (int) $promotion['id'], 'status' => $operation['status'], 'plugin_file' => $operation['plugin_file'] ] );
		return array_merge( [ 'outcome' => 'promotion', 'promotion_id' => (int) $promotion['id'], 'revision_id' => (int) $revision['id'], 'mode' => $mode ], $operation );
	}
}
