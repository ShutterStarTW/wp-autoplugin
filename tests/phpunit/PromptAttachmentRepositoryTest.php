<?php

use WP_Autoplugin\V2\Infrastructure\Database\Installer;
use WP_Autoplugin\V2\Infrastructure\Database\Job_Repository;
use WP_Autoplugin\V2\Infrastructure\Database\Prompt_Attachment_Repository;
use WP_Autoplugin\V2\Infrastructure\Database\Workspace_Repository;
use WP_Autoplugin\V2\Rest\Routes;

/** Verifies durable, private, message-scoped attachment persistence. */
final class PromptAttachmentRepositoryTest extends WP_UnitTestCase {
	/** @var array<int, int> */
	private array $workspace_ids = [];
	/** @var array<int, int> */
	private array $project_ids = [];

	public function tear_down(): void {
		global $wpdb;
		foreach ( $this->workspace_ids as $workspace_id ) {
			$job_ids = array_map( 'intval', $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM ' . Installer::table( 'jobs' ) . ' WHERE workspace_id = %d', $workspace_id ) ) );
			foreach ( $job_ids as $job_id ) {
				$wpdb->delete( Installer::table( 'job_prompt_attachments' ), [ 'job_id' => $job_id ] );
				$wpdb->delete( Installer::table( 'job_events' ), [ 'job_id' => $job_id ] );
			}
			$wpdb->delete( Installer::table( 'prompt_attachments' ), [ 'workspace_id' => $workspace_id ] );
			$wpdb->delete( Installer::table( 'jobs' ), [ 'workspace_id' => $workspace_id ] );
			$wpdb->delete( Installer::table( 'workspaces' ), [ 'id' => $workspace_id ] );
		}
		foreach ( $this->project_ids as $project_id ) {
			$wpdb->delete( Installer::table( 'projects' ), [ 'id' => $project_id ] );
		}
		parent::tear_down();
	}

	public function test_preserves_order_reuses_bytes_and_keeps_content_out_of_job_responses(): void {
		Installer::activate();
		$user_id   = self::factory()->user->create( [ 'role' => 'administrator' ] );
		$workspace = $this->workspace( $user_id, 'primary' );
		$jobs      = new Job_Repository();
		$first_job = $jobs->create( $workspace, 'conversation', [ 'stage' => 'explain', 'message' => 'Compare these.' ], $user_id );
		$repository = new Prompt_Attachment_Repository();
		$attached   = $repository->attach(
			(int) $first_job['id'],
			$workspace,
			$user_id,
			[
				$this->image( 'first.png', 'first-private-bytes' ),
				$this->image( 'second.png', 'second-private-bytes' ),
			]
		);

		$this->assertFalse( is_wp_error( $attached ) );
		$this->assertSame( [ 'first.png', 'second.png' ], array_column( $attached, 'filename' ) );
		$this->assertArrayNotHasKey( 'content', $attached[0] );
		$this->assertArrayNotHasKey( 'workspace_id', $attached[0] );
		$this->assertArrayNotHasKey( 'created_by', $attached[0] );

		$retry_job = $jobs->create( $workspace, 'conversation', [ 'stage' => 'explain', 'message' => 'Retry.' ], $user_id );
		$reused    = $repository->attach(
			(int) $retry_job['id'],
			$workspace,
			$user_id,
			[],
			[ (int) $attached[1]['id'], (int) $attached[0]['id'] ]
		);
		$this->assertFalse( is_wp_error( $reused ) );
		$this->assertSame( [ (int) $attached[1]['id'], (int) $attached[0]['id'] ], array_column( $reused, 'id' ) );

		$hydrated = $jobs->find( (int) $retry_job['id'] );
		$this->assertSame( array_column( $reused, 'id' ), array_column( $hydrated['prompt_attachments'], 'id' ) );
		$this->assertArrayNotHasKey( 'content', $hydrated['prompt_attachments'][0] );
		$this->assertStringNotContainsString( 'private-bytes', wp_json_encode( $hydrated ) );
		$this->assertSame( 2, (int) $this->attachment_count( $workspace ) );
	}

	public function test_rejects_reuse_by_another_owner_or_workspace(): void {
		Installer::activate();
		$owner_id        = self::factory()->user->create( [ 'role' => 'administrator' ] );
		$other_user_id   = self::factory()->user->create( [ 'role' => 'administrator' ] );
		$workspace       = $this->workspace( $owner_id, 'owned' );
		$other_workspace = $this->workspace( $owner_id, 'other' );
		$jobs            = new Job_Repository();
		$repository      = new Prompt_Attachment_Repository();
		$source_job      = $jobs->create( $workspace, 'conversation', [ 'stage' => 'explain', 'message' => 'Source' ], $owner_id );
		$attached        = $repository->attach( (int) $source_job['id'], $workspace, $owner_id, [ $this->image( 'private.png', 'private' ) ] );
		$attachment_id   = (int) $attached[0]['id'];

		$owner_mismatch_job = $jobs->create( $workspace, 'conversation', [ 'stage' => 'explain', 'message' => 'No' ], $other_user_id );
		$workspace_mismatch_job = $jobs->create( $other_workspace, 'conversation', [ 'stage' => 'explain', 'message' => 'No' ], $owner_id );
		$owner_mismatch = $repository->attach( (int) $owner_mismatch_job['id'], $workspace, $other_user_id, [], [ $attachment_id ] );
		$workspace_mismatch = $repository->attach( (int) $workspace_mismatch_job['id'], $other_workspace, $owner_id, [], [ $attachment_id ] );

		$this->assertWPError( $owner_mismatch );
		$this->assertWPError( $workspace_mismatch );
		$this->assertSame( 'wp_autoplugin_prompt_attachment_invalid', $owner_mismatch->get_error_code() );
		$this->assertSame( 'wp_autoplugin_prompt_attachment_invalid', $workspace_mismatch->get_error_code() );
	}

	public function test_preview_is_private_and_streams_only_after_owner_authorization(): void {
		Installer::activate();
		$owner_id   = self::factory()->user->create( [ 'role' => 'administrator' ] );
		$other_id   = self::factory()->user->create( [ 'role' => 'administrator' ] );
		$workspace  = $this->workspace( $owner_id, 'preview' );
		$job        = ( new Job_Repository() )->create( $workspace, 'conversation', [ 'stage' => 'explain', 'message' => '' ], $owner_id );
		$attachment = ( new Prompt_Attachment_Repository() )->attach( (int) $job['id'], $workspace, $owner_id, [ $this->image( 'preview.png', 'private-preview-bytes' ) ] );
		$request    = new WP_REST_Request( 'GET', '/wp-autoplugin/v2/prompt-attachments/' . $attachment[0]['id'] );
		$request->set_param( 'id', (int) $attachment[0]['id'] );
		$routes = new Routes();

		wp_set_current_user( $owner_id );
		$response = $routes->prompt_attachment( $request );
		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertStringNotContainsString( 'private-preview-bytes', wp_json_encode( $response->get_data() ) );
		$headers = $response->get_headers();
		$this->assertSame( 'image/png', $headers['Content-Type'] );
		$this->assertSame( 'nosniff', $headers['X-Content-Type-Options'] );
		$this->assertSame( 'private, no-store, max-age=0', $headers['Cache-Control'] );
		$this->assertStringStartsWith( 'inline;', $headers['Content-Disposition'] );
		ob_start();
		$served = $routes->serve_package_download( false, $response, $request, rest_get_server() );
		$bytes  = ob_get_clean();
		$this->assertTrue( $served );
		$this->assertSame( 'private-preview-bytes', $bytes );

		wp_set_current_user( $other_id );
		$denied = $routes->prompt_attachment( $request );
		$this->assertWPError( $denied );
		$this->assertSame( 404, $denied->get_error_data()['status'] );
	}

	private function workspace( int $user_id, string $suffix ): int {
		$created = ( new Workspace_Repository() )->create(
			[
				'kind' => 'new_plugin',
				'ref'  => 'prompt-image-test-' . $suffix . '-' . wp_generate_uuid4(),
				'name' => 'Prompt image test',
			],
			'create',
			'Build a test plugin.',
			$user_id
		);
		$this->workspace_ids[] = (int) $created['workspace_id'];
		$this->project_ids[]   = (int) $created['project_id'];
		return (int) $created['workspace_id'];
	}

	/** @return array<string, mixed> */
	private function image( string $filename, string $content ): array {
		return [
			'filename'  => $filename,
			'mime_type' => 'image/png',
			'byte_size' => strlen( $content ),
			'width'     => 1,
			'height'    => 1,
			'sha256'    => hash( 'sha256', $content ),
			'content'   => $content,
		];
	}

	private function attachment_count( int $workspace_id ): int {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Installer::table( 'prompt_attachments' ) . ' WHERE workspace_id = %d', $workspace_id ) );
	}
}
