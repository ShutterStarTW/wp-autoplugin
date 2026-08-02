<?php

namespace WP_Autoplugin\V2\Domain\AI;

/** Validates one complete, revision-anchored Review response. */
final class Review_Response {
	private const PRIORITIES         = [ 'P0', 'P1', 'P2', 'P3' ];
	private const CATEGORIES         = [ 'security', 'correctness', 'compatibility', 'performance', 'maintainability' ];
	private const DISPOSITIONS       = [ 'open', 'resolved', 'retracted' ];
	private const MAX_FINDINGS       = 20;
	private const MAX_TEXT_BYTES     = 32768;

	/**
	 * @param array<string, mixed>             $revision Revision with private file contents.
	 * @param array<int, array<string, mixed>> $previous Required open/addressed findings.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function parse( string $response, array $revision, array $previous = [], bool $allow_answer = false, bool $same_revision = false ) {
		if ( str_contains( $response, '```' ) ) {
			return $this->error( 'review_markdown_fence', __( 'The Review response must be JSON without Markdown code fences.', 'wp-autoplugin' ) );
		}
		$decoded = json_decode( Json_Response::strip_fence( $response ), true );
		if ( ! is_array( $decoded ) || ! is_string( $decoded['outcome'] ?? null ) ) {
			return $this->error( 'review_response_shape', __( 'The reviewer returned an invalid response shape.', 'wp-autoplugin' ) );
		}
		$outcome = sanitize_key( $decoded['outcome'] );
		if ( 'answer' === $outcome ) {
			if ( ! $allow_answer || array_diff( array_keys( $decoded ), [ 'outcome', 'content' ] ) || ! is_string( $decoded['content'] ?? null ) ) {
				return $this->error( 'review_answer_shape', __( 'The reviewer returned an invalid answer.', 'wp-autoplugin' ) );
			}
			$content = trim( $decoded['content'] );
			if ( '' === $content || strlen( $content ) > self::MAX_TEXT_BYTES ) {
				return $this->error( 'review_answer_size', __( 'The Review answer is empty or too large.', 'wp-autoplugin' ) );
			}
			return [
				'outcome' => 'answer',
				'content' => $content,
			];
		}
		if ( 'report' !== $outcome || array_diff( array_keys( $decoded ), [ 'outcome', 'content', 'summary', 'prior_findings', 'new_findings', 'tests' ] ) ) {
			return $this->error( 'review_report_shape', __( 'The reviewer must return an answer or a complete Review report.', 'wp-autoplugin' ) );
		}
		if ( ! is_string( $decoded['summary'] ?? null ) || ! is_array( $decoded['prior_findings'] ?? null ) || ! is_array( $decoded['new_findings'] ?? null ) || ! is_array( $decoded['tests'] ?? null ) ) {
			return $this->error( 'review_report_shape', __( 'The Review report fields are invalid.', 'wp-autoplugin' ) );
		}
		$summary = trim( $decoded['summary'] );
		$content = is_string( $decoded['content'] ?? null ) ? trim( $decoded['content'] ) : '';
		if ( '' === $summary || strlen( $summary ) > self::MAX_TEXT_BYTES || strlen( $content ) > self::MAX_TEXT_BYTES ) {
			return $this->error( 'review_summary_size', __( 'The Review summary is empty or too large.', 'wp-autoplugin' ) );
		}

		$expected = [];
		foreach ( $previous as $finding ) {
			$expected[ (int) $finding['id'] ] = $finding;
		}
		$prior = [];
		foreach ( $decoded['prior_findings'] as $item ) {
			if ( ! is_array( $item ) || ! is_numeric( $item['finding_id'] ?? null ) || ! is_string( $item['disposition'] ?? null ) ) {
				return $this->error( 'review_prior_shape', __( 'A prior Review finding has an invalid disposition.', 'wp-autoplugin' ) );
			}
			$id          = (int) $item['finding_id'];
			$disposition = sanitize_key( $item['disposition'] );
			if ( ! isset( $expected[ $id ] ) || isset( $prior[ $id ] ) || ! in_array( $disposition, self::DISPOSITIONS, true ) || ( $same_revision && 'resolved' === $disposition ) ) {
				return $this->error( 'review_prior_invalid', __( 'Every prior open finding must be accounted for exactly once.', 'wp-autoplugin' ) );
			}
			$normalized = [
				'finding_id'  => $id,
				'disposition' => $disposition,
			];
			if ( 'open' === $disposition ) {
				$finding = $this->finding( $item, $revision, [ 'finding_id', 'disposition' ] );
				if ( is_wp_error( $finding ) ) {
					return $finding;
				}
				$normalized['finding'] = $finding;
			}
			$prior[ $id ] = $normalized;
		}
		$expected_ids = array_keys( $expected );
		$prior_ids    = array_keys( $prior );
		sort( $expected_ids );
		sort( $prior_ids );
		if ( $expected_ids !== $prior_ids ) {
				return $this->error( 'review_prior_missing', __( 'The Review update omitted a prior open finding.', 'wp-autoplugin' ) );
		}

		$new  = [];
		$seen = [];
		foreach ( $prior as $item ) {
			if ( 'open' !== $item['disposition'] ) {
				continue;
			}
			$finding      = $item['finding'];
			$key          = strtolower( (string) $finding['path'] . '|' . (string) $finding['side'] . '|' . (string) $finding['start_line'] . '|' . $finding['title'] );
			$seen[ $key ] = true;
		}
		foreach ( $decoded['new_findings'] as $item ) {
			if ( ! is_array( $item ) ) {
				return $this->error( 'review_finding_shape', __( 'A new Review finding is invalid.', 'wp-autoplugin' ) );
			}
			$finding = $this->finding( $item, $revision );
			if ( is_wp_error( $finding ) ) {
				return $finding;
			}
			$key = strtolower( (string) $finding['path'] . '|' . (string) $finding['side'] . '|' . (string) $finding['start_line'] . '|' . $finding['title'] );
			if ( isset( $seen[ $key ] ) ) {
				return $this->error( 'review_finding_duplicate', __( 'The Review report contains a duplicate finding.', 'wp-autoplugin' ) );
			}
			$seen[ $key ] = true;
			$new[]        = $finding;
		}
		$open_prior = count( array_filter( $prior, static fn( array $item ): bool => 'open' === $item['disposition'] ) );
		if ( $open_prior + count( $new ) > self::MAX_FINDINGS ) {
			return $this->error( 'review_findings_large', sprintf( __( 'A Review report may contain at most %d open findings.', 'wp-autoplugin' ), self::MAX_FINDINGS ) );
		}
		$tests = $this->tests( $decoded['tests'] );
		if ( is_wp_error( $tests ) ) {
			return $tests;
		}
		return [
			'outcome'        => 'report',
			'content'        => $content,
			'summary'        => $summary,
			'prior_findings' => array_values( $prior ),
			'new_findings'   => $new,
			'tests'          => $tests,
		];
	}

	/** @param array<string, mixed> $raw @param array<int, string> $extra */
	private function finding( array $raw, array $revision, array $extra = [] ) {
		$required = [ 'priority', 'category', 'title', 'body', 'suggested_fix', 'path', 'side', 'start_line', 'end_line' ];
		$allowed  = array_merge( $required, $extra );
		if ( array_diff( array_keys( $raw ), $allowed ) || array_diff( $required, array_keys( $raw ) ) ) {
			return $this->error( 'review_finding_shape', __( 'A Review finding contains unsupported fields.', 'wp-autoplugin' ) );
		}
		$priority = is_string( $raw['priority'] ?? null ) ? strtoupper( trim( $raw['priority'] ) ) : '';
		$category = is_string( $raw['category'] ?? null ) ? sanitize_key( $raw['category'] ) : '';
		$title    = is_string( $raw['title'] ?? null ) ? trim( wp_strip_all_tags( $raw['title'] ) ) : '';
		$body     = is_string( $raw['body'] ?? null ) ? trim( $raw['body'] ) : '';
		$fix      = is_string( $raw['suggested_fix'] ?? null ) ? trim( $raw['suggested_fix'] ) : '';
		if ( ! in_array( $priority, self::PRIORITIES, true ) || ! in_array( $category, self::CATEGORIES, true ) || '' === $title || strlen( $title ) > 255 || '' === $body || strlen( $body ) > self::MAX_TEXT_BYTES || strlen( $fix ) > self::MAX_TEXT_BYTES ) {
			return $this->error( 'review_finding_invalid', __( 'A Review finding has an invalid priority, category, title, or description.', 'wp-autoplugin' ) );
		}
		$path = is_string( $raw['path'] ?? null ) ? trim( str_replace( '\\', '/', $raw['path'] ) ) : '';
		if ( '' === $path ) {
			if ( null !== $raw['path'] || null !== $raw['side'] || null !== $raw['start_line'] || null !== $raw['end_line'] ) {
				return $this->error( 'review_finding_project_location', __( 'A project-level Review finding must use null source location fields.', 'wp-autoplugin' ) );
			}
			return [
				'priority'      => $priority,
				'category'      => $category,
				'title'         => $title,
				'body'          => $body,
				'suggested_fix' => $fix,
				'path'          => null,
				'side'          => null,
				'start_line'    => null,
				'end_line'      => null,
				'anchor_hash'   => null,
			];
		}
		$file = null;
		foreach ( (array) ( $revision['files'] ?? [] ) as $candidate ) {
			if ( $path === (string) ( $candidate['path'] ?? '' ) ) {
				$file = $candidate;
				break;
			}
		}
		$side = is_string( $raw['side'] ?? null ) ? sanitize_key( $raw['side'] ) : 'staged';
		if ( ! $file || ! in_array( $side, [ 'staged', 'base' ], true ) ) {
			return $this->error( 'review_finding_path', __( 'A Review finding must reference a staged file or the project summary.', 'wp-autoplugin' ) );
		}
		$operation = (string) ( $file['change_type'] ?? 'add' );
		if ( ( 'base' === $side && ! in_array( $operation, [ 'update', 'delete' ], true ) ) || ( 'staged' === $side && 'delete' === $operation ) ) {
			return $this->error( 'review_finding_side', __( 'A Review finding references an unavailable side of the staged change.', 'wp-autoplugin' ) );
		}
		$source = 'base' === $side ? (string) ( $file['base_content'] ?? '' ) : (string) ( $file['content'] ?? '' );
		$lines  = preg_split( '/\r\n|\r|\n/', $source );
		$count  = max( 1, count( is_array( $lines ) ? $lines : [] ) );
		$start  = is_numeric( $raw['start_line'] ?? null ) ? (int) $raw['start_line'] : 0;
		$end    = is_numeric( $raw['end_line'] ?? null ) ? (int) $raw['end_line'] : $start;
		if ( $start < 1 || $end < $start || $end > $count || $end - $start > 50 ) {
			return $this->error( 'review_finding_lines', __( 'A Review finding references an invalid source line range.', 'wp-autoplugin' ) );
		}
		$slice    = array_slice( (array) $lines, $start - 1, $end - $start + 1 );
		$evidence = implode( "\n", $slice );
		return [
			'priority'      => $priority,
			'category'      => $category,
			'title'         => $title,
			'body'          => $body,
			'suggested_fix' => $fix,
			'path'          => $path,
			'side'          => $side,
			'start_line'    => $start,
			'end_line'      => $end,
			'anchor_hash'   => hash( 'sha256', $evidence ),
		];
	}

	/** @param array<int, mixed> $raw */
	private function tests( array $raw ) {
		if ( count( $raw ) > 10 ) {
			return $this->error( 'review_tests_large', __( 'The Review testing checklist is too large.', 'wp-autoplugin' ) );
		}
		$tests = [];
		foreach ( $raw as $test ) {
			if ( ! is_array( $test ) || array_diff( array_keys( $test ), [ 'title', 'steps', 'expected' ] ) || ! is_string( $test['title'] ?? null ) || ! is_array( $test['steps'] ?? null ) || ! is_string( $test['expected'] ?? null ) || ! $test['steps'] || count( $test['steps'] ) > 10 ) {
				return $this->error( 'review_test_invalid', __( 'A Review testing instruction is invalid.', 'wp-autoplugin' ) );
			}
			$title    = trim( wp_strip_all_tags( $test['title'] ) );
			$expected = trim( $test['expected'] );
			$steps    = [];
			foreach ( $test['steps'] as $step ) {
				if ( ! is_string( $step ) || '' === trim( $step ) || strlen( $step ) > 4096 ) {
					return $this->error( 'review_test_invalid', __( 'A Review testing step is invalid.', 'wp-autoplugin' ) );
				}
				$steps[] = trim( $step );
			}
			if ( '' === $title || strlen( $title ) > 255 || '' === $expected || strlen( $expected ) > 4096 ) {
				return $this->error( 'review_test_invalid', __( 'A Review testing instruction is invalid.', 'wp-autoplugin' ) );
			}
			$tests[] = compact( 'title', 'steps', 'expected' );
		}
		return $tests;
	}

	private function error( string $code, string $message ): \WP_Error {
		return new \WP_Error(
			$code,
			$message,
			[
				'retryable' => true,
				'ambiguous' => false,
			]
		);
	}
}
