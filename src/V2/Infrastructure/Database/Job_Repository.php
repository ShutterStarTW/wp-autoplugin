<?php

namespace WP_Autoplugin\V2\Infrastructure\Database;

use WP_Autoplugin\AI_Utils;

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
	 * Whether a completed job represents a staged Plan artifact.
	 *
	 * Older Plan jobs predate explicit artifact metadata, so they remain valid
	 * Plan artifacts for compatibility.
	 *
	 * @param array<string, mixed> $job Hydrated job record.
	 */
	public function is_plan_artifact( array $job ): bool {
		if ( 'completed' !== ( $job['status'] ?? '' ) || ! is_array( $job['result'] ?? null ) ) {
			return false;
		}

		if ( 'plan' === ( $job['task'] ?? '' ) ) {
			return isset( $job['result']['content'] );
		}
		if ( 'plan_structure' === ( $job['task'] ?? '' ) ) {
			return 'plan' === ( $job['result']['artifact']['type'] ?? '' )
				&& isset( $job['result']['artifact']['content'] );
		}

		return 'conversation' === ( $job['task'] ?? '' )
			&& 'plan' === ( $job['payload']['stage'] ?? '' )
			&& 'artifact' === ( $job['result']['outcome'] ?? '' )
			&& 'plan' === ( $job['result']['artifact']['type'] ?? '' )
			&& isset( $job['result']['artifact']['content'] );
	}

	/**
	 * Store a human-edited Plan as a new immutable successor job.
	 *
	 * @param array<string, mixed> $source Completed Plan artifact being edited.
	 * @return array<string, mixed>|null
	 */
	public function create_plan_successor( array $source, string $content, int $user_id ): ?array {
		if ( ! $this->is_plan_artifact( $source ) ) {
			return null;
		}

		$now        = $this->now();
		$structured = json_decode( AI_Utils::strip_code_fences( trim( $content ), 'json' ), true );
		if ( ! is_array( $structured ) && is_array( $source['result']['structured'] ?? null ) ) {
			// Markdown edits change the narrative Plan, not its static file map.
			$structured = $source['result']['structured'];
		}
		$this->wpdb->insert(
			Installer::table( 'jobs' ),
			[
				'workspace_id' => $source['workspace_id'],
				'task'         => 'plan',
				'status'       => 'completed',
				'progress'     => 100,
				'payload'      => $this->json(
					[
						'stage'                  => 'plan',
						'source'                 => 'manual_edit',
						'artifact_parent_job_id' => $source['id'],
					]
				),
				'result'       => $this->json(
					[
						'content'    => $content,
						'structured' => is_array( $structured ) ? $structured : null,
						'artifact'   => [
							'type'          => 'plan',
							'parent_job_id' => $source['id'],
						],
					]
				),
				'created_by'   => $user_id,
				'created_at'   => $now,
				'started_at'   => $now,
				'finished_at'  => $now,
				'updated_at'   => $now,
			],
			[ '%d', '%s', '%s', '%d', '%s', '%s', '%d', '%s', '%s', '%s', '%s' ]
		);

		$id = (int) $this->wpdb->insert_id;
		if ( ! $id ) {
			throw new \RuntimeException( 'Could not create Plan successor.' );
		}

		$this->wpdb->update(
			Installer::table( 'workspaces' ),
			[ 'updated_at' => $now ],
			[ 'id' => $source['workspace_id'] ],
			[ '%s' ],
			[ '%d' ]
		);
		$this->event( $id, 'plan_successor', __( 'Plan edited by an administrator.', 'wp-autoplugin' ), [ 'parent_job_id' => $source['id'] ] );

		return $this->find( $id );
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
