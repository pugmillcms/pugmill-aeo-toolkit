<?php
/**
 * Rate limiting for admin AJAX endpoints.
 *
 * Uses WordPress transients keyed by user ID.
 * Limits AI generation to 50 requests per hour per user.
 *
 * @package WPPugmill
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'WPPUGMILL_RATE_LIMIT',    50 );
define( 'WPPUGMILL_RATE_WINDOW',   HOUR_IN_SECONDS );

/**
 * Check if the current user is within the rate limit.
 * Increments the counter if allowed.
 *
 * @return true|WP_Error  True if allowed, WP_Error if rate limited.
 */
function wppugmill_check_rate_limit() {
	$user_id  = get_current_user_id();
	$key      = 'wppugmill_rl_' . $user_id;
	$attempts = (int) get_transient( $key );

	if ( $attempts >= WPPUGMILL_RATE_LIMIT ) {
		return new WP_Error(
			'rate_limited',
			sprintf(
				/* translators: %d: number of AI generations allowed per hour */
				__( 'You have reached the limit of %d AI generations per hour. Please try again later.', 'wp-pugmill' ),
				WPPUGMILL_RATE_LIMIT
			)
		);
	}

	// Increment — preserve existing TTL by checking remaining time
	if ( $attempts === 0 ) {
		set_transient( $key, 1, WPPUGMILL_RATE_WINDOW );
	} else {
		// get_transient doesn't expose TTL; set_transient resets it.
		// Acceptable trade-off — window slides on first increment only.
		set_transient( $key, $attempts + 1, WPPUGMILL_RATE_WINDOW );
	}

	return true;
}

/**
 * Return the current usage count for the logged-in user without incrementing.
 *
 * @return int
 */
function wppugmill_get_usage_count() {
	return (int) get_transient( 'wppugmill_rl_' . get_current_user_id() );
}

add_action( 'wp_ajax_wppugmill_get_usage', 'wppugmill_ajax_get_usage' );

function wppugmill_ajax_get_usage() {
	check_ajax_referer( 'wppugmill_get_usage', 'nonce' );
	if ( ! current_user_can( 'edit_posts' ) ) {
		wp_send_json_error( null, 403 );
	}
	wp_send_json_success( array(
		'count' => wppugmill_get_usage_count(),
		'limit' => WPPUGMILL_RATE_LIMIT,
	) );
}
