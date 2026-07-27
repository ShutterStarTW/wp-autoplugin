<?php

namespace WP_Autoplugin\V2\Orchestration;

use WP_Autoplugin\V2\Domain\AI\Global_Instructions;
use WP_Autoplugin\V2\Domain\AI\Prompts\Review_Prompt;
use WP_Autoplugin\V2\Domain\AI\Review_Response;
use WP_Autoplugin\V2\Domain\Target\Source_Tools;
use WP_Autoplugin\V2\Infrastructure\AI\Direct_Transport_Factory;
use WP_Autoplugin\V2\Infrastructure\Database\Job_Repository;
use WP_Autoplugin\V2\Infrastructure\Database\Review_Repository;
use WP_Autoplugin\V2\Infrastructure\Database\Revision_Repository;
use WP_Autoplugin\V2\Infrastructure\Database\Usage_Repository;
use WP_Autoplugin\V2\Infrastructure\Database\Workspace_Repository;
use WP_Autoplugin\V2\Infrastructure\Database\Prompt_Attachment_Repository;
use WP_Autoplugin\V2\Infrastructure\Queue\Queue;
use WP_Autoplugin\V2\Release\Package_Builder;

/** Executes immutable, revision-bound Review reports and Review conversations. */
final class Review_Orchestrator {
	public function register(): void {
		add_filter( 'wp_autoplugin_v2_execute_job', [ $this, 'execute' ], 8, 2 );
		add_action( 'wp_autoplugin_v2_job_completed', [ $this, 'queue_verification' ], 10, 2 );
	}

	/** Queue incremental verification only after the Review-fix lock is released. */
	public function queue_verification( ?array $job, array $result ): void {
		if ( ! $job || 'review_fix' !== ( $job['task'] ?? '' ) || empty( $job['payload']['auto_re_review'] ) || 'revision' !== ( $result['outcome'] ?? '' ) || empty( $result['revision_id'] ) ) {
			return;
		}
		$jobs = new Job_Repository();
		$verification = null;
		try {
			$verification = $jobs->create(
				(int) $job['workspace_id'],
				'review',
				[
					'revision_id'                 => (int) $result['revision_id'],
					'expected_latest_revision_id' => (int) $result['revision_id'],
					'mode'                        => 'verification',
					'parent_report_id'            => (int) ( $job['payload']['review_report_id'] ?? 0 ),
					'reviewer'                    => (array) ( $job['payload']['reviewer'] ?? [] ),
				],
				(int) $job['created_by']
			);
			$runner = ( new Queue() )->dispatch( (int) $verification['id'] );
			$jobs->update( (int) $verification['id'], [ 'runner' => $runner ] );
			$jobs->event( (int) $job['id'], 'review_verification_queued', __( 'Incremental Review was queued for the Review-fix revision.', 'wp-autoplugin' ), [ 'review_job_id' => (int) $verification['id'], 'revision_id' => (int) $result['revision_id'] ] );
		} catch ( \Throwable $error ) {
			if ( $verification ) {
				$jobs->update( (int) $verification['id'], [ 'status' => 'failed', 'error_message' => $error->getMessage(), 'finished_at' => current_time( 'mysql', true ) ] );
				$jobs->event( (int) $verification['id'], 'failed', $error->getMessage(), [], 'error' );
			}
			$jobs->event( (int) $job['id'], 'review_verification_dispatch_failed', __( 'The successor revision is intact, but automatic Review could not be queued. Use Verify fixes.', 'wp-autoplugin' ), [ 'revision_id' => (int) $result['revision_id'] ], 'warning' );
		}
	}

	/**
	 * @param array<string, mixed>|null $result Previous adapter result.
	 * @param array<string, mixed>      $job    Durable job.
	 * @return array<string, mixed>|\WP_Error|null
	 */
	public function execute( $result, array $job ) {
		if ( null !== $result || ! $this->supports( $job ) ) {
			return $result;
		}
		$workspaces = new Workspace_Repository();
		$revisions  = new Revision_Repository();
		$reviews    = new Review_Repository();
		$jobs       = new Job_Repository();
		$workspace  = $workspaces->find( (int) $job['workspace_id'] );
		$revision_id = absint( $job['payload']['revision_id'] ?? 0 );
		$revision    = $revision_id ? $revisions->find( $revision_id ) : null;
		if ( ! $workspace || ! $revision || (int) $revision['workspace_id'] !== (int) $workspace['id'] ) {
			return new \WP_Error( 'review_revision_missing', __( 'The staged revision for this Review is unavailable.', 'wp-autoplugin' ) );
		}
		if ( $revision_id !== $revisions->latest_id( (int) $workspace['id'] ) ) {
			return new \WP_Error( 'review_revision_stale', __( 'A newer staged revision exists. Review the latest revision instead.', 'wp-autoplugin' ), [ 'status' => 409 ] );
		}
		$stale = $this->target_is_stale( $workspace, $revision );
		if ( is_wp_error( $stale ) ) {
			return $stale;
		}

		$parent_id = absint( $job['payload']['parent_report_id'] ?? 0 );
		$parent    = $parent_id ? $reviews->find( $parent_id ) : null;
		if ( $parent_id && ( ! $parent || (int) $parent['workspace_id'] !== (int) $workspace['id'] ) ) {
			return new \WP_Error( 'review_parent_missing', __( 'The previous Review report is unavailable in this workspace.', 'wp-autoplugin' ) );
		}
		$is_conversation = 'conversation' === ( $job['task'] ?? '' );
		if ( $is_conversation && ( ! $parent || (int) $parent['revision_id'] !== $revision_id ) ) {
			return new \WP_Error( 'review_conversation_stale', __( 'Review follow-ups require the latest report for the latest revision.', 'wp-autoplugin' ), [ 'status' => 409 ] );
		}

		$capability = [
			'provider' => sanitize_key( (string) ( $job['payload']['reviewer']['provider'] ?? '' ) ),
			'model'    => sanitize_text_field( (string) ( $job['payload']['reviewer']['model'] ?? '' ) ),
			'effort'   => sanitize_key( (string) ( $job['payload']['reviewer']['effort'] ?? '' ) ),
		];
		if ( '' === $capability['provider'] || '' === $capability['model'] ) {
			$current    = ( new Direct_Transport_Factory() )->capability( 'review' );
			$capability = [ 'provider' => $current['provider'], 'model' => $current['model'], 'effort' => $current['effort'] ];
		}
		$transport = ( new Direct_Transport_Factory() )->create_for( $capability['provider'], $capability['model'], $capability['effort'] );
		if ( is_wp_error( $transport ) ) {
			return $transport;
		}

		$previous = $reviews->required_findings( (int) $workspace['id'] );
		$context  = $this->context( $workspace, $revision, $parent, $jobs );
		if ( is_wp_error( $context ) ) {
			return $context;
		}
		$same_revision = $parent && (int) $parent['revision_id'] === $revision_id;
		$prompt        = new Review_Prompt();
		$instructions  = Global_Instructions::apply(
			$prompt->instructions( $is_conversation, (bool) $same_revision ),
			$jobs->global_instructions( (int) $job['id'] )
		);
		$input         = $prompt->input(
			$context,
			$previous,
			$this->history( $jobs->list_for_workspace( (int) $workspace['id'] ), (int) $job['id'] ),
			$is_conversation ? (string) ( $job['payload']['message'] ?? '' ) : ''
		);

		$jobs->update( (int) $job['id'], [ 'progress' => 25 ] );
		$jobs->event(
			(int) $job['id'],
			'review_provider_request',
			__( 'Sending the staged revision to the selected reviewer.', 'wp-autoplugin' ),
			[ 'revision_id' => $revision_id, 'provider' => $transport->provider(), 'model' => $transport->model(), 'effort' => $transport->effort(), 'prompt_slug' => Review_Prompt::SLUG, 'prompt_version' => Review_Prompt::VERSION ]
		);

		$parsed = null;
		$usage  = [ 'input_tokens' => 0, 'output_tokens' => 0 ];
		for ( $attempt = 1; $attempt <= 3; $attempt++ ) {
			$latest_job = $jobs->find( (int) $job['id'] );
			if ( ! $latest_job || $latest_job['cancel_requested'] ) {
				return $this->cancel( $job, $jobs );
			}
			$response = $transport->complete( $instructions, $input, [ 'json' => true, 'max_output_tokens' => 16384, 'prompt_images' => ( new Prompt_Attachment_Repository() )->for_job( (int) $job['id'], true ) ] );
			if ( ! is_wp_error( $response ) ) {
				$attempt_usage = (array) ( $response['usage'] ?? [] );
				$usage['input_tokens']  += (int) ( $attempt_usage['input_tokens'] ?? 0 );
				$usage['output_tokens'] += (int) ( $attempt_usage['output_tokens'] ?? 0 );
				( new Usage_Repository() )->record( (int) $job['id'], $transport->provider(), $transport->model(), 'review', $attempt_usage );
				if ( 'final' !== ( $response['type'] ?? '' ) || ! is_string( $response['content'] ?? null ) ) {
					$response = new \WP_Error( 'review_response_invalid', __( 'The reviewer did not return a final Review response.', 'wp-autoplugin' ), [ 'retryable' => true, 'ambiguous' => false ] );
				} else {
					$parsed = ( new Review_Response() )->parse( $response['content'], $revision, $previous, $is_conversation, (bool) $same_revision );
					if ( ! is_wp_error( $parsed ) ) {
						break;
					}
					$response = $parsed;
				}
			}
			$data = is_wp_error( $response ) ? (array) $response->get_error_data() : [];
			if ( 3 === $attempt || empty( $data['retryable'] ) || ! empty( $data['ambiguous'] ) ) {
				return is_wp_error( $response ) ? $response : new \WP_Error( 'review_failed', __( 'Review failed.', 'wp-autoplugin' ) );
			}
			$jobs->event( (int) $job['id'], 'review_retry', __( 'The reviewer response was invalid or retryable; retrying.', 'wp-autoplugin' ), [ 'attempt' => $attempt + 1 ], 'warning' );
			$input .= "\n\nThe previous response failed strict validation. Return the complete exact JSON contract again.";
		}

		if ( ! is_array( $parsed ) ) {
			return new \WP_Error( 'review_failed', __( 'Review did not produce a valid result.', 'wp-autoplugin' ) );
		}
		$latest_job = $jobs->find( (int) $job['id'] );
		if ( ! $latest_job || $latest_job['cancel_requested'] ) {
			return $this->cancel( $job, $jobs );
		}
		if ( 'answer' === $parsed['outcome'] ) {
			return [
				'outcome'     => 'answer',
				'content'     => $parsed['content'],
				'report_id'   => $parent_id,
				'revision_id' => $revision_id,
				'model'       => $transport->model(),
				'provider'    => $transport->provider(),
				'effort'      => $transport->effort(),
				'usage'       => $usage,
			];
		}

		$report = $reviews->create_report(
			$job,
			$revision,
			$parsed,
			[ 'provider' => $transport->provider(), 'model' => $transport->model(), 'effort' => $transport->effort(), 'prompt_slug' => Review_Prompt::SLUG, 'prompt_version' => Review_Prompt::VERSION ]
		);
		if ( is_wp_error( $report ) ) {
			return $report;
		}
		$jobs->event( (int) $job['id'], 'review_report_created', __( 'The immutable Review report was saved.', 'wp-autoplugin' ), [ 'report_id' => (int) $report['id'], 'revision_id' => $revision_id, 'verdict' => $report['verdict'] ] );
		return [
			'outcome'     => 'report',
			'content'     => (string) ( $parsed['content'] ?? '' ),
			'report_id'   => (int) $report['id'],
			'revision_id' => $revision_id,
			'verdict'     => $report['verdict'],
			'model'       => $transport->model(),
			'provider'    => $transport->provider(),
			'effort'      => $transport->effort(),
			'usage'       => $usage,
			'prompt'      => [ 'slug' => Review_Prompt::SLUG, 'version' => Review_Prompt::VERSION ],
		];
	}

	/** @param array<string, mixed> $job */
	private function supports( array $job ): bool {
		return 'review' === ( $job['task'] ?? '' )
			|| ( 'conversation' === ( $job['task'] ?? '' ) && 'review' === ( $job['payload']['stage'] ?? '' ) );
	}

	/** @return array<string, mixed>|\WP_Error */
	private function context( array $workspace, array $revision, ?array $parent, Job_Repository $jobs ) {
		$plan = $jobs->find( (int) ( $revision['plan_job_id'] ?? 0 ) );
		$files = [];
		$parent_files = [];
		$parent_revision = null;
		if ( ! empty( $revision['parent_revision_id'] ) ) {
			$parent_revision = ( new Revision_Repository() )->find( (int) $revision['parent_revision_id'] );
			foreach ( (array) ( $parent_revision['files'] ?? [] ) as $file ) {
				$parent_files[ (string) $file['path'] ] = (string) $file['content'];
			}
		}
		foreach ( (array) $revision['files'] as $file ) {
			$before = null !== ( $file['base_content'] ?? null ) ? (string) $file['base_content'] : ( $parent_files[ $file['path'] ] ?? null );
			$file['base_content'] = $before;
			$files[] = [
				'id'           => (int) $file['id'],
				'path'         => (string) $file['path'],
				'change_type'  => (string) $file['change_type'],
				'content'      => (string) $file['content'],
				'base_content' => $before,
				'patch'        => (string) ( $file['patch'] ?? '' ),
			];
		}
		$revision['files'] = $files;
		$target_tree = null;
		if ( 'changes' === ( $revision['project_manifest']['scope'] ?? '' ) ) {
			try {
				$tree = ( new Source_Tools( (array) $workspace['target_metadata'] ) )->revision_tree();
				$target_tree = [ 'directories' => array_slice( (array) $tree['directories'], 0, 500 ), 'files' => array_slice( array_map( static fn( array $file ): array => [ 'path' => $file['path'], 'type' => $file['type'], 'size' => $file['size'] ], (array) $tree['files'] ), 0, 2000 ) ];
			} catch ( \Throwable $error ) {
				return new \WP_Error( 'review_target_unavailable', __( 'The installed target could not be read safely for Review.', 'wp-autoplugin' ) );
			}
		}
		$root_plugin_instructions = null;
		if ( 'plugin' === ( $workspace['target_kind'] ?? '' ) ) {
			try {
				$instructions = ( new Source_Tools( (array) $workspace['target_metadata'] ) )->plugin_instructions();
				if ( $instructions ) {
					$root_plugin_instructions = [ 'path' => $instructions['path'], 'content' => $instructions['content'] ];
				}
			} catch ( \Throwable $error ) {
				return new \WP_Error( 'review_plugin_instructions_unavailable', $error->getMessage() );
			}
		}
		return [
			'workspace' => [
				'id'              => (int) $workspace['id'],
				'request'         => (string) $workspace['request'],
				'operation'       => (string) $workspace['operation'],
				'target_kind'     => (string) $workspace['target_kind'],
				'target_ref'      => (string) $workspace['target_ref'],
				'target_name'     => (string) ( $workspace['target_metadata']['name'] ?? $workspace['project_name'] ?? '' ),
				'target_metadata' => array_intersect_key(
					(array) $workspace['target_metadata'],
					array_flip( [ 'kind', 'ref', 'name', 'version', 'author', 'description', 'active', 'source_files', 'lines', 'tokens', 'hooks', 'stylesheet', 'template', 'is_child', 'is_block_theme', 'parent_ref', 'parent_available', 'parent_name', 'parent_version', 'parent_theme', 'active_as_stylesheet', 'active_as_template', 'in_use' ] )
				),
			],
			'root_plugin_instructions' => $root_plugin_instructions,
			'plan' => [ 'job_id' => (int) ( $plan['id'] ?? 0 ), 'content' => (string) ( $plan['result']['artifact']['content'] ?? $plan['result']['content'] ?? '' ), 'structured' => (array) ( $plan['result']['structured'] ?? [] ) ],
			'revision' => [ 'id' => (int) $revision['id'], 'number' => (int) $revision['revision_number'], 'parent_revision_id' => $revision['parent_revision_id'], 'manifest' => $revision['project_manifest'], 'files' => $files, 'parent_changes' => $this->parent_changes( $parent_revision, $revision ), 'target_tree' => $target_tree ],
			'previous_report' => $parent ? [ 'id' => (int) $parent['id'], 'revision_id' => (int) $parent['revision_id'], 'verdict' => $parent['verdict'], 'summary' => $parent['summary'], 'tests' => $parent['tests'] ] : null,
		];
	}

	/** Describe only the source changes between a parent and successor revision. */
	private function parent_changes( ?array $parent, array $revision ): array {
		if ( ! $parent ) {
			return [];
		}
		$before = [];
		$after  = [];
		foreach ( (array) ( $parent['files'] ?? [] ) as $file ) {
			$before[ (string) $file['path'] ] = [ 'change_type' => (string) $file['change_type'], 'content_hash' => (string) $file['content_hash'], 'base_content_hash' => (string) ( $file['base_content_hash'] ?? '' ) ];
		}
		foreach ( (array) ( $revision['files'] ?? [] ) as $file ) {
			$after[ (string) $file['path'] ] = [ 'change_type' => (string) $file['change_type'], 'content_hash' => (string) $file['content_hash'], 'base_content_hash' => (string) ( $file['base_content_hash'] ?? '' ) ];
		}
		$changes = [];
		foreach ( array_unique( array_merge( array_keys( $before ), array_keys( $after ) ) ) as $path ) {
			if ( ( $before[ $path ] ?? null ) === ( $after[ $path ] ?? null ) ) {
				continue;
			}
			$changes[] = [ 'path' => $path, 'before' => $before[ $path ] ?? null, 'after' => $after[ $path ] ?? null ];
		}
		return $changes;
	}

	/** Finish a running Review cancellation without creating a partial report. */
	private function cancel( array $job, Job_Repository $jobs ): array {
		$jobs->update( (int) $job['id'], [ 'status' => 'cancelled', 'finished_at' => current_time( 'mysql', true ) ] );
		$jobs->event( (int) $job['id'], 'cancelled', __( 'Review was cancelled. No partial report was created.', 'wp-autoplugin' ) );
		return [ '_continuation' => true ];
	}

	/** @return true|\WP_Error */
	private function target_is_stale( array $workspace, array $revision ) {
		$manifest = (array) ( $revision['project_manifest'] ?? [] );
		$complete = (string) ( $manifest['complete_target_fingerprint'] ?? '' );
		$kind     = (string) ( $manifest['artifact_kind'] ?? '' );
		if ( 'changes' === ( $manifest['scope'] ?? '' ) && in_array( $kind, [ 'plugin', 'theme' ], true ) && '' !== $complete ) {
			$current_complete = ( new Package_Builder() )->fingerprint_target( (string) $workspace['target_ref'], 'theme' === $kind, $kind );
			if ( is_wp_error( $current_complete ) || ! hash_equals( $complete, (string) ( $current_complete['fingerprint'] ?? '' ) ) ) {
				return is_wp_error( $current_complete ) ? $current_complete : new \WP_Error( 'review_complete_target_changed', __( 'The complete installed target changed after Code was staged. Regenerate Code before Review.', 'wp-autoplugin' ), [ 'status' => 409 ] );
			}
		}
		$expected = 'changes' === ( $manifest['scope'] ?? '' ) ? (string) ( $manifest['target_fingerprint'] ?? '' ) : (string) ( $manifest['integration_target_fingerprint'] ?? '' );
		if ( '' === $expected ) {
			return true;
		}
		try {
			$tools   = new Source_Tools( (array) $workspace['target_metadata'] );
			$current = 'changes' === ( $manifest['scope'] ?? '' ) ? $tools->tree_fingerprint() : $tools->inspection_fingerprint();
		} catch ( \Throwable $error ) {
			return new \WP_Error( 'review_target_unavailable', __( 'The inspected integration target is no longer available.', 'wp-autoplugin' ), [ 'status' => 409 ] );
		}
		return hash_equals( $expected, $current )
			? true
			: new \WP_Error( 'review_target_changed', __( 'The inspected target changed after Code was staged. Regenerate Code before Review.', 'wp-autoplugin' ), [ 'status' => 409 ] );
	}

	/** @param array<int, array<string, mixed>> $jobs @return array<int, array<string, string>> */
	private function history( array $jobs, int $current_job_id ): array {
		$history = [];
		foreach ( $jobs as $item ) {
			if ( (int) $item['id'] === $current_job_id || 'conversation' !== $item['task'] || 'review' !== ( $item['payload']['stage'] ?? '' ) ) {
				continue;
			}
			if ( ! empty( $item['payload']['message'] ) ) {
				$history[] = [ 'role' => 'administrator', 'content' => (string) $item['payload']['message'] ];
			}
			if ( 'completed' === $item['status'] && ! empty( $item['result']['content'] ) ) {
				$history[] = [ 'role' => 'assistant', 'content' => (string) $item['result']['content'] ];
			}
		}
		return array_slice( $history, -8 );
	}
}
