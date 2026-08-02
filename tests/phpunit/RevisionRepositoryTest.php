<?php

use WP_Autoplugin\V2\Infrastructure\Database\Code_Run_Repository;
use WP_Autoplugin\V2\Infrastructure\Database\Installer;
use WP_Autoplugin\V2\Infrastructure\Database\Job_Repository;
use WP_Autoplugin\V2\Infrastructure\Database\Revision_Repository;
use WP_Autoplugin\V2\Orchestration\Code_Orchestrator;

/** Immutable revision staging, editing, conflict, and restore coverage. */
final class RevisionRepositoryTest extends WP_Autoplugin_Integration_Test_Case {
	private function plugin( string $version, string $value ): string {
		return "<?php\n/**\n * Plugin Name: Test Plugin\n * Version: {$version}\n * Author: Test Suite\n */\nreturn '{$value}';\n";
	}

	public function test_staging_is_atomic_and_scrubs_temporary_code_source(): void {
		global $wpdb;

		$project = $this->create_test_project();
		$fixture = $this->stage_test_revision(
			(int) $project['id'],
			[
				'test-plugin.php' => $this->plugin( '1.0.0', 'initial' ),
				'assets/app.js'   => 'window.testPlugin = true;',
			]
		);
		$revisions = new Revision_Repository();
		$manifest  = $revisions->manifest( (int) $fixture['revision']['id'] );
		$private   = $revisions->find( (int) $fixture['revision']['id'] );
		$run       = ( new Code_Run_Repository() )->find_by_job( (int) $fixture['job']['id'] );

		$this->assertSame( 1, $fixture['revision']['revision_number'] );
		$this->assertSame( 2, $manifest['files_count'] );
		$this->assertSame( [ 'assets/app.js', 'test-plugin.php' ], array_column( $manifest['files'], 'path' ) );
		$this->assertArrayNotHasKey( 'content', $manifest['files'][0] );
		$this->assertStringContainsString( "return 'initial'", $private['files'][1]['content'] );
		$this->assertSame(
			0,
			(int) $wpdb->get_var(
				$wpdb->prepare(
					'SELECT COUNT(*) FROM ' . Installer::table( 'code_run_files' ) . ' WHERE run_id = %d AND content IS NOT NULL',
					$run['id']
				)
			)
		);
	}

	public function test_failed_code_run_preserves_and_reuses_its_validated_prefix(): void {
		global $wpdb;

		$project = $this->create_test_project();
		$fixture = $this->create_ready_plan(
			(int) $project['id'],
			[
				[ 'path' => 'test-plugin.php', 'type' => 'php', 'description' => 'Main plugin file.', 'action' => 'add' ],
				[ 'path' => 'assets/app.js', 'type' => 'js', 'description' => 'Browser behavior.', 'action' => 'add' ],
			],
			'test-plugin.php'
		);
		$manifest = ( new \WP_Autoplugin\V2\Domain\Revision\Code_Validator() )->plan( $fixture['structured'], $project );
		$this->assertFalse( is_wp_error( $manifest ), is_wp_error( $manifest ) ? $manifest->get_error_message() : '' );

		$jobs    = new Job_Repository();
		$payload = [
			'mode'                        => 'generate',
			'plan_id'                     => (int) $fixture['plan']['id'],
			'expected_latest_revision_id' => null,
		];
		$first_job = $jobs->create( (int) $project['id'], 'code', $payload, $this->admin_id );
		$runs      = new Code_Run_Repository();
		$first_run = $runs->create(
			(int) $first_job['id'],
			(int) $fixture['plan']['id'],
			null,
			'fixture',
			'offline',
			'',
			'existing-target-code',
			9,
			$manifest['files'],
			'generate',
			$manifest
		);
		$files       = $runs->files( (int) $first_run['id'] );
		$first_file  = $files[0];
		$first_body  = 'php' === $first_file['type']
			? "<?php\n/**\n * Plugin Name: Test Plugin\n */\n"
			: 'window.testPlugin = true;';
		$token       = wp_generate_password( 32, false, false );
		$this->assertTrue( $runs->acquire( (int) $first_run['id'], (int) $first_run['generation'], $token ) );
		$runs->mark_generating( (int) $first_run['id'], (int) $first_file['sequence'], $token );
		$this->assertTrue( $runs->complete_file( (int) $first_run['id'], (int) $first_file['sequence'], $token, $first_body, [] ) );

		$first_run  = $runs->find_by_job( (int) $first_job['id'] );
		$failed_file = $files[1];
		$token       = wp_generate_password( 32, false, false );
		$this->assertTrue( $runs->acquire( (int) $first_run['id'], (int) $first_run['generation'], $token ) );
		$runs->mark_generating( (int) $first_run['id'], (int) $failed_file['sequence'], $token );
		$issues = [ [ 'path' => $failed_file['path'], 'line' => 0, 'code' => 'search_match_count', 'message' => 'No exact match.', 'replacement_index' => 1, 'match_count' => 0 ] ];
		$this->assertTrue( $runs->fail_file( (int) $first_run['id'], (int) $failed_file['sequence'], $token, 'No exact match.', $issues ) );
		$jobs->update( (int) $first_job['id'], [ 'status' => 'failed', 'error_message' => 'No exact match.' ] );
		$runs->terminate_by_job( (int) $first_job['id'], 'failed', 'No exact match.' );

		$failed_files = $runs->files( (int) $first_run['id'] );
		$this->assertSame( [ 'completed', 'failed' ], array_column( $failed_files, 'status' ) );
		$this->assertSame( $first_body, $failed_files[0]['content'] );
		$this->assertSame( 0, $failed_files[1]['error_metadata']['issues'][0]['match_count'] );

		$second_job = $jobs->create( (int) $project['id'], 'code', $payload, $this->admin_id );
		$method     = new ReflectionMethod( Code_Orchestrator::class, 'resume_seed' );
		$method->setAccessible( true );
		$resume = $method->invoke(
			new Code_Orchestrator(),
			$runs,
			(int) $second_job['id'],
			(int) $fixture['plan']['id'],
			null,
			'generate',
			[ 'provider' => 'fixture', 'model' => 'offline', 'effort' => '' ],
			[ 'slug' => 'existing-target-code', 'version' => 9 ],
			$manifest
		);
		$this->assertSame( (int) $first_run['id'], $resume['run_id'] );
		$this->assertSame( [ (int) $first_file['sequence'] => $first_body ], $resume['contents'] );

		$second_run = $runs->create(
			(int) $second_job['id'],
			(int) $fixture['plan']['id'],
			null,
			'fixture',
			'offline',
			'',
			'existing-target-code',
			9,
			$manifest['files'],
			'generate',
			$manifest,
			$resume['contents']
		);
		$runs->scrub_contents( (int) $first_run['id'] );
		$second_files = $runs->files( (int) $second_run['id'] );
		$this->assertSame( 1, $second_run['next_file_index'] );
		$this->assertSame( [ 'completed', 'pending' ], array_column( $second_files, 'status' ) );
		$this->assertSame( $first_body, $second_files[0]['content'] );
		$this->assertSame(
			0,
			(int) $wpdb->get_var(
				$wpdb->prepare(
					'SELECT COUNT(*) FROM ' . Installer::table( 'code_run_files' ) . ' WHERE run_id = %d AND content IS NOT NULL',
					$first_run['id']
				)
			)
		);
	}

	public function test_manual_successors_are_immutable_and_reject_stale_writes(): void {
		$project   = $this->create_test_project();
		$fixture   = $this->stage_test_revision( (int) $project['id'], [ 'test-plugin.php' => $this->plugin( '1.0.0', 'initial' ) ] );
		$repository = new Revision_Repository();
		$initial    = $repository->find( (int) $fixture['revision']['id'] );
		$successor  = $repository->save_successor(
			(int) $initial['id'],
			(int) $initial['id'],
			[
				[
					'path'              => 'test-plugin.php',
					'content'           => $this->plugin( '1.0.0', 'edited' ),
					'base_content_hash' => hash( 'sha256', $initial['files'][0]['content'] ),
				],
			],
			$this->admin_id
		);

		$this->assertFalse( is_wp_error( $successor ), is_wp_error( $successor ) ? $successor->get_error_message() : '' );
		$successor = $repository->find( (int) $successor['id'] );
		$this->assertSame( 'manual', $successor['origin'] );
		$this->assertSame( (int) $initial['id'], (int) $successor['parent_revision_id'] );
		$this->assertStringContainsString( "return 'initial'", $repository->find( (int) $initial['id'] )['files'][0]['content'] );
		$this->assertStringContainsString( "return 'edited'", $repository->find( (int) $successor['id'] )['files'][0]['content'] );

		$stale = $repository->save_successor(
			(int) $initial['id'],
			(int) $initial['id'],
			[ [ 'path' => 'test-plugin.php', 'content' => $this->plugin( '1.0.0', 'stale' ) ] ],
			$this->admin_id
		);
		$this->assertWPError( $stale );
		$this->assertSame( 'revision_conflict', $stale->get_error_code() );
	}

	public function test_restore_creates_a_new_latest_revision_and_active_work_blocks_history_changes(): void {
		$project    = $this->create_test_project();
		$fixture    = $this->stage_test_revision( (int) $project['id'], [ 'test-plugin.php' => $this->plugin( '1.0.0', 'initial' ) ] );
		$repository = new Revision_Repository();
		$initial    = $repository->find( (int) $fixture['revision']['id'] );
		$successor  = $repository->save_successor(
			(int) $initial['id'],
			(int) $initial['id'],
			[ [ 'path' => 'test-plugin.php', 'content' => $this->plugin( '1.0.0', 'edited' ) ] ],
			$this->admin_id
		);
		$jobs       = new Job_Repository();
		$active     = $jobs->create( (int) $project['id'], 'review', [], $this->admin_id );

		$blocked = $repository->restore( (int) $initial['id'], (int) $successor['id'], $this->admin_id );
		$this->assertWPError( $blocked );
		$this->assertSame( 'artifact_work_active', $blocked->get_error_code() );
		$jobs->update( (int) $active['id'], [ 'status' => 'completed' ] );

		$restored = $repository->restore( (int) $initial['id'], (int) $successor['id'], $this->admin_id );
		$this->assertFalse( is_wp_error( $restored ), is_wp_error( $restored ) ? $restored->get_error_message() : '' );
		$restored = $repository->find( (int) $restored['id'] );
		$this->assertSame( 3, $restored['revision_number'] );
		$this->assertSame( 'restore', $restored['origin'] );
		$this->assertSame( (int) $successor['id'], (int) $restored['parent_revision_id'] );
		$this->assertSame( (int) $initial['id'], (int) $restored['restored_from_revision_id'] );
		$this->assertStringContainsString( "return 'initial'", $repository->find( (int) $restored['id'] )['files'][0]['content'] );
		$this->assertSame( (int) $restored['id'], $repository->latest_id( (int) $project['id'] ) );
	}
}
