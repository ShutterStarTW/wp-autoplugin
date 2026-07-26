<?php

namespace WP_Autoplugin\V2\Domain\AI\Prompts;

use WP_Autoplugin\V2\Domain\Target\Plugin_Instructions;

/** Independently checks a generated Code follow-up before staging. */
final class Code_Follow_Up_Compliance_Prompt {
	public function instructions(): string {
		$priority            = Code_Follow_Up_Priority::instructions();
		$plugin_instructions = Plugin_Instructions::prompt_policy( true );

		return <<<PROMPT
You are the final compliance checker for a generated WordPress Code follow-up. Independently determine what the administrator's newest message requests, using the recent Code conversation to resolve contextual references. Check the candidate staged source against that request and every applicable acceptance criterion. The analyzer's resolved request and criteria are advisory: report a failure if they misinterpret the administrator.

$priority

$plugin_instructions

Return exactly one valid JSON object and no Markdown fences.

When the candidate fully implements the newest request without substituting unrelated work, return:
{"outcome":"pass","content":"Concise reason the requested result is present.","issues":[]}

When it does not, return:
{"outcome":"fail","content":"Concise explanation of the mismatch.","issues":[{"path":"relative/file.php","message":"Specific correction required in this generated file."}]}

Use an exact candidate manifest path for each file-level issue. Use an empty path only when the mismatch requires a topology or analysis change that cannot be corrected by regenerating an existing generated file. Report no more than five issues, and do not quote source excerpts in the content or issue messages. Do not require adherence to the reference Plan or original workspace request when either conflicts with the newest administrator message. Do not request optional improvements.
PROMPT;
	}

	public function input( string $message, array $history, string $resolved_request, array $acceptance_criteria, array $target, array $manifest, array $source ): string {
		return wp_json_encode(
			[
				'json_contract'                  => 'Return one JSON object with outcome pass or fail.',
				'recent_code_conversation'       => $history,
				'analyzer_resolved_request'      => $resolved_request,
				'analyzer_acceptance_criteria'   => $acceptance_criteria,
				'target'                         => $target,
				'candidate_manifest'             => $manifest,
				'candidate_staged_source'        => $source,
				'authoritative_latest_request'   => $message,
			],
			JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
		) ?: '{}';
	}
}
