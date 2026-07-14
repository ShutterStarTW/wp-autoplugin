<?php

use WP_Autoplugin\V2\Domain\AI\Plan_Response;

/** Focused validation coverage for terminal native Plan responses. */
final class PlanResponseTest extends WP_UnitTestCase {
	public function test_parses_initial_plan_artifact(): void {
		$response = wp_json_encode(
			[
				'outcome'    => 'artifact',
				'content'    => "# Plan\n\nUpdate the existing service.",
				'structured' => [
					'project_structure' => [
						'directories' => [ 'includes' ],
						'files'       => [
							[
								'path'        => 'includes/class-service.php',
								'type'        => 'php',
								'description' => 'Update the service behavior.',
								'action'      => 'update',
							],
						],
					],
				],
			]
		);

		$result = ( new Plan_Response() )->parse( (string) $response );
		$this->assertFalse( is_wp_error( $result ) );
		$this->assertSame( 'artifact', $result['outcome'] );
		$this->assertSame( [ 'includes/' ], $result['structured']['project_structure']['directories'] );
		$this->assertArrayNotHasKey( 'artifact', $result );
	}

	public function test_parses_follow_up_answer_without_replacing_artifact(): void {
		$result = ( new Plan_Response() )->parse( '{"outcome":"answer","content":"The current Plan already covers that."}', true, 42 );
		$this->assertSame( [ 'content' => 'The current Plan already covers that.', 'outcome' => 'answer' ], $result );
	}

	public function test_rejects_unsafe_or_duplicate_file_paths(): void {
		$response = wp_json_encode(
			[
				'outcome'    => 'artifact',
				'content'    => '# Unsafe plan',
				'structured' => [
					'project_structure' => [
						'directories' => [],
						'files'       => [
							[ 'path' => '../wp-config.php', 'type' => 'php', 'description' => 'Unsafe.', 'action' => 'update' ],
						],
					],
				],
			]
		);

		$this->assertWPError( ( new Plan_Response() )->parse( (string) $response ) );
	}

	public function test_initial_plan_cannot_finish_as_answer(): void {
		$this->assertWPError( ( new Plan_Response() )->parse( '{"outcome":"answer","content":"Need more detail."}' ) );
	}
}
