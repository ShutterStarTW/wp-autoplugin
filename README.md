# WP-Autoplugin

WP-Autoplugin brings an agentic coding workflow into WordPress. From the WordPress admin, it can explore an existing codebase, understand how it works, plan a change, write or revise the code, review the result, and prepare it for release as a new plugin, a fork, or a direct modification of an installed plugin or theme.

It is completely free and bring-your-own-key. You can connect it directly to OpenAI, Anthropic, Google Gemini, xAI, or a compatible API endpoint and choose the models you want to use. It can also work with your ChatGPT subscription, using the subscription’s Codex credits instead of an OpenAI API key.

In practical terms, it is similar to having **Claude Code or any other coding agent inside WordPress**, but with a workflow designed specifically for plugins and themes. It works with the code already installed on the site, keeps every generated revision recoverable, and never modifies a live target unless an administrator explicitly approves the release.

Use it to:

* create complete WordPress plugins from a description;
* diagnose and fix an installed plugin or theme;
* add features to existing code without rebuilding it from scratch;
* create a separate extension plugin using the target’s hooks;
* understand an unfamiliar codebase through an ongoing conversation; and
* review, test, package, install, fork, apply, or roll back changes from one workspace.

Version 2 is a complete rewrite for WordPress 6.6+ and PHP 8.2+. It replaces the separate v1 workflows with one durable **Plan → Code → Review** workspace built for real development work: exploring source code, making focused changes, iterating safely, and deciding exactly how a finished revision should be released.

> **Screenshot placeholder — The universal workspace (`workspace-overview.png`):** Annotated overview of a project with multiple workspace tabs, the Plan → Code → Review navigation, model and token-usage chips, and the current task. Suggested labels: “Durable workspace tabs”, “Chat at every stage”, “Per-stage model”, “Project usage”, and “Distraction-free mode”.

## What WP-Autoplugin helps you do

### Build a new plugin without leaving WordPress

Describe the plugin you need, refine the Plan, generate the files, inspect every change, and download or install the result. The output is a normal WordPress plugin that you can continue maintaining with or without WP-Autoplugin.

### Modify existing plugins and themes safely

Ask WP-Autoplugin to fix a bug, add a feature, update an integration, or explain where a change should be made. It inspects the target code and stages the result separately rather than editing the installed files immediately.

This makes it practical to work on custom plugins, internal tools, abandoned projects, and site-specific modifications without manually moving code between an editor and WordPress.

### Extend third-party code without editing it

WP-Autoplugin can inspect the actions and filters exposed by an installed plugin or theme and plan a separate extension plugin around them.

This is useful when you want to preserve update compatibility. When the requested behavior cannot be implemented reliably through the available hooks, the Plan explains the limitation instead of pretending that an extension is possible.

### Understand unfamiliar codebases

An Explain project gives you a durable conversation about an installed plugin or theme. You can ask where functionality lives, how data flows through the project, which hooks are available, or what would be involved in making a change.

The agent can inspect additional files as the conversation develops, so you do not need to know which source files are relevant before asking.

### Iterate without losing earlier work

Plans, generated revisions, manual edits, Reviews, conversations, usage records, and release actions are retained as part of the project.

You can return after a reload, reopen a closed project, compare revisions, restore an earlier result as a new revision, or see exactly which Review applied to which version of the code.

## Source-aware agents inside WordPress

When working with an existing plugin or theme, WP-Autoplugin does not need to place the entire codebase into one oversized prompt.

Instead, supported models can progressively explore the project using bounded, read-only source tools. The agent can:

* inspect target metadata and the project structure;
* list and page through source files;
* read only the relevant sections of a file;
* search for literal text across supported source files;
* discover WordPress actions and filters with their locations and surrounding code; and
* inspect an installed parent theme separately when working with a child theme.

This allows the agent to follow the code where it leads. A task that begins with a REST route, for example, can continue into the callback, supporting classes, validation logic, and related hooks without sending every project file on every request.

The tool loop runs through PHP, JavaScript, the WordPress REST API, and durable background jobs. It can pause between model turns, survive a page reload, and resume with the same model, reasoning effort, instructions, source fingerprint, and remaining tool budget.

The loop is deliberately bounded. Agents receive a limited source scope and tool budget rather than unrestricted access to the WordPress installation.

> **Screenshot placeholder — Agentic source exploration (`agent-activity.png`):** Show an existing-plugin Plan or Explain conversation with the Agent activity panel expanded. Annotate a source search, a targeted line-range read, hook discovery, model turns, and the final answer or Plan.

### Focused edits instead of unnecessary rewrites

WP-Autoplugin v1 regenerated an entire file even when a task required changing only a few lines.

For existing plugins and themes, v2 can generate exact, bounded search-and-replace operations. The server applies those operations to an immutable snapshot of the original file, validates the complete result, and stages only the approved Add, Update, and Delete paths.

This reduces unrelated changes, makes diffs easier to review, and lowers the risk of the model accidentally replacing working code outside the requested scope.

> **Screenshot placeholder — A targeted modification (`targeted-diff.png`):** Show the Code workspace in Changes view with an installed-plugin file selected and a small unified diff inside a larger file. Suggested labels: “Exact targeted replacement”, “Immutable baseline”, “Only planned paths staged”, “Add/Update/Delete summary”, and “Target-change protection”.

## One workspace for every project

Every task opens as an IDE-style workspace tab. The same interface supports:

* new plugins;
* installed plugins;
* standalone and child themes;
* hook-based extension plugins; and
* codebase explanation projects.

The workspace is designed for tasks that may take more than one request or browser session:

* tabs and their active jobs survive navigation and reloads;
* closing a tab does not delete the project or cancel its work;
* the project browser can search and paginate across open and closed projects; and
* closed projects can be reopened with their Plans, conversations, revisions, Reviews, usage, and release history intact.

Background work runs through the bundled Action Scheduler integration, so longer operations do not depend on keeping one browser request open.

## Plan → Code → Review

The workflow separates deciding what should change, producing the change, and evaluating the result.

This makes each stage easier to inspect and gives administrators clear control over when the project moves forward.

### 1. Plan

Start by describing what you want to create, change, fix, or understand.

The Planner turns that request into a readable implementation Plan and, where applicable, a validated map of the files that should be added, updated, or deleted.

Before any code is generated, you can:

* ask questions about the proposed approach;
* clarify requirements;
* request a revised Plan;
* edit the Plan manually;
* regenerate the structured file map after an edit; and
* inspect the full lineage of earlier Plans.

Plans are immutable and versioned. Revising one creates a successor instead of silently overwriting the original.

This matters when a project changes direction. You can see what was originally proposed, what changed during discussion, and which Plan produced a particular code revision.

For Hook Extension projects, planning begins by inspecting real actions, filters, and other integration points in the target. The result is a Plan for a separate plugin rather than a modification of the original code.

When the requested behavior cannot be implemented safely through the available hooks, the Planner explains why.

### 2. Code

Code generation begins only when an administrator selects **Generate Code**.

Generated code is staged inside WP-Autoplugin. It is not written directly to the installed plugin or theme.

The Code workspace lets you:

* browse the project as a file tree or a list of changes;
* inspect Add, Update, and Delete operations;
* edit files with WordPress CodeMirror;
* compare staged files with their original versions;
* review sanitized unified diffs;
* follow per-file generation and validation progress;
* edit several files and save them as one coherent successor revision;
* navigate validation problems directly to the affected file;
* restore an earlier revision without destroying later history;
* regenerate from the latest Plan; and
* work with PHP, JavaScript, CSS, JSON, HTML, SVG, XML, Markdown, and plain-text files.

Each generated result is an immutable revision. Manual edits create another revision rather than altering the existing one in place.

Generation is atomic. Files are checked individually and then validated as a complete project or change set. A failed, cancelled, conflicted, or noncompliant run does not leave behind a partial revision.

For existing targets, WP-Autoplugin also checks whether the source changed after the task began. This helps prevent an older generated change from being applied over a newer plugin or theme version without warning.

### 3. Review

AI Review evaluates one exact code revision.

The Reviewer produces a structured report containing:

* an overall verdict;
* a summary of the implementation;
* suggested manual test cases; and
* actionable findings ranked from P0 to P3.

A finding can include the affected source location, an explanation of the problem, and a suggested fix.

You can:

* discuss the report with the Reviewer;
* ask it to inspect a concern more closely;
* request reconsideration or reprioritization;
* dismiss and reopen findings with durable history;
* fix one finding, a selected group, or all findings;
* verify fixes against the resulting successor revision; and
* see when a Review became stale because the code changed afterward.

Because each Review is tied to a specific revision, an all-clear result cannot silently carry over after further edits. The workspace always shows whether the current code is still covered by the report.

> **Screenshot placeholder — Structured Review (`review-findings.png`):** Show the Review master-detail interface with the verdict, finding rail, selected P0–P3 finding, source anchor, suggested fix, Fix controls, manual test cases, and Review conversation button.

## Continue the conversation at every stage

Plan, Code, Review, and Explain each include a durable conversation.

This lets you work naturally instead of trying to fit every requirement into the first prompt.

You can:

* ask a question without changing the current Plan, revision, or Review;
* request a change and create a validated successor artifact;
* refer to earlier context with messages such as “Please make that change”;
* override an earlier requirement with a newer, explicit instruction;
* ask the Coder about an untouched target file before deciding to modify it; and
* reload or reopen the project without losing the conversation.

Code change requests also run through a separate compliance check before staging. WP-Autoplugin verifies whether the candidate actually satisfies the latest request.

When it does not, the system can perform one bounded corrective regeneration. If the result still fails the check, the operation stops without creating a revision.

This is intended to prevent plausible-looking code from being accepted when it does not implement what was requested.

## Use screenshots and mockups as part of the request

Every free-form composer accepts text, images, or both when the selected model supports vision.

Images can be attached to:

* initial Create, Modify, Fix, Hook Extension, and Explain requests;
* Plan follow-ups;
* Code questions and change requests;
* Review conversations; and
* Explain follow-ups.

This is useful for tasks such as:

* recreating an interface from a mockup;
* fixing a layout shown in a screenshot;
* explaining a visible error;
* matching an existing admin screen; or
* discussing a diagram alongside the code.

Images are private, message-scoped attachments. WP-Autoplugin accepts verified JPEG, PNG, and WebP files and checks the selected model’s image capability before queueing the request.

## Choose how a finished revision is released

A generated revision remains separate from the installed target until an administrator chooses a release action.

This gives you several ways to move forward depending on the project and the level of risk you are comfortable with.

WP-Autoplugin can:

* build a private ZIP containing one exact revision;
* install a new plugin or hook extension in an inactive state;
* activate an installed plugin as a separate action;
* create an inactive fork of an existing plugin;
* switch from the original plugin to its fork through an isolated activation flow;
* install an inactive copy of a standalone or child theme;
* produce replacement ZIPs for existing plugins and themes;
* apply confirmed Add, Update, and Delete operations directly to a target; and
* roll back the latest supported direct modification when the affected files have not changed since release.

You can therefore download the result for testing elsewhere, install it without activating it, keep it as a fork, or deliberately apply it to the original target.

Review is strongly recommended but remains advisory. Release becomes available once a staged revision exists. Releasing without a current all-clear Review requires an explicit, recorded override.

### Release checks

Before packaging, installing, or applying a revision, WP-Autoplugin rechecks:

* file paths;
* original baselines;
* source fingerprints;
* plugin or theme headers;
* WordPress and PHP requirements; and
* the identity of the destination.

For direct modifications, it stores complete before-and-after records before writing the first file. If a write fails partway through, it attempts to restore the original state.

When appropriate, direct modifications also receive a deterministic semantic patch-version bump. The version change is handled by WP-Autoplugin rather than asking the model to edit headers correctly.

### Theme safeguards

Theme releases are intentionally more restrictive.

WP-Autoplugin does not:

* convert a parent theme into a generated child theme;
* activate or switch themes;
* copy Customizer settings;
* copy Site Editor changes;
* copy global styles or templates stored in the database; or
* treat database-held theme configuration as part of the source files.

Direct theme changes are blocked while the theme is active or while it is the parent of the active child theme.

> **Screenshot placeholder — Release workspace (`release-workspace.png`):** Show release actions beside the Review-generated manual testing checklist and recent activity. Annotate ZIP download, inactive install, Activate or Switch to fork, advanced direct modification, rollback, and Review readiness/override state.

## Providers and models

WP-Autoplugin can connect directly to:

* OpenAI;
* Anthropic;
* Google Gemini;
* xAI; and
* custom OpenAI-compatible endpoints.

Compatible models from these providers can be used for structured Plan, Code, and Review work.

OpenAI, Anthropic, and experimental ChatGPT Subscription models with the required capabilities can also use the native read-only source tools. Model capabilities vary, so not every model supports source exploration, vision, structured output, or every project type.

### Experimental ChatGPT Subscription support

Administrators can optionally connect one site-wide ChatGPT account through a Codex device-authorization flow.

This makes supported Codex models available through the connected account’s ChatGPT subscription entitlement and usage allowance. An OpenAI API key is not required for this provider.

Availability depends on:

* the connected account’s eligibility;
* ChatGPT workspace policies;
* currently available models; and
* subscription usage limits.

The account can be disconnected at any time.

WP-Autoplugin provides separate model roles for:

* Default;
* Planner;
* Coder; and
* Reviewer.

You can use one model for every stage or select models with different strengths and costs for each role. Supported models also expose per-role reasoning-effort controls.

API usage may be billed by the selected provider.

> **Screenshot placeholder — Models and instructions (`settings-models.png`):** Annotated Settings screenshot showing the ChatGPT Subscription connection, provider API-key test controls, Default/Planner/Coder/Reviewer model roles, reasoning effort, Custom instructions, and the uninstall data-retention option.

## Give agents project-specific instructions

An installed plugin can include an exact root-level `AGENTS.md` file.

When present, WP-Autoplugin automatically provides the complete file to agents working on that plugin, including Hook Extension projects that target it.

This gives plugin authors a place to document information such as:

* architectural boundaries;
* coding conventions;
* preferred APIs;
* compatibility requirements;
* testing expectations;
* files that should not be changed; and
* project-specific implementation guidance.

Settings also provides site-wide **Custom instructions** for conventions and preferences that should apply across projects.

These can cover naming, metadata, architecture, formatting, compatibility targets, and other persistent requirements.

## See how much each project uses

WP-Autoplugin records token usage for every provider call and aggregates it across the project.

The token chip in the workspace header shows total input and output usage. It opens a breakdown by:

* provider;
* model; and
* executed AI job.

This makes it easier to understand which stages and models account for the project’s usage rather than seeing only an unexplained provider bill.

## Installation

1. Install and activate WP-Autoplugin.
2. Open **WP-Autoplugin → Settings**.
3. Configure at least one provider and select a default model, or connect the experimental ChatGPT Subscription provider.
4. Open **WP-Autoplugin → Workspace** and create a project.

## Development

Install the declared PHP and Node dependencies, then run:

```sh
npm run lint:js -- --no-fix
npm run build
find src/V2 -name '*.php' -print0 | xargs -0 -n1 php -l
composer validate --strict --no-check-publish
git diff --check
```

Frontend source changes must be made in `assets/v2/src/`.

Commit the regenerated files in `assets/v2/build/`, including asset metadata and RTL CSS.

Do not hand-edit generated bundles.

## Privacy

WP-Autoplugin does not require a WP-Autoplugin account.

The following data remains on the WordPress site:

* provider credentials;
* durable job snapshots;
* generated revisions;
* Reviews;
* release records; and
* project history.

To perform an AI task, WP-Autoplugin sends the configured provider the content required for that request. Depending on the task, this can include:

* the user’s instructions;
* relevant source code;
* attached prompt images;
* project instructions from `AGENTS.md`; and
* site-wide Custom instructions.

Uninstall cleanup is enabled by default. It can be disabled when project data should remain available for a later reinstall.

Uninstalling WP-Autoplugin never deletes or reverts plugins or themes that were created or modified through the workspace.

See `readme.txt` for external-service disclosures.

## License

GPL-2.0-or-later.
