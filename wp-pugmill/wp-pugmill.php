<?php
/**
 * Plugin Name: WP Pugmill
 * Plugin URI:  https://wppugmill.com
 * Description: A pugmill turns slop into usable clay. This one turns your existing SEO into structured, AI-ready content — llms.txt, AEO metadata, schema, and sitemaps for ChatGPT, Perplexity, and Gemini.
 * Version:     1.0.7
 * Author:      Janzen Works
 * Author URI:  https://janzenworks.com
 * License:     GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: wp-pugmill
 * Domain Path: /languages
 *
 * @package WPPugmill
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'WPPUGMILL_VERSION',         '1.0.7' );
define( 'WPPUGMILL_PLUGIN_DIR',      plugin_dir_path( __FILE__ ) );
define( 'WPPUGMILL_PLUGIN_URL',      plugin_dir_url( __FILE__ ) );
define( 'WPPUGMILL_PLUGIN_FILE',     __FILE__ );
define( 'WPPUGMILL_ANTHROPIC_MODEL',  'claude-sonnet-4-6' );
define( 'WPPUGMILL_MAX_AI_INPUT',     8000 ); // character cap — approximately 2K tokens for typical English prose
// Shared secret for Pugmill Intelligence Network HMAC signing.
// This value must match PUGMILL_NETWORK_SECRET on the pugmill.dev server.
define( 'WPPUGMILL_NETWORK_SECRET',   'pugmill-network-v1' );

/**
 * Detect which mode the plugin is running in.
 *
 * - 'free' : no license key, or key present but invalid
 * - 'ai'   : valid Lemon Squeezy license — unlocks BYOK AI generation
 * - 'pro'  : future tier — reserved for token infrastructure
 *
 * A valid license key is always required to access AI features.
 */
function wppugmill_mode() {
	// Dev bypass — define WPPUGMILL_DEV_MODE true in wp-config.php (local only)
	// to force AI mode without a license key. Never set this on a production site.
	if ( defined( 'WPPUGMILL_DEV_MODE' ) && WPPUGMILL_DEV_MODE ) {
		return 'ai';
	}

	$license_key = wppugmill_get_encrypted_option( 'wppugmill_license_key', '' );

	if ( ! empty( $license_key ) ) {
		return wppugmill_is_licensed() ? 'ai' : 'free';
	}

	return 'free';
}

// Load core includes
require_once WPPUGMILL_PLUGIN_DIR . 'includes/encryption.php';
require_once WPPUGMILL_PLUGIN_DIR . 'includes/rate-limit.php';
require_once WPPUGMILL_PLUGIN_DIR . 'includes/license.php';
require_once WPPUGMILL_PLUGIN_DIR . 'includes/aeo-meta.php';
require_once WPPUGMILL_PLUGIN_DIR . 'includes/on-page-seo.php';
require_once WPPUGMILL_PLUGIN_DIR . 'includes/json-ld.php';
require_once WPPUGMILL_PLUGIN_DIR . 'includes/llms-txt.php';
require_once WPPUGMILL_PLUGIN_DIR . 'includes/sitemap.php';
require_once WPPUGMILL_PLUGIN_DIR . 'includes/settings.php';
require_once WPPUGMILL_PLUGIN_DIR . 'includes/rest-api.php';
require_once WPPUGMILL_PLUGIN_DIR . 'includes/ai.php';
require_once WPPUGMILL_PLUGIN_DIR . 'includes/health.php';
require_once WPPUGMILL_PLUGIN_DIR . 'includes/bot-analytics.php';

// Auto-updates via GitHub Releases (Plugin Update Checker)
if ( is_admin() ) {
	$puc_file = WPPUGMILL_PLUGIN_DIR . 'lib/plugin-update-checker/plugin-update-checker.php';
	if ( file_exists( $puc_file ) ) {
		require_once $puc_file;
		$puc = YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
			'https://github.com/michaelsjanzen/wppugmill/',
			__FILE__,
			'wp-pugmill'
		);
		// Use the attached release asset zip (correct folder structure) rather
		// than GitHub's auto-generated zipball (which uses a commit-hash folder name).
		$puc->getVcsApi()->enableReleaseAssets();
	}
}

// Load admin UI
if ( is_admin() ) {
	require_once WPPUGMILL_PLUGIN_DIR . 'admin/editor-assets.php';
	require_once WPPUGMILL_PLUGIN_DIR . 'admin/meta-box.php';
	require_once WPPUGMILL_PLUGIN_DIR . 'admin/post-columns.php';
	require_once WPPUGMILL_PLUGIN_DIR . 'admin/settings-page.php';
	require_once WPPUGMILL_PLUGIN_DIR . 'admin/migration.php';
	require_once WPPUGMILL_PLUGIN_DIR . 'admin/bot-analytics-page.php';
}

/**
 * Plugin activation
 */
function wppugmill_activate() {
	// Register rewrite rules before flushing so all endpoints work immediately
	wppugmill_llms_rewrite_rules();
	wppugmill_sitemap_rewrite();
	wppugmill_indexnow_rewrite();
	// Create bot analytics DB tables
	wppugmill_bot_analytics_install();
	wppugmill_bot_analytics_prune();
	// Schedule daily prune if not already scheduled
	if ( ! wp_next_scheduled( 'wppugmill_daily_prune' ) ) {
		wp_schedule_event( time(), 'daily', 'wppugmill_daily_prune' );
	}
	// Schedule daily intelligence send (offset by 1 hour to run after midnight)
	if ( ! wp_next_scheduled( 'wppugmill_intelligence_send' ) ) {
		wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'wppugmill_intelligence_send' );
	}
	flush_rewrite_rules();
}
register_activation_hook( __FILE__, 'wppugmill_activate' );

/**
 * Plugin deactivation
 */
function wppugmill_deactivate() {
	wp_clear_scheduled_hook( 'wppugmill_daily_prune' );
	wp_clear_scheduled_hook( 'wppugmill_intelligence_send' );
	flush_rewrite_rules();
}
register_deactivation_hook( __FILE__, 'wppugmill_deactivate' );

add_action( 'wppugmill_daily_prune', 'wppugmill_bot_analytics_prune' );
