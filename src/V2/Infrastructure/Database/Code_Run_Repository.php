<?php

namespace WP_Autoplugin\V2\Infrastructure\Database;

/** Persists resumable, source-private Code generation state. */
final class Code_Run_Repository extends Repository {
	/**
	 * @param array<int, array<string, mixed>> $files Ordered normalized manifest.
	 * @return array<string, mixed>
	 */
	public function create( int $job_id, int $plan_job_id, ?int $parent_revision_id, string $provider, string $model, string $effort, string $prompt_slug, int $prompt_version, array $files, string $mode = 'generate', ?array $target_manifest = null ): array {
		$now = $this->now();
		$this->wpdb->query( 'START TRANSACTION' );
		try {
			$this->wpdb->insert(
				Installer::table( 'code_runs' ),
				[
					'job_id'             => $job_id,
					'plan_job_id'        => $plan_job_id,
					'parent_revision_id' => $parent_revision_id,
					'status'             => 'active',
					'mode'               => sanitize_key( $mode ),
					'phase'              => 'files',
					'provider'           => sanitize_key( $provider ),
					'model'              => sanitize_text_field( $model ),
					'effort'             => sanitize_key( $effort ),
					'prompt_slug'        => sanitize_key( $prompt_slug ),
					'prompt_version'     => $prompt_version,
					'target_manifest'    => $target_manifest ? $this->json( $target_manifest ) : null,
					'created_at'         => $now,
					'updated_at'         => $now,
				]
			);
			$run_id = (int) $this->wpdb->insert_id;
			if ( ! $run_id ) {
				throw new \RuntimeException( __( 'Could not initialize Code generation.', 'wp-autoplugin' ) );
			}
			foreach ( array_values( $files ) as $sequence => $file ) {
				$operation = sanitize_key( (string) ( $file['operation'] ?? 'add' ) );
				$inserted  = $this->wpdb->insert(
					Installer::table( 'code_run_files' ),
					[
						'run_id'          => $run_id,
						'sequence'        => $sequence,
						'path'            => $file['path'],
						'type'            => $file['type'],
						'description'     => $file['description'],
						'operation'       => $operation,
						'status'          => 'delete' === $operation ? 'completed' : 'pending',
						'error_metadata'  => $this->json( [] ),
						'created_at'      => $now,
						'updated_at'      => $now,
					]
				);
				if ( false === $inserted ) {
					throw new \RuntimeException( __( 'Could not initialize a planned Code file.', 'wp-autoplugin' ) );
				}
			}
			$this->wpdb->query( 'COMMIT' );
		} catch ( \Throwable $error ) {
			$this->wpdb->query( 'ROLLBACK' );
			throw $error;
		}

		$run = $this->find_by_job( $job_id );
		if ( ! $run ) {
			throw new \RuntimeException( __( 'Could not load Code generation state.', 'wp-autoplugin' ) );
		}
		return $run;
	}

	/** Initialize a durable Code follow-up at its analysis phase. */
	public function create_follow_up( int $job_id, int $plan_job_id, int $parent_revision_id, string $provider, string $model, string $effort, string $prompt_slug, int $prompt_version ): array {
		$now = $this->now();
		$inserted = $this->wpdb->insert(
			Installer::table( 'code_runs' ),
			[
				'job_id'             => $job_id,
				'plan_job_id'        => $plan_job_id,
				'parent_revision_id' => $parent_revision_id,
				'status'             => 'active',
				'mode'               => 'follow_up',
				'phase'              => 'analysis',
				'provider'           => sanitize_key( $provider ),
				'model'              => sanitize_text_field( $model ),
				'effort'             => sanitize_key( $effort ),
				'prompt_slug'        => sanitize_key( $prompt_slug ),
				'prompt_version'     => $prompt_version,
				'created_at'         => $now,
				'updated_at'         => $now,
			]
		);
		if ( false === $inserted ) {
			$existing = $this->find_by_job( $job_id );
			if ( $existing ) {
				return $existing;
			}
		}
		if ( ! $this->wpdb->insert_id ) {
			throw new \RuntimeException( __( 'Could not initialize the Code follow-up.', 'wp-autoplugin' ) );
		}
		$run = $this->find_by_job( $job_id );
		if ( ! $run ) {
			throw new \RuntimeException( __( 'Could not load Code follow-up state.', 'wp-autoplugin' ) );
		}
		return $run;
	}

	/** @return array<string, mixed>|null */
	public function find_by_job( int $job_id ): ?array {
		$row = $this->wpdb->get_row(
			$this->wpdb->prepare( 'SELECT * FROM ' . Installer::table( 'code_runs' ) . ' WHERE job_id = %d', $job_id ),
			ARRAY_A
		);
		return $row ? $this->hydrate( $row ) : null;
	}

	/** @return array<int, array<string, mixed>> */
	public function files( int $run_id ): array {
		$rows = $this->wpdb->get_results(
			$this->wpdb->prepare( 'SELECT * FROM ' . Installer::table( 'code_run_files' ) . ' WHERE run_id = %d ORDER BY sequence ASC', $run_id ),
			ARRAY_A
		);
		return array_map( [ $this, 'hydrate_file' ], $rows );
	}

	/** Safe progress data; intentionally excludes generated source. */
	public function progress_for_job( int $job_id ): ?array {
		$run = $this->find_by_job( $job_id );
		if ( ! $run ) {
			return null;
		}
		$files = array_map(
			static fn( array $file ): array => [
				'path'      => $file['path'],
				'type'      => $file['type'],
				'operation' => $file['operation'],
				'status'    => $file['status'],
				'error'     => 'failed' === $file['status'] ? (string) ( $file['error_metadata']['message'] ?? '' ) : '',
			],
			$this->files( (int) $run['id'] )
		);
		$completed = count( array_filter( $files, static fn( array $file ): bool => 'completed' === $file['status'] ) );

		return [
			'mode'         => $run['mode'],
			'phase'        => $run['phase'],
			'outcome'      => $run['outcome'],
			'total'        => count( $files ),
			'completed'    => $completed,
			'current'      => min( (int) $run['next_file_index'] + 1, count( $files ) ),
			'provider'     => $run['provider'],
			'model'        => $run['model'],
			'effort'       => $run['effort'],
			'input_tokens' => (int) $run['input_tokens'],
			'output_tokens'=> (int) $run['output_tokens'],
			'deleted_paths'=> array_values( (array) ( $run['change_instructions']['deleted_paths'] ?? [] ) ),
			'files'        => $files,
		];
	}

	/** @return array<int, array<string, mixed>> */
	public function recoverable(): array {
		$before = gmdate( 'Y-m-d H:i:s', time() - 2 * MINUTE_IN_SECONDS );
		$rows = $this->wpdb->get_results(
			$this->wpdb->prepare(
				'SELECT r.* FROM ' . Installer::table( 'code_runs' ) . ' r INNER JOIN ' . Installer::table( 'jobs' ) . ' j ON j.id = r.job_id WHERE ((r.status = %s AND (r.lease_token IS NULL OR r.lease_expires_at < %s)) OR (r.status = %s AND r.outcome IS NOT NULL)) AND r.updated_at <= %s AND j.status IN (%s,%s)',
				'active',
				$this->now(),
				'completed',
				$before,
				'running',
				'retrying'
			),
			ARRAY_A
		);
		return array_map( [ $this, 'hydrate' ], $rows );
	}

	public function acquire( int $run_id, int $generation, string $token, int $seconds = 330 ): bool {
		$now     = $this->now();
		$expires = gmdate( 'Y-m-d H:i:s', time() + $seconds );
		$query   = $this->wpdb->prepare(
			'UPDATE ' . Installer::table( 'code_runs' ) . ' SET lease_token = %s, lease_expires_at = %s, updated_at = %s WHERE id = %d AND status = %s AND generation = %d AND (lease_token IS NULL OR lease_expires_at < %s)',
			$token,
			$expires,
			$now,
			$run_id,
			'active',
			$generation,
			$now
		);
		return 1 === $this->wpdb->query( $query ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Prepared immediately above.
	}

	/** @param array<int, array<string, mixed>> $issues */
	public function mark_generating( int $run_id, int $sequence, string $token, array $issues = [] ): void {
		$files = Installer::table( 'code_run_files' );
		$runs  = Installer::table( 'code_runs' );
		$query = $this->wpdb->prepare(
			"UPDATE $files f INNER JOIN $runs r ON r.id = f.run_id SET f.status = %s, f.error_metadata = %s, f.updated_at = %s WHERE f.run_id = %d AND f.sequence = %d AND r.lease_token = %s",
			'generating',
			$this->json( $issues ? [ 'issues' => $issues, 'message' => (string) ( $issues[0]['message'] ?? '' ) ] : [] ),
			$this->now(),
			$run_id,
			$sequence,
			$token
		);
		if ( 1 !== $this->wpdb->query( $query ) ) { // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Prepared immediately above.
			throw new \RuntimeException( __( 'The Code generation lease expired before the file could start.', 'wp-autoplugin' ) );
		}
	}

	/** Record a complete file and release the lease for the next generation. */
	public function complete_file( int $run_id, int $sequence, string $token, string $content, array $usage ): bool {
		$this->wpdb->query( 'START TRANSACTION' );
		try {
			$file_updated = $this->wpdb->update(
				Installer::table( 'code_run_files' ),
				[
					'status'         => 'completed',
					'content'        => $content,
					'content_hash'   => hash( 'sha256', $content ),
					'error_metadata' => $this->json( [] ),
					'updated_at'     => $this->now(),
				],
				[ 'run_id' => $run_id, 'sequence' => $sequence ]
			);
			if ( false === $file_updated ) {
				throw new \RuntimeException( __( 'Could not save the generated Code file.', 'wp-autoplugin' ) );
			}
			$updated = $this->wpdb->query(
				$this->wpdb->prepare(
					'UPDATE ' . Installer::table( 'code_runs' ) . ' SET generation = generation + 1, next_file_index = next_file_index + 1, retry_count = 0, input_tokens = input_tokens + %d, output_tokens = output_tokens + %d, last_error = NULL, lease_token = NULL, lease_expires_at = NULL, updated_at = %s WHERE id = %d AND lease_token = %s',
					max( 0, (int) ( $usage['input_tokens'] ?? 0 ) ),
					max( 0, (int) ( $usage['output_tokens'] ?? 0 ) ),
					$this->now(),
					$run_id,
					$token
				)
			);
			if ( 1 !== $updated ) {
				throw new \RuntimeException( __( 'The Code generation lease expired before the file could be saved.', 'wp-autoplugin' ) );
			}
			$this->wpdb->query( 'COMMIT' );
			return true;
		} catch ( \Throwable $error ) {
			$this->wpdb->query( 'ROLLBACK' );
			throw $error;
		}
	}

	/**
	 * Persist a validated follow-up change analysis and initialize its file work.
	 *
	 * @param array<string, mixed>             $manifest    Normalized desired manifest.
	 * @param array<string, mixed>             $instructions Bounded change metadata.
	 * @param array<int, array<string, mixed>> $files       Ordered changed/new files.
	 */
	public function complete_analysis_changes( int $run_id, string $token, array $manifest, array $instructions, string $summary, array $files, array $usage ): bool {
		$this->wpdb->query( 'START TRANSACTION' );
		try {
			$now = $this->now();
			foreach ( array_values( $files ) as $sequence => $file ) {
				$inserted = $this->wpdb->insert(
					Installer::table( 'code_run_files' ),
					[
						'run_id'         => $run_id,
						'sequence'       => $sequence,
						'path'           => $file['path'],
						'type'           => $file['type'],
						'description'    => $file['instruction'],
						'operation'      => $file['operation'],
						'status'         => 'pending',
						'error_metadata' => $this->json( [] ),
						'created_at'     => $now,
						'updated_at'     => $now,
					]
				);
				if ( false === $inserted ) {
					throw new \RuntimeException( __( 'Could not initialize a Code follow-up file.', 'wp-autoplugin' ) );
				}
			}
			$updated = $this->wpdb->query(
				$this->wpdb->prepare(
					'UPDATE ' . Installer::table( 'code_runs' ) . ' SET phase = %s, generation = generation + 1, next_file_index = 0, retry_count = 0, target_manifest = %s, change_instructions = %s, change_summary = %s, input_tokens = input_tokens + %d, output_tokens = output_tokens + %d, last_error = NULL, lease_token = NULL, lease_expires_at = NULL, updated_at = %s WHERE id = %d AND lease_token = %s',
					'files',
					$this->json( $manifest ),
					$this->json( $instructions ),
					$summary,
					max( 0, (int) ( $usage['input_tokens'] ?? 0 ) ),
					max( 0, (int) ( $usage['output_tokens'] ?? 0 ) ),
					$now,
					$run_id,
					$token
				)
			);
			if ( 1 !== $updated ) {
				throw new \RuntimeException( __( 'The Code follow-up lease expired before analysis could be saved.', 'wp-autoplugin' ) );
			}
			$this->wpdb->query( 'COMMIT' );
			return true;
		} catch ( \Throwable $error ) {
			$this->wpdb->query( 'ROLLBACK' );
			throw $error;
		}
	}

	/** Advance a fully generated follow-up into its independent request-compliance pass. */
	public function begin_compliance( int $run_id, string $token ): bool {
		$query = $this->wpdb->prepare(
			'UPDATE ' . Installer::table( 'code_runs' ) . ' SET phase = %s, generation = generation + 1, retry_count = 0, last_error = NULL, lease_token = NULL, lease_expires_at = NULL, updated_at = %s WHERE id = %d AND lease_token = %s',
			'compliance',
			$this->now(),
			$run_id,
			$token
		);
		return 1 === $this->wpdb->query( $query ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Prepared immediately above.
	}

	/**
	 * Regenerate the affected file and every later file once after a compliance mismatch.
	 *
	 * @param array<string, mixed>             $instructions Updated durable change metadata.
	 * @param array<int, array<string, mixed>> $issues       Bounded compliance feedback.
	 * @param array<string, int>               $usage        Compliance-call usage.
	 */
	public function retry_compliance( int $run_id, string $token, int $from_sequence, array $instructions, array $issues, array $usage, string $message ): bool {
		$this->wpdb->query( 'START TRANSACTION' );
		try {
			$files = Installer::table( 'code_run_files' );
			$query = $this->wpdb->prepare(
				"UPDATE $files SET status = %s, content = NULL, content_hash = NULL, error_metadata = %s, updated_at = %s WHERE run_id = %d AND sequence >= %d",
				'pending',
				$this->json( [ 'message' => substr( $message, 0, 500 ), 'issues' => $issues ] ),
				$this->now(),
				$run_id,
				$from_sequence
			);
			if ( false === $this->wpdb->query( $query ) ) { // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Prepared immediately above.
				throw new \RuntimeException( __( 'Could not reset Code files after the request-compliance check.', 'wp-autoplugin' ) );
			}
			$updated = $this->wpdb->query(
				$this->wpdb->prepare(
					'UPDATE ' . Installer::table( 'code_runs' ) . ' SET phase = %s, generation = generation + 1, next_file_index = %d, retry_count = 0, change_instructions = %s, input_tokens = input_tokens + %d, output_tokens = output_tokens + %d, last_error = %s, lease_token = NULL, lease_expires_at = NULL, updated_at = %s WHERE id = %d AND lease_token = %s',
					'files',
					$from_sequence,
					$this->json( $instructions ),
					max( 0, (int) ( $usage['input_tokens'] ?? 0 ) ),
					max( 0, (int) ( $usage['output_tokens'] ?? 0 ) ),
					substr( $message, 0, 500 ),
					$this->now(),
					$run_id,
					$token
				)
			);
			if ( 1 !== $updated ) {
				throw new \RuntimeException( __( 'The Code compliance lease expired before regeneration could start.', 'wp-autoplugin' ) );
			}
			$this->wpdb->query( 'COMMIT' );
			return true;
		} catch ( \Throwable $error ) {
			$this->wpdb->query( 'ROLLBACK' );
			throw $error;
		}
	}

	/** Complete a question without creating a revision. */
	public function complete_answer( int $run_id, string $token, string $content, array $usage ): bool {
		$updated = $this->wpdb->query(
			$this->wpdb->prepare(
				'UPDATE ' . Installer::table( 'code_runs' ) . ' SET status = %s, phase = %s, outcome = %s, answer_content = %s, generation = generation + 1, retry_count = 0, input_tokens = input_tokens + %d, output_tokens = output_tokens + %d, last_error = NULL, lease_token = NULL, lease_expires_at = NULL, updated_at = %s WHERE id = %d AND lease_token = %s',
				'completed',
				'completed',
				'answer',
				$content,
				max( 0, (int) ( $usage['input_tokens'] ?? 0 ) ),
				max( 0, (int) ( $usage['output_tokens'] ?? 0 ) ),
				$this->now(),
				$run_id,
				$token
			)
		);
		return 1 === $updated;
	}

	/** Add usage for a provider response that failed deterministic validation. */
	public function account_usage( int $run_id, string $token, array $usage ): void {
		$this->wpdb->query(
			$this->wpdb->prepare(
				'UPDATE ' . Installer::table( 'code_runs' ) . ' SET input_tokens = input_tokens + %d, output_tokens = output_tokens + %d, updated_at = %s WHERE id = %d AND lease_token = %s',
				max( 0, (int) ( $usage['input_tokens'] ?? 0 ) ),
				max( 0, (int) ( $usage['output_tokens'] ?? 0 ) ),
				$this->now(),
				$run_id,
				$token
			)
		); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Prepared immediately above.
	}

	/** @param array<int, array<string, mixed>> $issues */
	public function retry_file( int $run_id, int $sequence, string $token, string $message, array $issues = [] ): bool {
		$this->wpdb->query( 'START TRANSACTION' );
		try {
			$file_updated = $this->wpdb->update(
				Installer::table( 'code_run_files' ),
				[ 'status' => 'pending', 'error_metadata' => $this->json( [ 'message' => $message, 'issues' => $issues ] ), 'updated_at' => $this->now() ],
				[ 'run_id' => $run_id, 'sequence' => $sequence ]
			);
			if ( false === $file_updated ) {
				throw new \RuntimeException( __( 'Could not save Code retry state.', 'wp-autoplugin' ) );
			}
			$query = $this->wpdb->prepare(
				'UPDATE ' . Installer::table( 'code_runs' ) . ' SET generation = generation + 1, retry_count = retry_count + 1, last_error = %s, lease_token = NULL, lease_expires_at = NULL, updated_at = %s WHERE id = %d AND lease_token = %s',
				$message,
				$this->now(),
				$run_id,
				$token
			);
			if ( 1 !== $this->wpdb->query( $query ) ) { // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Prepared immediately above.
				throw new \RuntimeException( __( 'The Code generation lease expired before the retry could be saved.', 'wp-autoplugin' ) );
			}
			$this->wpdb->query( 'COMMIT' );
			return true;
		} catch ( \Throwable $error ) {
			$this->wpdb->query( 'ROLLBACK' );
			throw $error;
		}
	}

	/** Save bounded retry state for the analysis request. */
	public function retry_analysis( int $run_id, string $token, string $message ): bool {
		$query = $this->wpdb->prepare(
			'UPDATE ' . Installer::table( 'code_runs' ) . ' SET generation = generation + 1, retry_count = retry_count + 1, last_error = %s, lease_token = NULL, lease_expires_at = NULL, updated_at = %s WHERE id = %d AND lease_token = %s',
			substr( $message, 0, 500 ),
			$this->now(),
			$run_id,
			$token
		);
		return 1 === $this->wpdb->query( $query ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Prepared immediately above.
	}

	public function release( int $run_id, string $token ): void {
		$this->wpdb->update(
			Installer::table( 'code_runs' ),
			[ 'lease_token' => null, 'lease_expires_at' => null, 'updated_at' => $this->now() ],
			[ 'id' => $run_id, 'lease_token' => $token ]
		);
	}

	/** Scrub temporary source on every terminal path. */
	public function terminate_by_job( int $job_id, string $status, string $message = '' ): void {
		$run = $this->find_by_job( $job_id );
		if ( ! $run ) {
			return;
		}
		$this->wpdb->update(
			Installer::table( 'code_runs' ),
			[ 'status' => sanitize_key( $status ), 'phase' => sanitize_key( $status ), 'last_error' => $message ?: null, 'lease_token' => null, 'lease_expires_at' => null, 'updated_at' => $this->now() ],
			[ 'id' => $run['id'] ]
		);
		if ( 'failed' === $status ) {
			$this->wpdb->query(
				$this->wpdb->prepare(
					'UPDATE ' . Installer::table( 'code_run_files' ) . ' SET status = %s, error_metadata = %s, updated_at = %s WHERE run_id = %d AND status = %s',
					'failed',
					$this->json( [ 'message' => substr( $message, 0, 500 ) ] ),
					$this->now(),
					$run['id'],
					'generating'
				)
			); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Prepared immediately above.
		}
		$this->wpdb->query(
			$this->wpdb->prepare( 'UPDATE ' . Installer::table( 'code_run_files' ) . ' SET content = NULL, updated_at = %s WHERE run_id = %d', $this->now(), $run['id'] )
		); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Prepared immediately above.
	}

	/** @param array<string, mixed> $row */
	private function hydrate( array $row ): array {
		foreach ( [ 'id', 'job_id', 'plan_job_id', 'revision_id', 'parent_revision_id', 'generation', 'next_file_index', 'prompt_version', 'retry_count', 'input_tokens', 'output_tokens' ] as $field ) {
			$row[ $field ] = null === $row[ $field ] ? null : (int) $row[ $field ];
		}
		$row['target_manifest']     = $this->decode( $row['target_manifest'] ?? null );
		$row['change_instructions'] = $this->decode( $row['change_instructions'] ?? null );
		return $row;
	}

	/** @param array<string, mixed> $row */
	private function hydrate_file( array $row ): array {
		foreach ( [ 'id', 'run_id', 'sequence' ] as $field ) {
			$row[ $field ] = (int) $row[ $field ];
		}
		$row['error_metadata'] = $this->decode( $row['error_metadata'] );
		return $row;
	}
}
