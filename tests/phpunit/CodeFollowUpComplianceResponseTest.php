<?php

use WP_Autoplugin\V2\Domain\AI\Code_Follow_Up_Compliance_Response;

/** Strict compliance-check parsing before a Code follow-up revision is staged. */
final class CodeFollowUpComplianceResponseTest extends WP_UnitTestCase {
	private array $files = [
		[ 'path' => 'includes/class-model-manager.php', 'type' => 'php' ],
		[ 'path' => 'assets/admin.css', 'type' => 'css' ],
	];

	public function test_parses_pass_without_issues(): void {
		$result = ( new Code_Follow_Up_Compliance_Response() )->parse(
			'{"outcome":"pass","content":"The submenu requirement is implemented.","issues":[]}',
			$this->files
		);

		$this->assertFalse( is_wp_error( $result ) );
		$this->assertSame( 'pass', $result['outcome'] );
		$this->assertSame( [], $result['issues'] );
	}

	public function test_normalizes_file_level_failure(): void {
		$result = ( new Code_Follow_Up_Compliance_Response() )->parse(
			'{"outcome":"fail","content":"The page is still top-level.","issues":[{"path":"includes/class-model-manager.php","message":"Replace add_menu_page() with add_submenu_page() and the verified parent slug."}]}',
			$this->files
		);

		$this->assertFalse( is_wp_error( $result ) );
		$this->assertSame( 'fail', $result['outcome'] );
		$this->assertSame( 'includes/class-model-manager.php', $result['issues'][0]['path'] );
		$this->assertSame( 'request_mismatch', $result['issues'][0]['code'] );
	}

	public function test_rejects_unknown_paths_and_inconsistent_passes(): void {
		$unknown = '{"outcome":"fail","content":"Mismatch.","issues":[{"path":"outside.php","message":"Change it."}]}';
		$pass    = '{"outcome":"pass","content":"Done.","issues":[{"path":"","message":"Unexpected."}]}';

		$this->assertWPError( ( new Code_Follow_Up_Compliance_Response() )->parse( $unknown, $this->files ) );
		$this->assertWPError( ( new Code_Follow_Up_Compliance_Response() )->parse( $pass, $this->files ) );
	}
}
