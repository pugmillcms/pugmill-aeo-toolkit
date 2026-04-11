<?php
/**
 * Encryption helpers for sensitive options (API keys, license keys).
 *
 * Uses AES-256-CBC via OpenSSL with a site-specific key derived from
 * WordPress's SECURE_AUTH_KEY. If OpenSSL is unavailable, falls back
 * to plain storage with a warning logged.
 *
 * Threat model: protects against raw database access. Anyone with
 * wp-config.php already controls the site — that's accepted.
 *
 * @package WPPugmill
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Derive a 32-byte encryption key from WordPress's SECURE_AUTH_KEY.
 *
 * @return string
 */
function aeopugmill_encryption_key() {
	$wp_key = defined( 'SECURE_AUTH_KEY' ) ? SECURE_AUTH_KEY : wp_salt( 'secure_auth' );
	return hash( 'sha256', $wp_key . 'aeopugmill_v1', true );
}

/**
 * Encrypt a plaintext string.
 *
 * @param  string $plaintext
 * @return string  Base64-encoded ciphertext (IV prepended), or plaintext if OpenSSL unavailable.
 */
function aeopugmill_encrypt( $plaintext ) {
	if ( empty( $plaintext ) ) {
		return '';
	}

	if ( ! function_exists( 'openssl_encrypt' ) ) {
		error_log( 'AEO Pugmill: OpenSSL is not available on this server. API and license keys cannot be saved securely and will not be stored.' );
		add_action( 'admin_notices', 'aeopugmill_notice_openssl_unavailable' );
		return '';
	}

	$key    = aeopugmill_encryption_key();
	$iv     = random_bytes( 16 );
	$cipher = openssl_encrypt( $plaintext, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv );

	if ( false === $cipher ) {
		error_log( 'AEO Pugmill: Encryption failed.' );
		return $plaintext;
	}

	return base64_encode( $iv . $cipher );
}

/**
 * Decrypt a value encrypted by aeopugmill_encrypt().
 *
 * @param  string $ciphertext  Base64-encoded ciphertext with prepended IV.
 * @return string  Plaintext, or empty string on failure.
 */
function aeopugmill_decrypt( $ciphertext ) {
	if ( empty( $ciphertext ) ) {
		return '';
	}

	if ( ! function_exists( 'openssl_decrypt' ) ) {
		// OpenSSL not available — value was stored as plaintext
		return $ciphertext;
	}

	$raw = base64_decode( $ciphertext, true );
	if ( false === $raw || strlen( $raw ) < 17 ) {
		// Not base64 — likely a legacy plaintext value; return as-is
		return $ciphertext;
	}

	$key    = aeopugmill_encryption_key();
	$iv     = substr( $raw, 0, 16 );
	$cipher = substr( $raw, 16 );

	$plaintext = openssl_decrypt( $cipher, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv );

	return false !== $plaintext ? $plaintext : '';
}

/**
 * Save an encrypted option.
 *
 * @param string $option_name
 * @param string $plaintext
 */
function aeopugmill_save_encrypted_option( $option_name, $plaintext ) {
	update_option( $option_name, aeopugmill_encrypt( $plaintext ), false );
}

/**
 * Retrieve and decrypt an encrypted option.
 *
 * @param  string $option_name
 * @param  string $default
 * @return string Plaintext value.
 */
function aeopugmill_get_encrypted_option( $option_name, $default = '' ) {
	$value = get_option( $option_name, '' );
	if ( empty( $value ) ) {
		return $default;
	}
	return aeopugmill_decrypt( $value );
}

/**
 * Return a masked display version of a sensitive value.
 * Shows first 4 chars, masks the rest, shows last 4 chars.
 *
 * e.g. "sk-ant-abc123xyz" → "sk-a••••••••xyz"
 *
 * @param  string $value
 * @return string
 */
/**
 * Admin notice shown when OpenSSL is unavailable.
 * Displayed via add_action() inside aeopugmill_encrypt() if encryption fails.
 */
function aeopugmill_notice_openssl_unavailable() {
	echo '<div class="notice notice-error"><p><strong>' .
		esc_html__( 'AEO Pugmill requires OpenSSL to store API and license keys securely.', 'aeo-pugmill' ) .
		'</strong> ' .
		esc_html__( 'OpenSSL is not available on this server. Keys cannot be saved until OpenSSL is enabled. Please contact your host.', 'aeo-pugmill' ) .
		'</p></div>';
}

/**
 * Return a masked display version of a sensitive value.
 * Shows first 4 chars, masks the rest, shows last 4 chars.
 *
 * e.g. "sk-ant-abc123xyz" → "sk-a••••••••xyz"
 *
 * @param  string $value
 * @return string
 */
function aeopugmill_mask_secret( $value ) {
	if ( empty( $value ) ) {
		return '';
	}
	$len = strlen( $value );
	if ( $len <= 8 ) {
		return str_repeat( '•', $len );
	}
	return substr( $value, 0, 4 ) . str_repeat( '•', max( 4, $len - 8 ) ) . substr( $value, -4 );
}
