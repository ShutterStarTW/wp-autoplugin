<?php

namespace WP_Autoplugin\V2\Admin;

/**
 * Registers the v2-only WordPress admin surface.
 */
final class Menu {
	public function register(): void {
		add_action( 'admin_menu', [ $this, 'add_pages' ] );
		add_filter( 'plugin_action_links_' . plugin_basename( WP_AUTOPLUGIN_DIR . 'wp-autoplugin.php' ), [ $this, 'add_action_links' ] );
	}

	public function add_pages(): void {
		add_menu_page(
			esc_html__( 'WP-Autoplugin', 'wp-autoplugin' ),
			esc_html__( 'WP-Autoplugin', 'wp-autoplugin' ),
			'manage_options',
			'wp-autoplugin',
			[ $this, 'render_workspace' ],
			'dashicons-admin-plugins',
			100
		);

		add_submenu_page(
			'wp-autoplugin',
			esc_html__( 'Workspace', 'wp-autoplugin' ),
			esc_html__( 'Workspace', 'wp-autoplugin' ),
			'manage_options',
			'wp-autoplugin',
			[ $this, 'render_workspace' ]
		);

		add_submenu_page(
			'wp-autoplugin',
			esc_html__( 'Settings', 'wp-autoplugin' ),
			esc_html__( 'Settings', 'wp-autoplugin' ),
			'manage_options',
			'wp-autoplugin-settings',
			[ $this, 'render_settings' ]
		);
	}

	public function render_workspace(): void {
		$this->require_capability();
		include __DIR__ . '/views/workspace.php';
	}

	public function render_settings(): void {
		$this->require_capability();
		( new Settings_Page() )->render();
	}

	/**
	 * Add a settings shortcut for WP-Autoplugin itself.
	 *
	 * @param array<int|string, string> $links Existing plugin action links.
	 * @return array<int|string, string>
	 */
	public function add_action_links( array $links ): array {
		$settings = sprintf(
			'<a href="%s">%s</a>',
			esc_url( admin_url( 'admin.php?page=wp-autoplugin-settings' ) ),
			esc_html__( 'Settings', 'wp-autoplugin' )
		);
		array_unshift( $links, $settings );

		return $links;
	}

	private function require_capability(): void {
		if ( current_user_can( 'manage_options' ) ) {
			return;
		}

		wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'wp-autoplugin' ) );
	}
}
