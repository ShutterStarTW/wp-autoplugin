<?php

namespace WP_Autoplugin\V2\Domain\AI;

/**
 * Provider-neutral contract for one direct model request without tools.
 */
interface Direct_Transport {
	/** Long-running coder/reasoning requests regularly exceed WordPress's default timeout. */
	public const REQUEST_TIMEOUT = 300;

	public function provider(): string;
	public function model(): string;
	public function effort(): string;

	/** @return array<string, mixed>|\WP_Error */
	/** @param array<string, mixed> $options Request limits, response-format hints, and validated prompt images. */
	public function complete( string $instructions, string $input, array $options = [] );
}
