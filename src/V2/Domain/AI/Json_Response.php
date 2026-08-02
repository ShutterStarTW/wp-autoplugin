<?php

namespace WP_Autoplugin\V2\Domain\AI;

/** Small v2 helper for tolerating a provider-wrapped JSON response. */
final class Json_Response {
	public static function strip_fence( string $content ): string {
		$content  = trim( $content );
		$unfenced = preg_replace( '/^```json\s*\n(.*)\n```$/s', '$1', $content );

		return null === $unfenced ? $content : trim( $unfenced );
	}
}
