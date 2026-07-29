<?php

use WP_Autoplugin\V2\Rest\Routes;

/** Uniform REST permissions and durable-resource ownership coverage. */
final class RestAuthorizationTest extends WP_Autoplugin_Integration_Test_Case {
	public function test_every_v2_route_requires_manage_options(): void {
		global $wp_rest_server;

		$wp_rest_server = new WP_REST_Server();
		do_action( 'rest_api_init' );
		$handlers = [];
		foreach ( $wp_rest_server->get_routes() as $route => $definitions ) {
			if ( ! str_starts_with( $route, '/wp-autoplugin/v2/' ) ) {
				continue;
			}
			foreach ( $definitions as $definition ) {
				if ( isset( $definition['callback'] ) ) {
					$handlers[] = $definition;
				}
			}
		}
		$this->assertGreaterThanOrEqual( 30, count( $handlers ) );

		$subscriber = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		wp_set_current_user( $subscriber );
		foreach ( $handlers as $handler ) {
			$this->assertFalse( (bool) call_user_func( $handler['permission_callback'] ) );
		}

		wp_set_current_user( $this->admin_id );
		foreach ( $handlers as $handler ) {
			$this->assertTrue( (bool) call_user_func( $handler['permission_callback'] ) );
		}
	}

	public function test_registered_bootstrap_route_rejects_anonymous_and_subscriber_requests(): void {
		global $wp_rest_server;

		$wp_rest_server = new WP_REST_Server();
		do_action( 'rest_api_init' );
		$request = new WP_REST_Request( 'GET', '/wp-autoplugin/v2/bootstrap' );

		wp_set_current_user( 0 );
		$this->assertSame( 401, $wp_rest_server->dispatch( $request )->get_status() );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'subscriber' ] ) );
		$this->assertSame( 403, $wp_rest_server->dispatch( $request )->get_status() );
		wp_set_current_user( $this->admin_id );
		$response = $wp_rest_server->dispatch( $request );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( WP_AUTOPLUGIN_VERSION, $response->get_data()['version'] );
	}

	public function test_rest_write_payloads_are_strictly_bounded_before_persistence(): void {
		$routes = new Routes();

		$this->assertTrue( $routes->validate_project_request( str_repeat( 'a', 65536 ) ) );
		$this->assertFalse( $routes->validate_project_request( str_repeat( 'a', 65537 ) ) );
		$this->assertFalse( $routes->validate_project_request( "invalid\0request" ) );
		$this->assertFalse( $routes->validate_plan_content( "invalid\xFFplan" ) );

		$this->assertTrue( $routes->validate_job_payload( [ 'message' => 'safe' ] ) );
		$this->assertTrue( $routes->validate_job_payload( '{"message":"safe"}' ) );
		$this->assertFalse( $routes->validate_job_payload( '{"message":' ) );
		$this->assertFalse( $routes->validate_job_payload( [ 'message' => str_repeat( 'a', 65536 ) ] ) );

		$this->assertTrue( $routes->validate_attachment_id_argument( [ 1, '2', 3 ] ) );
		$this->assertFalse( $routes->validate_attachment_id_argument( [ 0 ] ) );
		$this->assertFalse( $routes->validate_attachment_id_argument( range( 1, 7 ) ) );

		$this->assertTrue( $routes->validate_workspace_order( [ 1, '2', 3 ] ) );
		$this->assertTrue( $routes->validate_workspace_order( [] ) );
		$this->assertFalse( $routes->validate_workspace_order( [ 1, 1 ] ) );
		$this->assertFalse( $routes->validate_workspace_order( [ 0 ] ) );
		$this->assertFalse( $routes->validate_workspace_order( range( 1, 501 ) ) );

		$this->assertTrue( $routes->validate_revision_changes( [ [ 'path' => 'plugin.php', 'content' => '<?php' ] ] ) );
		$this->assertFalse( $routes->validate_revision_changes( [] ) );
		$this->assertFalse( $routes->validate_revision_changes( array_fill( 0, 21, [ 'path' => 'plugin.php' ] ) ) );
	}

	public function test_project_job_and_revision_resources_are_hidden_from_another_administrator(): void {
		$project = $this->create_test_project();
		$fixture = $this->stage_test_revision(
			(int) $project['id'],
			[ 'test-plugin.php' => "<?php\n/**\n * Plugin Name: Test Plugin\n * Author: Test Suite\n */\n" ]
		);
		$routes  = new Routes();
		$other   = self::factory()->user->create( [ 'role' => 'administrator' ] );
		$requests = [
			[ 'workspace', 'GET', '/wp-autoplugin/v2/projects/' . $project['id'], (int) $project['id'] ],
			[ 'job', 'GET', '/wp-autoplugin/v2/jobs/' . $fixture['job']['id'], (int) $fixture['job']['id'] ],
			[ 'revision', 'GET', '/wp-autoplugin/v2/revisions/' . $fixture['revision']['id'], (int) $fixture['revision']['id'] ],
		];

		wp_set_current_user( $other );
		foreach ( $requests as [ $method, $verb, $route, $id ] ) {
			$request = new WP_REST_Request( $verb, $route );
			$request->set_param( 'id', $id );
			$result = $routes->{$method}( $request );
			$this->assertWPError( $result );
			$this->assertSame( 404, $result->get_error_data()['status'] );
		}

		wp_set_current_user( $this->admin_id );
		foreach ( $requests as [ $method, $verb, $route, $id ] ) {
			$request = new WP_REST_Request( $verb, $route );
			$request->set_param( 'id', $id );
			$this->assertInstanceOf( WP_REST_Response::class, $routes->{$method}( $request ) );
		}
	}
}
