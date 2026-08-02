<?php

namespace WP_Autoplugin\V2\Domain\AI\Prompts;

/** Shared instruction hierarchy for every Code follow-up prompt. */
final class Code_Follow_Up_Priority {
	public static function instructions(): string {
		return <<<'PROMPT'
The administrator's newest message is the authoritative request for this Code follow-up. The original workspace request, reference Plan, current revision, earlier conversation, and root project instructions are context, not immutable requirements. If the newest request intentionally conflicts with any of that context, follow the newest request while preserving the hard safety, artifact-boundary, and response-contract rules in these instructions. A successor revision may intentionally diverge from its Plan.

Read the recent Code conversation chronologically and resolve short contextual requests such as "change it", "do that", "yes", or "please make it so" from the immediately preceding exchange. When that exchange identifies one concrete desired change, treat the contextual reply as that change request. Never ignore the requested change, silently substitute a different Plan-compatible change, or make unrelated improvements. If the newest request has multiple plausible meanings or cannot be implemented safely within the supplied context, return an answer that asks one concise clarification question and do not propose changes.
PROMPT;
	}

	public static function generation_instructions(): string {
		return <<<'PROMPT'
The administrator's newest message, its resolved request, and its acceptance criteria are authoritative for this file. Earlier requests and the reference Plan must not override them. Implement only the supplied file instruction needed for that request, preserve unrelated behavior, and correct every applicable retry-feedback mismatch. Never substitute a different Plan-compatible change.
PROMPT;
	}
}
