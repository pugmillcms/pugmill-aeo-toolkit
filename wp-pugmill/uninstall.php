<?php
/**
 * Uninstall — clean up all WP Pugmill data when the plugin is deleted.
 *
 * Removes:
 * - All plugin options from wp_options
 * - All AEO post meta from wp_postmeta
 * - All cached transients
 *
 * This file is called automatically by WordPress on plugin deletion.
 * It does NOT run on deactivation — only on delete.
 *
 * @package WPPugmill
 */

// Bail if not called by WordPress uninstall process
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// ── Options ───────────────────────────────────────────────────────────────

$options = array(
	'wppugmill_site_summary',
	'wppugmill_org_name',
	'wppugmill_org_type',
	'wppugmill_industry',
	'wppugmill_author_voice',
	'wppugmill_author_same_as',
	'wppugmill_ai_provider',
	'wppugmill_ai_api_key',
	'wppugmill_ai_rate_limit',
	'wppugmill_license_key',
	'wppugmill_instance_id',
	'wppugmill_disable_json_ld',
	'wppugmill_disable_llms_txt',
	'wppugmill_disable_seo_meta',
	'wppugmill_disable_breadcrumbs',
	'wppugmill_disable_robots_append',
	'wppugmill_disable_sitemap',
	'wppugmill_robots_txt_custom',
	'wppugmill_indexnow_key',
	'wppugmill_llms_full_page_count',
	'wppugmill_bot_db_version',
	'wppugmill_signal_db_version',
	'wppugmill_analytics_opted_in',
);

foreach ( $options as $option ) {
	delete_option( $option );
}

// ── Transients ────────────────────────────────────────────────────────────

delete_transient( 'wppugmill_llms_txt' );
delete_transient( 'wppugmill_license_status' );
delete_transient( 'wppugmill_license_status_last_good' );
delete_transient( 'wppugmill_llms_txt_conflict_check' );
delete_transient( 'wppugmill_ai_analytics_insights' );
delete_transient( 'wppugmill_intel_site_meta' );

// Bust all paginated llms-full.txt caches
for ( $i = 1; $i <= 9999; $i++ ) {
	$key = 'wppugmill_llms_full_' . $i;
	if ( false === get_transient( $key ) ) {
		break;
	}
	delete_transient( $key );
}

// Rate limit transients — keyed by user ID, clean up for all users
$users = get_users( array( 'fields' => 'ID' ) );
foreach ( $users as $user_id ) {
	delete_transient( 'wppugmill_rl_' . $user_id );
}

// ── Post Meta ─────────────────────────────────────────────────────────────

global $wpdb;

$meta_keys = array( '_wppugmill_aeo', '_wppugmill_seo', '_wppugmill_schema', '_wppugmill_score', '_wppugmill_content_score' );
foreach ( $meta_keys as $meta_key ) {
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$wpdb->delete( $wpdb->postmeta, array( 'meta_key' => $meta_key ), array( '%s' ) );
}

// ── Bot analytics tables ───────────────────────────────────────────────────

// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}wppugmill_bot_daily" );
// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}wppugmill_bot_recent" );
// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}wppugmill_signal_daily" );

// Clear scheduled prune event
wp_clear_scheduled_hook( 'wppugmill_daily_prune' );
