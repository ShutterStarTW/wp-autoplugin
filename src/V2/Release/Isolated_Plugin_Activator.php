<?php

namespace WP_Autoplugin\V2\Release;

/**
 * Activates a plugin through WordPress's normal sandbox in a separate request.
 *
 * A fork deliberately retains the original plugin's PHP symbols. When the
 * original is active, loading the fork in the same request would therefore
 * cause a redeclaration fatal even after the original has been deactivated.
 */
final class Isolated_Plugin_Activator {
	/** @return true|\WP_Error */
	public function probe( int $user_id ) {
		$response = $this->request( admin_url( 'plugins.php' ), $user_id );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$status = wp_remote_retrieve_response_code( $response );
		if ( $status < 200 || $status >= 300 ) {
			return new \WP_Error( 'promotion_activation_loopback', __( 'WordPress could not reach its plugin administration screen to switch plugins safely.', 'wp-autoplugin' ) );
		}
		return true;
	}

	/** @return true|\WP_Error */
	public function activate( string $plugin, int $user_id ) {
		$plugin = plugin_basename( trim( $plugin ) );
		if ( $this->is_active( $plugin ) ) {
			return true;
		}
		$response = $this->request(
			add_query_arg(
				[
					'action'        => 'activate',
					'plugin'        => $plugin,
					'plugin_status' => 'all',
				],
				admin_url( 'plugins.php' )
			),
			$user_id,
			'activate-plugin_' . $plugin
		);
		if ( $this->is_active( $plugin ) ) {
			return true;
		}
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		return new \WP_Error( 'promotion_activation_failed', __( 'The plugin could not be activated in WordPress\'s isolated activation sandbox. Check the PHP error log for details.', 'wp-autoplugin' ) );
	}

	/**
	 * @return array<string,mixed>|\WP_Error
	 */
	private function request( string $url, int $user_id, ?string $nonce_action = null ) {
		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return new \WP_Error( 'promotion_activation_user', __( 'The promotion owner is unavailable.', 'wp-autoplugin' ) );
		}
		$expiration = time() + 5 * MINUTE_IN_SECONDS;
		$manager    = \WP_Session_Tokens::get_instance( $user_id );
		$token      = $manager->create( $expiration );
		try {
			$auth      = wp_generate_auth_cookie( $user_id, $expiration, 'auth', $token );
			$secure    = wp_generate_auth_cookie( $user_id, $expiration, 'secure_auth', $token );
			$logged_in = wp_generate_auth_cookie( $user_id, $expiration, 'logged_in', $token );
			if ( '' === $auth || '' === $secure || '' === $logged_in ) {
				return new \WP_Error( 'promotion_activation_session', __( 'WordPress could not create a temporary isolated activation session.', 'wp-autoplugin' ) );
			}
			if ( null !== $nonce_action ) {
				$url = add_query_arg( '_wpnonce', $this->nonce( $user_id, $logged_in, $nonce_action ), $url );
			}
			$timeout = (int) apply_filters( 'wp_autoplugin_v2_activation_loopback_timeout', 120 );
			$response = wp_remote_get(
				$url,
				[
					'timeout'     => min( 300, max( 5, $timeout ) ),
					'redirection' => 0,
					'sslverify'   => apply_filters( 'https_local_ssl_verify', false ),
					'headers'     => [
						'Cookie' => AUTH_COOKIE . '=' . $auth . '; ' . SECURE_AUTH_COOKIE . '=' . $secure . '; ' . LOGGED_IN_COOKIE . '=' . $logged_in,
					],
				]
			);
			if ( is_wp_error( $response ) ) {
				return new \WP_Error( 'promotion_activation_loopback', __( 'WordPress could not complete the isolated plugin activation request.', 'wp-autoplugin' ) );
			}
			return $response;
		} finally {
			$manager->destroy( $token );
		}
	}

	private function nonce( int $user_id, string $logged_in_cookie, string $action ): string {
		$previous_user   = get_current_user_id();
		$had_cookie      = array_key_exists( LOGGED_IN_COOKIE, $_COOKIE );
		$previous_cookie = $had_cookie ? $_COOKIE[ LOGGED_IN_COOKIE ] : null;
		try {
			$_COOKIE[ LOGGED_IN_COOKIE ] = $logged_in_cookie;
			wp_set_current_user( $user_id );
			return wp_create_nonce( $action );
		} finally {
			if ( $had_cookie ) {
				$_COOKIE[ LOGGED_IN_COOKIE ] = $previous_cookie;
			} else {
				unset( $_COOKIE[ LOGGED_IN_COOKIE ] );
			}
			wp_set_current_user( $previous_user );
		}
	}

	private function is_active( string $plugin ): bool {
		wp_cache_delete( 'active_plugins', 'options' );
		wp_cache_delete( 'alloptions', 'options' );
		return in_array( $plugin, (array) get_option( 'active_plugins', [] ), true );
	}
}
