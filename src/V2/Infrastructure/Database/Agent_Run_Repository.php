<?php

namespace WP_Autoplugin\V2\Infrastructure\Database;

/**
 * Persists resumable agent state without exposing source excerpts through REST.
 */
final class Agent_Run_Repository extends Repository {
	/**
	 * @param array<int, array<string, mixed>> $transcript Canonical provider-neutral messages.
	 * @param array<string, string>             $inspected  Relative paths keyed to source hashes.
	 * @return array<string, mixed>
	 */
	public function create( int $job_id, string $provider, string $model, array $transcript, string $tree_fingerprint, array $inspected, int $source_bytes = 0 ): array {
		$now = $this->now();
		$this->wpdb->insert(
			Installer::table( 'agent_runs' ),
			[
				'job_id'           => $job_id,
				'status'           => 'active',
				'provider'         => sanitize_key( $provider ),
				'model'            => sanitize_text_field( $model ),
				'transcript'       => $this->json( $transcript ),
				'tree_fingerprint' => $tree_fingerprint,
				'inspected_files'  => $this->json( $inspected ),
				'source_bytes'     => max( 0, $source_bytes ),
				'created_at'       => $now,
				'updated_at'       => $now,
			]
		);

		$run = $this->find_by_job( $job_id );
		if ( ! $run ) {
			throw new \RuntimeException( __( 'Could not initialize the Explain agent.', 'wp-autoplugin' ) );
		}

		return $run;
	}

	/** @return array<string, mixed>|null */
	public function find_by_job( int $job_id ): ?array {
		$row = $this->wpdb->get_row(
			$this->wpdb->prepare( 'SELECT * FROM ' . Installer::table( 'agent_runs' ) . ' WHERE job_id = %d', $job_id ),
			ARRAY_A
		);
		return $row ? $this->hydrate( $row ) : null;
	}

	/** @return array<int, array<string, mixed>> */
	public function recoverable(): array {
		$before = gmdate( 'Y-m-d H:i:s', time() - 2 * MINUTE_IN_SECONDS );
		$rows = $this->wpdb->get_results(
			$this->wpdb->prepare(
				'SELECT r.* FROM ' . Installer::table( 'agent_runs' ) . ' r INNER JOIN ' . Installer::table( 'jobs' ) . ' j ON j.id = r.job_id WHERE r.status = %s AND r.updated_at <= %s AND (r.lease_token IS NULL OR r.lease_expires_at < %s) AND j.status IN (%s,%s)',
				'active',
				$before,
				$this->now(),
				'running',
				'retrying'
			),
			ARRAY_A
		);
		return array_map( [ $this, 'hydrate' ], $rows );
	}

	/**
	 * Atomically lease the expected continuation generation.
	 */
	public function acquire( int $run_id, int $generation, string $token, int $seconds = 90 ): bool {
		$now     = $this->now();
		$expires = gmdate( 'Y-m-d H:i:s', time() + $seconds );
		$query   = $this->wpdb->prepare(
			'UPDATE ' . Installer::table( 'agent_runs' ) . ' SET lease_token = %s, lease_expires_at = %s, updated_at = %s WHERE id = %d AND status = %s AND generation = %d AND (lease_token IS NULL OR lease_expires_at < %s)',
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

	/**
	 * @param array<string, mixed> $state Updated bounded state.
	 */
	public function checkpoint( int $run_id, string $token, array $state ): bool {
		$allowed = [ 'generation', 'model_turns', 'tool_calls', 'source_bytes', 'input_tokens', 'output_tokens', 'transcript', 'inspected_files', 'retry_count', 'last_error' ];
		$data    = [ 'updated_at' => $this->now(), 'lease_token' => null, 'lease_expires_at' => null ];
		foreach ( $state as $field => $value ) {
			if ( in_array( $field, $allowed, true ) ) {
				$data[ $field ] = in_array( $field, [ 'transcript', 'inspected_files' ], true ) ? $this->json( $value ) : $value;
			}
		}
		return false !== $this->wpdb->update( Installer::table( 'agent_runs' ), $data, [ 'id' => $run_id, 'lease_token' => $token ] );
	}

	public function release( int $run_id, string $token ): void {
		$this->wpdb->update(
			Installer::table( 'agent_runs' ),
			[ 'lease_token' => null, 'lease_expires_at' => null, 'updated_at' => $this->now() ],
			[ 'id' => $run_id, 'lease_token' => $token ]
		);
	}

	/**
	 * Remove source-bearing resumability data while retaining redacted audit metadata.
	 */
	public function finish( int $run_id, string $token, string $status ): void {
		$this->wpdb->update(
			Installer::table( 'agent_runs' ),
			[
				'status'           => sanitize_key( $status ),
				'transcript'       => $this->json( [] ),
				'lease_token'      => null,
				'lease_expires_at' => null,
				'updated_at'       => $this->now(),
			],
			[ 'id' => $run_id, 'lease_token' => $token ]
		);
		$this->scrub_step_payloads( $run_id, $status );
	}

	public function terminate_by_job( int $job_id, string $status ): void {
		$run = $this->find_by_job( $job_id );
		if ( ! $run ) {
			return;
		}
		$this->wpdb->update(
			Installer::table( 'agent_runs' ),
			[ 'status' => sanitize_key( $status ), 'transcript' => $this->json( [] ), 'lease_token' => null, 'lease_expires_at' => null, 'updated_at' => $this->now() ],
			[ 'id' => $run['id'] ]
		);
		$this->scrub_step_payloads( (int) $run['id'], $status );
	}

	private function scrub_step_payloads( int $run_id, string $status ): void {
		$preserve_failed_model = 'failed' === $status && defined( 'WP_DEBUG' ) && WP_DEBUG;
		if ( $preserve_failed_model ) {
			$query = $this->wpdb->prepare( 'UPDATE ' . Installer::table( 'agent_steps' ) . ' SET payload = NULL WHERE run_id = %d AND kind = %s', $run_id, 'tool' );
		} else {
			$query = $this->wpdb->prepare( 'UPDATE ' . Installer::table( 'agent_steps' ) . ' SET payload = NULL WHERE run_id = %d AND kind IN (%s,%s)', $run_id, 'model', 'tool' );
		}
		$this->wpdb->query( $query ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Prepared in the branches above.
	}

	/**
	 * Append a private step. Tool payloads are always scrubbed at termination.
	 * Failed model payloads remain private only while WP_DEBUG is enabled.
	 *
	 * @param array<string, mixed> $payload Private step payload.
	 */
	public function step( int $run_id, string $kind, array $payload, string $tool_name = '', string $path = '' ): void {
		$table    = Installer::table( 'agent_steps' );
		$sequence = (int) $this->wpdb->get_var( $this->wpdb->prepare( "SELECT COALESCE(MAX(sequence), 0) + 1 FROM $table WHERE run_id = %d", $run_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Internal allow-listed table.
		$this->wpdb->insert(
			$table,
			[
				'run_id'    => $run_id,
				'sequence'  => $sequence,
				'kind'      => sanitize_key( $kind ),
				'tool_name' => $tool_name ? sanitize_key( $tool_name ) : null,
				'path'      => $path ? sanitize_text_field( $path ) : null,
				'payload'   => $this->json( $payload ),
				'created_at'=> $this->now(),
			]
		);
	}

	/** @param array<string, mixed> $row */
	private function hydrate( array $row ): array {
		foreach ( [ 'id', 'job_id', 'generation', 'model_turns', 'tool_calls', 'source_bytes', 'input_tokens', 'output_tokens', 'retry_count' ] as $field ) {
			$row[ $field ] = (int) $row[ $field ];
		}
		$row['transcript']      = $this->decode( $row['transcript'] );
		$row['inspected_files'] = $this->decode( $row['inspected_files'] );
		return $row;
	}
}
