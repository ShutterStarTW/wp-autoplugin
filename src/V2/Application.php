<?php

namespace WP_Autoplugin\V2;

use WP_Autoplugin\V2\Admin\Assets;
use WP_Autoplugin\V2\Infrastructure\Database\Installer;
use WP_Autoplugin\V2\Infrastructure\Queue\Job_Runner;
use WP_Autoplugin\V2\Infrastructure\Queue\Queue;
use WP_Autoplugin\V2\Orchestration\Legacy_Orchestrator;
use WP_Autoplugin\V2\Orchestration\Source_Agent;
use WP_Autoplugin\V2\Rest\Routes;

/**
 * Wires the v2 modules into WordPress.
 */
final class Application {
	/**
	 * Register runtime hooks.
	 */
	public function boot(): void {
		Installer::maybe_upgrade();

		( new Assets() )->register();
		( new Routes() )->register();
		( new Source_Agent() )->register();
		( new Legacy_Orchestrator() )->register();
		( new Queue() )->register();
		( new Job_Runner() )->register();
	}
}
