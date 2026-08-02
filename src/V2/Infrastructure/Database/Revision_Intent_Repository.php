<?php

namespace WP_Autoplugin\V2\Infrastructure\Database;

/** Resolves the immutable administrator intent that belongs to one exact revision. */
final class Revision_Intent_Repository extends Repository {
	private const MAX_DEPTH = 100;

	/** @return array<string, mixed>|\WP_Error */
	public function for_revision( int $revision_id ) {
		$revision = $this->revision( $revision_id );
		if ( ! $revision ) {
			return new \WP_Error( 'revision_intent_missing', __( 'The staged revision intent is unavailable.', 'wp-autoplugin' ) );
		}

		$visited = [];
		$intent  = $this->resolve( $revision, $visited, 0 );
		if ( is_wp_error( $intent ) ) {
			return $intent;
		}

		return [
			'revision_id'             => (int) $revision['id'],
			'plan_id'                 => (int) $revision['plan_id'],
			'derived_from_revision_id' => (int) $intent['derived_from_revision_id'],
			'accepted_code_changes'    => array_values( $intent['accepted_code_changes'] ),
			'provenance'               => [
				'origin'                    => (string) $revision['origin'],
				'parent_revision_id'        => $revision['parent_revision_id'],
				'restored_from_revision_id' => $revision['restored_from_revision_id'],
			],
		];
	}

	/** @param array<int, bool> $visited @return array<string, mixed>|\WP_Error */
	private function resolve( array $revision, array &$visited, int $depth ) {
		$revision_id = (int) $revision['id'];
		if ( $depth >= self::MAX_DEPTH || isset( $visited[ $revision_id ] ) ) {
			return new \WP_Error( 'revision_intent_cycle', __( 'The staged revision intent lineage is invalid.', 'wp-autoplugin' ) );
		}
		$visited[ $revision_id ] = true;

		if ( $revision['restored_from_revision_id'] ) {
			$restored = $this->revision( (int) $revision['restored_from_revision_id'] );
			if ( ! $restored || (int) $restored['project_id'] !== (int) $revision['project_id'] ) {
				return new \WP_Error( 'revision_intent_restore_missing', __( 'The historical revision intent restored into this artifact is unavailable.', 'wp-autoplugin' ) );
			}
			return $this->resolve( $restored, $visited, $depth + 1 );
		}

		$base = [
			'derived_from_revision_id' => $revision_id,
			'plan_id'                 => (int) $revision['plan_id'],
			'accepted_code_changes'    => [],
		];
		$parent = $revision['parent_revision_id'] ? $this->revision( (int) $revision['parent_revision_id'] ) : null;
		if ( $parent && (int) $parent['project_id'] !== (int) $revision['project_id'] ) {
			return new \WP_Error( 'revision_intent_parent_invalid', __( 'The staged revision intent parent is invalid.', 'wp-autoplugin' ) );
		}

		$job = $revision['source_job_id'] ? ( new Job_Repository( $this->wpdb ) )->find( (int) $revision['source_job_id'] ) : null;
		$run = $revision['source_job_id'] ? ( new Code_Run_Repository( $this->wpdb ) )->find_by_job( (int) $revision['source_job_id'] ) : null;

		if ( $job && 'review_fix' === ( $job['task'] ?? '' ) ) {
			return $this->inherit( $revision, $parent, $visited, $depth, $base );
		}

		$is_code_follow_up = $job
			&& 'conversation' === ( $job['task'] ?? '' )
			&& 'code' === ( $job['payload']['stage'] ?? '' );
		if ( $is_code_follow_up ) {
			if ( ! $run || 'follow_up' !== ( $run['mode'] ?? '' ) || 'revision' !== ( $run['outcome'] ?? '' ) || (int) ( $run['revision_id'] ?? 0 ) !== $revision_id ) {
				return new \WP_Error( 'revision_intent_change_missing', __( 'The accepted Code-change intent for this revision is unavailable.', 'wp-autoplugin' ) );
			}
			$intent = $this->inherit( $revision, $parent, $visited, $depth, $base );
			if ( is_wp_error( $intent ) ) {
				return $intent;
			}
			$metadata = (array) ( $run['change_instructions'] ?? [] );
			$request  = trim( (string) ( $metadata['resolved_request'] ?? '' ) );
			$criteria = array_values(
				array_filter(
					array_map( static fn( $criterion ): string => is_string( $criterion ) ? trim( $criterion ) : '', (array) ( $metadata['acceptance_criteria'] ?? [] ) )
				)
			);
			if ( '' === $request || ! $criteria ) {
				return new \WP_Error( 'revision_intent_change_invalid', __( 'The accepted Code-change requirements for this revision are invalid.', 'wp-autoplugin' ) );
			}
			$intent['accepted_code_changes'][] = [
				'revision_id'        => $revision_id,
				'source_job_id'       => (int) $job['id'],
				'resolved_request'    => $this->bounded( $request, 4096 ),
				'acceptance_criteria' => array_map( fn( string $criterion ): string => $this->bounded( $criterion, 1024 ), array_slice( $criteria, 0, 8 ) ),
			];
			return $intent;
		}
		if ( $run && 'follow_up' === ( $run['mode'] ?? '' ) ) {
			return new \WP_Error( 'revision_intent_change_job_invalid', __( 'The accepted Code-change job for this revision is invalid.', 'wp-autoplugin' ) );
		}

		if ( ( $job && 'code' === ( $job['task'] ?? '' ) ) || ( $run && in_array( (string) ( $run['mode'] ?? '' ), [ 'generate', 'regenerate' ], true ) ) ) {
			return $base;
		}

		return $this->inherit( $revision, $parent, $visited, $depth, $base );
	}

	/** @param array<int, bool> $visited @return array<string, mixed>|\WP_Error */
	private function inherit( array $revision, ?array $parent, array &$visited, int $depth, array $base ) {
		if ( ! $parent || (int) $parent['plan_id'] !== (int) $revision['plan_id'] ) {
			return $base;
		}
		return $this->resolve( $parent, $visited, $depth + 1 );
	}

	/** @return array<string, mixed>|null */
	private function revision( int $revision_id ): ?array {
		$row = $this->wpdb->get_row(
			$this->wpdb->prepare(
				'SELECT id, project_id, plan_id, source_job_id, parent_revision_id, restored_from_revision_id, origin FROM ' . Installer::table( 'revisions' ) . ' WHERE id = %d',
				$revision_id
			),
			ARRAY_A
		);
		if ( ! $row ) {
			return null;
		}
		foreach ( [ 'id', 'project_id', 'plan_id', 'source_job_id', 'parent_revision_id', 'restored_from_revision_id' ] as $field ) {
			$row[ $field ] = null === $row[ $field ] ? null : (int) $row[ $field ];
		}
		return $row;
	}

	private function bounded( string $value, int $bytes ): string {
		if ( strlen( $value ) <= $bytes ) {
			return $value;
		}
		if ( function_exists( 'mb_strcut' ) ) {
			return mb_strcut( $value, 0, $bytes, 'UTF-8' );
		}
		return wp_check_invalid_utf8( substr( $value, 0, $bytes ), true );
	}
}
