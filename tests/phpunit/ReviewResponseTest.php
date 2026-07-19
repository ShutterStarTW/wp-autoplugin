<?php

use WP_Autoplugin\V2\Domain\AI\Review_Response;

/** Focused deterministic coverage for the versioned Review JSON contract. */
final class ReviewResponseTest extends WP_UnitTestCase {
	private array $revision;

	protected function setUp(): void {
		parent::setUp();
		$this->revision = [
			'files' => [
				[
					'path'         => 'example.php',
					'change_type'  => 'update',
					'content'      => "<?php\nfunction example_value() {\n\treturn 2;\n}\n",
					'base_content' => "<?php\nfunction example_value() {\n\treturn 1;\n}\n",
				],
				[
					'path'         => 'assets/app.js',
					'change_type'  => 'add',
					'content'      => "window.Example = true;\n",
					'base_content' => '',
				],
				[
					'path'         => 'assets/old.js',
					'change_type'  => 'delete',
					'content'      => '',
					'base_content' => "window.ExampleOld = true;\n",
				],
			],
		];
	}

	public function test_initial_report_normalizes_findings_tests_and_server_anchor(): void {
		$result = ( new Review_Response() )->parse(
			wp_json_encode(
				[
					'outcome'        => 'report',
					'content'        => 'Focused review of the staged revision.',
					'summary'        => 'One correctness issue requires attention.',
					'prior_findings' => [],
					'new_findings'   => [ $this->finding( 'example.php', 'staged', 3 ) ],
					'tests'          => [ [ 'title' => 'Value lookup', 'steps' => [ 'Load the plugin.', 'Read the value.' ], 'expected' => 'The value is safe.' ] ],
				]
			),
			$this->revision
		);

		$this->assertFalse( is_wp_error( $result ) );
		$this->assertSame( 'report', $result['outcome'] );
		$this->assertSame( hash( 'sha256', "\treturn 2;" ), $result['new_findings'][0]['anchor_hash'] );
		$this->assertSame( 'Value lookup', $result['tests'][0]['title'] );
	}

	public function test_side_path_and_line_rules_are_enforced(): void {
		$parser = new Review_Response();

		$this->assertWPError( $parser->parse( $this->report_with( $this->finding( 'assets/app.js', 'base', 1 ) ), $this->revision ) );
		$this->assertWPError( $parser->parse( $this->report_with( $this->finding( 'assets/old.js', 'staged', 1 ) ), $this->revision ) );
		$this->assertWPError( $parser->parse( $this->report_with( $this->finding( '../outside.php', 'staged', 1 ) ), $this->revision ) );
		$this->assertWPError( $parser->parse( $this->report_with( $this->finding( 'example.php', 'base', 99 ) ), $this->revision ) );
	}

	public function test_project_findings_require_explicit_null_location_fields(): void {
		$finding = $this->finding( '', 'staged', 1 );
		$finding['path'] = null;
		$this->assertWPError( ( new Review_Response() )->parse( $this->report_with( $finding ), $this->revision ) );

		$finding['side']       = null;
		$finding['start_line'] = null;
		$finding['end_line']   = null;
		$this->assertFalse( is_wp_error( ( new Review_Response() )->parse( $this->report_with( $finding ), $this->revision ) ) );
	}

	public function test_prior_findings_must_be_accounted_for_exactly_once(): void {
		$previous = [ [ 'id' => 7 ], [ 'id' => 9 ] ];
		$payload  = [
			'outcome'        => 'report',
			'content'        => '',
			'summary'        => 'Updated report.',
			'prior_findings' => [ [ 'finding_id' => 7, 'disposition' => 'retracted' ] ],
			'new_findings'   => [],
			'tests'          => [],
		];

		$this->assertWPError( ( new Review_Response() )->parse( (string) wp_json_encode( $payload ), $this->revision, $previous ) );
		$payload['prior_findings'][] = [ 'finding_id' => 9, 'disposition' => 'retracted' ];
		$this->assertFalse( is_wp_error( ( new Review_Response() )->parse( (string) wp_json_encode( $payload ), $this->revision, $previous ) ) );
	}

	public function test_same_revision_update_cannot_claim_resolution_but_successor_can(): void {
		$payload = [
			'outcome'        => 'report',
			'content'        => '',
			'summary'        => 'Verification result.',
			'prior_findings' => [ [ 'finding_id' => 7, 'disposition' => 'resolved' ] ],
			'new_findings'   => [],
			'tests'          => [],
		];
		$json     = (string) wp_json_encode( $payload );

		$this->assertWPError( ( new Review_Response() )->parse( $json, $this->revision, [ [ 'id' => 7 ] ], false, true ) );
		$this->assertFalse( is_wp_error( ( new Review_Response() )->parse( $json, $this->revision, [ [ 'id' => 7 ] ], false, false ) ) );
	}

	public function test_answer_is_only_allowed_for_review_conversation(): void {
		$json = '{"outcome":"answer","content":"R7 is tied to the return statement."}';

		$this->assertWPError( ( new Review_Response() )->parse( $json, $this->revision ) );
		$this->assertFalse( is_wp_error( ( new Review_Response() )->parse( $json, $this->revision, [], true ) ) );
	}

	/** @return array<string, mixed> */
	private function finding( string $path, string $side, int $line ): array {
		return [
			'priority'      => 'P1',
			'category'      => 'correctness',
			'title'         => 'Validate the returned value',
			'body'          => 'The staged value is returned without the required guard.',
			'suggested_fix' => 'Add the guard immediately before returning the value.',
			'path'          => $path,
			'side'          => $side,
			'start_line'    => $line,
			'end_line'      => $line,
		];
	}

	private function report_with( array $finding ): string {
		return (string) wp_json_encode(
			[
				'outcome'        => 'report',
				'content'        => '',
				'summary'        => 'Review summary.',
				'prior_findings' => [],
				'new_findings'   => [ $finding ],
				'tests'          => [],
			]
		);
	}
}
