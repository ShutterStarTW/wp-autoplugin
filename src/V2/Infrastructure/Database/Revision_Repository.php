<?php

namespace WP_Autoplugin\V2\Infrastructure\Database;

use WP_Autoplugin\V2\Domain\Revision\Code_Validator;
use WP_Autoplugin\V2\Domain\Target\Source_Tools;

/** Persists and reads immutable validated staged revisions. */
final class Revision_Repository extends Repository {
	/**
	 * Backward-compatible staging entry point.
	 *
	 * @param array<int, array<string, mixed>> $files Staged files.
	 * @return array<string, mixed>
	 */
	public function stage( int $workspace_id, array $files, string $summary, int $user_id ): array {
		$result = $this->create_complete( $workspace_id, $files, $summary, $user_id, [ 'origin' => 'ai' ], null, false );
		if ( is_wp_error( $result ) ) {
			throw new \RuntimeException( $result->get_error_message() );
		}
		return $this->find( (int) $result['id'] );
	}

	/** Atomically stage a completed Code run and scrub its temporary source. */
	public function stage_code_run( array $run, array $manifest, int $workspace_id, int $user_id, ?int $expected_latest_revision_id, array $source_files = [] ) {
		$run_files = ( new Code_Run_Repository( $this->wpdb ) )->files( (int) $run['id'] );
		$source     = [];
		foreach ( $source_files as $file ) {
			$source[ (string) ( $file['path'] ?? '' ) ] = (string) ( $file['content'] ?? '' );
		}
		$files = array_map(
			static function ( array $file ) use ( $source ): array {
				$operation = (string) ( $file['operation'] ?? 'add' );
				$base      = in_array( $operation, [ 'update', 'delete' ], true ) ? (string) ( $source[ $file['path'] ] ?? '' ) : null;
				return [
					'path'              => $file['path'],
					'type'              => $file['type'],
					'change_type'       => $operation,
					'content'           => 'delete' === $operation ? '' : (string) $file['content'],
					'base_content'      => $base,
					'base_content_hash' => null === $base ? null : hash( 'sha256', $base ),
				];
			},
			$run_files
		);
		$issues = ( new Code_Validator() )->project_issues( $files, $manifest );
		if ( $issues ) {
			return new \WP_Error( 'code_project_invalid', $issues[0]['message'], [ 'status' => 422, 'issues' => $issues ] );
		}

		$this->wpdb->query( 'START TRANSACTION' );
		try {
			$latest = $this->locked_latest_id( $workspace_id );
			if ( $latest !== $expected_latest_revision_id ) {
				$this->wpdb->query( 'ROLLBACK' );
				return $this->conflict();
			}
			$summary = 'changes' === ( $manifest['scope'] ?? '' )
				? __( 'AI-generated target changes.', 'wp-autoplugin' )
				: ( 'hook_extension' === ( $manifest['operation'] ?? '' ) ? __( 'AI-generated extension plugin code.', 'wp-autoplugin' ) : __( 'AI-generated plugin code.', 'wp-autoplugin' ) );
			$revision = $this->insert_complete(
				$workspace_id,
				$files,
				$summary,
				$user_id,
				[
					'origin'             => 'ai',
					'plan_job_id'        => (int) $run['plan_job_id'],
					'source_job_id'      => (int) $run['job_id'],
					'parent_revision_id' => $run['parent_revision_id'],
				],
				$manifest
			);
			$run_updated = $this->wpdb->update(
				Installer::table( 'code_runs' ),
				[ 'status' => 'completed', 'phase' => 'completed', 'outcome' => 'revision', 'revision_id' => $revision['id'], 'lease_token' => null, 'lease_expires_at' => null, 'updated_at' => $this->now() ],
				[ 'id' => $run['id'] ]
			);
			$scrubbed = $this->wpdb->query(
				$this->wpdb->prepare( 'UPDATE ' . Installer::table( 'code_run_files' ) . ' SET content = NULL, updated_at = %s WHERE run_id = %d', $this->now(), $run['id'] )
			); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Prepared immediately above.
			if ( false === $run_updated || false === $scrubbed ) {
				throw new \RuntimeException( __( 'Could not finalize private Code generation state.', 'wp-autoplugin' ) );
			}
			$this->wpdb->query( 'COMMIT' );
			return $revision;
		} catch ( \Throwable $error ) {
			$this->wpdb->query( 'ROLLBACK' );
			throw $error;
		}
	}

	/**
	 * Atomically stage a topology-aware Code follow-up successor.
	 *
	 * @param array<string, mixed>             $run      Durable Code run.
	 * @param array<string, mixed>             $manifest Normalized desired manifest.
	 * @param array<int, array<string, mixed>> $files    Complete merged project.
	 */
	public function stage_code_follow_up( array $run, array $manifest, array $files, int $workspace_id, int $user_id, int $expected_latest_revision_id, string $summary ) {
		$issues = ( new Code_Validator() )->project_issues( $files, $manifest );
		if ( $issues ) {
			return new \WP_Error( 'code_project_invalid', $issues[0]['message'], [ 'status' => 422, 'issues' => $issues ] );
		}

		$this->wpdb->query( 'START TRANSACTION' );
		try {
			if ( $this->locked_latest_id( $workspace_id ) !== $expected_latest_revision_id ) {
				$this->wpdb->query( 'ROLLBACK' );
				return $this->conflict();
			}
			$revision = $this->insert_complete(
				$workspace_id,
				array_map( [ $this, 'normalize_file' ], $files ),
				$summary,
				$user_id,
				[
					'origin'             => 'ai',
					'plan_job_id'        => (int) $run['plan_job_id'],
					'source_job_id'      => (int) $run['job_id'],
					'parent_revision_id' => $expected_latest_revision_id,
				],
				$manifest
			);
			$run_updated = $this->wpdb->update(
				Installer::table( 'code_runs' ),
				[ 'status' => 'completed', 'phase' => 'completed', 'outcome' => 'revision', 'revision_id' => $revision['id'], 'lease_token' => null, 'lease_expires_at' => null, 'updated_at' => $this->now() ],
				[ 'id' => $run['id'] ]
			);
			$scrubbed = $this->wpdb->query(
				$this->wpdb->prepare( 'UPDATE ' . Installer::table( 'code_run_files' ) . ' SET content = NULL, updated_at = %s WHERE run_id = %d', $this->now(), $run['id'] )
			); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Prepared immediately above.
			if ( false === $run_updated || false === $scrubbed ) {
				throw new \RuntimeException( __( 'Could not finalize private Code follow-up state.', 'wp-autoplugin' ) );
			}
			$this->wpdb->query( 'COMMIT' );
			return $revision;
		} catch ( \Throwable $error ) {
			$this->wpdb->query( 'ROLLBACK' );
			throw $error;
		}
	}

	/** @return array<int, array<string, mixed>> */
	public function list_for_workspace( int $workspace_id ): array {
		$revisions = Installer::table( 'revisions' );
		$files     = Installer::table( 'revision_files' );
		$rows      = $this->wpdb->get_results(
			$this->wpdb->prepare(
				"SELECT r.*, COUNT(f.id) AS files_count, COALESCE(SUM(OCTET_LENGTH(f.content)),0) AS aggregate_size,
				SUM(CASE WHEN f.change_type = 'add' THEN 1 ELSE 0 END) AS adds,
				SUM(CASE WHEN f.change_type = 'update' THEN 1 ELSE 0 END) AS updates,
				SUM(CASE WHEN f.change_type = 'delete' THEN 1 ELSE 0 END) AS deletes
				FROM $revisions r LEFT JOIN $files f ON f.revision_id = r.id WHERE r.workspace_id = %d
				GROUP BY r.id ORDER BY r.revision_number DESC",
				$workspace_id
			), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Internal allow-listed tables.
			ARRAY_A
		);
		return array_map(
			function ( array $row ): array {
				$row = $this->hydrate_summary( $row );
				unset( $row['project_manifest'] );
				return $row;
			},
			$rows
		);
	}

	public function latest_id( int $workspace_id ): ?int {
		$id = $this->wpdb->get_var(
			$this->wpdb->prepare( 'SELECT id FROM ' . Installer::table( 'revisions' ) . ' WHERE workspace_id = %d ORDER BY revision_number DESC LIMIT 1', $workspace_id )
		);
		return $id ? (int) $id : null;
	}

	/** Manifest metadata only; never includes file bodies. */
	public function manifest( int $id ): ?array {
		$revision = $this->wpdb->get_row(
			$this->wpdb->prepare( 'SELECT * FROM ' . Installer::table( 'revisions' ) . ' WHERE id = %d', $id ),
			ARRAY_A
		);
		if ( ! $revision ) {
			return null;
		}
		$revision = $this->hydrate_summary( $revision );
		$effective_manifest = $this->revision_manifest( $revision );
		$plan_manifest      = $this->plan_manifest( (int) ( $revision['plan_job_id'] ?? 0 ) );
		$revision['project_manifest']      = is_wp_error( $effective_manifest ) ? null : $effective_manifest;
		$revision['plan_structure_matches'] = ! is_wp_error( $effective_manifest ) && ! is_wp_error( $plan_manifest ) && $this->same_structure( $effective_manifest, $plan_manifest );
		$files    = $this->wpdb->get_results(
			$this->wpdb->prepare( 'SELECT id, path, change_type, content_hash, OCTET_LENGTH(content) AS size FROM ' . Installer::table( 'revision_files' ) . ' WHERE revision_id = %d ORDER BY id ASC', $id ),
			ARRAY_A
		);
		$revision['files']          = array_map( [ $this, 'hydrate_manifest_file' ], $files );
		$revision['files_count']    = count( $files );
		$revision['aggregate_size'] = array_sum( array_column( $revision['files'], 'size' ) );
		$revision['adds']           = count( array_filter( $revision['files'], static fn( array $file ): bool => 'add' === $file['change_type'] ) );
		$revision['updates']        = count( array_filter( $revision['files'], static fn( array $file ): bool => 'update' === $file['change_type'] ) );
		$revision['deletes']        = count( array_filter( $revision['files'], static fn( array $file ): bool => 'delete' === $file['change_type'] ) );
		$revision['validation']     = [ 'status' => 'valid', 'issues' => [] ];
		return $revision;
	}

	/** Full private record for server-side operations. */
	public function find( int $id ): ?array {
		$revision = $this->manifest( $id );
		if ( ! $revision ) {
			return null;
		}
		$revision['files'] = $this->files_with_content( $id );
		return $revision;
	}

	/** Return one requested source body and its parent-relative diff. */
	public function file( int $revision_id, int $file_id ): ?array {
		$revision = $this->manifest( $revision_id );
		if ( ! $revision ) {
			return null;
		}
		$file = $this->wpdb->get_row(
			$this->wpdb->prepare( 'SELECT id, revision_id, path, change_type, content, content_hash, base_content FROM ' . Installer::table( 'revision_files' ) . ' WHERE id = %d AND revision_id = %d', $file_id, $revision_id ),
			ARRAY_A
		);
		if ( ! $file ) {
			return null;
		}
		$before = 'changes' === ( $revision['project_manifest']['scope'] ?? '' ) ? (string) $file['base_content'] : '';
		if ( 'changes' !== ( $revision['project_manifest']['scope'] ?? '' ) && $revision['parent_revision_id'] ) {
			$before = (string) $this->wpdb->get_var(
				$this->wpdb->prepare( 'SELECT content FROM ' . Installer::table( 'revision_files' ) . ' WHERE revision_id = %d AND path = %s', $revision['parent_revision_id'], $file['path'] )
			);
		}
		$file['id']          = (int) $file['id'];
		$file['revision_id'] = (int) $file['revision_id'];
		$file['size']        = strlen( (string) $file['content'] );
		$file['diff_html']   = $this->diff_html( $before, (string) $file['content'] );
		unset( $file['base_content'] );
		return $file;
	}

	/**
	 * Create one validated full successor from a multi-file content edit session.
	 *
	 * @param array<int, array<string, mixed>> $changes Submitted changed buffers.
	 */
	public function save_successor( int $base_revision_id, int $expected_latest_revision_id, array $changes, int $user_id ) {
		$base = $this->find( $base_revision_id );
		if ( ! $base || $base_revision_id !== $expected_latest_revision_id || $this->latest_id( (int) $base['workspace_id'] ) !== $expected_latest_revision_id ) {
			return $this->conflict();
		}
		$changed = [];
		foreach ( $changes as $change ) {
			$path = is_array( $change ) ? (string) ( $change['path'] ?? '' ) : '';
			if ( '' === $path || ! array_key_exists( 'content', $change ) || isset( $changed[ $path ] ) ) {
				return new \WP_Error( 'revision_changes_invalid', __( 'The edit session contains invalid or duplicate file changes.', 'wp-autoplugin' ), [ 'status' => 400 ] );
			}
			$changed[ $path ] = [
				'content'           => (string) $change['content'],
				'base_content_hash' => is_string( $change['base_content_hash'] ?? null ) ? $change['base_content_hash'] : '',
			];
		}
		if ( ! $changed ) {
			return new \WP_Error( 'revision_changes_empty', __( 'No changed file contents were submitted.', 'wp-autoplugin' ), [ 'status' => 400 ] );
		}
		$manifest = $base['project_manifest'] ?? $this->revision_manifest( $base );
		if ( is_wp_error( $manifest ) ) {
			return $manifest;
		}

		$files = [];
		foreach ( $base['files'] as $file ) {
			$content = array_key_exists( $file['path'], $changed ) ? $changed[ $file['path'] ]['content'] : (string) $file['content'];
			unset( $changed[ $file['path'] ] );
			$files[] = [
				'path'              => $file['path'],
				'type'              => strtolower( (string) pathinfo( $file['path'], PATHINFO_EXTENSION ) ),
				'change_type'       => $file['change_type'],
				'content'           => $content,
				'base_content'      => $file['base_content'] ?? null,
				'base_content_hash' => $file['base_content_hash'] ?? null,
			];
		}
		if ( $changed ) {
			if ( 'changes' !== ( $manifest['scope'] ?? '' ) || count( $manifest['files'] ) + count( $changed ) > Code_Validator::MAX_FILES ) {
				return new \WP_Error( 'revision_topology_change', __( 'This Code slice does not allow files to be added, removed, renamed, or moved.', 'wp-autoplugin' ), [ 'status' => 422 ] );
			}
			$workspace = ( new Workspace_Repository( $this->wpdb ) )->find( (int) $base['workspace_id'] );
			try {
				$tools = new Source_Tools( (array) ( $workspace['target_metadata'] ?? [] ) );
			} catch ( \Throwable $error ) {
				return new \WP_Error( 'revision_target_unavailable', __( 'The installed target is no longer available for revision editing.', 'wp-autoplugin' ), [ 'status' => 409 ] );
			}
			if ( (string) ( $manifest['target_fingerprint'] ?? '' ) !== $tools->tree_fingerprint() ) {
				return new \WP_Error( 'revision_target_changed', __( 'The installed target changed after this revision was staged. Regenerate Code before saving edits.', 'wp-autoplugin' ), [ 'status' => 409 ] );
			}

			foreach ( $changed as $path => $change ) {
				$source = $tools->revision_file( $path );
				if ( is_wp_error( $source ) ) {
					return new \WP_Error( 'revision_target_file_invalid', __( 'An edited target file is no longer available or safe to stage.', 'wp-autoplugin' ), [ 'status' => 422 ] );
				}
				if ( $path !== $source['path'] || $source['content_hash'] !== $change['base_content_hash'] ) {
					return new \WP_Error( 'revision_target_file_changed', __( 'An edited target file changed after it was loaded. Reload the latest source before saving.', 'wp-autoplugin' ), [ 'status' => 409 ] );
				}
				$files[] = [
					'path'              => $source['path'],
					'type'              => $source['type'],
					'change_type'       => 'update',
					'content'           => $change['content'],
					'base_content'      => $source['content'],
					'base_content_hash' => $source['content_hash'],
				];
				$manifest['files'][] = [
					'path'        => $source['path'],
					'type'        => $source['type'],
					'description' => __( 'Administrator-edited target file.', 'wp-autoplugin' ),
					'operation'   => 'update',
				];
				$manifest['base_hashes'][ $source['path'] ] = $source['content_hash'];
			}
			$manifest = ( new Code_Validator() )->manifest( $manifest );
			if ( is_wp_error( $manifest ) ) {
				return new \WP_Error( 'revision_manifest_invalid', __( 'The edited target files could not be added to this revision safely.', 'wp-autoplugin' ), [ 'status' => 422 ] );
			}
		}
		$issues = ( new Code_Validator() )->project_issues( $files, $manifest, Code_Validator::MAX_MANUAL_FILE_BYTES );
		if ( $issues ) {
			return new \WP_Error( 'revision_validation_failed', __( 'The edited project did not pass validation.', 'wp-autoplugin' ), [ 'status' => 422, 'issues' => $issues ] );
		}

		return $this->create_complete(
			(int) $base['workspace_id'],
			$files,
			__( 'Administrator code edit.', 'wp-autoplugin' ),
			$user_id,
			[ 'origin' => 'manual', 'plan_job_id' => (int) $base['plan_job_id'], 'parent_revision_id' => $base_revision_id ],
			$expected_latest_revision_id,
			true,
			$manifest
		);
	}

	/** Restore historical contents by copying them into a new immutable successor. */
	public function restore( int $selected_revision_id, int $expected_latest_revision_id, int $user_id ) {
		$selected = $this->find( $selected_revision_id );
		if ( ! $selected || $this->latest_id( (int) $selected['workspace_id'] ) !== $expected_latest_revision_id ) {
			return $this->conflict();
		}
		if ( $selected_revision_id === $expected_latest_revision_id ) {
			return new \WP_Error( 'revision_restore_latest', __( 'Select an older revision to restore as latest.', 'wp-autoplugin' ), [ 'status' => 409 ] );
		}
		$files = array_map(
			static fn( array $file ): array => [
				'path' => $file['path'], 'type' => strtolower( (string) pathinfo( $file['path'], PATHINFO_EXTENSION ) ), 'change_type' => $file['change_type'], 'content' => (string) $file['content'],
				'base_content' => $file['base_content'] ?? null, 'base_content_hash' => $file['base_content_hash'] ?? null,
			],
			$selected['files']
		);
		$manifest = $selected['project_manifest'] ?? $this->revision_manifest( $selected );
		if ( is_wp_error( $manifest ) ) {
			return $manifest;
		}
		$issues = ( new Code_Validator() )->project_issues( $files, $manifest, Code_Validator::MAX_MANUAL_FILE_BYTES );
		if ( $issues ) {
			return new \WP_Error( 'revision_restore_invalid', __( 'The selected historical revision no longer passes validation.', 'wp-autoplugin' ), [ 'status' => 422, 'issues' => $issues ] );
		}
		return $this->create_complete(
			(int) $selected['workspace_id'],
			$files,
			__( 'Restored historical revision.', 'wp-autoplugin' ),
			$user_id,
			[
				'origin'                    => 'restore',
				'plan_job_id'               => (int) $selected['plan_job_id'],
				'parent_revision_id'        => $expected_latest_revision_id,
				'restored_from_revision_id' => $selected_revision_id,
			],
			$expected_latest_revision_id,
			true,
			$manifest
		);
	}

	/**
	 * @param array<int, array<string, mixed>> $files
	 * @param array<string, mixed>             $provenance
	 */
	private function create_complete( int $workspace_id, array $files, string $summary, int $user_id, array $provenance, ?int $expected_latest_revision_id, bool $enforce_expected = true, ?array $project_manifest = null ) {
		$files = array_map( [ $this, 'normalize_file' ], $files );
		$paths = array_column( $files, 'path' );
		if ( ! $files || count( $paths ) !== count( array_unique( $paths ) ) ) {
			return new \WP_Error( 'revision_files_invalid', __( 'A revision requires unique, safe file paths.', 'wp-autoplugin' ), [ 'status' => 422 ] );
		}
		$this->wpdb->query( 'START TRANSACTION' );
		try {
			$this->wpdb->get_var(
				$this->wpdb->prepare( 'SELECT id FROM ' . Installer::table( 'workspaces' ) . ' WHERE id = %d FOR UPDATE', $workspace_id )
			);
			if ( ( new Job_Repository( $this->wpdb ) )->has_active_code( $workspace_id ) ) {
				$this->wpdb->query( 'ROLLBACK' );
				return new \WP_Error( 'code_work_active', __( 'Wait for the active Code work to finish before changing revision history.', 'wp-autoplugin' ), [ 'status' => 409 ] );
			}
			$latest = $this->locked_latest_id( $workspace_id );
			if ( $enforce_expected && $latest !== $expected_latest_revision_id ) {
				$this->wpdb->query( 'ROLLBACK' );
				return $this->conflict();
			}
			$revision = $this->insert_complete( $workspace_id, $files, $summary, $user_id, $provenance, $project_manifest );
			$this->wpdb->query( 'COMMIT' );
			return $revision;
		} catch ( \Throwable $error ) {
			$this->wpdb->query( 'ROLLBACK' );
			throw $error;
		}
	}

	/** @param array<int, array<string, mixed>> $files @param array<string, mixed> $provenance */
	private function insert_complete( int $workspace_id, array $files, string $summary, int $user_id, array $provenance, ?array $project_manifest = null ): array {
		$revisions = Installer::table( 'revisions' );
		$number    = (int) $this->wpdb->get_var(
			$this->wpdb->prepare( "SELECT COALESCE(MAX(revision_number), 0) + 1 FROM $revisions WHERE workspace_id = %d", $workspace_id ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Internal allow-listed table.
		);
		$now = $this->now();
		$this->wpdb->insert(
			$revisions,
			[
				'workspace_id'              => $workspace_id,
				'revision_number'           => $number,
				'status'                    => 'staged',
				'summary'                   => $summary,
				'origin'                    => sanitize_key( (string) ( $provenance['origin'] ?? 'ai' ) ),
				'plan_job_id'               => $provenance['plan_job_id'] ?? null,
				'source_job_id'             => $provenance['source_job_id'] ?? null,
				'parent_revision_id'        => $provenance['parent_revision_id'] ?? null,
				'restored_from_revision_id' => $provenance['restored_from_revision_id'] ?? null,
				'project_manifest'           => $project_manifest ? $this->json( $project_manifest ) : null,
				'created_by'                => $user_id,
				'created_at'                => $now,
			]
		);
		$revision_id = (int) $this->wpdb->insert_id;
		if ( ! $revision_id ) {
			throw new \RuntimeException( __( 'Could not create the staged revision.', 'wp-autoplugin' ) );
		}
		foreach ( $files as $file ) {
			$inserted = $this->wpdb->insert(
				Installer::table( 'revision_files' ),
				[
					'revision_id'      => $revision_id,
					'path'             => $file['path'],
					'change_type'      => $file['change_type'],
					'content'          => $file['content'],
					'patch'            => null,
					'content_hash'     => hash( 'sha256', $file['content'] ),
					'base_content'     => $file['base_content'],
					'base_content_hash' => $file['base_content_hash'],
				]
			);
			if ( false === $inserted ) {
				throw new \RuntimeException( __( 'Could not persist a complete revision file.', 'wp-autoplugin' ) );
			}
		}
		$workspace_updated = $this->wpdb->update( Installer::table( 'workspaces' ), [ 'status' => 'staged', 'updated_at' => $now ], [ 'id' => $workspace_id ] );
		if ( false === $workspace_updated ) {
			throw new \RuntimeException( __( 'Could not mark the workspace staged.', 'wp-autoplugin' ) );
		}
		return [ 'id' => $revision_id, 'revision_number' => $number, 'workspace_id' => $workspace_id, 'files_count' => count( $files ) ];
	}

	private function locked_latest_id( int $workspace_id ): ?int {
		$id = $this->wpdb->get_var(
			$this->wpdb->prepare( 'SELECT id FROM ' . Installer::table( 'revisions' ) . ' WHERE workspace_id = %d ORDER BY revision_number DESC LIMIT 1 FOR UPDATE', $workspace_id )
		);
		return $id ? (int) $id : null;
	}

	/** @return array<int, array<string, mixed>> */
	private function files_with_content( int $revision_id ): array {
		$rows = $this->wpdb->get_results(
			$this->wpdb->prepare( 'SELECT id, revision_id, path, change_type, content, content_hash, base_content, base_content_hash FROM ' . Installer::table( 'revision_files' ) . ' WHERE revision_id = %d ORDER BY id ASC', $revision_id ),
			ARRAY_A
		);
		return array_map(
			static function ( array $row ): array {
				$row['id'] = (int) $row['id']; $row['revision_id'] = (int) $row['revision_id']; return $row;
			},
			$rows
		);
	}

	private function plan_manifest( int $plan_job_id ) {
		$plan = ( new Job_Repository( $this->wpdb ) )->find( $plan_job_id );
		if ( ! $plan || !( new Job_Repository( $this->wpdb ) )->is_plan_artifact( $plan ) ) {
			return new \WP_Error( 'revision_plan_missing', __( 'The revision Plan artifact is unavailable.', 'wp-autoplugin' ), [ 'status' => 409 ] );
		}
		$workspace = ( new Workspace_Repository( $this->wpdb ) )->find( (int) $plan['workspace_id'] );
		$manifest  = ( new Code_Validator() )->plan( (array) ( $plan['result']['structured'] ?? [] ), $workspace ?: [] );
		if ( is_wp_error( $manifest ) ) {
			$manifest->add_data( [ 'status' => 409 ] );
		}
		return $manifest;
	}

	/** Return a normalized revision-owned manifest, with a Plan fallback for legacy rows. */
	private function revision_manifest( array $revision ) {
		$stored = $revision['project_manifest'] ?? null;
		if ( is_string( $stored ) && '' !== $stored ) {
			$stored = $this->decode( $stored );
		}
		if ( is_array( $stored ) && $stored ) {
			$manifest = ( new Code_Validator() )->manifest( $stored );
			if ( ! is_wp_error( $manifest ) ) {
				return $manifest;
			}
		}
		return $this->plan_manifest( (int) ( $revision['plan_job_id'] ?? 0 ) );
	}

	/** Compare topology without allowing generated prose descriptions to affect divergence. */
	private function same_structure( array $left, array $right ): bool {
		$shape = static function ( array $manifest ): array {
			$files = array_map(
				static fn( array $file ): array => [
					'path'      => (string) $file['path'],
					'type'      => (string) $file['type'],
					'operation' => (string) ( $file['operation'] ?? 'add' ),
				],
				(array) ( $manifest['files'] ?? [] )
			);
			usort( $files, static fn( array $a, array $b ): int => strcmp( $a['path'], $b['path'] ) );
			return [
				'scope'         => (string) ( $manifest['scope'] ?? 'project' ),
				'artifact_kind' => (string) ( $manifest['artifact_kind'] ?? 'plugin' ),
				'main_file'     => (string) ( $manifest['main_file'] ?? '' ),
				'files'         => $files,
			];
		};
		return $shape( $left ) === $shape( $right );
	}

	/** @param array<string, mixed> $file @return array<string, string> */
	private function normalize_file( array $file ): array {
		$path = str_replace( '\\', '/', trim( (string) ( $file['path'] ?? '' ) ) );
		$segments = explode( '/', $path );
		if ( '' === $path || str_starts_with( $path, '/' ) || preg_match( '/^[A-Za-z]:/', $path ) || array_intersect( [ '', '.', '..' ], $segments ) || preg_match( '/[\x00-\x1F]/', $path ) ) {
			throw new \InvalidArgumentException( __( 'Revision files must use safe paths relative to the target root.', 'wp-autoplugin' ) );
		}
		$change_type = sanitize_key( (string) ( $file['change_type'] ?? 'add' ) );
		if ( ! in_array( $change_type, [ 'add', 'update', 'delete' ], true ) ) {
			throw new \InvalidArgumentException( __( 'Unsupported revision change type.', 'wp-autoplugin' ) );
		}
		$base_content = array_key_exists( 'base_content', $file ) && null !== $file['base_content'] ? (string) $file['base_content'] : null;
		$base_hash    = null === $base_content ? null : hash( 'sha256', $base_content );
		if ( is_string( $file['base_content_hash'] ?? null ) && preg_match( '/^[a-f0-9]{64}$/', $file['base_content_hash'] ) ) {
			$base_hash = $file['base_content_hash'];
		}
		return [
			'path'              => $path,
			'change_type'       => $change_type,
			'content'           => (string) ( $file['content'] ?? '' ),
			'base_content'      => $base_content,
			'base_content_hash' => $base_hash,
		];
	}

	/** @param array<string, mixed> $row */
	private function hydrate_summary( array $row ): array {
		foreach ( [ 'id', 'workspace_id', 'revision_number', 'created_by', 'plan_job_id', 'source_job_id', 'parent_revision_id', 'restored_from_revision_id', 'files_count', 'aggregate_size', 'adds', 'updates', 'deletes' ] as $field ) {
			if ( array_key_exists( $field, $row ) ) {
				$row[ $field ] = null === $row[ $field ] ? null : (int) $row[ $field ];
			}
		}
		return $row;
	}

	/** @param array<string, mixed> $row */
	private function hydrate_manifest_file( array $row ): array {
		$row['id']   = (int) $row['id'];
		$row['size'] = (int) $row['size'];
		$row['type'] = strtolower( (string) pathinfo( $row['path'], PATHINFO_EXTENSION ) );
		return $row;
	}

	private function diff_html( string $before, string $after ): string {
		require_once ABSPATH . WPINC . '/wp-diff.php';
		$old      = '' === $before ? [] : ( preg_split( '/(?<=\n)/', $before ) ?: [] );
		$new      = '' === $after ? [] : ( preg_split( '/(?<=\n)/', $after ) ?: [] );
		$diff     = new \Text_Diff( 'auto', [ $old, $new ] );
		$renderer = new \WP_Text_Diff_Renderer_Table( [ 'show_split_view' => false ] );
		return wp_kses_post( (string) $renderer->render( $diff ) );
	}

	private function conflict(): \WP_Error {
		return new \WP_Error( 'revision_conflict', __( 'A newer revision exists. Reload the latest revision before retrying.', 'wp-autoplugin' ), [ 'status' => 409 ] );
	}
}
