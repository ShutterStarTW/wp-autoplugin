<?php

namespace WP_Autoplugin\V2\Domain\Revision;

/**
 * Deterministically updates only the main plugin header's semantic version.
 */
final class Version_Bumper {
	/**
	 * Increment the semantic patch version, or apply a validated replacement.
	 *
	 * @throws \InvalidArgumentException When no valid version can be produced.
	 */
	public function bump( string $source, ?string $replacement = null ): string {
		$header = substr( $source, 0, 8192 );
		$regex  = '/^(\s*\*?\s*Version:\s*)(\d+\.\d+\.\d+(?:-[0-9A-Za-z.-]+)?(?:\+[0-9A-Za-z.-]+)?)(\s*)$/mi';

		if ( ! preg_match( $regex, $header, $match, PREG_OFFSET_CAPTURE ) ) {
			throw new \InvalidArgumentException( 'The main plugin header does not contain a parseable semantic version.' );
		}

		$current = $match[2][0];
		$next    = null === $replacement ? $this->increment_patch( $current ) : trim( $replacement );
		if ( ! preg_match( '/^\d+\.\d+\.\d+(?:-[0-9A-Za-z.-]+)?(?:\+[0-9A-Za-z.-]+)?$/', $next ) ) {
			throw new \InvalidArgumentException( 'The replacement must be a semantic version.' );
		}

		$offset = $match[2][1];
		return substr( $source, 0, $offset ) . $next . substr( $source, $offset + strlen( $current ) );
	}

	private function increment_patch( string $version ): string {
		$core  = preg_split( '/[-+]/', $version, 2 )[0];
		$parts = array_map( 'intval', explode( '.', $core ) );
		++$parts[2];

		return implode( '.', $parts );
	}
}
