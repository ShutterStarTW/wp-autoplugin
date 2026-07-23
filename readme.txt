=== WP-Autoplugin ===
Contributors: balazspiller
Donate link: https://wp-autoplugin.com
Tags: ai, plugin generator, development, wordpress, automation
Requires at least: 6.6
Tested up to: 6.9
Stable tag: 2.0.0-dev
Requires PHP: 8.2
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A local-first AI workspace for planning, creating, reviewing, and safely promoting WordPress plugin changes.

== Description ==

WP-Autoplugin v2 is a durable WordPress development workspace for new plugins and changes involving installed plugins or themes.

**Current v2 capabilities:**

* Durable projects, workspace tabs, jobs, events, revisions, reviews, and usage records.
* AI planning, source inspection, code generation, follow-up changes, and explanations.
* Immutable staged revisions with source, diff, history, manual editing, and restore tools.
* Structured AI Review reports and finding-resolution workflows.
* Explicit plugin packaging, installation, activation, forks, direct modification, and rollback where supported.
* OpenAI, Anthropic, Google Gemini, xAI, custom OpenAI-compatible endpoints, and an experimental ChatGPT Subscription connection.
* Default, Planner, Coder, and Reviewer model roles with supported reasoning-effort controls.
* Local-first storage and administrator-only v2 REST resources.

AI output never writes directly to a target. Generated code is validated and staged before an administrator can explicitly promote it. Closing a workspace tab does not delete or cancel its durable work.

The v1 flow-specific admin pages, AJAX workers, and simple/complex mode switch have been removed. AI Response Language is not supported by v2 at this time.

== Installation ==

1. Upload and activate WP-Autoplugin.
2. Open WP-Autoplugin > Settings.
3. Configure at least one provider and select a default model.
4. Open WP-Autoplugin > Workspace and create a project.

== Frequently Asked Questions ==

= Do I need an API key? =

Configure a supported provider such as OpenAI, Anthropic, Google Gemini, or xAI, add a compatible custom endpoint, or connect the experimental ChatGPT Subscription provider. Provider charges or subscription limits may apply.

= Where are credentials stored? =

API credentials remain in WordPress options on your server. ChatGPT OAuth tokens are encrypted with WordPress salts. Secrets are not returned through v2 REST resources or stored in jobs, revisions, usage, or browser bootstrap data.

= Does AI write directly to installed plugins? =

No. AI-generated source is stored in a validated staged revision. Supported release actions require explicit administrator approval, WordPress capability checks, target conflict checks, and durable rollback data.

= Is generated code production-ready? =

AI-generated code should be reviewed and tested on a staging site before production use. Consider a professional security review for critical applications.

= Can I use different models for different tasks? =

Yes. Settings provides a Default model plus optional Planner, Coder, and Reviewer overrides. Models with supported reasoning controls also expose an effort selector.

== External Services ==

WP-Autoplugin sends task instructions, relevant source context, and optional images only to providers configured by an administrator.

**OpenAI**

* API-key requests use OpenAI API services.
* The optional experimental ChatGPT connection sends device authorization and token requests to `https://auth.openai.com`, and model discovery and AI requests to `https://chatgpt.com/backend-api/codex`.
* ChatGPT OAuth tokens are exchanged and refreshed server-side, encrypted at rest, and never exposed through v2 REST responses.
* [Terms of Use](https://openai.com/policies/terms-of-use/)
* [Privacy Policy](https://openai.com/policies/privacy-policy/)

**Anthropic**

* [Terms of Service](https://www.anthropic.com/terms-of-service)
* [Privacy Policy](https://www.anthropic.com/privacy-policy)

**Google Generative Language API**

* [Terms of Service](https://policies.google.com/terms)
* [Generative AI Additional Terms](https://policies.google.com/terms/generative-ai)
* [Privacy Policy](https://policies.google.com/privacy)

**xAI**

* [Terms of Service](https://x.ai/legal/terms-of-service)
* [Privacy Policy](https://x.ai/legal/privacy-policy)

Custom OpenAI-compatible endpoints are governed by the administrator's chosen service.

== Changelog ==

= 2.0.0-dev =

* Replaced the v1 admin application with the native v2 workspace, menu, settings, model registry, provider transports, orchestration, and release services.
* Removed v1 flow pages, AJAX workers, provider adapters, admin assets, simple/complex generation mode, and AI Response Language registration.
* Added durable Plan, Code, Review, Explain, revision, package, promotion, and rollback workflows.
* Added native read-only source agents for supported models.
* Added v2 settings assets and Settings API persistence for credentials, model roles, effort, custom endpoints, and the experimental ChatGPT Subscription provider.

== License ==

This plugin is licensed under the GPLv2 or later.
