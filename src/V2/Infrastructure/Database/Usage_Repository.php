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
}
