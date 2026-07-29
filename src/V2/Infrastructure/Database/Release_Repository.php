<?php

namespace WP_Autoplugin\V2\Infrastructure\Database;

use WP_Autoplugin\V2\Release\Private_Release_Storage;

/** Persists private release packages, promotions, and conflict-safe file snapshots. */
final class Release_Repository extends Repository {
	/** @return array<string,mixed> */
	public function create_package( array $job, array $revision, string $mode, string $slug, ?string $target_ref, bool $override, string $artifact_kind = 'plugin' ): array {
		$now = $this->now();
		$this->wpdb->insert(
			Installer::table( 'release_packages' ),
			[
				'job_id'           => (int) $job['id'],
				'project_id'       => (int) $job['project_id'],
				'revision_id'      => (int) $revision['id'],
				'review_report_id' => absint( $job['payload']['review_report_id'] ?? 0 ) ?: null,
				'mode'             => $mode,
				'status'           => 'building',
				'artifact_kind'    => $artifact_kind,
				'target_ref'       => $target_ref,
				'slug'             => $slug,
				'review_override'  => $override ? 1 : 0,
				'created_by'       => (int) $job['created_by'],
				'created_at'       => $now,
				'updated_at'       => $now,
			]
		);
		if ( ! $this->wpdb->insert_id ) {
			throw new \RuntimeException( __( 'Could not create the release package record.', 'wp-autoplugin' ) );
		}
		return $this->package( (int) $this->wpdb->insert_id );
	}

	public function complete_package( int $id, string $path, string $hash, int $size, array $metadata = [] ): bool {
		return false !== $this->wpdb->update(
			Installer::table( 'release_packages' ),
			[
				'status'               => 'ready',
				'temp_path'            => $path,
				'sha256'               => $hash,
				'size'                 => $size,
				'artifact_kind'        => (string) ( $metadata['artifact_kind'] ?? 'plugin' ),
				'target_ref'           => (string) ( $metadata['target_ref'] ?? '' ),
				'slug'                 => (string) ( $metadata['slug'] ?? '' ),
				'source_fingerprint'   => $metadata['source_tree_fingerprint'] ?? null,
				'artifact_fingerprint' => $metadata['tree_fingerprint'] ?? null,
				'header_transforms'    => $this->json( (array) ( $metadata['header_transforms'] ?? [] ) ),
				'expires_at'           => gmdate( 'Y-m-d H:i:s', time() + HOUR_IN_SECONDS ),
				'updated_at'           => $this->now(),
			],
			[ 'id' => $id ]
		);
	}

	public function fail_package( int $id, string $message ): void {
		$this->wpdb->update(
			Installer::table( 'release_packages' ),
			[
				'status'        => 'failed',
				'error_message' => substr( $message, 0, 2000 ),
				'temp_path'     => null,
				'updated_at'    => $this->now(),
			],
			[ 'id' => $id ]
		);
	}

	public function cancel_package( int $id ): void {
		$this->wpdb->update(
			Installer::table( 'release_packages' ),
			[
				'status'        => 'cancelled',
				'error_message' => null,
				'temp_path'     => null,
				'updated_at'    => $this->now(),
			],
			[ 'id' => $id ]
		);
	}

	/** @return array<string,mixed>|null */
	public function package( int $id ): ?array {
		$row = $this->wpdb->get_row( $this->wpdb->prepare( 'SELECT * FROM ' . Installer::table( 'release_packages' ) . ' WHERE id = %d', $id ), ARRAY_A );
		return $row ? $this->hydrate_package( $row ) : null;
	}

	/** @return array<string,mixed>|null */
	public function package_by_job( int $job_id ): ?array {
		$id = $this->wpdb->get_var( $this->wpdb->prepare( 'SELECT id FROM ' . Installer::table( 'release_packages' ) . ' WHERE job_id = %d', $job_id ) );
		return $id ? $this->package( (int) $id ) : null;
	}

	/** Remove expired private artifacts without returning their paths to callers. */
	public function cleanup_expired(): void {
		$rows    = $this->wpdb->get_results( $this->wpdb->prepare( 'SELECT id, temp_path FROM ' . Installer::table( 'release_packages' ) . ' WHERE status = %s AND expires_at IS NOT NULL AND expires_at < %s', 'ready', $this->now() ), ARRAY_A );
		$storage = new Private_Release_Storage();
		foreach ( (array) $rows as $row ) {
			$path = $storage->verified_archive( (string) $row['temp_path'] );
			if ( $path ) {
				wp_delete_file( $path );
			}
			$this->wpdb->update(
				Installer::table( 'release_packages' ),
				[
					'status'     => 'expired',
					'temp_path'  => null,
					'updated_at' => $this->now(),
				],
				[ 'id' => (int) $row['id'] ]
			);
		}
	}

	/** @return array<string,mixed> */
	public function create_promotion( array $job, array $revision, string $mode, ?string $source_ref, ?string $destination_ref, ?string $slug, bool $override, string $artifact_kind = 'plugin' ): array {
		$now = $this->now();
		$this->wpdb->insert(
			Installer::table( 'promotions' ),
			[
				'job_id'                  => (int) $job['id'],
				'project_id'             => (int) $job['project_id'],
				'revision_id'             => (int) $revision['id'],
				'review_report_id'        => absint( $job['payload']['review_report_id'] ?? 0 ) ?: null,
				'mode'                    => $mode,
				'status'                  => 'running',
				'artifact_kind'           => $artifact_kind,
				'source_target_ref'       => $source_ref,
				'destination_target_ref'  => $destination_ref,
				'destination_slug'        => $slug,
				'review_override'         => $override ? 1 : 0,
				'created_by'              => (int) $job['created_by'],
				'created_at'              => $now,
				'updated_at'              => $now,
			]
		);
		if ( ! $this->wpdb->insert_id ) {
			throw new \RuntimeException( __( 'Could not create the promotion record.', 'wp-autoplugin' ) );
		}
		return $this->promotion( (int) $this->wpdb->insert_id );
	}

	/** @param array<string,mixed> $fields */
	public function update_promotion( int $id, array $fields ): bool {
		$allowed = [ 'status', 'artifact_kind', 'source_target_ref', 'destination_target_ref', 'destination_slug', 'target_fingerprint', 'header_transforms', 'created_directories', 'active_before', 'active_after', 'error_message', 'finished_at' ];
		$data    = [ 'updated_at' => $this->now() ];
		foreach ( $fields as $field => $value ) {
			if ( in_array( $field, $allowed, true ) ) {
				$data[ $field ] = in_array( $field, [ 'header_transforms', 'created_directories' ], true ) ? $this->json( $value ) : $value;
			}
		}
		return false !== $this->wpdb->update( Installer::table( 'promotions' ), $data, [ 'id' => $id ] );
	}

	/** @return array<string,mixed>|null */
	public function promotion( int $id ): ?array {
		$row = $this->wpdb->get_row( $this->wpdb->prepare( 'SELECT * FROM ' . Installer::table( 'promotions' ) . ' WHERE id = %d', $id ), ARRAY_A );
		if ( ! $row ) {
			return null;
		}
		foreach ( [ 'id', 'job_id', 'project_id', 'revision_id', 'review_report_id', 'active_before', 'active_after', 'review_override', 'created_by' ] as $field ) {
			$row[ $field ] = null === $row[ $field ] ? null : (int) $row[ $field ];
		}
		$row['header_transforms']      = $this->decode( $row['header_transforms'] );
		$row['created_directories']    = $this->decode( $row['created_directories'] );
		$row['artifact_kind']          = (string) ( ( $row['artifact_kind'] ?? '' ) ?: 'plugin' );
		return $row;
	}

	public function promotion_by_job( int $job_id ): ?array {
		$id = $this->wpdb->get_var( $this->wpdb->prepare( 'SELECT id FROM ' . Installer::table( 'promotions' ) . ' WHERE job_id = %d', $job_id ) );
		return $id ? $this->promotion( (int) $id ) : null;
	}

	/** Persist complete before/after state before the first in-place write. */
	public function replace_promotion_files( int $promotion_id, array $files ): void {
		$this->wpdb->query( 'START TRANSACTION' );
		try {
			$this->wpdb->delete( Installer::table( 'promotion_files' ), [ 'promotion_id' => $promotion_id ] );
			foreach ( $files as $file ) {
				$inserted = $this->wpdb->insert(
					Installer::table( 'promotion_files' ),
					[
						'promotion_id'     => $promotion_id,
						'path'             => $file['path'],
						'operation'        => $file['operation'],
						'base_exists'      => $file['base_exists'] ? 1 : 0,
						'base_content'     => $file['base_content'],
						'base_hash'        => $file['base_hash'],
						'promoted_exists'  => $file['promoted_exists'] ? 1 : 0,
						'promoted_content' => $file['promoted_content'],
						'promoted_hash'    => $file['promoted_hash'],
						'created_at'       => $this->now(),
					]
				);
				if ( false === $inserted ) {
					throw new \RuntimeException( __( 'Could not persist promotion rollback data.', 'wp-autoplugin' ) );
				}
			}
			$this->wpdb->query( 'COMMIT' );
		} catch ( \Throwable $error ) {
			$this->wpdb->query( 'ROLLBACK' );
			throw $error;
		}
	}

	/** @return array<int,array<string,mixed>> */
	public function promotion_files( int $promotion_id ): array {
		$rows = $this->wpdb->get_results( $this->wpdb->prepare( 'SELECT * FROM ' . Installer::table( 'promotion_files' ) . ' WHERE promotion_id = %d ORDER BY id ASC', $promotion_id ), ARRAY_A );
		return array_map(
			static function ( array $row ): array {
				foreach ( [ 'id', 'promotion_id', 'base_exists', 'promoted_exists' ] as $field ) {
					$row[ $field ] = (int) $row[ $field ];
				}
				return $row;
			},
			(array) $rows
		);
	}

	public function is_latest_in_place( int $promotion_id, string $destination_ref, string $artifact_kind = 'plugin' ): bool {
		$modes = 'theme' === $artifact_kind ? [ 'modify_theme_original' ] : [ 'modify_original' ];
		$id    = $this->wpdb->get_var(
			$this->wpdb->prepare(
				'SELECT id FROM ' . Installer::table( 'promotions' ) . ' WHERE mode = %s AND artifact_kind = %s AND destination_target_ref = %s AND status IN (%s,%s) ORDER BY id DESC LIMIT 1',
				$modes[0],
				$artifact_kind,
				$destination_ref,
				'completed',
				'rollback_failed'
			)
		);
		return (int) $id === $promotion_id;
	}

	/** @return array<string,mixed> */
	private function hydrate_package( array $row ): array {
		foreach ( [ 'id', 'job_id', 'project_id', 'revision_id', 'review_report_id', 'size', 'review_override', 'created_by' ] as $field ) {
			$row[ $field ] = null === $row[ $field ] ? null : (int) $row[ $field ];
		}
		$row['header_transforms'] = $this->decode( $row['header_transforms'] ?? null );
		$row['artifact_kind']     = (string) ( ( $row['artifact_kind'] ?? '' ) ?: 'plugin' );
		return $row;
	}
}
