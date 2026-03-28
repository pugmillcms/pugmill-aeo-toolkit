<?php
/**
 * AI Bot Analytics — helper functions and redirect.
 *
 * The analytics dashboard is now rendered as the "Bot Analytics" tab on the
 * main WP Pugmill settings page (?page=wp-pugmill&tab=analytics).
 *
 * This file:
 *  - Keeps the wppugmill_bot_config() helper used by settings-page.php.
 *  - Redirects any direct visits to the old wp-pugmill-bots page to the new tab.
 *
 * @package WPPugmill
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Bot display config: label + color.
 *
 * @return array<string, array{label: string, color: string}>
 */
function wppugmill_bot_config() {
	return array(
		'ChatGPT'    => array( 'label' => 'ChatGPT',    'color' => '#10a37f' ),
		'Claude'     => array( 'label' => 'Claude',     'color' => '#d97706' ),
		'Perplexity' => array( 'label' => 'Perplexity', 'color' => '#6366f1' ),
		'Gemini'     => array( 'label' => 'Gemini',     'color' => '#4285f4' ),
		'Amazonbot'  => array( 'label' => 'Amazonbot',  'color' => '#ff9900' ),
		'Meta'       => array( 'label' => 'Meta',       'color' => '#0866ff' ),
	);
}

/**
 * Redirect legacy wp-pugmill-bots page to the new analytics tab.
 */
function wppugmill_redirect_legacy_bots_page() {
	if ( ! is_admin() ) {
		return;
	}
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended
	if ( isset( $_GET['page'] ) && 'wp-pugmill-bots' === sanitize_key( $_GET['page'] ) ) {
		wp_safe_redirect( admin_url( 'options-general.php?page=wp-pugmill&tab=analytics' ) );
		exit;
	}
}
add_action( 'admin_init', 'wppugmill_redirect_legacy_bots_page' );
