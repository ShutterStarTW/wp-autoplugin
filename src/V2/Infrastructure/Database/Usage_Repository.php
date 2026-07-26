<?php

namespace WP_Autoplugin\V2\Infrastructure\Database;

/**
 * Persists normalized token usage without request or credential content.
 */
final class Usage_Repository extends Repository {
	/**
	 * @param array<string, int> $usage Normalized token counts.
	 */
	public function record( int $job_id, string $provider, string $model, string $task, array $usage ): void {
		$this->wpdb->insert(
			Installer::table( 'usage' ),
			[
				'job_id'       => $job_id,
				'provider'     => sanitize_key( $provider ),
				'model'        => sanitize_text_field( $model ),
				'task'         => sanitize_key( $task ),
				'input_tokens' => max( 0, (int) ( $usage['input_tokens'] ?? 0 ) ),
				'output_tokens'=> max( 0, (int) ( $usage['output_tokens'] ?? 0 ) ),
				'created_at'   => $this->now(),
			]
		);
	}

	/**
	 * Aggregate every recorded provider call for a durable project.
	 *
	 * Usage rows are intentionally aggregated instead of reading the optional
	 * totals embedded in terminal job results. This includes retry attempts,
	 * failed jobs, cancelled jobs that reached a provider, and resumable jobs
	 * that made more than one model request.
	 *
	 * @return array<string, mixed>
	 */
	public function summary_for_project( int $project_id ): array {
		$usage      = Installer::table( 'usage' );
		$jobs       = Installer::table( 'jobs' );
		$workspaces = Installer::table( 'workspaces' );
		$model_rows = $this->wpdb->get_results(
			$this->wpdb->prepare(
				"SELECT u.provider, u.model, COUNT(DISTINCT u.job_id) AS job_count,
					SUM(u.input_tokens) AS input_tokens, SUM(u.output_tokens) AS output_tokens
				FROM $usage u
				INNER JOIN $jobs j ON j.id = u.job_id
				INNER JOIN $workspaces w ON w.id = j.workspace_id
				WHERE w.project_id = %d
				GROUP BY u.provider, u.model
				ORDER BY (SUM(u.input_tokens) + SUM(u.output_tokens)) DESC, u.provider ASC, u.model ASC",
				$project_id
			), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Tables are allow-listed internal names.
			ARRAY_A
		);
		$job_rows = $this->wpdb->get_results(
			$this->wpdb->prepare(
				"SELECT j.id, j.task, j.status, j.payload, j.created_at, j.started_at, j.finished_at,
					u.task AS usage_task, u.provider, u.model,
					SUM(u.input_tokens) AS input_tokens, SUM(u.output_tokens) AS output_tokens
				FROM $usage u
				INNER JOIN $jobs j ON j.id = u.job_id
				INNER JOIN $workspaces w ON w.id = j.workspace_id
				WHERE w.project_id = %d
				GROUP BY j.id, j.task, j.status, j.payload, j.created_at, j.started_at, j.finished_at,
					u.task, u.provider, u.model
				ORDER BY j.id DESC, u.provider ASC, u.model ASC, u.task ASC",
				$project_id
			), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Tables are allow-listed internal names.
			ARRAY_A
		);

		$models = [];
		$total  = [
			'input_tokens'  => 0,
			'output_tokens' => 0,
		];
		foreach ( (array) $model_rows as $row ) {
			$model = [
				'provider'      => (string) $row['provider'],
				'model'         => (string) $row['model'],
				'job_count'     => (int) $row['job_count'],
				'input_tokens'  => (int) $row['input_tokens'],
				'output_tokens' => (int) $row['output_tokens'],
			];
			$models[]               = $model;
			$total['input_tokens']  += $model['input_tokens'];
			$total['output_tokens'] += $model['output_tokens'];
		}

		$executed_jobs = [];
		foreach ( (array) $job_rows as $row ) {
			$job_id = (int) $row['id'];
			if ( ! isset( $executed_jobs[ $job_id ] ) ) {
				$payload = $this->decode( $row['payload'] );
				$executed_jobs[ $job_id ] = [
					'id'            => $job_id,
					'task'          => (string) $row['task'],
					'stage'         => (string) ( $payload['stage'] ?? $row['usage_task'] ),
					'mode'          => (string) ( $payload['mode'] ?? '' ),
					'status'        => (string) $row['status'],
					'input_tokens'  => 0,
					'output_tokens' => 0,
					'models'        => [],
					'created_at'    => (string) $row['created_at'],
					'started_at'    => (string) ( $row['started_at'] ?? '' ),
					'finished_at'   => (string) ( $row['finished_at'] ?? '' ),
				];
			}

			$input_tokens  = (int) $row['input_tokens'];
			$output_tokens = (int) $row['output_tokens'];
			$model_key     = (string) $row['provider'] . "\0" . (string) $row['model'];
			if ( ! isset( $executed_jobs[ $job_id ]['models'][ $model_key ] ) ) {
				$executed_jobs[ $job_id ]['models'][ $model_key ] = [
					'provider'      => (string) $row['provider'],
					'model'         => (string) $row['model'],
					'input_tokens'  => 0,
					'output_tokens' => 0,
				];
			}
			$executed_jobs[ $job_id ]['input_tokens']                        += $input_tokens;
			$executed_jobs[ $job_id ]['output_tokens']                       += $output_tokens;
			$executed_jobs[ $job_id ]['models'][ $model_key ]['input_tokens']  += $input_tokens;
			$executed_jobs[ $job_id ]['models'][ $model_key ]['output_tokens'] += $output_tokens;
		}

		foreach ( $executed_jobs as &$job ) {
			$job['models'] = array_values( $job['models'] );
		}
		unset( $job );

		return [
			'project_id'    => $project_id,
			'total'         => $total,
			'models'        => $models,
			'executed_jobs' => array_values( $executed_jobs ),
		];
	}
}
