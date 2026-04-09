<?php
/**
 * License validation via pugmillaeo.com (self-hosted, Stripe-backed).
 *
 * Modes:
 *   free  — no license key entered
 *   ai    — valid active license key (unlocks BYOK AI features)
 *   pro   — future tier (reserved)
 *
 * - Keys are stored encrypted via includes/encryption.php
 * - Validation cached for 6 hours (not 24 — revoked licenses invalidate sooner)
 * - Cache busted when key changes
 * - Domain registered automatically on first validate call (up to 3 sites)
 * - All external calls use explicit sslverify: true
 *
 * @package WPPugmill
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'WPPUGMILL_LICENSE_CACHE_TTL',  6 * HOUR_IN_SECONDS );
define( 'WPPUGMILL_VALIDATE_URL', 'https://pugmillaeo.com/api/validate-license' );

// Hardcoded test key — enter this in the License Key field on any install to
// activate AI mode without a real license key. For testing / development only.
define( 'WPPUGMILL_HARDCODED_TEST_KEY', 'WPPUGMILL-TEST-AI-KEY' );

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
 * Determine the plugin's operating mode.
 *
 * @return string  'free' | 'ai' | 'pro'
 */
function wppugmill_mode() {
	if ( wppugmill_is_licensed() ) {
		return 'ai';
	}
	return 'free';
}

/**
 * Validate the stored license key against the pugmillaeo.com API.
 * Sends the site's domain so it is registered on first call.
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

	if ( empty( $key ) || strlen( $key ) < 10 ) {
		return $empty;
	}

	// Test keys — return synthetic active status without hitting the API.
	if ( ( defined( 'WPPUGMILL_TEST_KEY' ) && WPPUGMILL_TEST_KEY === $key )
		|| WPPUGMILL_HARDCODED_TEST_KEY === $key ) {
		$result = array(
			'status'         => 'active',
			'error'          => '',
			'customer_email' => 'test@wppugmill.local',
			'expires_at'     => '',
		);
		set_transient( 'wppugmill_license_status', $result, WPPUGMILL_LICENSE_CACHE_TTL );
		set_transient( 'wppugmill_license_status_last_good', $result, WEEK_IN_SECONDS );
		return $result;
	}

	$response = wp_remote_post(
		WPPUGMILL_VALIDATE_URL,
		array(
			'timeout'     => 15,
			'sslverify'   => true,
			'headers'     => array( 'Content-Type' => 'application/json' ),
			'body'        => wp_json_encode( array(
				'license_key' => strtoupper( trim( $key ) ),
				'domain'      => home_url(),
			) ),
		)
	);

	if ( is_wp_error( $response ) ) {
		// Network error — preserve last known good status if available.
		$last = get_transient( 'wppugmill_license_status_last_good' );
		return $last ?: array_merge( $empty, array( 'error' => __( 'Could not reach license server.', 'wp-pugmill' ) ) );
	}

	$body = json_decode( wp_remote_retrieve_body( $response ), true );
	$code = wp_remote_retrieve_response_code( $response );

	if ( 200 === $code && ! empty( $body['valid'] ) ) {
		$result = array(
			'status'         => 'active',
			'error'          => '',
			'customer_email' => sanitize_email( $body['email'] ?? '' ),
			'expires_at'     => sanitize_text_field( $body['expiration_date'] ?? '' ),
		);
		// Store last known good for network-failure fallback (1 week).
		set_transient( 'wppugmill_license_status_last_good', $result, WEEK_IN_SECONDS );
	} else {
		$status_code = sanitize_key( $body['status'] ?? 'invalid' );

		$error_messages = array(
			'not_found'    => __( 'License key not found.', 'wp-pugmill' ),
			'cancelled'    => __( 'License has been cancelled.', 'wp-pugmill' ),
			'expired'      => __( 'License has expired. Please renew to continue.', 'wp-pugmill' ),
			'domain_limit' => sprintf(
				/* translators: %d: number of allowed sites */
				__( 'Domain limit reached. This license covers %d sites.', 'wp-pugmill' ),
				intval( $body['sites_allowed'] ?? 3 )
			),
		);

		$result = array_merge( $empty, array(
			'error' => $error_messages[ $status_code ] ?? __( 'Invalid license key.', 'wp-pugmill' ),
		) );
	}

	set_transient( 'wppugmill_license_status', $result, WPPUGMILL_LICENSE_CACHE_TTL );
	return $result;
}

/**
 * When the license key option is updated, bust the cache so the next
 * page load re-validates against the API with the new key + domain.
 *
 * Domain registration now happens passively inside wppugmill_validate_license_remote()
 * (the API registers the domain on first successful validate call), so no
 * separate activate/deactivate HTTP calls are needed.
 */
function wppugmill_on_license_key_update( $old_encrypted, $new_encrypted ) {
	delete_transient( 'wppugmill_license_status' );
	delete_transient( 'wppugmill_license_status_last_good' );

	// Trigger immediate re-validation so the settings page reflects the new status.
	$new_key = wppugmill_decrypt( $new_encrypted );
	if ( ! empty( $new_key ) ) {
		wppugmill_validate_license_remote();
	}
}
add_action( 'update_option_wppugmill_license_key', 'wppugmill_on_license_key_update', 10, 2 );
add_action( 'add_option_wppugmill_license_key', function( $option, $encrypted ) {
	delete_transient( 'wppugmill_license_status' );
	$key = wppugmill_decrypt( $encrypted );
	if ( ! empty( $key ) ) {
		wppugmill_validate_license_remote();
	}
}, 10, 2 );
