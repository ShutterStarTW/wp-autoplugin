<?php
/**
 * Universal v2 workspace mount point.
 *
 * @package WP-Autoplugin
 * @since 2.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Keep the initial shell styled while the generated stylesheet and React bundle load.
?>
<style id="wp-autoplugin-v2-loader-styles">
	.wp-autoplugin-v2-wrap{margin:0;margin-inline-start:-20px}.wp-autoplugin-v2-loading{background:#f3f5f7;box-sizing:border-box;color:#172026;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;min-height:calc(100vh - 32px);padding:32px}.wp-autoplugin-v2-loading__header{margin-bottom:24px}.wp-autoplugin-v2-loading__eyebrow{color:#3858e9;font-size:13px;font-weight:600;letter-spacing:.08em;margin:0;text-transform:uppercase}.wp-autoplugin-v2-loading__shell{border:1px solid #cdd3d8;border-radius:8px;box-shadow:0 12px 32px rgba(23,32,38,.08);overflow:hidden}.wp-autoplugin-v2-loading__tabs{align-items:stretch;background:#e4e8eb;border-bottom:1px solid #cdd3d8;display:flex;height:43px}.wp-autoplugin-v2-loading__tab{align-items:center;background:#fff;box-shadow:inset 0 2px 0 #3858e9;display:flex;gap:8px;padding:0 16px;width:154px}.wp-autoplugin-v2-loading__tab-dot{background:#dba617;border-radius:50%;height:7px;width:7px}.wp-autoplugin-v2-loading__tab-line{background:#c8ced3;border-radius:999px;height:7px;width:84px}.wp-autoplugin-v2-loading__new-tab{align-items:center;color:#66727a;display:flex;font-size:20px;justify-content:center;width:43px}.wp-autoplugin-v2-loading__canvas{align-items:center;background:radial-gradient(circle at 50% 42%,#fff 0,#f7f8fa 38%,#eef1f3 75%);display:flex;justify-content:center;min-height:620px;padding:32px}.wp-autoplugin-v2-loading__status{max-width:420px;text-align:center}.wp-autoplugin-v2-loading__mark{align-items:center;background:linear-gradient(145deg,#fff,#e9edff);border:1px solid #cbd3ff;border-radius:17px;box-shadow:0 10px 24px rgba(56,88,233,.15);color:#3858e9;display:flex;font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:19px;font-weight:700;height:56px;justify-content:center;margin:0 auto 22px;width:56px}.wp-autoplugin-v2-loading__title{font-size:22px;font-weight:600;line-height:1.3;margin:0 0 8px}.wp-autoplugin-v2-loading__copy{color:#5f6b73;font-size:14px;line-height:1.5;margin:0}.wp-autoplugin-v2-loading__progress{background:#dfe3e6;border-radius:999px;height:3px;margin:24px auto 0;max-width:260px;overflow:hidden}.wp-autoplugin-v2-loading__progress span{animation:wp-autoplugin-v2-loader 1.35s ease-in-out infinite;background:#3858e9;border-radius:inherit;display:block;height:100%;width:42%}@keyframes wp-autoplugin-v2-loader{0%{transform:translateX(-110%)}100%{transform:translateX(345%)}}@media (prefers-reduced-motion:reduce){.wp-autoplugin-v2-loading__progress span{animation:none;margin:auto}}@media (max-width:960px){.wp-autoplugin-v2-loading{padding:24px}.wp-autoplugin-v2-loading__canvas{min-height:520px}}@media (max-width:500px){.wp-autoplugin-v2-loading{padding:20px}.wp-autoplugin-v2-loading__canvas{min-height:440px;padding:24px 20px}.wp-autoplugin-v2-loading__title{font-size:20px}}
</style>
<div class="wrap wp-autoplugin-v2-wrap">
	<h1 class="screen-reader-text"><?php esc_html_e( 'WP-Autoplugin Workspace', 'wp-autoplugin' ); ?></h1>
	<div id="wp-autoplugin-v2-root">
		<main class="wp-autoplugin-v2-loading" role="status" aria-live="polite" aria-busy="true">
			<header class="wp-autoplugin-v2-loading__header">
				<p class="wp-autoplugin-v2-loading__eyebrow">WP-Autoplugin</p>
			</header>
			<div class="wp-autoplugin-v2-loading__shell">
				<div class="wp-autoplugin-v2-loading__tabs" aria-hidden="true">
					<span class="wp-autoplugin-v2-loading__tab">
						<span class="wp-autoplugin-v2-loading__tab-dot"></span>
						<span class="wp-autoplugin-v2-loading__tab-line"></span>
					</span>
					<span class="wp-autoplugin-v2-loading__new-tab">+</span>
				</div>
				<div class="wp-autoplugin-v2-loading__canvas">
					<div class="wp-autoplugin-v2-loading__status">
						<div class="wp-autoplugin-v2-loading__mark" aria-hidden="true">&lt;/&gt;</div>
						<p class="wp-autoplugin-v2-loading__title"><?php esc_html_e( 'Preparing your workspace', 'wp-autoplugin' ); ?></p>
						<p class="wp-autoplugin-v2-loading__copy"><?php esc_html_e( 'Restoring your projects, tabs, and recent work…', 'wp-autoplugin' ); ?></p>
						<div class="wp-autoplugin-v2-loading__progress" aria-hidden="true"><span></span></div>
					</div>
				</div>
			</div>
		</main>
	</div>
</div>
