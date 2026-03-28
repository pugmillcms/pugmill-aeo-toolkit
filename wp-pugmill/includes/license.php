<?php
/**
 * License validation via Lemon Squeezy.
 *
 * Modes:
 *   free  — no license key entered
 *   ai    — valid Lemon Squeezy license key (unlocks BYOK AI features)
 *   pro   — future tier (reserved)
 *
 * - Keys are stored encrypted via includes/encryption.php
 * - Validation cached for 6 hours (not 24 — revoked licenses invalidate sooner)
 * - Cache busted when key changes
 * - Each site tracked as a unique instance (prevents key sharing)
 * - All external calls use explicit sslverify: true
 *
 * @package WPPugmill
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'WPPUGMILL_LICENSE_CACHE_TTL',   6 * HOUR_IN_SECONDS );
define( 'WPPUGMILL_LS_VALIDATE_URL',  'https://api.lemonsqueezy.com/v1/licenses/validate' );
define( 'WPPUGMILL_LS_ACTIVATE_URL',  'https://api.lemonsqueezy.com/v1/licenses/activate' );
define( 'WPPUGMILL_LS_DEACTIVATE_URL','https://api.lemonsqueezy.com/v1/licenses/deactivate' );

/**
 * Common request args for all Lemon Squeezy calls.
 */
function wppugmill_ls_request_args( array $body ) {
	return array(
		'timeout'   => 15,
		'sslverify' => true,
		'headers'   => array( 'Accept' => 'application/json' ),
		'body'      => $body,
	);
}

/**
 * Get a stable instance ID for this WordPress installation.
 */
function wppugmill_instance_id() {
	$stored = get_option( 'wppugmill_instance_id', '' );
	if ( $stored ) {
		return $stored;
	}
	$id = wp_generate_uuid4();
	update_option( 'wppugmill_instance_id', $id, false );
	return $id;
}

/**
 * Check if this site has a valid license.
 *
 * @return bool
 */
function wppugmill_is_licensed() {
	return 'active' === ( wppugmill_license_status()['status'] ?? '' );
}

/**
 * Get full license status, using cache where available.
 *
 * @return array{status: string, error: string, customer_email: string, expires_at: string}
 */
function wppugmill_license_status() {
	$cache = get_transient( 'wppugmill_license_status' );
	if ( false !== $cache ) {
		return $cache;
	}
	return wppugmill_validate_license_remote();
}

/**
 * Validate the stored license key against Lemon Squeezy.
 *
 * @return array
 */
function wppugmill_validate_license_remote() {
	$key = wppugmill_get_encrypted_option( 'wppugmill_license_key', '' );

	$empty = array(
		'status'         => 'inactive',
		'error'          => '',
		'customer_email' => '',
		'expires_at'     => '',
	);

	if ( empty( $key ) || strlen( $key ) < 20 ) {
		return $empty;
	}

	$response = wp_remote_post(
		WPPUGMILL_LS_VALIDATE_URL,
		wppugmill_ls_request_args( array(
			'license_key' => $key,
			'instance_id' => wppugmill_instance_id(),
		) )
	);

	if ( is_wp_error( $response ) ) {
		// Network error — preserve last known good status if available
		$last = get_transient( 'wppugmill_license_status_last_good' );
		return $last ?: array_merge( $empty, array( 'error' => __( 'Could not reach license server.', 'wp-pugmill' ) ) );
	}

	$body = json_decode( wp_remote_retrieve_body( $response ), true );
	$code = wp_remote_retrieve_response_code( $response );

	if ( 200 === $code && ! empty( $body['valid'] ) ) {
		$result = array(
			'status'         => 'active',
			'error'          => '',
			'customer_email' => sanitize_email( $body['license_key']['customer_email'] ?? '' ),
			'expires_at'     => sanitize_text_field( $body['license_key']['expires_at'] ?? '' ),
		);
		// Store last known good for network-failure fallback
		set_transient( 'wppugmill_license_status_last_good', $result, WEEK_IN_SECONDS );
	} else {
		$result = array_merge( $empty, array(
			'error' => sanitize_text_field( $body['error'] ?? 'Invalid license key.' ),
		) );
	}

	set_transient( 'wppugmill_license_status', $result, WPPUGMILL_LICENSE_CACHE_TTL );
	return $result;
}

/**
 * Activate the license key with Lemon Squeezy.
 *
 * @param  string $key  Plaintext license key.
 * @return array{success: bool, error?: string}
 */
function wppugmill_activate_license( $key ) {
	if ( empty( $key ) || strlen( $key ) < 20 ) {
		return array( 'success' => false, 'error' => __( 'Invalid license key format.', 'wp-pugmill' ) );
	}

	$response = wp_remote_post(
		WPPUGMILL_LS_ACTIVATE_URL,
		wppugmill_ls_request_args( array(
			'license_key'   => $key,
			'instance_id'   => wppugmill_instance_id(),
			'instance_name' => parse_url( home_url(), PHP_URL_HOST ),
		) )
	);

	if ( is_wp_error( $response ) ) {
		return array( 'success' => false, 'error' => __( 'Could not reach license server.', 'wp-pugmill' ) );
	}

	$body = json_decode( wp_remote_retrieve_body( $response ), true );
	$code = wp_remote_retrieve_response_code( $response );

	if ( 200 === $code && ! empty( $body['activated'] ) ) {
		$result = array(
			'status'         => 'active',
			'error'          => '',
			'customer_email' => sanitize_email( $body['license_key']['customer_email'] ?? '' ),
			'expires_at'     => sanitize_text_field( $body['license_key']['expires_at'] ?? '' ),
		);
		set_transient( 'wppugmill_license_status', $result, WPPUGMILL_LICENSE_CACHE_TTL );
		set_transient( 'wppugmill_license_status_last_good', $result, WEEK_IN_SECONDS );
		return array( 'success' => true );
	}

	return array( 'success' => false, 'error' => sanitize_text_field( $body['error'] ?? __( 'Activation failed.', 'wp-pugmill' ) ) );
}

/**
 * Deactivate the license key (call when key is removed or changed).
 *
 * @param string $key  Plaintext license key.
 */
function wppugmill_deactivate_license( $key ) {
	if ( empty( $key ) ) {
		return;
	}
	wp_remote_post(
		WPPUGMILL_LS_DEACTIVATE_URL,
		wppugmill_ls_request_args( array(
			'license_key' => $key,
			'instance_id' => wppugmill_instance_id(),
		) )
	);
	delete_transient( 'wppugmill_license_status' );
	delete_transient( 'wppugmill_license_status_last_good' );
}

/**
 * When the license key option is updated, activate/deactivate accordingly.
 * Note: values are already encrypted by the sanitize_callback in settings.php.
 * We decrypt both old and new before calling the LS API.
 */
function wppugmill_on_license_key_update( $old_encrypted, $new_encrypted ) {
	delete_transient( 'wppugmill_license_status' );

	$old_key = wppugmill_decrypt( $old_encrypted );
	$new_key = wppugmill_decrypt( $new_encrypted );

	if ( ! empty( $old_key ) && $old_key !== $new_key ) {
		wppugmill_deactivate_license( $old_key );
	}

	if ( ! empty( $new_key ) ) {
		wppugmill_activate_license( $new_key );
	}
}
add_action( 'update_option_wppugmill_license_key', 'wppugmill_on_license_key_update', 10, 2 );
add_action( 'add_option_wppugmill_license_key', function( $option, $encrypted ) {
	$key = wppugmill_decrypt( $encrypted );
	if ( ! empty( $key ) ) {
		wppugmill_activate_license( $key );
	}
}, 10, 2 );
