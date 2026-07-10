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
			$this->wpdb->query(
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

			$target_id = (int) $this->wpdb->get_var(
				$this->wpdb->prepare( "SELECT id FROM $table WHERE kind = %s AND ref = %s", $target['kind'], $target['ref'] ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table is an allow-listed internal name.
			);

			$this->wpdb->insert(
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

			$this->wpdb->insert(
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

			if ( ! $project_id || ! $workspace_id ) {
				throw new \RuntimeException( 'Could not persist workspace.' );
			}

			$this->wpdb->query( 'COMMIT' );

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

	/**
	 * @return array<string, mixed>|null
	 */
	public function find( int $id ): ?array {
		$workspaces = Installer::table( 'workspaces' );
		$projects   = Installer::table( 'projects' );
		$targets    = Installer::table( 'targets' );
		$row        = $this->wpdb->get_row(
			$this->wpdb->prepare(
				"SELECT w.*, p.name AS project_name, t.kind AS target_kind, t.ref AS target_ref, t.metadata AS target_metadata
				FROM $workspaces w INNER JOIN $projects p ON p.id = w.project_id
				LEFT JOIN $targets t ON t.id = p.target_id WHERE w.id = %d",
				$id
			), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Tables are allow-listed internal names.
			ARRAY_A
		);

		if ( ! $row ) {
			return null;
		}

		$row['id']              = (int) $row['id'];
		$row['project_id']      = (int) $row['project_id'];
		$row['target_metadata'] = $this->decode( $row['target_metadata'] );

		return $row;
	}
}
