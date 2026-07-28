<?php

namespace WP_Autoplugin\V2\Infrastructure\Database;

use WP_Autoplugin\V2\Domain\AI\Prompt_Image_Validator;

/** Persists private, workspace-owned prompt images separately from public job data. */
final class Prompt_Attachment_Repository extends Repository {
	/**
	 * Persist new images and link new/reused records to a job in stable order.
	 *
	 * @param array<int, array<string, mixed>> $images
	 * @param array<int, int>                  $reuse_ids
	 * @return array<int, array<string, mixed>>|\WP_Error
	 */
	public function attach( int $job_id, int $workspace_id, int $user_id, array $images, array $reuse_ids = [] ) {
		$reuse_ids = array_values( array_unique( array_filter( array_map( 'absint', $reuse_ids ) ) ) );
		if ( count( $images ) + count( $reuse_ids ) > Prompt_Image_Validator::MAX_IMAGES ) {
			return new \WP_Error( 'wp_autoplugin_prompt_images_count', __( 'Attach no more than six images to one message.', 'wp-autoplugin' ), [ 'status' => 400 ] );
		}
		$job = $this->wpdb->get_row(
			$this->wpdb->prepare( 'SELECT workspace_id, created_by FROM ' . Installer::table( 'jobs' ) . ' WHERE id = %d', $job_id ),
			ARRAY_A
		);
		if ( ! $job || (int) $job['workspace_id'] !== $workspace_id || (int) $job['created_by'] !== $user_id ) {
			return new \WP_Error( 'wp_autoplugin_prompt_attachment_job_invalid', __( 'Prompt images cannot be linked to this job.', 'wp-autoplugin' ), [ 'status' => 404 ] );
		}

		$records = [];
		$total   = 0;
		foreach ( $reuse_ids as $id ) {
			$record = $this->find( $id );
			if ( ! $record || (int) $record['workspace_id'] !== $workspace_id || (int) $record['created_by'] !== $user_id ) {
				return new \WP_Error( 'wp_autoplugin_prompt_attachment_invalid', __( 'A reused prompt image is unavailable in this workspace.', 'wp-autoplugin' ), [ 'status' => 404 ] );
			}
			$total += (int) $record['byte_size'];
			if ( $total > Prompt_Image_Validator::MAX_TOTAL_BYTES ) {
				return new \WP_Error( 'wp_autoplugin_prompt_images_total', __( 'Prompt images may use at most 20 MiB in total.', 'wp-autoplugin' ), [ 'status' => 400 ] );
			}
			$records[] = $record;
		}
		foreach ( $images as $image ) {
			$total += (int) $image['byte_size'];
			if ( $total > Prompt_Image_Validator::MAX_TOTAL_BYTES ) {
				return new \WP_Error( 'wp_autoplugin_prompt_images_total', __( 'Prompt images may use at most 20 MiB in total.', 'wp-autoplugin' ), [ 'status' => 400 ] );
			}
		}

		$this->wpdb->query( 'START TRANSACTION' );
		try {
			foreach ( $images as $image ) {
				$this->wpdb->insert(
					Installer::table( 'prompt_attachments' ),
					[
						'workspace_id' => $workspace_id,
						'created_by'   => $user_id,
						'filename'     => (string) $image['filename'],
						'mime_type'    => (string) $image['mime_type'],
						'byte_size'    => (int) $image['byte_size'],
						'width'        => (int) $image['width'],
						'height'       => (int) $image['height'],
						'sha256'       => (string) $image['sha256'],
						'content'      => (string) $image['content'],
						'created_at'   => $this->now(),
					],
					[ '%d', '%d', '%s', '%s', '%d', '%d', '%d', '%s', '%s', '%s' ]
				);
				$id = (int) $this->wpdb->insert_id;
				if ( ! $id ) {
					throw new \RuntimeException( __( 'A prompt image could not be stored.', 'wp-autoplugin' ) );
				}
				$records[] = $this->find( $id );
			}

			foreach ( $records as $sequence => $record ) {
				if ( ! $record || false === $this->wpdb->insert(
					Installer::table( 'job_prompt_attachments' ),
					[
						'job_id'        => $job_id,
						'attachment_id' => (int) $record['id'],
						'sequence'      => $sequence,
					],
					[ '%d', '%d', '%d' ]
				) ) {
					throw new \RuntimeException( __( 'A prompt image could not be linked to its job.', 'wp-autoplugin' ) );
				}
			}
			if ( false === $this->wpdb->query( 'COMMIT' ) ) {
				throw new \RuntimeException( __( 'Prompt images could not be committed.', 'wp-autoplugin' ) );
			}
		} catch ( \Throwable $error ) {
			$this->wpdb->query( 'ROLLBACK' );
			return new \WP_Error( 'wp_autoplugin_prompt_attachment_store', $error->getMessage(), [ 'status' => 500 ] );
		}

		return $this->for_job( $job_id );
	}

	/** @return array<string, mixed>|null */
	public function find( int $id, bool $with_content = true ): ?array {
		$columns = $with_content ? '*' : 'id, workspace_id, created_by, filename, mime_type, byte_size, width, height, sha256, created_at';
		$row     = $this->wpdb->get_row( $this->wpdb->prepare( 'SELECT ' . $columns . ' FROM ' . Installer::table( 'prompt_attachments' ) . ' WHERE id = %d', $id ), ARRAY_A );
		return $row ? $this->hydrate( $row ) : null;
	}

	/** @return array<int, array<string, mixed>> */
	public function for_job( int $job_id, bool $with_content = false ): array {
		$columns = $with_content ? 'a.*' : 'a.id, a.workspace_id, a.created_by, a.filename, a.mime_type, a.byte_size, a.width, a.height, a.sha256, a.created_at';
		$rows    = $this->wpdb->get_results(
			$this->wpdb->prepare( 'SELECT ' . $columns . ' FROM ' . Installer::table( 'prompt_attachments' ) . ' a INNER JOIN ' . Installer::table( 'job_prompt_attachments' ) . ' j ON j.attachment_id = a.id WHERE j.job_id = %d ORDER BY j.sequence ASC', $job_id ),
			ARRAY_A
		);
		$records = array_map( [ $this, 'hydrate' ], (array) $rows );
		return $with_content ? $records : array_map( [ $this, 'summary' ], $records );
	}

	/** @param array<string, mixed> $record @return array<string, mixed> */
	private function summary( array $record ): array {
		return array_intersect_key(
			$record,
			array_flip( [ 'id', 'filename', 'mime_type', 'byte_size', 'width', 'height', 'sha256', 'created_at', 'preview_path' ] )
		);
	}

	/** @param array<string, mixed> $row @return array<string, mixed> */
	private function hydrate( array $row ): array {
		foreach ( [ 'id', 'workspace_id', 'created_by', 'byte_size', 'width', 'height' ] as $field ) {
			$row[ $field ] = (int) $row[ $field ];
		}
		$row['preview_path'] = '/wp-autoplugin/v2/prompt-attachments/' . $row['id'];
		return $row;
	}
}
