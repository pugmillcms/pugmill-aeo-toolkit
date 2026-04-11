<?php
/**
 * Plugin Name: AEO Pugmill
 * Plugin URI:  https://pugmillaeo.com
 * Description: The AEO plugin for WordPress. Structures your content for AI answer engines — FAQPage schema, entity graph, citations, bot analytics, and llms.txt. Works alongside Yoast, RankMath, and AIOSEO.
 * Version:     1.3.1
 * Author:      Janzen Works
 * Author URI:  https://janzenworks.com
 * License:     GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: aeo-pugmill
 * Domain Path: /languages
 *
 * @package WPPugmill
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Captured as early as possible so aeopugmill_intel_shutdown() can measure
// the full PHP generation time for bot requests.
define( 'AEOPUGMILL_REQUEST_START', microtime( true ) );

define( 'AEOPUGMILL_VERSION',         '1.3.1' );
define( 'AEOPUGMILL_PLUGIN_DIR',      plugin_dir_path( __FILE__ ) );
define( 'AEOPUGMILL_PLUGIN_URL',      plugin_dir_url( __FILE__ ) );
define( 'AEOPUGMILL_PLUGIN_FILE',     __FILE__ );
define( 'AEOPUGMILL_ANTHROPIC_MODEL',  'claude-sonnet-4-6' );
define( 'AEOPUGMILL_MAX_AI_INPUT',     8000 ); // character cap — approximately 2K tokens for typical English prose
// Protocol version string for Pugmill AEO Intelligence Network HMAC signing.
// This is intentionally a public, hard-coded protocol identifier — not a private
// secret. Its purpose is to version-gate the HMAC scheme so both sides agree on
// the algorithm, not to keep a value hidden. The actual per-site secret is the
// network_token returned at registration and stored encrypted in the database.
// This value must match PUGMILL_NETWORK_SECRET on the pugmillaeo.com server.
define( 'AEOPUGMILL_NETWORK_SECRET',   'pugmill-network-v1' );

/**
 * Detect which mode the plugin is running in.
 *
 * - 'free' : no license key, or key present but invalid
 * - 'ai'   : valid license — unlocks BYOK AI generation
 * - 'pro'  : future tier — reserved for token infrastructure
 *
 * @return string 'free' | 'ai' | 'pro'
 */
function aeopugmill_mode() {
	// Developer mode: define( 'AEOPUGMILL_DEV_MODE', true ) in wp-config.php
	// to bypass license validation and force AI mode on local/staging installs.
	// Inert unless explicitly defined. Never enable on a production site.
	if ( defined( 'AEOPUGMILL_DEV_MODE' ) && AEOPUGMILL_DEV_MODE ) {
		return 'ai';
	}

	$license_key = aeopugmill_get_encrypted_option( 'aeopugmill_license_key', '' );

	if ( ! empty( $license_key ) ) {
		return aeopugmill_is_licensed() ? 'ai' : 'free';
	}

	return 'free';
}

// Load core includes
require_once AEOPUGMILL_PLUGIN_DIR . 'includes/encryption.php';
require_once AEOPUGMILL_PLUGIN_DIR . 'includes/rate-limit.php';
require_once AEOPUGMILL_PLUGIN_DIR . 'includes/license.php';
require_once AEOPUGMILL_PLUGIN_DIR . 'includes/compat.php';
require_once AEOPUGMILL_PLUGIN_DIR . 'includes/aeo-meta.php';
require_once AEOPUGMILL_PLUGIN_DIR . 'includes/on-page-seo.php';
require_once AEOPUGMILL_PLUGIN_DIR . 'includes/json-ld.php';
require_once AEOPUGMILL_PLUGIN_DIR . 'includes/llms-txt.php';
require_once AEOPUGMILL_PLUGIN_DIR . 'includes/sitemap.php';
require_once AEOPUGMILL_PLUGIN_DIR . 'includes/settings.php';
require_once AEOPUGMILL_PLUGIN_DIR . 'includes/rest-api.php';
require_once AEOPUGMILL_PLUGIN_DIR . 'includes/ai.php';
require_once AEOPUGMILL_PLUGIN_DIR . 'includes/health.php';
require_once AEOPUGMILL_PLUGIN_DIR . 'includes/bot-analytics.php';
require_once AEOPUGMILL_PLUGIN_DIR . 'includes/bot-intelligence.php';
require_once AEOPUGMILL_PLUGIN_DIR . 'includes/bulk-aeo.php';

// Load admin UI
if ( is_admin() ) {
	require_once AEOPUGMILL_PLUGIN_DIR . 'admin/editor-assets.php';
	require_once AEOPUGMILL_PLUGIN_DIR . 'admin/meta-box.php';
	require_once AEOPUGMILL_PLUGIN_DIR . 'admin/post-columns.php';
	require_once AEOPUGMILL_PLUGIN_DIR . 'admin/settings-page.php';
	require_once AEOPUGMILL_PLUGIN_DIR . 'admin/bot-analytics-page.php';
}

/**
 * Plugin activation
 */
function aeopugmill_activate() {
	// Register rewrite rules before flushing so all endpoints work immediately
	aeopugmill_llms_rewrite_rules();
	aeopugmill_sitemap_rewrite();
	aeopugmill_indexnow_rewrite();
	// Create bot analytics DB tables
	aeopugmill_bot_analytics_install();
	aeopugmill_bot_analytics_prune();
	// Create signal capture table
	aeopugmill_signal_install();
	// Schedule daily prune if not already scheduled
	if ( ! wp_next_scheduled( 'aeopugmill_daily_prune' ) ) {
		wp_schedule_event( time(), 'daily', 'aeopugmill_daily_prune' );
	}
	// Schedule daily intelligence send (offset by 1 hour to run after midnight)
	if ( ! wp_next_scheduled( 'aeopugmill_intelligence_send' ) ) {
		wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'aeopugmill_intelligence_send' );
	}
	flush_rewrite_rules();
}
register_activation_hook( __FILE__, 'aeopugmill_activate' );

/**
 * Plugin deactivation
 */
function aeopugmill_deactivate() {
	wp_clear_scheduled_hook( 'aeopugmill_daily_prune' );
	wp_clear_scheduled_hook( 'aeopugmill_intelligence_send' );
	flush_rewrite_rules();
}
register_deactivation_hook( __FILE__, 'aeopugmill_deactivate' );

add_action( 'aeopugmill_daily_prune', 'aeopugmill_bot_analytics_prune' );
