<?php

namespace WP_Autoplugin\V2\Infrastructure\AI;

/** Stores the site-wide ChatGPT token pair using authenticated encryption. */
final class ChatGPT_Token_Store {
	public const OPTION = '_wp_autoplugin_chatgpt_oauth_tokens';
	private const CIPHER = 'aes-256-gcm';
	private const CONTEXT = 'wp-autoplugin/chatgpt-oauth/v1';

	public function available(): bool {
		return function_exists( 'openssl_encrypt' ) && function_exists( 'openssl_decrypt' ) && in_array( self::CIPHER, array_map( 'strtolower', openssl_get_cipher_methods() ), true );
	}

	/** @return array<string, mixed>|null|\WP_Error */
	public function get() {
		$value = get_option( self::OPTION, null );
		if ( null === $value || false === $value || '' === $value ) {
			return null;
		}
		if ( ! $this->available() || ! is_string( $value ) ) {
			return new \WP_Error( 'chatgpt_oauth_storage_invalid', __( 'The stored ChatGPT credentials are unavailable.', 'wp-autoplugin' ) );
		}
		$envelope = json_decode( $value, true );
		if ( ! is_array( $envelope ) || 1 !== ( $envelope['version'] ?? null ) || self::CIPHER !== ( $envelope['cipher'] ?? null ) ) {
			return new \WP_Error( 'chatgpt_oauth_storage_invalid', __( 'The stored ChatGPT credentials are invalid.', 'wp-autoplugin' ) );
		}
		$iv = base64_decode( (string) ( $envelope['iv'] ?? '' ), true );
		$tag = base64_decode( (string) ( $envelope['tag'] ?? '' ), true );
		$ciphertext = base64_decode( (string) ( $envelope['ciphertext'] ?? '' ), true );
		if ( false === $iv || false === $tag || false === $ciphertext ) {
			return new \WP_Error( 'chatgpt_oauth_storage_invalid', __( 'The stored ChatGPT credentials are invalid.', 'wp-autoplugin' ) );
		}
		$plain = openssl_decrypt( $ciphertext, self::CIPHER, $this->key(), OPENSSL_RAW_DATA, $iv, $tag, self::CONTEXT );
		$tokens = is_string( $plain ) ? json_decode( $plain, true ) : null;
		return $this->valid( $tokens ) ? $tokens : new \WP_Error( 'chatgpt_oauth_decryption_failed', __( 'The ChatGPT credentials could not be decrypted with the current WordPress salts. Reconnect the account.', 'wp-autoplugin' ), [ 'reconnect_required' => true ] );
	}

	/** @param array<string, mixed> $tokens @return true|\WP_Error */
	public function save( array $tokens ) {
		if ( ! $this->available() ) {
			return new \WP_Error( 'chatgpt_oauth_encryption_unavailable', __( 'OpenSSL AES-256-GCM support is required to connect ChatGPT.', 'wp-autoplugin' ), [ 'status' => 500 ] );
		}
		if ( ! $this->valid( $tokens ) ) {
			return new \WP_Error( 'chatgpt_oauth_token_invalid', __( 'OpenAI did not return a usable token pair.', 'wp-autoplugin' ), [ 'status' => 502 ] );
		}
		try {
			$iv = random_bytes( openssl_cipher_iv_length( self::CIPHER ) );
		} catch ( \Throwable $error ) {
			return new \WP_Error( 'chatgpt_oauth_encryption_failed', __( 'Secure random data is unavailable for ChatGPT credential encryption.', 'wp-autoplugin' ), [ 'status' => 500 ] );
		}
		$tag = '';
		$ciphertext = openssl_encrypt( (string) wp_json_encode( $tokens ), self::CIPHER, $this->key(), OPENSSL_RAW_DATA, $iv, $tag, self::CONTEXT, 16 );
		if ( ! is_string( $ciphertext ) || 16 !== strlen( $tag ) ) {
			return new \WP_Error( 'chatgpt_oauth_encryption_failed', __( 'The ChatGPT credentials could not be encrypted.', 'wp-autoplugin' ), [ 'status' => 500 ] );
		}
		$value = wp_json_encode( [ 'version' => 1, 'cipher' => self::CIPHER, 'iv' => base64_encode( $iv ), 'tag' => base64_encode( $tag ), 'ciphertext' => base64_encode( $ciphertext ) ] );
		if ( ! add_option( self::OPTION, $value, '', false ) ) {
			update_option( self::OPTION, $value, false );
		}
		return get_option( self::OPTION, null ) === $value ? true : new \WP_Error( 'chatgpt_oauth_storage_failed', __( 'The ChatGPT credentials could not be saved.', 'wp-autoplugin' ), [ 'status' => 500 ] );
	}

	public function delete(): void {
		delete_option( self::OPTION );
	}

	private function key(): string {
		return hash_hmac( 'sha256', self::CONTEXT, wp_salt( 'auth' ) . "\0" . wp_salt( 'secure_auth' ), true );
	}

	private function valid( $tokens ): bool {
		return is_array( $tokens ) && is_string( $tokens['access_token'] ?? null ) && '' !== $tokens['access_token'] && is_string( $tokens['refresh_token'] ?? null ) && '' !== $tokens['refresh_token'] && is_numeric( $tokens['expires_at'] ?? null );
	}
}
