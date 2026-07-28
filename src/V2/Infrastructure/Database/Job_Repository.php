<?php

namespace WP_Autoplugin\V2\Infrastructure\Database;

use WP_Autoplugin\V2\Domain\AI\Global_Instructions;

/**
 * Durable job state and append-only event persistence.
 */
final class Job_Repository extends Repository {
	/**
	 * @param array<string, mixed> $payload Scoped task input.
	 * @return array<string, mixed>
	 */
	public function create( int $project_id, string $task, array $payload, int $user_id ): array {
		$now           = $this->now();
		$artifact_lock = self::is_artifact_work(
			[
				'task'    => $task,
				'payload' => $payload,
			]
		);
		$instructions  = self::is_ai_work(
			[
				'task'    => $task,
				'payload' => $payload,
			]
		)
			? Global_Instructions::snapshot()
			: null;
		$this->wpdb->query( 'START TRANSACTION' );
		try {
			$this->lock_project( $project_id );
			if ( $artifact_lock ) {
				if ( $this->has_active_artifact_work( $project_id ) ) {
					throw new \RuntimeException( __( 'Another Code, Review, or Release operation is already active in this workspace.', 'wp-autoplugin' ), 409 );
				}
			}
			$this->wpdb->insert(
				Installer::table( 'jobs' ),
				[
					'project_id'             => $project_id,
					'task'                     => $task,
					'status'                   => 'queued',
					'progress'                 => 0,
					'payload'                  => $this->json( $payload ),
					'global_instructions'      => $instructions['content'] ?? null,
					'global_instructions_hash' => $instructions['content_hash'] ?? null,
					'created_by'               => $user_id,
					'created_at'               => $now,
					'updated_at'               => $now,
				],
				[ '%d', '%s', '%s', '%d', '%s', '%s', '%s', '%d', '%s', '%s' ]
			);

			$id = (int) $this->wpdb->insert_id;
			if ( ! $id ) {
				throw new \RuntimeException( __( 'Could not create job.', 'wp-autoplugin' ) );
			}
			$this->wpdb->update(
				Installer::table( 'projects' ),
				[ 'updated_at' => $now ],
				[ 'id' => $project_id ],
				[ '%s' ],
				[ '%d' ]
			);
			if ( false === $this->wpdb->query( 'COMMIT' ) ) {
				throw new \RuntimeException( __( 'Could not finalize the job.', 'wp-autoplugin' ) );
			}
		} catch ( \Throwable $error ) {
			$this->wpdb->query( 'ROLLBACK' );
			throw $error;
		}

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
	public function list_for_workspace( int $project_id ): array {
		$rows = $this->wpdb->get_results(
			$this->wpdb->prepare(
				'SELECT * FROM ' . Installer::table( 'jobs' ) . ' WHERE project_id = %d ORDER BY id ASC',
				$project_id
			),
			ARRAY_A
		);

		return array_map( [ $this, 'hydrate' ], $rows );
	}

	/** Whether billable Code work is already active in the workspace. */
	public function has_active_code( int $project_id ): bool {
		$rows = $this->wpdb->get_results(
			$this->wpdb->prepare(
				'SELECT * FROM ' . Installer::table( 'jobs' ) . ' WHERE project_id = %d AND status IN (%s,%s,%s)',
				$project_id,
				'queued',
				'running',
				'retrying'
			),
			ARRAY_A
		);
		foreach ( $rows as $row ) {
			if ( self::is_code_work( $this->hydrate( $row ) ) ) {
				return true;
			}
		}
		return false;
	}

	/** Whether revision-mutating or revision-bound work is active in the workspace. */
	public function has_active_artifact_work( int $project_id ): bool {
		$rows = $this->wpdb->get_results(
			$this->wpdb->prepare(
				'SELECT * FROM ' . Installer::table( 'jobs' ) . ' WHERE project_id = %d AND status IN (%s,%s,%s)',
				$project_id,
				'queued',
				'running',
				'retrying'
			),
			ARRAY_A
		);
		foreach ( (array) $rows as $row ) {
			if ( self::is_artifact_work( $this->hydrate( $row ) ) ) {
				return true;
			}
		}
		return false;
	}

	/** @return array<int,array<string,mixed>> */
	public function active_before( string $before ): array {
		$rows = $this->wpdb->get_results(
			$this->wpdb->prepare(
				'SELECT * FROM ' . Installer::table( 'jobs' ) . ' WHERE status IN (%s,%s,%s) AND updated_at <= %s ORDER BY id ASC',
				'queued',
				'running',
				'retrying',
				$before
			),
			ARRAY_A
		);
		return array_map( [ $this, 'hydrate' ], (array) $rows );
	}

	/** Whether a job participates in the mutually exclusive Code-work lock. */
	public static function is_code_work( array $job ): bool {
		return in_array( (string) ( $job['task'] ?? '' ), [ 'code', 'review_fix' ], true )
			|| ( 'conversation' === ( $job['task'] ?? '' ) && 'code' === ( $job['payload']['stage'] ?? '' ) );
	}

	/** Whether a durable job sends one or more requests to an AI provider. */
	public static function is_ai_work( array $job ): bool {
		return in_array(
			(string) ( $job['task'] ?? '' ),
			[ 'plan', 'plan_structure', 'code', 'review', 'review_fix', 'explain', 'conversation' ],
			true
		);
	}

	/**
	 * Return the private immutable site-wide instruction snapshot for one job.
	 *
	 * @return array{content:string,content_hash:string}|null
	 */
	public function global_instructions( int $job_id ): ?array {
		$row = $this->wpdb->get_row(
			$this->wpdb->prepare(
				'SELECT global_instructions, global_instructions_hash FROM ' . Installer::table( 'jobs' ) . ' WHERE id = %d',
				$job_id
			),
			ARRAY_A
		);
		if ( ! $row || null === $row['global_instructions'] || '' === (string) $row['global_instructions'] ) {
			return null;
		}

		$snapshot = [
			'content'      => (string) $row['global_instructions'],
			'content_hash' => (string) $row['global_instructions_hash'],
		];

		return Global_Instructions::validate_snapshot( $snapshot );
	}

	/** Whether a job participates in the workspace artifact lock. */
	public static function is_artifact_work( array $job ): bool {
		if ( in_array( (string) ( $job['task'] ?? '' ), [ 'code', 'review', 'review_fix', 'package', 'promotion' ], true ) ) {
			return true;
		}
		return 'conversation' === ( $job['task'] ?? '' )
			&& in_array( (string) ( $job['payload']['stage'] ?? '' ), [ 'code', 'review' ], true );
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

	/** @return array<string, mixed>|null */
	public function latest_event( int $job_id ): ?array {
		$row = $this->wpdb->get_row(
			$this->wpdb->prepare( 'SELECT sequence, level, event, message FROM ' . Installer::table( 'job_events' ) . ' WHERE job_id = %d ORDER BY sequence DESC LIMIT 1', $job_id ),
			ARRAY_A
		);
		if ( ! $row ) {
			return null;
		}
		$row['sequence'] = (int) $row['sequence'];
		return $row;
	}

	/**
	 * Request cancellation. The runner checks this between every bounded step.
	 */
	public function request_cancel( int $id ): bool {
		$job = $this->find( $id );
		if ( $job && 'queued' === $job['status'] ) {
			$this->update(
				$id,
				[
					'status'      => 'cancelled',
					'finished_at' => $this->now(),
				]
			);
			$this->event( $id, 'cancelled', __( 'Queued job cancelled.', 'wp-autoplugin' ) );
			return true;
		}

		$updated = $this->wpdb->update(
			Installer::table( 'jobs' ),
			[
				'cancel_requested' => 1,
				'updated_at'       => $this->now(),
			],
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
		$allowed = [ 'status', 'progress', 'result', 'error_message', 'started_at', 'finished_at' ];
		$data    = [ 'updated_at' => $this->now() ];

		foreach ( $fields as $field => $value ) {
			if ( in_array( $field, $allowed, true ) ) {
				$data[ $field ] = 'result' === $field ? $this->json( $value ) : $value;
			}
		}

		return false !== $this->wpdb->update( Installer::table( 'jobs' ), $data, [ 'id' => $id ] );
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
				'job_id'     => $job_id,
				'sequence'   => $sequence,
				'level'      => $level,
				'event'      => sanitize_key( $event ),
				'message'    => $message,
				'context'    => $this->json( $context ),
				'created_at' => $this->now(),
			]
		);
	}

	/**
	 * @param array<string, mixed> $row Raw database row.
	 * @return array<string, mixed>
	 */
	private function hydrate( array $row ): array {
		foreach ( [ 'id', 'project_id', 'progress', 'cancel_requested', 'created_by' ] as $field ) {
			$row[ $field ] = (int) $row[ $field ];
		}
		$row['payload'] = $this->decode( $row['payload'] );
		$row['result']  = $this->decode( $row['result'] );
		if (
			in_array( (string) $row['task'], [ 'plan', 'plan_structure' ], true )
			|| ( 'conversation' === $row['task'] && 'plan' === ( $row['payload']['stage'] ?? '' ) )
		) {
			$row['result'] = ( new Plan_Repository( $this->wpdb ) )->expand_job_result( $row['result'] );
		}
		unset( $row['global_instructions'], $row['global_instructions_hash'] );
		$row['prompt_attachments'] = ( new Prompt_Attachment_Repository( $this->wpdb ) )->for_job( (int) $row['id'] );

		return $row;
	}

	/** Lock an existing project so job creation cannot race project deletion. */
	private function lock_project( int $project_id ): void {
		$project_exists = $this->wpdb->get_var(
			$this->wpdb->prepare( 'SELECT id FROM ' . Installer::table( 'projects' ) . ' WHERE id = %d FOR UPDATE', $project_id )
		);
		if ( ! $project_exists ) {
			throw new \RuntimeException( __( 'The workspace no longer exists.', 'wp-autoplugin' ), 404 );
		}
	}
}
