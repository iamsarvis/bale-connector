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

	const KEY_OPTION_NAME = 'bale_connector_encryption_key';

	/**
	 * Ensure a dedicated 32-byte encryption key exists in wp_options.
	 *
	 * @return string|false Binary 32-byte encryption key or false on failure.
	 */
	public static function ensure_encryption_key() {
		$stored_key = get_option( self::KEY_OPTION_NAME, '' );
		if ( ! empty( $stored_key ) ) {
			$binary_key = base64_decode( $stored_key, true );
			if ( false !== $binary_key && strlen( $binary_key ) === 32 ) {
				return $binary_key;
			}
		}

		// Generate a new 32-byte cryptographically secure key
		try {
			$raw_key = random_bytes( 32 );
		} catch ( Exception $e ) {
			if ( function_exists( 'openssl_random_pseudo_bytes' ) ) {
				$raw_key = openssl_random_pseudo_bytes( 32 );
			} else {
				return false;
			}
		}

		if ( ! empty( $raw_key ) && strlen( $raw_key ) === 32 ) {
			update_option( self::KEY_OPTION_NAME, base64_encode( $raw_key ) );
			return $raw_key;
		}

		return false;
	}

	/**
	 * Get the dedicated encryption key.
	 *
	 * @return string|false Binary key or false if unavailable.
	 */
	private static function get_encryption_key() {
		return self::ensure_encryption_key();
	}

	/**
	 * Check if any supported cryptographic engine is available.
	 *
	 * @return bool True if libsodium or openssl is available with encryption key.
	 */
	public static function has_crypto_support() {
		$has_engine = ( function_exists( 'sodium_crypto_secretbox' ) && function_exists( 'random_bytes' ) ) || function_exists( 'openssl_encrypt' );
		$has_key    = false !== self::get_encryption_key();

		return $has_engine && $has_key;
	}

	/**
	 * Encrypt a plaintext string using libsodium (or OpenSSL fallback).
	 * Refuses to store if no secure crypto engine is available.
	 *
	 * @param string $plaintext Data to encrypt.
	 * @return string Base64-encoded encrypted payload, or empty string on failure/empty.
	 */
	public static function encrypt( $plaintext ) {
		if ( '' === $plaintext || null === $plaintext ) {
			return '';
		}

		$key = self::get_encryption_key();
		if ( false === $key ) {
			return '';
		}

		if ( function_exists( 'sodium_crypto_secretbox' ) && function_exists( 'random_bytes' ) ) {
			try {
				$nonce = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
				$ciphertext = sodium_crypto_secretbox( $plaintext, $nonce, $key );
				return base64_encode( 'sodium:' . $nonce . $ciphertext );
			} catch ( Exception $e ) {
				// Fall through to openssl
			}
		}

		// Fallback to OpenSSL AES-256-CBC if libsodium is not available
		if ( function_exists( 'openssl_encrypt' ) && function_exists( 'openssl_cipher_iv_length' ) ) {
			$iv_length = openssl_cipher_iv_length( 'aes-256-cbc' );
			$iv = function_exists( 'random_bytes' ) ? random_bytes( $iv_length ) : openssl_random_pseudo_bytes( $iv_length );
			$encrypted = openssl_encrypt( $plaintext, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv );
			if ( false !== $encrypted ) {
				return base64_encode( 'openssl:' . $iv . $encrypted );
			}
		}

		// Never store as plaintext or reversible base64
		return '';
	}

	/**
	 * Decrypt an encrypted payload.
	 *
	 * Distinguishes between:
	 * - null: payload was empty (no token stored)
	 * - false: decryption failed (corrupted ciphertext, key mismatch, missing crypto)
	 * - string: successfully decrypted token
	 *
	 * @param string $encrypted_payload Base64-encoded payload.
	 * @return string|false|null Decrypted plaintext, null if no payload, false on decryption failure.
	 */
	public static function decrypt( $encrypted_payload ) {
		if ( '' === $encrypted_payload || null === $encrypted_payload ) {
			return null;
		}

		$decoded = base64_decode( $encrypted_payload, true );
		if ( false === $decoded ) {
			return false;
		}

		$key = self::get_encryption_key();
		if ( false === $key ) {
			return false;
		}

		if ( 0 === strpos( $decoded, 'sodium:' ) ) {
			$raw = substr( $decoded, 7 );
			$nonce_length = defined( 'SODIUM_CRYPTO_SECRETBOX_NONCEBYTES' ) ? SODIUM_CRYPTO_SECRETBOX_NONCEBYTES : 24;
			if ( strlen( $raw ) <= $nonce_length ) {
				return false;
			}
			$nonce = substr( $raw, 0, $nonce_length );
			$ciphertext = substr( $raw, $nonce_length );
			if ( function_exists( 'sodium_crypto_secretbox_open' ) ) {
				$decrypted = sodium_crypto_secretbox_open( $ciphertext, $nonce, $key );
				return false !== $decrypted ? $decrypted : false;
			}
		} elseif ( 0 === strpos( $decoded, 'openssl:' ) ) {
			$raw = substr( $decoded, 8 );
			if ( ! function_exists( 'openssl_cipher_iv_length' ) || ! function_exists( 'openssl_decrypt' ) ) {
				return false;
			}
			$iv_length = openssl_cipher_iv_length( 'aes-256-cbc' );
			if ( strlen( $raw ) <= $iv_length ) {
				return false;
			}
			$iv = substr( $raw, 0, $iv_length );
			$encrypted_data = substr( $raw, $iv_length );
			$decrypted = openssl_decrypt( $encrypted_data, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv );
			return false !== $decrypted ? $decrypted : false;
		}

		return false;
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
