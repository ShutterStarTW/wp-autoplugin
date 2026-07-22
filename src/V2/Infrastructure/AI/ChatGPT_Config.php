<?php

namespace WP_Autoplugin\V2\Infrastructure\AI;

/** Fixed, safety-checked configuration for the experimental ChatGPT provider. */
final class ChatGPT_Config {
	public const CLIENT_ID        = 'app_EMoamEEZ73f0CkXaXp7hrann';
	public const DEVICE_START_URL = 'https://auth.openai.com/api/accounts/deviceauth/usercode';
	public const DEVICE_POLL_URL  = 'https://auth.openai.com/api/accounts/deviceauth/token';
	public const TOKEN_URL        = 'https://auth.openai.com/oauth/token';
	public const VERIFICATION_URL = 'https://auth.openai.com/codex/device';
	public const REDIRECT_URL     = 'https://auth.openai.com/deviceauth/callback';
	public const API_BASE_URL     = 'https://chatgpt.com/backend-api/codex';

	/** @return array<string, array{label:string,default:string,levels:array<int,string>}> */
	public static function models(): array {
		return [
			'gpt-5.6-sol'  => [ 'label' => 'GPT-5.6 Sol', 'default' => 'low', 'levels' => [ 'low', 'medium', 'high', 'xhigh', 'max', 'ultra' ] ],
			'gpt-5.6-terra'=> [ 'label' => 'GPT-5.6 Terra', 'default' => 'medium', 'levels' => [ 'low', 'medium', 'high', 'xhigh', 'max', 'ultra' ] ],
			'gpt-5.6-luna' => [ 'label' => 'GPT-5.6 Luna', 'default' => 'medium', 'levels' => [ 'low', 'medium', 'high', 'xhigh', 'max' ] ],
			'gpt-5.5'      => [ 'label' => 'GPT-5.5', 'default' => 'medium', 'levels' => [ 'low', 'medium', 'high', 'xhigh' ] ],
			'gpt-5.4'      => [ 'label' => 'GPT-5.4', 'default' => 'medium', 'levels' => [ 'low', 'medium', 'high', 'xhigh' ] ],
			'gpt-5.4-mini' => [ 'label' => 'GPT-5.4 Mini', 'default' => 'medium', 'levels' => [ 'low', 'medium', 'high', 'xhigh' ] ],
		];
	}

	public static function catalog_id( string $slug ): string {
		return 'chatgpt:' . $slug;
	}

	public static function remote_model( string $catalog_id ): string {
		return str_starts_with( $catalog_id, 'chatgpt:' ) ? substr( $catalog_id, 8 ) : '';
	}

	public static function is_verification_url( string $url ): bool {
		return self::is_exact_https_url( $url, 'auth.openai.com', '/codex/device', true );
	}

	public static function is_api_url( string $url ): bool {
		if ( preg_match( '/[\x00-\x20\x7f]/', $url ) ) {
			return false;
		}
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || 'https' !== strtolower( (string) ( $parts['scheme'] ?? '' ) ) || 'chatgpt.com' !== strtolower( (string) ( $parts['host'] ?? '' ) ) ) {
			return false;
		}
		if ( isset( $parts['port'] ) || isset( $parts['user'] ) || isset( $parts['pass'] ) || isset( $parts['fragment'] ) ) {
			return false;
		}
		$path = (string) ( $parts['path'] ?? '' );
		if ( str_contains( $path, '%' ) || str_contains( $path, '\\' ) ) {
			return false;
		}
		foreach ( explode( '/', $path ) as $segment ) {
			if ( '.' === $segment || '..' === $segment ) {
				return false;
			}
		}
		$base = '/backend-api/codex';
		return $base === $path || str_starts_with( $path, $base . '/' );
	}

	private static function is_exact_https_url( string $url, string $host, string $path, bool $allow_query ): bool {
		$parts = wp_parse_url( $url );
		return is_array( $parts )
			&& 'https' === strtolower( (string) ( $parts['scheme'] ?? '' ) )
			&& $host === strtolower( (string) ( $parts['host'] ?? '' ) )
			&& $path === (string) ( $parts['path'] ?? '' )
			&& ! isset( $parts['port'] )
			&& ! isset( $parts['user'] )
			&& ! isset( $parts['pass'] )
			&& ! isset( $parts['fragment'] )
			&& ( $allow_query || ! isset( $parts['query'] ) );
	}
}
