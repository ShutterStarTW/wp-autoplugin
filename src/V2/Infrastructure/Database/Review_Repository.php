<?php

namespace WP_Autoplugin\V2\Infrastructure\Database;

/** Persists immutable Review reports and stable finding-thread projections. */
final class Review_Repository extends Repository {
	/**
	 * @param array<string, mixed>      $job
	 * @param array<string, mixed>      $revision
	 * @param array<string, mixed>      $parsed
	 * @param array<string, string|int> $model
	 * @return array<string, mixed>|\WP_Error
	 */
	public function create_report( array $job, array $revision, array $parsed, array $model ) {
		if ( 'report' !== ( $parsed['outcome'] ?? '' ) ) {
			return new \WP_Error( 'review_report_invalid', __( 'Only a complete Review report can be persisted.', 'wp-autoplugin' ) );
		}
		$project_id = (int) $job['project_id'];
		$parent_id    = absint( $job['payload']['parent_report_id'] ?? 0 );
		$now          = $this->now();
		$this->wpdb->query( 'START TRANSACTION' );
		try {
			$this->wpdb->get_var(
				$this->wpdb->prepare( 'SELECT id FROM ' . Installer::table( 'projects' ) . ' WHERE id = %d FOR UPDATE', $project_id )
			);
			if ( ( new Revision_Repository( $this->wpdb ) )->latest_id( $project_id ) !== (int) $revision['id'] ) {
				$this->wpdb->query( 'ROLLBACK' );
				return new \WP_Error( 'review_revision_stale', __( 'A newer staged revision exists. Review the latest revision instead.', 'wp-autoplugin' ), [ 'status' => 409 ] );
			}
			$inserted = $this->wpdb->insert(
				Installer::table( 'review_reports' ),
				[
					'job_id'           => (int) $job['id'],
					'project_id'     => $project_id,
					'revision_id'      => (int) $revision['id'],
					'parent_report_id' => $parent_id ?: null,
					'mode'             => sanitize_key( (string) ( $job['payload']['mode'] ?? 'initial' ) ),
					'verdict'          => 'pending',
					'summary'          => (string) $parsed['summary'],
					'tests'            => $this->json( (array) $parsed['tests'] ),
					'provider'         => sanitize_key( (string) $model['provider'] ),
					'model'            => sanitize_text_field( (string) $model['model'] ),
					'effort'           => sanitize_key( (string) ( $model['effort'] ?? '' ) ),
					'prompt_slug'      => sanitize_key( (string) $model['prompt_slug'] ),
					'prompt_version'   => (int) $model['prompt_version'],
					'created_by'       => (int) $job['created_by'],
					'created_at'       => $now,
				]
			);
			if ( false === $inserted || ! $this->wpdb->insert_id ) {
				throw new \RuntimeException( __( 'Could not persist the Review report.', 'wp-autoplugin' ) );
			}
			$report_id    = (int) $this->wpdb->insert_id;
			$dispositions = [];
			foreach ( (array) $parsed['prior_findings'] as $item ) {
				$dispositions[ (int) $item['finding_id'] ] = $item;
			}

			$current = $this->current_findings( $project_id, true );
			foreach ( $current as $finding ) {
				$event = 'updated';
				if ( isset( $dispositions[ (int) $finding['id'] ] ) && 'dismissed' !== $finding['status'] ) {
					$item        = $dispositions[ (int) $finding['id'] ];
					$disposition = (string) $item['disposition'];
					$event       = 'open' === $disposition ? 'updated' : $disposition;
					if ( 'open' === $disposition ) {
						$finding = array_merge(
							$finding,
							(array) $item['finding'],
							[
								'status'                   => 'open',
								'addressed_by_revision_id' => null,
								'latest_report_id'         => $report_id,
							]
						);
					} else {
						$finding['status']                   = $disposition;
						$finding['addressed_by_revision_id'] = null;
						$finding['latest_report_id']         = $report_id;
					}
					$this->update_finding( $finding );
				} else {
					$finding['latest_report_id'] = $report_id;
					$this->wpdb->update(
						Installer::table( 'review_findings' ),
						[
							'latest_report_id' => $report_id,
							'updated_at'       => $now,
						],
						[ 'id' => (int) $finding['id'] ],
						[ '%d', '%s' ],
						[ '%d' ]
					);
				}
				$this->insert_event( $finding, $report_id, (int) $revision['id'], (int) $job['id'], $event, 'ai', '', (int) $job['created_by'] );
			}

			foreach ( (array) $parsed['new_findings'] as $item ) {
				$data = array_merge(
					$item,
					[
						'project_id'      => $project_id,
						'created_report_id' => $report_id,
						'latest_report_id'  => $report_id,
						'status'            => 'open',
						'created_by'        => (int) $job['created_by'],
						'created_at'        => $now,
						'updated_at'        => $now,
					]
				);
				$this->insert_finding( $data );
				$data['id'] = (int) $this->wpdb->insert_id;
				$this->insert_event( $data, $report_id, (int) $revision['id'], (int) $job['id'], 'opened', 'ai', '', (int) $job['created_by'] );
			}

			$open    = $this->count_statuses( $project_id, [ 'open', 'addressed' ] );
			$verdict = $open > 0 ? 'action_required' : 'all_clear';
			if ( false === $this->wpdb->update( Installer::table( 'review_reports' ), [ 'verdict' => $verdict ], [ 'id' => $report_id ], [ '%s' ], [ '%d' ] ) ) {
				throw new \RuntimeException( __( 'Could not finalize the Review verdict.', 'wp-autoplugin' ) );
			}
			$this->wpdb->query( 'COMMIT' );
			return $this->find( $report_id );
		} catch ( \Throwable $error ) {
			$this->wpdb->query( 'ROLLBACK' );
			throw $error;
		}
	}

	/** @return array<string, mixed>|null */
	public function find( int $id ): ?array {
		$row = $this->wpdb->get_row( $this->wpdb->prepare( 'SELECT * FROM ' . Installer::table( 'review_reports' ) . ' WHERE id = %d', $id ), ARRAY_A );
		if ( ! $row ) {
			return null;
		}
		$report   = $this->hydrate_report( $row );
		$events   = $this->wpdb->get_results(
			$this->wpdb->prepare( 'SELECT * FROM ' . Installer::table( 'review_finding_events' ) . ' WHERE report_id = %d ORDER BY id ASC', $id ),
			ARRAY_A
		);
		$findings = [];
		foreach ( (array) $events as $event ) {
			$snapshot = $this->decode( $event['snapshot'] );
			if ( $snapshot ) {
				$findings[ (int) $event['finding_id'] ] = $this->hydrate_finding( $snapshot );
			}
		}
		$report['findings']         = array_values( $findings );
		$report['effective_status'] = $this->effective_status_for_report( $report );
		return $report;
	}

	/** @return array<int, array<string, mixed>> */
	public function list_for_workspace( int $project_id ): array {
		$rows = $this->wpdb->get_results(
			$this->wpdb->prepare( 'SELECT * FROM ' . Installer::table( 'review_reports' ) . ' WHERE project_id = %d ORDER BY id DESC', $project_id ),
			ARRAY_A
		);
		return array_map( [ $this, 'hydrate_report' ], (array) $rows );
	}

	public function latest_for_revision( int $project_id, int $revision_id ): ?array {
		$id = $this->wpdb->get_var(
			$this->wpdb->prepare( 'SELECT id FROM ' . Installer::table( 'review_reports' ) . ' WHERE project_id = %d AND revision_id = %d ORDER BY id DESC LIMIT 1', $project_id, $revision_id )
		);
		return $id ? $this->find( (int) $id ) : null;
	}

	public function latest_for_workspace( int $project_id ): ?array {
		$id = $this->wpdb->get_var(
			$this->wpdb->prepare( 'SELECT id FROM ' . Installer::table( 'review_reports' ) . ' WHERE project_id = %d ORDER BY id DESC LIMIT 1', $project_id )
		);
		return $id ? $this->find( (int) $id ) : null;
	}

	/** @return array<int, array<string, mixed>> */
	public function required_findings( int $project_id ): array {
		return array_values( array_filter( $this->current_findings( $project_id ), static fn( array $finding ): bool => in_array( $finding['status'], [ 'open', 'addressed' ], true ) ) );
	}

	/** @return array<string, mixed>|null */
	public function finding( int $id ): ?array {
		$row = $this->wpdb->get_row( $this->wpdb->prepare( 'SELECT * FROM ' . Installer::table( 'review_findings' ) . ' WHERE id = %d', $id ), ARRAY_A );
		return $row ? $this->hydrate_finding( $row ) : null;
	}

	/** @return array<int, array<string, mixed>> */
	public function events( int $finding_id ): array {
		$rows = $this->wpdb->get_results(
			$this->wpdb->prepare( 'SELECT id, finding_id, report_id, revision_id, job_id, event, actor, message, created_by, created_at FROM ' . Installer::table( 'review_finding_events' ) . ' WHERE finding_id = %d ORDER BY id ASC', $finding_id ),
			ARRAY_A
		);
		return array_map(
			static function ( array $row ): array {
				foreach ( [ 'id', 'finding_id', 'report_id', 'revision_id', 'job_id', 'created_by' ] as $field ) {
					$row[ $field ] = null === $row[ $field ] ? null : (int) $row[ $field ];
				}
				return $row;
			},
			(array) $rows
		);
	}

	/** @return array<string, mixed>|\WP_Error */
	public function dismiss( int $finding_id, int $report_id, int $revision_id, string $reason, int $user_id ) {
		return $this->administrator_transition( $finding_id, $report_id, $revision_id, 'dismissed', $reason, $user_id );
	}

	/** @return array<string, mixed>|\WP_Error */
	public function reopen( int $finding_id, int $report_id, int $revision_id, string $reason, int $user_id ) {
		return $this->administrator_transition( $finding_id, $report_id, $revision_id, 'open', $reason, $user_id );
	}

	/** @param array<int, int> $finding_ids */
	public function address( int $project_id, array $finding_ids, int $revision_id, int $job_id, int $user_id ): bool {
		$ids = array_values( array_unique( array_filter( array_map( 'absint', $finding_ids ) ) ) );
		if ( ! $ids ) {
			return false;
		}
		$this->wpdb->query( 'START TRANSACTION' );
		try {
			$this->wpdb->get_var( $this->wpdb->prepare( 'SELECT id FROM ' . Installer::table( 'projects' ) . ' WHERE id = %d FOR UPDATE', $project_id ) );
			$by_id = [];
			foreach ( $this->current_findings( $project_id, true ) as $finding ) {
				$by_id[ (int) $finding['id'] ] = $finding;
			}
			$findings = [];
			foreach ( $ids as $id ) {
				if ( ! isset( $by_id[ $id ] ) || 'open' !== $by_id[ $id ]['status'] ) {
					$this->wpdb->query( 'ROLLBACK' );
					return false;
				}
				$findings[] = $by_id[ $id ];
			}
			foreach ( $findings as $finding ) {
				$finding['status']                   = 'addressed';
				$finding['addressed_by_revision_id'] = $revision_id;
				$this->update_finding( $finding );
				$this->insert_event( $finding, (int) $finding['latest_report_id'], $revision_id, $job_id, 'addressed', 'system', __( 'A successor revision was staged for this finding.', 'wp-autoplugin' ), $user_id );
			}
			$this->wpdb->query( 'COMMIT' );
			return true;
		} catch ( \Throwable $error ) {
			$this->wpdb->query( 'ROLLBACK' );
			throw $error;
		}
	}

	/** @return array<string, mixed> */
	public function workspace_status( int $project_id, ?int $latest_revision_id ): array {
		$latest = $this->latest_for_workspace( $project_id );
		if ( ! $latest ) {
			return [
				'status'      => 'not_started',
				'report_id'   => null,
				'revision_id' => null,
				'open'        => 0,
				'dismissed'   => 0,
			];
		}
		if ( null === $latest_revision_id || (int) $latest['revision_id'] !== $latest_revision_id ) {
			return [
				'status'      => 'stale',
				'report_id'   => (int) $latest['id'],
				'revision_id' => (int) $latest['revision_id'],
				'open'        => $this->count_statuses( $project_id, [ 'open', 'addressed' ] ),
				'dismissed'   => $this->count_statuses( $project_id, [ 'dismissed' ] ),
			];
		}
		$open      = $this->count_statuses( $project_id, [ 'open', 'addressed' ] );
		$dismissed = $this->count_statuses( $project_id, [ 'dismissed' ] );
		$status    = $open ? 'action_required' : ( $dismissed ? 'cleared_with_dismissals' : 'all_clear' );
		return [
			'status'      => $status,
			'report_id'   => (int) $latest['id'],
			'revision_id' => (int) $latest['revision_id'],
			'open'        => $open,
			'dismissed'   => $dismissed,
		];
	}

	/** @return array<string, mixed>|\WP_Error */
	private function administrator_transition( int $finding_id, int $report_id, int $revision_id, string $target, string $reason, int $user_id ) {
		$this->wpdb->query( 'START TRANSACTION' );
		try {
			$finding = $this->finding( $finding_id );
			$report  = $this->find( $report_id );
			if ( ! $finding || ! $report || (int) $finding['project_id'] !== (int) $report['project_id'] || (int) $report['revision_id'] !== $revision_id ) {
				$this->wpdb->query( 'ROLLBACK' );
				return new \WP_Error( 'review_finding_conflict', __( 'The Review finding is no longer current.', 'wp-autoplugin' ), [ 'status' => 409 ] );
			}
			$project_id = (int) $report['project_id'];
			$this->wpdb->get_var( $this->wpdb->prepare( 'SELECT id FROM ' . Installer::table( 'projects' ) . ' WHERE id = %d FOR UPDATE', $project_id ) );
			$current_report_id = (int) $this->wpdb->get_var( $this->wpdb->prepare( 'SELECT id FROM ' . Installer::table( 'review_reports' ) . ' WHERE project_id = %d AND revision_id = %d ORDER BY id DESC LIMIT 1', $project_id, $revision_id ) );
			if ( $current_report_id !== $report_id || ( new Revision_Repository( $this->wpdb ) )->latest_id( $project_id ) !== $revision_id || ( new Job_Repository( $this->wpdb ) )->has_active_artifact_work( $project_id ) ) {
				$this->wpdb->query( 'ROLLBACK' );
				return new \WP_Error( 'review_finding_conflict', __( 'The Review finding is no longer current.', 'wp-autoplugin' ), [ 'status' => 409 ] );
			}
			$finding = $this->finding( $finding_id );
			if ( ! $finding || ( 'dismissed' === $target && ! in_array( $finding['status'], [ 'open', 'addressed' ], true ) ) || ( 'open' === $target && 'dismissed' !== $finding['status'] ) ) {
				$this->wpdb->query( 'ROLLBACK' );
				return new \WP_Error( 'review_finding_state', __( 'The Review finding cannot make that transition.', 'wp-autoplugin' ), [ 'status' => 409 ] );
			}
			$finding['status']       = $target;
			$finding['dismissed_by'] = 'dismissed' === $target ? $user_id : null;
			$finding['dismissed_at'] = 'dismissed' === $target ? $this->now() : null;
			$this->update_finding( $finding );
			$this->insert_event( $finding, $report_id, $revision_id, 0, 'dismissed' === $target ? 'dismissed' : 'reopened', 'user', $reason, $user_id );
			$this->wpdb->query( 'COMMIT' );
			return $this->finding( $finding_id );
		} catch ( \Throwable $error ) {
			$this->wpdb->query( 'ROLLBACK' );
			throw $error;
		}
	}

	/** @return array<int, array<string, mixed>> */
	private function current_findings( int $project_id, bool $for_update = false ): array {
		$sql  = 'SELECT * FROM ' . Installer::table( 'review_findings' ) . ' WHERE project_id = %d ORDER BY id ASC' . ( $for_update ? ' FOR UPDATE' : '' );
		$rows = $this->wpdb->get_results( $this->wpdb->prepare( $sql, $project_id ), ARRAY_A );
		return array_map( [ $this, 'hydrate_finding' ], (array) $rows );
	}

	private function count_statuses( int $project_id, array $statuses ): int {
		if ( ! $statuses ) {
			return 0;
		}
		$placeholders = implode( ',', array_fill( 0, count( $statuses ), '%s' ) );
		return (int) $this->wpdb->get_var(
			$this->wpdb->prepare( 'SELECT COUNT(*) FROM ' . Installer::table( 'review_findings' ) . " WHERE project_id = %d AND status IN ($placeholders)", $project_id, ...$statuses )
		);
	}

	/** @param array<string, mixed> $finding */
	private function insert_finding( array $finding ): void {
		$inserted = $this->wpdb->insert(
			Installer::table( 'review_findings' ),
			[
				'project_id'             => (int) $finding['project_id'],
				'created_report_id'        => (int) $finding['created_report_id'],
				'latest_report_id'         => (int) $finding['latest_report_id'],
				'status'                   => (string) $finding['status'],
				'priority'                 => (string) $finding['priority'],
				'category'                 => (string) $finding['category'],
				'title'                    => (string) $finding['title'],
				'body'                     => (string) $finding['body'],
				'suggested_fix'            => (string) ( $finding['suggested_fix'] ?? '' ),
				'path'                     => $finding['path'],
				'side'                     => $finding['side'],
				'start_line'               => $finding['start_line'],
				'end_line'                 => $finding['end_line'],
				'anchor_hash'              => $finding['anchor_hash'],
				'addressed_by_revision_id' => null,
				'dismissed_by'             => null,
				'dismissed_at'             => null,
				'created_by'               => (int) $finding['created_by'],
				'created_at'               => (string) $finding['created_at'],
				'updated_at'               => (string) $finding['updated_at'],
			]
		);
		if ( false === $inserted ) {
			throw new \RuntimeException( __( 'Could not persist a Review finding.', 'wp-autoplugin' ) );
		}
	}

	/** @param array<string, mixed> $finding */
	private function update_finding( array $finding ): void {
		$data = [
			'latest_report_id'         => (int) $finding['latest_report_id'],
			'status'                   => (string) $finding['status'],
			'priority'                 => (string) $finding['priority'],
			'category'                 => (string) $finding['category'],
			'title'                    => (string) $finding['title'],
			'body'                     => (string) $finding['body'],
			'suggested_fix'            => (string) ( $finding['suggested_fix'] ?? '' ),
			'path'                     => $finding['path'],
			'side'                     => $finding['side'],
			'start_line'               => $finding['start_line'],
			'end_line'                 => $finding['end_line'],
			'anchor_hash'              => $finding['anchor_hash'],
			'addressed_by_revision_id' => $finding['addressed_by_revision_id'] ?? null,
			'dismissed_by'             => $finding['dismissed_by'] ?? null,
			'dismissed_at'             => $finding['dismissed_at'] ?? null,
			'updated_at'               => $this->now(),
		];
		if ( false === $this->wpdb->update( Installer::table( 'review_findings' ), $data, [ 'id' => (int) $finding['id'] ] ) ) {
			throw new \RuntimeException( __( 'Could not update a Review finding.', 'wp-autoplugin' ) );
		}
	}

	/** @param array<string, mixed> $finding */
	private function insert_event( array $finding, int $report_id, int $revision_id, int $job_id, string $event, string $actor, string $message, int $user_id ): void {
		$inserted = $this->wpdb->insert(
			Installer::table( 'review_finding_events' ),
			[
				'finding_id'  => (int) $finding['id'],
				'report_id'   => $report_id ?: null,
				'revision_id' => $revision_id,
				'job_id'      => $job_id ?: null,
				'event'       => sanitize_key( $event ),
				'actor'       => sanitize_key( $actor ),
				'message'     => wp_html_excerpt( sanitize_textarea_field( $message ), 2000, '' ),
				'snapshot'    => $this->json( $this->snapshot( $finding ) ),
				'created_by'  => $user_id,
				'created_at'  => $this->now(),
			]
		);
		if ( false === $inserted ) {
			throw new \RuntimeException( __( 'Could not persist the Review finding history.', 'wp-autoplugin' ) );
		}
	}

	/** @param array<string, mixed> $finding @return array<string, mixed> */
	private function snapshot( array $finding ): array {
		$fields = [ 'id', 'project_id', 'created_report_id', 'latest_report_id', 'status', 'priority', 'category', 'title', 'body', 'suggested_fix', 'path', 'side', 'start_line', 'end_line', 'anchor_hash', 'addressed_by_revision_id', 'dismissed_by', 'dismissed_at', 'created_by', 'created_at', 'updated_at' ];
		return array_intersect_key( $finding, array_flip( $fields ) );
	}

	/** @param array<string, mixed> $row @return array<string, mixed> */
	private function hydrate_report( array $row ): array {
		foreach ( [ 'id', 'job_id', 'project_id', 'revision_id', 'parent_report_id', 'prompt_version', 'created_by' ] as $field ) {
			$row[ $field ] = null === $row[ $field ] ? null : (int) $row[ $field ];
		}
		$row['tests'] = $this->decode( $row['tests'] );
		return $row;
	}

	/** @param array<string, mixed> $row @return array<string, mixed> */
	private function hydrate_finding( array $row ): array {
		foreach ( [ 'id', 'project_id', 'created_report_id', 'latest_report_id', 'start_line', 'end_line', 'addressed_by_revision_id', 'dismissed_by', 'created_by' ] as $field ) {
			$row[ $field ] = array_key_exists( $field, $row ) && null !== $row[ $field ] ? (int) $row[ $field ] : null;
		}
		return $row;
	}

	/** @param array<string, mixed> $report */
	private function effective_status_for_report( array $report ): string {
		$latest_revision = ( new Revision_Repository( $this->wpdb ) )->latest_id( (int) $report['project_id'] );
		if ( $latest_revision !== (int) $report['revision_id'] ) {
			return 'stale';
		}
		$open      = $this->count_statuses( (int) $report['project_id'], [ 'open', 'addressed' ] );
		$dismissed = $this->count_statuses( (int) $report['project_id'], [ 'dismissed' ] );
		return $open ? 'action_required' : ( $dismissed ? 'cleared_with_dismissals' : 'all_clear' );
	}
}
