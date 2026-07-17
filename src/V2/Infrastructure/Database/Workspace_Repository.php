<?php

namespace WP_Autoplugin\V2\Infrastructure\Database;

/**
 * Persists targets, projects, and staged workspaces as one atomic operation.
 */
final class Workspace_Repository extends Repository {
	/**
	 * @param array<string, mixed> $target Target snapshot.
	 * @return array<string, int|string>
	 */
	public function create( array $target, string $operation, string $request, int $user_id ): array {
		$now = $this->now();
		$this->wpdb->query( 'START TRANSACTION' );

		try {
			$table = Installer::table( 'targets' );
			$target_result = $this->wpdb->query(
				$this->wpdb->prepare(
					"INSERT INTO $table (kind, ref, name, metadata, created_at, updated_at)
					VALUES (%s, %s, %s, %s, %s, %s)
					ON DUPLICATE KEY UPDATE name = VALUES(name), metadata = VALUES(metadata), updated_at = VALUES(updated_at)",
					$target['kind'],
					$target['ref'],
					$target['name'],
					$this->json( $target ),
					$now,
					$now
				)
			); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table is an allow-listed internal name.
			if ( false === $target_result ) {
				throw new \RuntimeException( $this->persistence_error( 'target' ) );
			}

			$target_id = (int) $this->wpdb->get_var(
				$this->wpdb->prepare( "SELECT id FROM $table WHERE kind = %s AND ref = %s", $target['kind'], $target['ref'] ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table is an allow-listed internal name.
			);
			if ( ! $target_id ) {
				throw new \RuntimeException( $this->persistence_error( 'target' ) );
			}

			$project_result = $this->wpdb->insert(
				Installer::table( 'projects' ),
				[
					'target_id'  => $target_id,
					'name'       => $target['name'],
					'status'     => 'active',
					'created_by' => $user_id,
					'created_at' => $now,
					'updated_at' => $now,
				],
				[ '%d', '%s', '%s', '%d', '%s', '%s' ]
			);
			$project_id = (int) $this->wpdb->insert_id;
			if ( false === $project_result || ! $project_id ) {
				throw new \RuntimeException( $this->persistence_error( 'project' ) );
			}

			$workspace_result = $this->wpdb->insert(
				Installer::table( 'workspaces' ),
				[
					'project_id' => $project_id,
					'operation'  => $operation,
					'status'     => 'draft',
					'request'    => $request,
					'created_by' => $user_id,
					'created_at' => $now,
					'updated_at' => $now,
				],
				[ '%d', '%s', '%s', '%s', '%d', '%s', '%s' ]
			);
			$workspace_id = (int) $this->wpdb->insert_id;

			if ( false === $workspace_result || ! $workspace_id ) {
				throw new \RuntimeException( $this->persistence_error( 'workspace' ) );
			}

			if ( false === $this->wpdb->query( 'COMMIT' ) ) {
				throw new \RuntimeException( $this->persistence_error( 'transaction' ) );
			}

			return [
				'project_id'   => $project_id,
				'workspace_id' => $workspace_id,
				'status'       => 'draft',
			];
		} catch ( \Throwable $error ) {
			$this->wpdb->query( 'ROLLBACK' );
			throw $error;
		}
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
		$workspaces = Installer::table( 'workspaces' );
		$projects   = Installer::table( 'projects' );
		$targets    = Installer::table( 'targets' );
		$jobs       = Installer::table( 'jobs' );
		$row        = $this->wpdb->get_row(
			$this->wpdb->prepare(
				"SELECT w.*, p.name AS project_name, t.kind AS target_kind, t.ref AS target_ref, t.metadata AS target_metadata,
				(SELECT j.id FROM $jobs j WHERE j.workspace_id = w.id ORDER BY j.id DESC LIMIT 1) AS latest_job_id,
				(SELECT j.status FROM $jobs j WHERE j.workspace_id = w.id ORDER BY j.id DESC LIMIT 1) AS latest_job_status
				FROM $workspaces w INNER JOIN $projects p ON p.id = w.project_id
				LEFT JOIN $targets t ON t.id = p.target_id WHERE w.id = %d",
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
		$workspaces = Installer::table( 'workspaces' );
		$projects   = Installer::table( 'projects' );
		$targets    = Installer::table( 'targets' );
		$jobs       = Installer::table( 'jobs' );
		$rows       = $this->wpdb->get_results(
			$this->wpdb->prepare(
				"SELECT w.*, p.name AS project_name, t.kind AS target_kind, t.ref AS target_ref, t.metadata AS target_metadata,
				(SELECT j.id FROM $jobs j WHERE j.workspace_id = w.id ORDER BY j.id DESC LIMIT 1) AS latest_job_id,
				(SELECT j.status FROM $jobs j WHERE j.workspace_id = w.id ORDER BY j.id DESC LIMIT 1) AS latest_job_status
				FROM $workspaces w INNER JOIN $projects p ON p.id = w.project_id
				LEFT JOIN $targets t ON t.id = p.target_id
				WHERE w.created_by = %d AND w.is_closed = 0 ORDER BY w.updated_at DESC, w.id DESC",
				$user_id
			), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Tables are allow-listed internal names.
			ARRAY_A
		);

		return array_map( [ $this, 'hydrate' ], $rows );
	}

	/**
	 * Hide a workspace tab without deleting its project, revisions, or jobs.
	 */
	public function close( int $id, int $user_id ): bool {
		$updated = $this->wpdb->update(
			Installer::table( 'workspaces' ),
			[
				'is_closed' => 1,
				'closed_at' => $this->now(),
				'updated_at' => $this->now(),
			],
			[ 'id' => $id, 'created_by' => $user_id ],
			[ '%d', '%s', '%s' ],
			[ '%d', '%d' ]
		);

		return false !== $updated && $updated > 0;
	}

	/** Update the durable project label shown by workspace tabs. */
	public function rename_project( int $workspace_id, string $name ): bool {
		$name = trim( sanitize_text_field( $name ) );
		if ( '' === $name ) {
			return false;
		}

		$project_id = (int) $this->wpdb->get_var(
			$this->wpdb->prepare(
				'SELECT project_id FROM ' . Installer::table( 'workspaces' ) . ' WHERE id = %d',
				$workspace_id
			)
		);
		if ( ! $project_id ) {
			return false;
		}

		return false !== $this->wpdb->update(
			Installer::table( 'projects' ),
			[ 'name' => wp_html_excerpt( $name, 255, '' ), 'updated_at' => $this->now() ],
			[ 'id' => $project_id ],
			[ '%s', '%s' ],
			[ '%d' ]
		);
	}

	/**
	 * @param array<string, mixed> $row Raw database row.
	 * @return array<string, mixed>
	 */
	private function hydrate( array $row ): array {
		foreach ( [ 'id', 'project_id', 'created_by', 'is_closed' ] as $field ) {
			$row[ $field ] = (int) $row[ $field ];
		}
		$row['latest_job_id']   = $row['latest_job_id'] ? (int) $row['latest_job_id'] : null;
		$row['target_metadata'] = $this->decode( $row['target_metadata'] );

		return $row;
	}
}
