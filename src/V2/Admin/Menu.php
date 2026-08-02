<?php

namespace WP_Autoplugin\V2\Admin;

/**
 * Registers the v2-only WordPress admin surface.
 */
final class Menu {
	public function register(): void {
		add_action( 'admin_menu', [ $this, 'add_pages' ] );
		add_action( 'admin_head', [ $this, 'render_icon_style' ] );
		add_filter( 'plugin_action_links_' . plugin_basename( WP_AUTOPLUGIN_DIR . 'wp-autoplugin.php' ), [ $this, 'add_action_links' ] );
	}

	/**
	 * Render the decorative ring around the plugin's admin menu icon.
	 */
	public function render_icon_style(): void {
		?>
		<style id="wp-autoplugin-admin-menu-icon">
			li.toplevel_page_wp-autoplugin .wp-menu-image::after {
				content: "";
				display: block;
				width: 20px;
				height: 20px;
				border: 2px solid;
				border-radius: 100px;
				position: absolute;
				top: 5px;
				left: 6px;
			}

			li.toplevel_page_wp-autoplugin:not(.wp-menu-open) a:not(:hover) .wp-menu-image::after {
				color: #a7aaad;
				color: rgba(240, 246, 252, 0.6);
			}
		</style>
		<?php
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
