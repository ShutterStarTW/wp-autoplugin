<?php

namespace WP_Autoplugin\V2;

use WP_Autoplugin\V2\Admin\Assets;
use WP_Autoplugin\V2\Admin\Menu;
use WP_Autoplugin\V2\Admin\Settings;
use WP_Autoplugin\V2\Admin\Settings_Assets;
use WP_Autoplugin\V2\Admin\Updater;
use WP_Autoplugin\V2\Infrastructure\Database\Installer;
use WP_Autoplugin\V2\Infrastructure\Queue\Job_Runner;
use WP_Autoplugin\V2\Infrastructure\Queue\Queue;
use WP_Autoplugin\V2\Orchestration\Direct_Planner;
use WP_Autoplugin\V2\Orchestration\Code_Orchestrator;
use WP_Autoplugin\V2\Orchestration\Code_Follow_Up_Orchestrator;
use WP_Autoplugin\V2\Orchestration\Source_Agent;
use WP_Autoplugin\V2\Orchestration\Review_Orchestrator;
use WP_Autoplugin\V2\Orchestration\Package_Orchestrator;
use WP_Autoplugin\V2\Orchestration\Promotion_Orchestrator;
use WP_Autoplugin\V2\Rest\Routes;
use WP_Autoplugin\V2\Rest\ChatGPT_Provider_Routes;

/**
 * Wires the v2 modules into WordPress.
 */
final class Application {
	/**
	 * Register runtime hooks.
	 */
	public function boot(): void {
		Installer::maybe_upgrade();

		( new Menu() )->register();
		( new Assets() )->register();
		( new Settings() )->register();
		( new Settings_Assets() )->register();
		( new Updater() )->register();
		( new Routes() )->register();
		( new ChatGPT_Provider_Routes() )->register();
		( new Source_Agent() )->register();
		( new Code_Orchestrator() )->register();
		( new Code_Follow_Up_Orchestrator() )->register();
		( new Review_Orchestrator() )->register();
		( new Package_Orchestrator() )->register();
		( new Promotion_Orchestrator() )->register();
		( new Direct_Planner() )->register();
		( new Queue() )->register();
		( new Job_Runner() )->register();
	}
}
