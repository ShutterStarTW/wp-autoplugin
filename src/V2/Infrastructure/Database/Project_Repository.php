<?php

namespace WP_Autoplugin\V2\Infrastructure\Database;

use WP_Autoplugin\V2\Domain\Target\Target_Scanner;

/**
 * Persists the durable project aggregate shown as a workspace in the UI.
 */
final class Project_Repository extends Repository {
	/**
	 * @param array<string, mixed> $target Target snapshot.
	 * @return array{id:int}
	 */
	public function create( array $target, string $operation, string $request, int $user_id ): array {
		$now = $this->now();
		$result = $this->wpdb->insert(
			Installer::table( 'projects' ),
			[
				'name'            => $target['name'],
				'target_kind'     => $target['kind'],
				'target_ref'      => $target['ref'],
				'target_snapshot' => $this->json( $target ),
				'operation'       => $operation,
				'request'         => $request,
				'created_by'      => $user_id,
				'created_at'      => $now,
				'updated_at'      => $now,
			],
			[ '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s' ]
		);
		$id     = (int) $this->wpdb->insert_id;
		if ( false === $result || ! $id ) {
			throw new \RuntimeException( $this->persistence_error( 'project' ) );
		}

		return [ 'id' => $id ];
	}

	private function persistence_error( string $resource ): string {
		$message = sprintf( 'Could not persist %s.', $resource );
		if ( $this->wpdb->last_error ) {
			$message .= ' ' . $this->wpdb->last_error;
		}

		return $message;
	}

	/**
	 * @return array<string, mixed>|null
	 */
	public function find( int $id ): ?array {
		$projects = Installer::table( 'projects' );
		$jobs     = Installer::table( 'jobs' );
		$row      = $this->wpdb->get_row(
			$this->wpdb->prepare(
				"SELECT p.*, p.name AS project_name, p.target_snapshot AS target_metadata,
				(SELECT j.id FROM $jobs j WHERE j.project_id = p.id ORDER BY j.id DESC LIMIT 1) AS latest_job_id,
				(SELECT j.status FROM $jobs j WHERE j.project_id = p.id ORDER BY j.id DESC LIMIT 1) AS latest_job_status
				FROM $projects p WHERE p.id = %d",
				$id
			), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Tables are allow-listed internal names.
			ARRAY_A
		);

		if ( ! $row ) {
			return null;
		}

		return $this->hydrate( $row );
	}

	/**
	 * List workspace tabs that the current user has not closed.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function list_open( int $user_id ): array {
		$projects = Installer::table( 'projects' );
		$jobs     = Installer::table( 'jobs' );
		$rows     = $this->wpdb->get_results(
			$this->wpdb->prepare(
				"SELECT p.*, p.name AS project_name, p.target_snapshot AS target_metadata,
				(SELECT j.id FROM $jobs j WHERE j.project_id = p.id ORDER BY j.id DESC LIMIT 1) AS latest_job_id,
				(SELECT j.status FROM $jobs j WHERE j.project_id = p.id ORDER BY j.id DESC LIMIT 1) AS latest_job_status
				FROM $projects p
				WHERE p.created_by = %d AND p.is_closed = 0 ORDER BY p.updated_at DESC, p.id DESC",
				$user_id
			), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Tables are allow-listed internal names.
			ARRAY_A
		);

		return array_map( [ $this, 'hydrate' ], $rows );
	}

	/**
	 * Add compact job, retry, and workflow-stage summaries to workspaces.
	 *
	 * @param array<int, array<string, mixed>> $workspaces Hydrated workspace rows.
	 * @return array<int, array<string, mixed>>
	 */
	private function add_activity_summaries( array $workspaces ): array {
		if ( ! $workspaces ) {
			return [];
		}

		$project_ids      = array_map( 'intval', array_column( $workspaces, 'id' ) );
		$placeholders     = implode( ',', array_fill( 0, count( $project_ids ), '%d' ) );
		$jobs_table       = Installer::table( 'jobs' );
		$events_table     = Installer::table( 'job_events' );
		$revisions_table  = Installer::table( 'revisions' );
		$summaries        = [];
		$stage_flags      = [];
		$latest_revisions = [];

		foreach ( $project_ids as $project_id ) {
			$summaries[ $project_id ]   = [
				'total_jobs'     => 0,
				'follow_up_jobs' => 0,
				'retry_count'    => 0,
			];
			$stage_flags[ $project_id ] = [];
			foreach ( [ 'plan', 'code', 'review', 'chat' ] as $stage ) {
				$stage_flags[ $project_id ][ $stage ] = [
					'attempted' => false,
					'active'    => false,
					'complete'  => false,
					'failed'    => false,
				];
			}
		}

		$job_rows = $this->wpdb->get_results(
			$this->wpdb->prepare(
				"SELECT id, project_id, task, status, payload FROM $jobs_table
				WHERE project_id IN ($placeholders) ORDER BY id ASC",
				...$project_ids
			), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Tables are allow-listed and placeholders are generated for integer IDs.
			ARRAY_A
		);
		foreach ( (array) $job_rows as $row ) {
			$project_id = (int) $row['project_id'];
			if ( ! isset( $summaries[ $project_id ] ) ) {
				continue;
			}

			$job = [
				'id'         => (int) $row['id'],
				'project_id' => $project_id,
				'task'       => (string) $row['task'],
				'status'     => (string) $row['status'],
				'payload'    => $this->decode( $row['payload'] ),
			];
			++$summaries[ $project_id ]['total_jobs'];
			if ( 'conversation' === $job['task'] ) {
				++$summaries[ $project_id ]['follow_up_jobs'];
			}

			$stage = $this->job_stage( $job );
			if ( ! $stage ) {
				continue;
			}
			$stage_flags[ $project_id ][ $stage ]['attempted'] = true;
			if ( in_array( $job['status'], [ 'queued', 'running', 'retrying' ], true ) ) {
				$stage_flags[ $project_id ][ $stage ]['active'] = true;
			}
			if ( in_array( $job['status'], [ 'failed', 'cancelled' ], true ) ) {
				$stage_flags[ $project_id ][ $stage ]['failed'] = true;
			}
			if (
				( 'plan' === $stage && 'plan' === $job['task'] && 'completed' === $job['status'] )
				|| ( 'review' === $stage && 'completed' === $job['status'] )
				|| ( 'chat' === $stage && 'completed' === $job['status'] )
			) {
				$stage_flags[ $project_id ][ $stage ]['complete'] = true;
			}
		}

		$revision_rows = $this->wpdb->get_results(
			$this->wpdb->prepare(
				"SELECT project_id, COUNT(*) AS revision_count, MAX(id) AS latest_revision_id FROM $revisions_table
				WHERE project_id IN ($placeholders) GROUP BY project_id",
				...$project_ids
			), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table is allow-listed and placeholders are generated for integer IDs.
			ARRAY_A
		);
		foreach ( (array) $revision_rows as $row ) {
			$project_id = (int) $row['project_id'];
			if ( isset( $stage_flags[ $project_id ] ) && (int) $row['revision_count'] > 0 ) {
				$stage_flags[ $project_id ]['code']['complete'] = true;
				$latest_revisions[ $project_id ]                = (int) $row['latest_revision_id'];
			}
		}

		$retry_rows = $this->wpdb->get_results(
			$this->wpdb->prepare(
				"SELECT j.project_id, COUNT(*) AS retry_count FROM $events_table e
				INNER JOIN $jobs_table j ON j.id = e.job_id
				WHERE j.project_id IN ($placeholders) AND RIGHT(e.event, 6) = '_retry'
				GROUP BY j.project_id",
				...$project_ids
			), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Tables are allow-listed and placeholders are generated for integer IDs.
			ARRAY_A
		);
		foreach ( (array) $retry_rows as $row ) {
			$project_id = (int) $row['project_id'];
			if ( isset( $summaries[ $project_id ] ) ) {
				$summaries[ $project_id ]['retry_count'] = (int) $row['retry_count'];
			}
		}

		foreach ( $workspaces as &$workspace ) {
			$project_id  = (int) $workspace['id'];
			$stages      = [];
			$stage_names = 'explain' === ( $workspace['operation'] ?? '' )
				? [ 'chat' ]
				: [ 'plan', 'code', 'review' ];
			foreach ( $stage_names as $stage ) {
				$flags = $stage_flags[ $project_id ][ $stage ];
				if ( $flags['active'] ) {
					$stages[ $stage ] = 'in_progress';
				} elseif ( $flags['complete'] ) {
					$stages[ $stage ] = 'complete';
				} elseif ( $flags['attempted'] ) {
					$stages[ $stage ] = 'incomplete';
				} else {
					$stages[ $stage ] = 'not_started';
				}
			}
			if ( in_array( 'review', $stage_names, true ) ) {
				$review_status = ( new Review_Repository( $this->wpdb ) )->workspace_status( $project_id, $latest_revisions[ $project_id ] ?? null )['status'];
				if ( $stage_flags[ $project_id ]['review']['active'] ) {
					$review_status = 'in_progress';
				} elseif ( 'not_started' === $review_status && $stage_flags[ $project_id ]['review']['failed'] ) {
					$review_status = 'failed';
				}
				$stages['review'] = $review_status;
			}
			$workspace['activity_summary'] = array_merge(
				$summaries[ $project_id ],
				[ 'stages' => $stages ]
			);
		}
		unset( $workspace );

		return $workspaces;
	}

	/**
	 * Resolve a durable job to the workspace stage it contributes to.
	 *
	 * @param array<string, mixed> $job Hydrated job summary.
	 */
	private function job_stage( array $job ): ?string {
		$task = (string) ( $job['task'] ?? '' );
		if ( 'conversation' === $task ) {
			$stage = (string) ( $job['payload']['stage'] ?? '' );
			return in_array( $stage, [ 'plan', 'code', 'review', 'explain' ], true )
				? ( 'explain' === $stage ? 'chat' : $stage )
				: null;
		}
		if ( in_array( $task, [ 'plan', 'plan_structure' ], true ) ) {
			return 'plan';
		}
		if ( 'explain' === $task ) {
			return 'chat';
		}

		if ( 'review_fix' === $task ) {
			return 'code';
		}
		return in_array( $task, [ 'code', 'review' ], true ) ? $task : null;
	}

	/**
	 * List all projects for the current user with server-side filtering and pagination.
	 *
	 * @return array{items: array<int, array<string, mixed>>, page: int, per_page: int, total: int, total_pages: int, has_more: bool}
	 */
	public function list_projects( int $user_id, string $search = '', int $page = 1, int $per_page = 20 ): array {
		$projects   = Installer::table( 'projects' );
		$jobs       = Installer::table( 'jobs' );
		$search     = trim( sanitize_text_field( $search ) );
		$page       = max( 1, $page );
		$per_page   = max( 1, min( 50, $per_page ) );
		$offset     = ( $page - 1 ) * $per_page;
		$where      = 'WHERE p.created_by = %d';
		$where_args = [ $user_id ];

		if ( '' !== $search ) {
			$like       = '%' . $this->wpdb->esc_like( $search ) . '%';
			$where     .= ' AND (p.name LIKE %s OR p.request LIKE %s OR p.target_ref LIKE %s)';
			$where_args = array_merge( $where_args, [ $like, $like, $like ] );
		}

		$total = (int) $this->wpdb->get_var(
			$this->wpdb->prepare(
				"SELECT COUNT(*) FROM $projects p $where",
				...$where_args
			) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Tables are allow-listed and all values use generated placeholders.
		);
		$rows  = $this->wpdb->get_results(
			$this->wpdb->prepare(
				"SELECT p.*, p.name AS project_name, p.target_snapshot AS target_metadata,
				(SELECT j.id FROM $jobs j WHERE j.project_id = p.id ORDER BY j.id DESC LIMIT 1) AS latest_job_id,
				(SELECT j.status FROM $jobs j WHERE j.project_id = p.id ORDER BY j.id DESC LIMIT 1) AS latest_job_status
				FROM $projects p $where
				ORDER BY p.updated_at DESC, p.id DESC LIMIT %d OFFSET %d",
				...array_merge( $where_args, [ $per_page, $offset ] )
			), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Tables are allow-listed and all values use generated placeholders.
			ARRAY_A
		);
		$items = $this->add_activity_summaries( array_map( [ $this, 'hydrate' ], $rows ) );

		return [
			'items'       => $items,
			'page'        => $page,
			'per_page'    => $per_page,
			'total'       => $total,
			'total_pages' => $total ? (int) ceil( $total / $per_page ) : 0,
			'has_more'    => ( $page * $per_page ) < $total,
		];
	}

	/**
	 * Permanently delete an owned project and every record belonging to it.
	 *
	 * Installed plugins and themes are deliberately outside this cleanup boundary.
	 *
	 * @return array{project_id: int, deleted: true}|\WP_Error
	 */
	public function delete_project( int $project_id, int $user_id ) {
		$projects = Installer::table( 'projects' );
		$this->wpdb->query( 'START TRANSACTION' );

		try {
			$owned = $this->wpdb->get_var(
				$this->wpdb->prepare(
					"SELECT id FROM $projects WHERE id = %d AND created_by = %d FOR UPDATE",
					$project_id,
					$user_id
				)
			); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table is an allow-listed internal name.
			if ( ! $owned ) {
				$this->wpdb->query( 'ROLLBACK' );
				return new \WP_Error( 'wp_autoplugin_project_not_found', __( 'Project not found.', 'wp-autoplugin' ), [ 'status' => 404 ] );
			}

			if ( $this->has_active_jobs( [ $project_id ] ) ) {
				$this->wpdb->query( 'ROLLBACK' );
				return new \WP_Error(
					'wp_autoplugin_project_active',
					__( 'Wait for active project jobs to finish or cancel them before deleting this project.', 'wp-autoplugin' ),
					[ 'status' => 409 ]
				);
			}

			$job_ids       = $this->ids_for( Installer::table( 'jobs' ), 'project_id', [ $project_id ] );
			$revision_ids  = $this->ids_for( Installer::table( 'revisions' ), 'project_id', [ $project_id ] );
			$code_run_ids  = $this->ids_for( Installer::table( 'code_runs' ), 'job_id', $job_ids );
			$finding_ids   = $this->ids_for( Installer::table( 'review_findings' ), 'project_id', [ $project_id ] );
			$promotion_ids = $this->ids_for( Installer::table( 'promotions' ), 'project_id', [ $project_id ] );
			$package_paths = $this->package_paths( [ $project_id ] );

			$this->delete_for_ids( Installer::table( 'agent_runs' ), 'job_id', $job_ids );
			$this->delete_for_ids( Installer::table( 'code_run_files' ), 'run_id', $code_run_ids );
			$this->delete_for_ids( Installer::table( 'code_runs' ), 'job_id', $job_ids );
			$this->delete_for_ids( Installer::table( 'job_prompt_attachments' ), 'job_id', $job_ids );
			$this->delete_for_ids( Installer::table( 'prompt_attachments' ), 'project_id', [ $project_id ] );
			$this->delete_for_ids( Installer::table( 'job_events' ), 'job_id', $job_ids );
			$this->delete_for_ids( Installer::table( 'usage' ), 'job_id', $job_ids );
			$this->delete_for_ids( Installer::table( 'review_finding_events' ), 'finding_id', $finding_ids );
			$this->delete_for_ids( Installer::table( 'review_findings' ), 'project_id', [ $project_id ] );
			$this->delete_for_ids( Installer::table( 'release_packages' ), 'project_id', [ $project_id ] );
			$this->delete_for_ids( Installer::table( 'promotion_files' ), 'promotion_id', $promotion_ids );
			$this->delete_for_ids( Installer::table( 'promotions' ), 'project_id', [ $project_id ] );
			$this->delete_for_ids( Installer::table( 'review_reports' ), 'project_id', [ $project_id ] );
			$this->delete_for_ids( Installer::table( 'revision_files' ), 'revision_id', $revision_ids );
			$this->delete_for_ids( Installer::table( 'revisions' ), 'project_id', [ $project_id ] );
			$this->delete_for_ids( Installer::table( 'plans' ), 'project_id', [ $project_id ] );
			$this->delete_for_ids( Installer::table( 'jobs' ), 'project_id', [ $project_id ] );
			$this->delete_for_ids( $projects, 'id', [ $project_id ] );

			if ( false === $this->wpdb->query( 'COMMIT' ) ) {
				throw new \RuntimeException( $this->persistence_error( 'project deletion' ) );
			}
		} catch ( \Throwable $error ) {
			$this->wpdb->query( 'ROLLBACK' );
			throw $error;
		}

		$this->delete_package_files( $package_paths );

		return [
			'project_id' => $project_id,
			'deleted'    => true,
		];
	}

	/**
	 * Hide a workspace tab without deleting its project, revisions, or jobs.
	 */
	public function close( int $id, int $user_id ): bool {
		$now     = $this->now();
		$updated = $this->wpdb->update(
			Installer::table( 'projects' ),
			[
				'is_closed'  => 1,
				'closed_at'  => $now,
				'updated_at' => $now,
			],
			[
				'id'         => $id,
				'created_by' => $user_id,
				'is_closed'  => 0,
			],
			[ '%d', '%s', '%s' ],
			[ '%d', '%d', '%d' ]
		);

		return false !== $updated && $updated > 0;
	}

	/**
	 * Reopen a closed workspace tab without changing its durable project data.
	 *
	 * @return array<string, mixed>|null
	 */
	public function reopen( int $id, int $user_id ): ?array {
		$updated = $this->wpdb->update(
			Installer::table( 'projects' ),
			[
				'is_closed'  => 0,
				'closed_at'  => null,
				'updated_at' => $this->now(),
			],
			[
				'id'         => $id,
				'created_by' => $user_id,
				'is_closed'  => 1,
			],
			[ '%d', '%s', '%s' ],
			[ '%d', '%d', '%d' ]
		);

		return false !== $updated && $updated > 0 ? $this->find( $id ) : null;
	}

	/** Update the durable project label shown by workspace tabs. */
	public function rename_project( int $project_id, string $name ): bool {
		$name = trim( sanitize_text_field( $name ) );
		if ( '' === $name ) {
			return false;
		}

		return false !== $this->wpdb->update(
			Installer::table( 'projects' ),
			[
				'name'       => wp_html_excerpt( $name, 255, '' ),
				'updated_at' => $this->now(),
			],
			[ 'id' => $project_id ],
			[ '%s', '%s' ],
			[ '%d' ]
		);
	}

	/**
	 * @param array<int, int> $project_ids
	 */
	private function has_active_jobs( array $project_ids ): bool {
		if ( ! $project_ids ) {
			return false;
		}

		$placeholders = implode( ',', array_fill( 0, count( $project_ids ), '%d' ) );
		$jobs         = Installer::table( 'jobs' );
		$count        = $this->wpdb->get_var(
			$this->wpdb->prepare(
				"SELECT COUNT(*) FROM $jobs WHERE project_id IN ($placeholders) AND status IN (%s,%s,%s)",
				...array_merge( $project_ids, [ 'queued', 'running', 'retrying' ] )
			)
		); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table is allow-listed and placeholders are generated for integer IDs.

		return (int) $count > 0;
	}

	/**
	 * @param array<int, int> $ids
	 * @return array<int, int>
	 */
	private function ids_for( string $table, string $column, array $ids, bool $for_update = false ): array {
		if ( ! $ids ) {
			return [];
		}

		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$lock         = $for_update ? ' FOR UPDATE' : '';
		$found        = $this->wpdb->get_col(
			$this->wpdb->prepare(
				"SELECT id FROM $table WHERE $column IN ($placeholders) ORDER BY id ASC$lock",
				...$ids
			)
		); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Tables, columns, and placeholders are internal allow-listed values.

		return array_map( 'intval', (array) $found );
	}

	/**
	 * @param array<int, int> $ids
	 */
	private function delete_for_ids( string $table, string $column, array $ids ): void {
		if ( ! $ids ) {
			return;
		}

		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$deleted      = $this->wpdb->query(
			$this->wpdb->prepare(
				"DELETE FROM $table WHERE $column IN ($placeholders)",
				...$ids
			)
		); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Tables, columns, and placeholders are internal allow-listed values.
		if ( false === $deleted ) {
			throw new \RuntimeException( $this->persistence_error( 'project data' ) );
		}
	}

	/**
	 * @param array<int, int> $project_ids
	 * @return array<int, string>
	 */
	private function package_paths( array $project_ids ): array {
		if ( ! $project_ids ) {
			return [];
		}

		$placeholders = implode( ',', array_fill( 0, count( $project_ids ), '%d' ) );
		$packages     = Installer::table( 'release_packages' );
		$paths        = $this->wpdb->get_col(
			$this->wpdb->prepare(
				"SELECT temp_path FROM $packages WHERE project_id IN ($placeholders) AND temp_path IS NOT NULL",
				...$project_ids
			)
		); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table is allow-listed and placeholders are generated for integer IDs.

		return array_values( array_filter( array_map( 'strval', (array) $paths ) ) );
	}

	/**
	 * Remove only release archives from the private v2 release directory.
	 *
	 * @param array<int, string> $paths
	 */
	private function delete_package_files( array $paths ): void {
		$root = untrailingslashit( wp_normalize_path( sys_get_temp_dir() . '/wp-autoplugin-v2-release' ) );
		foreach ( array_unique( $paths ) as $path ) {
			$path = wp_normalize_path( $path );
			if ( $root !== dirname( $path ) || ! preg_match( '/^package-[A-Za-z0-9]+\.zip$/', basename( $path ) ) ) {
				continue;
			}
			if ( is_file( $path ) ) {
				wp_delete_file( $path );
			}
		}
	}

	/**
	 * @param array<string, mixed> $row Raw database row.
	 * @return array<string, mixed>
	 */
	private function hydrate( array $row ): array {
		foreach ( [ 'id', 'created_by', 'is_closed' ] as $field ) {
			$row[ $field ] = (int) $row[ $field ];
		}
		$row['latest_job_id']   = $row['latest_job_id'] ? (int) $row['latest_job_id'] : null;
		$row['target_metadata'] = $this->decode( $row['target_metadata'] );
		unset( $row['target_snapshot'] );
		$row['target_metadata'] = ( new Target_Scanner() )->refresh_metadata(
			(string) ( $row['target_kind'] ?? '' ),
			(string) ( $row['target_ref'] ?? '' ),
			(array) $row['target_metadata']
		);

		return $row;
	}
}
