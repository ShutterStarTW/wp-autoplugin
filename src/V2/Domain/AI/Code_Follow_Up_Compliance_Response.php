<?php

namespace WP_Autoplugin\V2\Domain\AI;

/** Strictly parses the pre-staging Code follow-up compliance result. */
final class Code_Follow_Up_Compliance_Response {
	private const MAX_CONTENT_BYTES = 4096;
	private const MAX_ISSUES        = 5;
	private const MAX_MESSAGE_BYTES = 1024;

	/**
	 * @param array<int, array<string, mixed>> $manifest_files
	 * @return array<string, mixed>|\WP_Error
	 */
	public function parse( string $response, array $manifest_files ) {
		if ( str_contains( $response, '```' ) ) {
			return $this->error( 'code_follow_up_compliance_fence', __( 'The Code compliance response must be JSON without Markdown fences.', 'wp-autoplugin' ) );
		}
		$decoded = json_decode( Json_Response::strip_fence( $response ), true );
		if ( ! is_array( $decoded ) ) {
			return $this->error( 'code_follow_up_compliance_json', __( 'The Code compliance response must be a valid JSON object.', 'wp-autoplugin' ) );
		}

		$outcome = sanitize_key( (string) ( $decoded['outcome'] ?? '' ) );
		$content = is_string( $decoded['content'] ?? null ) ? trim( $decoded['content'] ) : '';
		$issues  = $decoded['issues'] ?? null;
		if ( ! in_array( $outcome, [ 'pass', 'fail' ], true ) || '' === $content || strlen( $content ) > self::MAX_CONTENT_BYTES || ! is_array( $issues ) ) {
			return $this->error( 'code_follow_up_compliance_shape', __( 'The Code compliance response must provide a bounded pass or fail result.', 'wp-autoplugin' ) );
		}

		if ( 'pass' === $outcome ) {
			if ( $issues ) {
				return $this->error( 'code_follow_up_compliance_pass_issues', __( 'A passing Code compliance response must not include issues.', 'wp-autoplugin' ) );
			}
			return [ 'outcome' => 'pass', 'content' => $content, 'issues' => [] ];
		}
		if ( ! $issues || count( $issues ) > self::MAX_ISSUES ) {
			return $this->error( 'code_follow_up_compliance_issues', __( 'A failed Code compliance response requires one to five bounded issues.', 'wp-autoplugin' ) );
		}

		$paths      = array_fill_keys( array_map( 'strval', array_column( $manifest_files, 'path' ) ), true );
		$normalized = [];
		foreach ( $issues as $issue ) {
			$path    = is_array( $issue ) ? trim( (string) ( $issue['path'] ?? '' ) ) : '';
			$message = is_array( $issue ) ? trim( (string) ( $issue['message'] ?? '' ) ) : '';
			if ( '' === $message || strlen( $message ) > self::MAX_MESSAGE_BYTES || ( '' !== $path && ! isset( $paths[ $path ] ) ) ) {
				return $this->error( 'code_follow_up_compliance_issue', __( 'Every Code compliance issue must be bounded and identify a candidate path or the whole change.', 'wp-autoplugin' ) );
			}
			$normalized[] = [ 'path' => $path, 'line' => 0, 'code' => 'request_mismatch', 'message' => $message ];
		}

		return [ 'outcome' => 'fail', 'content' => $content, 'issues' => $normalized ];
	}

	private function error( string $code, string $message ): \WP_Error {
		return new \WP_Error( $code, $message, [ 'retryable' => true, 'ambiguous' => false ] );
	}
}
