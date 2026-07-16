<?php

namespace WP_Autoplugin\V2\Domain\AI;

/**
 * Identifies jobs that require the durable read-only source agent.
 */
final class Agent_Task {
	/** @param array<string, mixed> $job */
	public static function stage( array $job ): ?string {
		$task = (string) ( $job['task'] ?? '' );
		if ( in_array( $task, [ 'plan', 'explain' ], true ) ) {
			return $task;
		}
		if ( 'conversation' === $task ) {
			$stage = (string) ( $job['payload']['stage'] ?? '' );
			return in_array( $stage, [ 'plan', 'explain' ], true ) ? $stage : null;
		}

		return null;
	}

	/**
	 * New-plugin planning intentionally stays on the direct request-response path.
	 *
	 * @param array<string, mixed> $job       Durable job.
	 * @param array<string, mixed> $workspace Durable workspace.
	 */
	public static function uses_source_tools( array $job, array $workspace ): bool {
		$stage = self::stage( $job );
		if ( 'explain' === $stage ) {
			return true;
		}

		return 'plan' === $stage
			&& 'new_plugin' !== ( $workspace['target_metadata']['kind'] ?? $workspace['target_kind'] ?? '' );
	}

	/**
	 * New-plugin planning uses a direct v2 request because there is no source target.
	 *
	 * @param array<string, mixed> $job       Durable job.
	 * @param array<string, mixed> $workspace Durable workspace.
	 */
	public static function uses_direct_plan( array $job, array $workspace ): bool {
		return 'plan' === self::stage( $job )
			&& 'new_plugin' === ( $workspace['target_metadata']['kind'] ?? $workspace['target_kind'] ?? '' );
	}
}
