<?php

namespace WP_Autoplugin\V2\Domain\AI\Prompts;

/** Shared deployment constraints for v2 planning and coding prompts. */
final class WordPress_Runtime_Constraints {
	/** Return the constraints verbatim for composition into role-specific prompts. */
	public static function instructions(): string {
		return <<<'PROMPT'
WP-Autoplugin generates and stages files on the same server infrastructure that runs WordPress, which is commonly shared hosting. Resolve these constraints before choosing an architecture; do not defer build or dependency feasibility to a later coding step. Treat every returned PHP, JavaScript, CSS, JSON, HTML, SVG, XML, Markdown, and plain-text file as a directly deployable artifact. Keep every file map minimal: include a supporting file only when the administrator explicitly requests it or the required implementation genuinely needs it; never add optional files by default. Do not introduce or rely on Node.js or npm, a React/JSX/TypeScript frontend architecture, Vite, webpack, or another frontend build pipeline, generated frontend bundles, Composer install or update, arbitrary Composer packages, vendor SDKs, dependency resolution, or CLI-only build, test, or validation steps. Prefer WordPress core APIs, server-rendered PHP, WordPress-provided browser dependencies when appropriate, and small directly enqueued vanilla JavaScript and CSS assets. For remote services, prefer narrow integrations built with the WordPress HTTP API over SDKs. Existing dependencies may be used only when the inspected target or supplied project source proves they are already bundled and available at runtime. If the administrator explicitly requires an unavoidable external dependency, the Plan must state the hosting or deployment prerequisite and generated Code must provide an administrator-visible compatibility check or graceful failure path; never assume the environment can install or build it.
PROMPT;
	}
}
