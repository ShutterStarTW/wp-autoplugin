<?php

namespace WP_Autoplugin\V2\Infrastructure\Database;

/**
 * Persists validated staged revisions without touching target source.
 */
final class Revision_Repository extends Repository {
	/**
	 * @param array<int, array<string, mixed>> $files Staged file changes.
	 * @return array<string, mixed>
	 */
	public function stage( int $workspace_id, array $files, string $summary, int $user_id ): array {
		if ( empty( $files ) ) {
			throw new \InvalidArgumentException( 'A revision must contain at least one file change.' );
		}

		$validated = array_map( [ $this, 'validate_file' ], $files );
		$paths     = array_column( $validated, 'path' );
		if ( count( $paths ) !== count( array_unique( $paths ) ) ) {
			throw new \InvalidArgumentException( 'A revision cannot contain duplicate file paths.' );
		}

		$revisions = Installer::table( 'revisions' );
		$now       = $this->now();
		$this->wpdb->query( 'START TRANSACTION' );

		try {
			$number = (int) $this->wpdb->get_var(
				$this->wpdb->prepare( "SELECT COALESCE(MAX(revision_number), 0) + 1 FROM $revisions WHERE workspace_id = %d FOR UPDATE", $workspace_id ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Internal allow-listed table.
			);
			$this->wpdb->insert(
				$revisions,
				[
					'workspace_id'   => $workspace_id,
					'revision_number'=> $number,
					'status'         => 'staged',
					'summary'        => $summary,
					'created_by'     => $user_id,
					'created_at'     => $now,
				]
			);
			$revision_id = (int) $this->wpdb->insert_id;

			foreach ( $validated as $file ) {
				$this->wpdb->insert(
					Installer::table( 'revision_files' ),
					[
						'revision_id' => $revision_id,
						'path'        => $file['path'],
						'change_type' => $file['change_type'],
						'content'     => $file['content'],
						'patch'       => $file['patch'],
						'content_hash'=> null === $file['content'] ? null : hash( 'sha256', $file['content'] ),
					]
				);
			}

			$this->wpdb->update( Installer::table( 'workspaces' ), [ 'status' => 'staged', 'updated_at' => $now ], [ 'id' => $workspace_id ] );
			$this->wpdb->query( 'COMMIT' );

			return $this->find( $revision_id );
		} catch ( \Throwable $error ) {
			$this->wpdb->query( 'ROLLBACK' );
			throw $error;
		}
	}

	/**
	 * @return array<string, mixed>|null
	 */
	public function find( int $id ): ?array {
		$revision = $this->wpdb->get_row(
			$this->wpdb->prepare( 'SELECT * FROM ' . Installer::table( 'revisions' ) . ' WHERE id = %d', $id ),
			ARRAY_A
		);
		if ( ! $revision ) {
			return null;
		}

		$revision['id']              = (int) $revision['id'];
		$revision['workspace_id']    = (int) $revision['workspace_id'];
		$revision['revision_number'] = (int) $revision['revision_number'];
		$revision['files']           = $this->wpdb->get_results(
			$this->wpdb->prepare( 'SELECT id, path, change_type, content, patch, content_hash FROM ' . Installer::table( 'revision_files' ) . ' WHERE revision_id = %d ORDER BY path ASC', $id ),
			ARRAY_A
		);

		return $revision;
	}

	/**
	 * @param array<string, mixed> $file Raw file change.
	 * @return array<string, string|null>
	 */
	private function validate_file( array $file ): array {
		$path        = str_replace( '\\', '/', trim( (string) ( $file['path'] ?? '' ) ) );
		$change_type = sanitize_key( (string) ( $file['change_type'] ?? '' ) );
		$content     = array_key_exists( 'content', $file ) ? (string) $file['content'] : null;
		$patch       = array_key_exists( 'patch', $file ) ? (string) $file['patch'] : null;

		if ( '' === $path || str_starts_with( $path, '/' ) || preg_match( '/^[A-Za-z]:/', $path ) || in_array( '..', explode( '/', $path ), true ) || preg_match( '/[\x00-\x1F]/', $path ) ) {
			throw new \InvalidArgumentException( 'Revision files must use safe paths relative to the target root.' );
		}
		if ( ! in_array( $change_type, [ 'add', 'update', 'delete' ], true ) ) {
			throw new \InvalidArgumentException( 'Unsupported revision change type.' );
		}
		if ( 'delete' !== $change_type && null === $content && null === $patch ) {
			throw new \InvalidArgumentException( 'Added and updated files require content or a unified patch.' );
		}

		return compact( 'path', 'change_type', 'content', 'patch' );
	}
}
