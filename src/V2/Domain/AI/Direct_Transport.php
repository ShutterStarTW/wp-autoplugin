<?php

namespace WP_Autoplugin\V2\Domain\AI;

/**
 * Provider-neutral contract for one direct model request without tools.
 */
interface Direct_Transport {
	public function provider(): string;
	public function model(): string;
	public function effort(): string;

	/** @return array<string, mixed>|\WP_Error */
	/** @param array<string, mixed> $options Request limits and response-format hints. */
	public function complete( string $instructions, string $input, array $options = [] );
}
