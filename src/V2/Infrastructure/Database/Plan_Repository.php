<?php

namespace WP_Autoplugin\V2\Infrastructure\Database;

/**
 * Immutable, versioned Plan artifacts.
 */
final class Plan_Repository extends Repository {
	/**
	 * Persist an AI-produced Plan artifact.
	 *
	 * @param array<string, mixed> $result Validated Plan result.
	 * @return array<string, mixed>
	 */
	public function create_artifact( int $project_id, int $source_job_id, array $result, int $user_id, int $parent_plan_id = 0 ): array {
		$existing = $this->find_by_source_job( $source_job_id );
		if ( $existing ) {
			if ( (int) $existing['project_id'] !== $project_id ) {
				throw new \RuntimeException( __( 'The Plan source job belongs to another project.', 'wp-autoplugin' ) );
			}
			return $existing;
		}

		$content    = trim( (string) ( $result['content'] ?? '' ) );
		$structured = is_array( $result['structured'] ?? null ) ? $result['structured'] : null;
		if ( '' === $content || ! $structured ) {
			throw new \RuntimeException( __( 'The Plan artifact is incomplete and could not be stored.', 'wp-autoplugin' ) );
		}
		$origin = 'ai';
		if ( $parent_plan_id ) {
			$parent = $this->find( $parent_plan_id );
			if ( $parent && 'manual' === $parent['origin'] && 'pending_structure' === $parent['status'] ) {
				$origin = 'manual';
			}
		}

		return $this->insert(
			$project_id,
			$content,
			$structured,
			$user_id,
			$origin,
			'ready',
			$source_job_id,
			$parent_plan_id
		);
	}

	/**
	 * Store a human edit as a Plan version awaiting a refreshed file map.
	 *
	 * @param array<string, mixed> $source Ready Plan being edited.
	 * @return array<string, mixed>
	 */
	public function create_manual_successor( array $source, string $content, int $user_id ): array {
		if ( ! $this->is_ready( $source ) ) {
			throw new \RuntimeException( __( 'Only a ready Plan can be edited.', 'wp-autoplugin' ) );
		}
		$content = trim( $content );
		if ( '' === $content ) {
			throw new \RuntimeException( __( 'The Plan cannot be empty.', 'wp-autoplugin' ) );
		}

		return $this->insert(
			(int) $source['project_id'],
			$content,
			null,
			$user_id,
			'manual',
			'pending_structure',
			0,
			(int) $source['id']
		);
	}

	/**
	 * @return array<string, mixed>|null
	 */
	public function find( int $id ): ?array {
		$row = $this->wpdb->get_row(
			$this->wpdb->prepare( 'SELECT * FROM ' . Installer::table( 'plans' ) . ' WHERE id = %d', $id ),
			ARRAY_A
		);

		return $row ? $this->hydrate( $row ) : null;
	}

	/**
	 * @return array<string, mixed>|null
	 */
	public function latest_ready( int $project_id ): ?array {
		$row = $this->wpdb->get_row(
			$this->wpdb->prepare(
				'SELECT * FROM ' . Installer::table( 'plans' ) . ' WHERE project_id = %d AND status = %s ORDER BY plan_number DESC LIMIT 1',
				$project_id,
				'ready'
			),
			ARRAY_A
		);

		return $row ? $this->hydrate( $row ) : null;
	}

	/**
	 * Return completed Plan history newest first without the potentially large
	 * narrative or structured file map.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function list_for_workspace( int $project_id ): array {
		$rows = $this->wpdb->get_results(
			$this->wpdb->prepare(
				'SELECT id, project_id, plan_number, parent_plan_id, source_job_id, status, origin, created_by, created_at FROM ' . Installer::table( 'plans' ) . ' WHERE project_id = %d AND status = %s ORDER BY plan_number DESC',
				$project_id,
				'ready'
			),
			ARRAY_A
		);

		return array_map( [ $this, 'hydrate' ], (array) $rows );
	}

	public function latest_id( int $project_id ): ?int {
		$id = $this->wpdb->get_var(
			$this->wpdb->prepare(
				'SELECT id FROM ' . Installer::table( 'plans' ) . ' WHERE project_id = %d AND status = %s ORDER BY plan_number DESC LIMIT 1',
				$project_id,
				'ready'
			)
		);

		return $id ? (int) $id : null;
	}

	/**
	 * Copy a historical ready Plan into a new immutable latest Plan.
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	public function restore( int $selected_plan_id, int $expected_latest_plan_id, int $user_id ) {
		$selected = $this->find( $selected_plan_id );
		if ( ! $selected || ! $this->is_ready( $selected ) ) {
			return new \WP_Error( 'plan_not_found', __( 'Plan not found.', 'wp-autoplugin' ), [ 'status' => 404 ] );
		}
		if ( $selected_plan_id === $expected_latest_plan_id ) {
			return new \WP_Error( 'plan_restore_latest', __( 'Select an older Plan to restore as latest.', 'wp-autoplugin' ), [ 'status' => 409 ] );
		}

		try {
			return $this->insert(
				(int) $selected['project_id'],
				(string) $selected['content'],
				(array) $selected['structured'],
				$user_id,
				'restore',
				'ready',
				0,
				$selected_plan_id,
				$expected_latest_plan_id,
				true
			);
		} catch ( \RuntimeException $error ) {
			if ( 409 === $error->getCode() ) {
				return new \WP_Error( 'plan_conflict', $error->getMessage(), [ 'status' => 409 ] );
			}
			throw $error;
		}
	}

	/**
	 * Expand a stored job outcome with its referenced Plan artifact.
	 *
	 * @param array<string, mixed> $result Stored job result.
	 * @return array<string, mixed>
	 */
	public function expand_job_result( array $result ): array {
		$plan_id = (int) ( $result['plan_id'] ?? 0 );
		if ( ! $plan_id ) {
			return $result;
		}

		$plan = $this->find( $plan_id );
		if ( ! $plan ) {
			return $result;
		}

		$result['content']    = $plan['content'];
		$result['structured'] = $plan['structured'];
		$result['artifact']   = [
			'type'           => 'plan',
			'content'        => $plan['content'],
			'plan_id'        => $plan['id'],
			'parent_plan_id' => $plan['parent_plan_id'],
		];

		return $result;
	}

	/**
	 * Reduce a validated result to execution metadata and a Plan reference.
	 *
	 * @param array<string, mixed> $result Full orchestration result.
	 * @return array<string, mixed>
	 */
	public function compact_job_result( array $result, int $plan_id ): array {
		unset( $result['content'], $result['structured'], $result['artifact'] );
		$result['outcome'] = 'artifact';
		$result['plan_id'] = $plan_id;

		return $result;
	}

	/**
	 * @param array<string, mixed> $plan Hydrated Plan.
	 */
	public function is_ready( array $plan ): bool {
		return (int) ( $plan['id'] ?? 0 ) > 0
			&& (int) ( $plan['project_id'] ?? 0 ) > 0
			&& 'ready' === ( $plan['status'] ?? '' )
			&& '' !== trim( (string) ( $plan['content'] ?? '' ) )
			&& is_array( $plan['structured'] ?? null );
	}

	/**
	 * @param array<string, mixed>|null $structured Structured Plan data.
	 * @return array<string, mixed>
	 */
	private function insert( int $project_id, string $content, ?array $structured, int $user_id, string $origin, string $status, int $source_job_id, int $parent_plan_id, int $expected_latest_plan_id = 0, bool $enforce_expected = false ): array {
		$this->wpdb->query( 'START TRANSACTION' );
		try {
			$project = $this->wpdb->get_var(
				$this->wpdb->prepare( 'SELECT id FROM ' . Installer::table( 'projects' ) . ' WHERE id = %d FOR UPDATE', $project_id )
			);
			if ( ! $project ) {
				throw new \RuntimeException( __( 'The project no longer exists.', 'wp-autoplugin' ) );
			}

			$table = Installer::table( 'plans' );
			if ( $enforce_expected ) {
				if ( ( new Job_Repository( $this->wpdb ) )->has_active_plan_work( $project_id ) ) {
					throw new \RuntimeException( __( 'Wait for active Plan work to finish before restoring Plan history.', 'wp-autoplugin' ), 409 );
				}
				$latest_id = (int) $this->wpdb->get_var(
					$this->wpdb->prepare( "SELECT id FROM $table WHERE project_id = %d AND status = %s ORDER BY plan_number DESC LIMIT 1 FOR UPDATE", $project_id, 'ready' ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Internal allow-listed table.
				);
				if ( $latest_id !== $expected_latest_plan_id ) {
					throw new \RuntimeException( __( 'A newer Plan exists. Reload Plan history before restoring.', 'wp-autoplugin' ), 409 );
				}
			}
			if ( $source_job_id ) {
				$existing_id = $this->wpdb->get_var(
					$this->wpdb->prepare( "SELECT id FROM $table WHERE source_job_id = %d", $source_job_id ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Internal allow-listed table.
				);
				if ( $existing_id ) {
					$this->wpdb->query( 'COMMIT' );
					$existing = $this->find( (int) $existing_id );
					if ( ! $existing ) {
						throw new \RuntimeException( __( 'Could not load the stored Plan.', 'wp-autoplugin' ) );
					}
					return $existing;
				}

				$job_project_id = $this->wpdb->get_var(
					$this->wpdb->prepare( 'SELECT project_id FROM ' . Installer::table( 'jobs' ) . ' WHERE id = %d', $source_job_id )
				);
				if ( (int) $job_project_id !== $project_id ) {
					throw new \RuntimeException( __( 'The Plan source job does not belong to this project.', 'wp-autoplugin' ) );
				}
			}
			if ( $parent_plan_id ) {
				$parent_project_id = $this->wpdb->get_var(
					$this->wpdb->prepare( "SELECT project_id FROM $table WHERE id = %d", $parent_plan_id ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Internal allow-listed table.
				);
				if ( (int) $parent_project_id !== $project_id ) {
					throw new \RuntimeException( __( 'The parent Plan does not belong to this project.', 'wp-autoplugin' ) );
				}
			}

			$plan_number = (int) $this->wpdb->get_var(
				$this->wpdb->prepare( "SELECT COALESCE(MAX(plan_number), 0) + 1 FROM $table WHERE project_id = %d", $project_id ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Internal allow-listed table.
			);
			$result      = $this->wpdb->insert(
				$table,
				[
					'project_id'    => $project_id,
					'plan_number'   => $plan_number,
					'parent_plan_id' => $parent_plan_id ?: null,
					'source_job_id' => $source_job_id ?: null,
					'status'        => $status,
					'origin'        => $origin,
					'content'       => trim( $content ),
					'structured'    => $structured ? $this->json( $structured ) : null,
					'created_by'    => $user_id,
					'created_at'    => $this->now(),
				],
				[ '%d', '%d', '%d', '%d', '%s', '%s', '%s', '%s', '%d', '%s' ]
			);
			$id          = (int) $this->wpdb->insert_id;
			if ( false === $result || ! $id ) {
				throw new \RuntimeException( __( 'Could not store the Plan.', 'wp-autoplugin' ) );
			}
			$this->wpdb->update(
				Installer::table( 'projects' ),
				[ 'updated_at' => $this->now() ],
				[ 'id' => $project_id ],
				[ '%s' ],
				[ '%d' ]
			);
			if ( false === $this->wpdb->query( 'COMMIT' ) ) {
				throw new \RuntimeException( __( 'Could not finalize the Plan.', 'wp-autoplugin' ) );
			}
		} catch ( \Throwable $error ) {
			$this->wpdb->query( 'ROLLBACK' );
			throw $error;
		}

		return $this->find( $id );
	}

	/**
	 * @return array<string, mixed>|null
	 */
	private function find_by_source_job( int $source_job_id ): ?array {
		if ( ! $source_job_id ) {
			return null;
		}
		$row = $this->wpdb->get_row(
			$this->wpdb->prepare( 'SELECT * FROM ' . Installer::table( 'plans' ) . ' WHERE source_job_id = %d ORDER BY id DESC LIMIT 1', $source_job_id ),
			ARRAY_A
		);

		return $row ? $this->hydrate( $row ) : null;
	}

	/**
	 * @param array<string, mixed> $row Raw database row.
	 * @return array<string, mixed>
	 */
	private function hydrate( array $row ): array {
		foreach ( [ 'id', 'project_id', 'plan_number', 'parent_plan_id', 'source_job_id', 'created_by' ] as $field ) {
			if ( array_key_exists( $field, $row ) ) {
				$row[ $field ] = null === $row[ $field ] ? null : (int) $row[ $field ];
			}
		}
		if ( array_key_exists( 'structured', $row ) ) {
			$row['structured'] = null === $row['structured'] ? null : $this->decode( $row['structured'] );
		}

		return $row;
	}
}
