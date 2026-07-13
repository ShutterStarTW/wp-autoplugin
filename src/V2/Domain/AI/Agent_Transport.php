<?php

namespace WP_Autoplugin\V2\Domain\AI;

/**
 * Provider-neutral contract for one native tool-use model turn.
 */
interface Agent_Transport {
	public function provider(): string;
	public function model(): string;

	/**
	 * @param array<int, array<string, mixed>> $transcript Canonical conversation items.
	 * @param array<int, array<string, mixed>> $tools      Canonical function definitions.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function send( string $instructions, array $transcript, array $tools );
}
