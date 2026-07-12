<?php

namespace WP_Autoplugin\V2\Infrastructure\Database;

/**
 * Durable job state and append-only event persistence.
 */
final class Job_Repository extends Repository {
	/**
	 * @param array<string, mixed> $payload Scoped task input.
	 * @return array<string, mixed>
	 */
	public function create( int $workspace_id, string $task, array $payload, int $user_id ): array {
		$now = $this->now();
		$this->wpdb->insert(
			Installer::table( 'jobs' ),
			[
				'workspace_id' => $workspace_id,
				'task'         => $task,
				'status'       => 'queued',
				'progress'     => 0,
				'payload'      => $this->json( $payload ),
				'created_by'   => $user_id,
				'created_at'   => $now,
				'updated_at'   => $now,
			],
			[ '%d', '%s', '%s', '%d', '%s', '%d', '%s', '%s' ]
		);

		$id = (int) $this->wpdb->insert_id;
		if ( ! $id ) {
			throw new \RuntimeException( 'Could not create job.' );
		}
		$this->wpdb->update(
			Installer::table( 'workspaces' ),
			[ 'updated_at' => $now ],
			[ 'id' => $workspace_id ],
			[ '%s' ],
			[ '%d' ]
		);

		$this->event( $id, 'queued', __( 'Job queued.', 'wp-autoplugin' ) );

		return $this->find( $id );
	}

	/**
	 * @return array<string, mixed>|null
	 */
	public function find( int $id ): ?array {
		$row = $this->wpdb->get_row(
			$this->wpdb->prepare( 'SELECT * FROM ' . Installer::table( 'jobs' ) . ' WHERE id = %d', $id ),
			ARRAY_A
		);

		if ( ! $row ) {
			return null;
		}

		return $this->hydrate( $row );
	}

	/**
	 * Return the durable history for one workspace, oldest first.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function list_for_workspace( int $workspace_id ): array {
		$rows = $this->wpdb->get_results(
			$this->wpdb->prepare(
				'SELECT * FROM ' . Installer::table( 'jobs' ) . ' WHERE workspace_id = %d ORDER BY id ASC',
				$workspace_id
			),
			ARRAY_A
		);

		return array_map( [ $this, 'hydrate' ], $rows );
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public function events( int $job_id, int $after = 0 ): array {
		$rows = $this->wpdb->get_results(
			$this->wpdb->prepare(
				'SELECT id, sequence, level, event, message, context, created_at FROM ' . Installer::table( 'job_events' ) . ' WHERE job_id = %d AND sequence > %d ORDER BY sequence ASC',
				$job_id,
				$after
			),
			ARRAY_A
		);

		return array_map(
			function ( array $row ): array {
				$row['id']       = (int) $row['id'];
				$row['sequence'] = (int) $row['sequence'];
				$row['context']  = $this->decode( $row['context'] );

				return $row;
			},
			$rows
		);
	}

	/**
	 * Request cancellation. The runner checks this between every bounded step.
	 */
	public function request_cancel( int $id ): bool {
		$job = $this->find( $id );
		if ( $job && 'queued' === $job['status'] ) {
			$this->update( $id, [ 'status' => 'cancelled', 'finished_at' => $this->now() ] );
			$this->event( $id, 'cancelled', __( 'Queued job cancelled.', 'wp-autoplugin' ) );
			return true;
		}

		$updated = $this->wpdb->update(
			Installer::table( 'jobs' ),
			[ 'cancel_requested' => 1, 'updated_at' => $this->now() ],
			[ 'id' => $id ],
			[ '%d', '%s' ],
			[ '%d' ]
		);

		if ( false !== $updated ) {
			$this->event( $id, 'cancel_requested', __( 'Cancellation requested.', 'wp-autoplugin' ) );
		}

		return false !== $updated;
	}

	/**
	 * @param array<string, mixed> $fields State fields to update.
	 */
	public function update( int $id, array $fields ): bool {
		$allowed = [ 'status', 'progress', 'runner', 'result', 'error_message', 'started_at', 'finished_at' ];
		$data    = [ 'updated_at' => $this->now() ];

		foreach ( $fields as $field => $value ) {
			if ( in_array( $field, $allowed, true ) ) {
				$data[ $field ] = 'result' === $field ? $this->json( $value ) : $value;
			}
		}

		return false !== $this->wpdb->update( Installer::table( 'jobs' ), $data, [ 'id' => $id ] );
	}

	/**
	 * Save an operator-edited plan without altering the immutable revision layer.
	 */
	public function update_plan_content( int $id, string $content ): bool {
		$job = $this->find( $id );
		if ( ! $job || 'plan' !== $job['task'] || 'completed' !== $job['status'] ) {
			return false;
		}

		$result            = is_array( $job['result'] ) ? $job['result'] : [];
		$result['content'] = $content;
		$result['structured'] = null;

		return $this->update( $id, [ 'result' => $result ] );
	}

	/**
	 * Append a progress event.
	 *
	 * @param array<string, mixed> $context Redacted event metadata.
	 */
	public function event( int $job_id, string $event, string $message = '', array $context = [], string $level = 'info' ): void {
		$table    = Installer::table( 'job_events' );
		$sequence = (int) $this->wpdb->get_var( $this->wpdb->prepare( "SELECT COALESCE(MAX(sequence), 0) + 1 FROM $table WHERE job_id = %d", $job_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Internal allow-listed table.

		$this->wpdb->insert(
			$table,
			[
				'job_id'    => $job_id,
				'sequence'  => $sequence,
				'level'     => $level,
				'event'     => sanitize_key( $event ),
				'message'   => $message,
				'context'   => $this->json( $context ),
				'created_at'=> $this->now(),
			]
		);
	}

	/**
	 * @param array<string, mixed> $row Raw database row.
	 * @return array<string, mixed>
	 */
	private function hydrate( array $row ): array {
		foreach ( [ 'id', 'workspace_id', 'progress', 'cancel_requested', 'created_by' ] as $field ) {
			$row[ $field ] = (int) $row[ $field ];
		}
		$row['payload'] = $this->decode( $row['payload'] );
		$row['result']  = $this->decode( $row['result'] );

		return $row;
	}
}
