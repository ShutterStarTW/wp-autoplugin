# WP-Autoplugin

WP-Autoplugin is a local-first WordPress development workspace that uses AI to plan, create, inspect, revise, review, package, and promote plugin changes.

Version 2 is a rewrite targeting WordPress 6.6+ and PHP 8.2+. The plugin now boots only the v2 application; the v1 workers, AJAX workflows, flow-specific admin pages, and simple/complex generation modes have been removed.

## How v2 works

Every task lives in a durable workspace:

1. Choose a new plugin, installed plugin, or installed theme.
2. Describe the task and generate a Plan.
3. Generate Code into an immutable staged revision.
4. Inspect source, diffs, history, and AI Review findings.
5. Explicitly package, install, activate, fork, modify, or roll back supported plugin changes.

AI output never writes directly to a target. Code is validated and staged first, and filesystem promotion remains an explicit administrator action with capability checks, conflict detection, and rollback records.

Closing a workspace tab is non-destructive. Projects, jobs, revisions, files, reviews, events, and usage remain available when the workspace is reopened.

Installed plugins can provide project-specific AI guidance in an exact root-level `AGENTS.md`. WP-Autoplugin automatically reads and supplies the complete file to Plan, Explain, Code, Code follow-up, Review, and Review-fix work for that plugin, including hook-extension projects targeting it. The file must be a regular, non-symlinked UTF-8 file no larger than 64 KiB; nested `AGENTS.md` files are not loaded automatically.

Settings also provides site-wide Custom instructions for coding conventions and other persistent guidance. Each future AI job privately snapshots the saved value when queued, so retries and resumable work stay consistent. Safety and response contracts take precedence, followed by the current request, a plugin-root `AGENTS.md`, and then the global guidance; built-in branding, metadata, naming, style, and architecture defaults are fallbacks below it.

## Admin settings

The native v2 settings screen keeps the existing upgrade-compatible option names for:

- OpenAI, Anthropic, Google Gemini, and xAI API keys
- Default, Planner, Coder, and Reviewer model selection
- per-role reasoning effort
- site-wide Custom instructions for future AI jobs
- custom OpenAI-compatible endpoints
- the experimental ChatGPT Subscription connection

The v1 simple/complex mode switch and AI Response Language option are intentionally not registered or shown in v2. Existing database rows for those retired options are left untouched and inert.

## Providers and models

Built-in model definitions are owned by the v2 model registry and remain filterable with `wp_autoplugin_models`. Supported direct transports include OpenAI, Anthropic, Google Gemini, xAI, and custom OpenAI-compatible endpoints. OpenAI and Anthropic models with the required capabilities can also use the native read-only source-agent tools.

Administrators may optionally connect one site-wide ChatGPT account through the experimental Codex device-authorization flow. OAuth tokens are exchanged and refreshed on the WordPress server, encrypted at rest with WordPress salts, and never exposed through v2 REST resources. Subscription availability, billing, workspace policy, and usage limits apply.

API usage may be billed by the selected provider.

## Safety model

- All v2 REST resources require `manage_options`.
- Source inspection is bounded, read-only, and constrained to the selected target root.
- Root plugin `AGENTS.md` instructions cannot override v2 safety, staging, manifest, or independent-Review invariants.
- Site-wide Custom instructions cannot override the current administrator request or a more-specific plugin-root `AGENTS.md`.
- API secrets never belong in jobs, events, revisions, usage, diagnostics, or browser bootstrap data.
- Generated files are deterministic staged revisions, not direct AI writes.
- Plugin package, install, activation, modification, and rollback actions require explicit approval and the relevant WordPress capabilities.
- `DISALLOW_FILE_MODS` and multisite mutation restrictions are respected.
- Markdown rendered in the workspace is sanitized before insertion into the DOM.

AI-generated code should still be reviewed and tested on a staging site before production use.

## Architecture

- `src/V2/` — PHP domain, infrastructure, orchestration, REST, release, and admin modules
- `assets/v2/src/` — TypeScript/React and SCSS source
- `assets/v2/build/` — committed production bundles, metadata, and RTL styles
- `assets/v2/vendor/` — vendored browser-only Markdown rendering dependencies
- `wp-autoplugin/v2` — REST namespace
- `WP_Autoplugin\V2\Application` — runtime composition root

Durable v2 records use additive, versioned custom tables. The bundled Action Scheduler runs queued jobs in the `wp-autoplugin` group.

The detailed architecture, lifecycle, schema, security invariants, implementation status, and verification checklist live in [`docs/V2.md`](docs/V2.md).

## Development

Install the declared PHP and Node dependencies, then use:

```sh
npm run lint:js -- --no-fix
npm run build
find src/V2 -name '*.php' -print0 | xargs -0 -n1 php -l
composer validate --strict --no-check-publish
git diff --check
```

Frontend changes must be made in `assets/v2/src/`. Commit the regenerated files in `assets/v2/build/`, including asset metadata and RTL CSS.

Do not hand-edit generated bundles.

## Installation

1. Install and activate WP-Autoplugin.
2. Open **WP-Autoplugin → Settings**.
3. Configure at least one provider and select a default model.
4. Open **WP-Autoplugin → Workspace** and create a project.

## Privacy

WP-Autoplugin does not require a WP-Autoplugin account. Source, credentials, and durable job snapshots remain on the WordPress site except for task content, relevant source, images, and any saved Custom instructions sent to the configured AI provider. Custom instructions are not a secret store. See `readme.txt` for the external-service disclosures.

## License

GPL-2.0-or-later.
