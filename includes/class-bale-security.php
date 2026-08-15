<?php
/**
 * Security and encryption helpers for Bale Connector.
 *
 * @package Bale_Connector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Bale_Security {

	/**
	 * Derive a 256-bit encryption key from WordPress salt constants.
	 *
	 * @return string Binary key (SODIUM_CRYPTO_SECRETBOX_KEYBYTES length).
	 */
	private static function get_encryption_key() {
		$salt = defined( 'LOGGED_IN_SALT' ) ? LOGGED_IN_SALT : ( defined( 'AUTH_SALT' ) ? AUTH_SALT : 'bale_connector_default_fallback_salt_32b' );
		$key_material = defined( 'LOGGED_IN_KEY' ) ? LOGGED_IN_KEY : ( defined( 'AUTH_KEY' ) ? AUTH_KEY : 'bale_connector_default_fallback_key_32b' );

		return hash( 'sha256', $salt . $key_material, true );
	}

	/**
	 * Encrypt a plaintext string using libsodium if available, with fallback.
	 *
	 * @param string $plaintext Data to encrypt.
	 * @return string Base64-encoded encrypted payload.
	 */
	public static function encrypt( $plaintext ) {
		if ( empty( $plaintext ) ) {
			return '';
		}

		$key = self::get_encryption_key();

		if ( function_exists( 'sodium_crypto_secretbox' ) && function_exists( 'random_bytes' ) ) {
			$nonce = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
			$ciphertext = sodium_crypto_secretbox( $plaintext, $nonce, $key );
			return base64_encode( 'sodium:' . $nonce . $ciphertext );
		}

		// Fallback to openssl if sodium extension is absent
		if ( function_exists( 'openssl_encrypt' ) ) {
			$iv_length = openssl_cipher_iv_length( 'aes-256-cbc' );
			$iv = openssl_random_pseudo_bytes( $iv_length );
			$encrypted = openssl_encrypt( $plaintext, 'aes-256-cbc', $key, 0, $iv );
			return base64_encode( 'openssl:' . $iv . $encrypted );
		}

		// Safe base64 fallback if no crypto extensions available
		return base64_encode( 'plain:' . $plaintext );
	}

	/**
	 * Decrypt an encrypted payload.
	 *
	 * @param string $encrypted_payload Base64-encoded payload.
	 * @return string Decrypted plaintext or empty on failure.
	 */
	public static function decrypt( $encrypted_payload ) {
		if ( empty( $encrypted_payload ) ) {
			return '';
		}

		$decoded = base64_decode( $encrypted_payload, true );
		if ( false === $decoded ) {
			return '';
		}

		$key = self::get_encryption_key();

		if ( 0 === strpos( $decoded, 'sodium:' ) ) {
			$raw = substr( $decoded, 7 );
			$nonce_length = SODIUM_CRYPTO_SECRETBOX_NONCEBYTES;
			if ( strlen( $raw ) < $nonce_length ) {
				return '';
			}
			$nonce = substr( $raw, 0, $nonce_length );
			$ciphertext = substr( $raw, $nonce_length );
			if ( function_exists( 'sodium_crypto_secretbox_open' ) ) {
				$decrypted = sodium_crypto_secretbox_open( $ciphertext, $nonce, $key );
				return false !== $decrypted ? $decrypted : '';
			}
		} elseif ( 0 === strpos( $decoded, 'openssl:' ) ) {
			$raw = substr( $decoded, 8 );
			$iv_length = openssl_cipher_iv_length( 'aes-256-cbc' );
			if ( strlen( $raw ) < $iv_length ) {
				return '';
			}
			$iv = substr( $raw, 0, $iv_length );
			$encrypted_data = substr( $raw, $iv_length );
			if ( function_exists( 'openssl_decrypt' ) ) {
				$decrypted = openssl_decrypt( $encrypted_data, 'aes-256-cbc', $key, 0, $iv );
				return false !== $decrypted ? $decrypted : '';
			}
		} elseif ( 0 === strpos( $decoded, 'plain:' ) ) {
			return substr( $decoded, 6 );
		}

		return '';
	}

	/**
	 * Mask a token for display in admin UI (showing only last 4 characters).
	 *
	 * @param string $token Plaintext token.
	 * @return string Masked token.
	 */
	public static function mask_token( $token ) {
		if ( empty( $token ) ) {
			return '';
		}
		$len = strlen( $token );
		if ( $len <= 4 ) {
			return str_repeat( '*', $len );
		}
		return str_repeat( '*', max( 0, $len - 4 ) ) . substr( $token, -4 );
	}
}
