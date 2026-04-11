<?php
/**
 * Uninstall — clean up all AEO Pugmill data when the plugin is deleted.
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
	'aeopugmill_site_summary',
	'aeopugmill_org_name',
	'aeopugmill_org_type',
	'aeopugmill_industry',
	'aeopugmill_author_voice',
	'aeopugmill_author_same_as',
	'aeopugmill_ai_provider',
	'aeopugmill_ai_api_key',
	'aeopugmill_ai_rate_limit',
	'aeopugmill_license_key',
	'aeopugmill_instance_id',
	'aeopugmill_disable_json_ld',
	'aeopugmill_disable_llms_txt',
	'aeopugmill_disable_seo_meta',
	'aeopugmill_disable_breadcrumbs',
	'aeopugmill_disable_robots_append',
	'aeopugmill_disable_sitemap',
	'aeopugmill_robots_txt_custom',
	'aeopugmill_indexnow_key',
	'aeopugmill_llms_full_page_count',
	'aeopugmill_bot_db_version',
	'aeopugmill_signal_db_version',
	'aeopugmill_analytics_opted_in',
);

foreach ( $options as $option ) {
	delete_option( $option );
}

// ── Transients ────────────────────────────────────────────────────────────

delete_transient( 'aeopugmill_llms_txt' );
delete_transient( 'aeopugmill_license_status' );
delete_transient( 'aeopugmill_license_status_last_good' );
delete_transient( 'aeopugmill_llms_txt_conflict_check' );
delete_transient( 'aeopugmill_ai_analytics_insights' );
delete_transient( 'aeopugmill_intel_site_meta' );

// Bust all paginated llms-full.txt caches
for ( $i = 1; $i <= 9999; $i++ ) {
	$key = 'aeopugmill_llms_full_' . $i;
	if ( false === get_transient( $key ) ) {
		break;
	}
	delete_transient( $key );
}

// Rate limit transients — keyed by user ID, clean up for all users
$users = get_users( array( 'fields' => 'ID' ) );
foreach ( $users as $user_id ) {
	delete_transient( 'aeopugmill_rl_' . $user_id );
}

// ── Post Meta ─────────────────────────────────────────────────────────────

global $wpdb;

$meta_keys = array( '_aeopugmill_aeo', '_aeopugmill_seo', '_aeopugmill_schema', '_aeopugmill_score', '_aeopugmill_content_score' );
foreach ( $meta_keys as $meta_key ) {
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$wpdb->delete( $wpdb->postmeta, array( 'meta_key' => $meta_key ), array( '%s' ) );
}

// ── Bot analytics tables ───────────────────────────────────────────────────

// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}aeopugmill_bot_daily" );
// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}aeopugmill_bot_recent" );
// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}aeopugmill_signal_daily" );

// Clear scheduled prune event
wp_clear_scheduled_hook( 'aeopugmill_daily_prune' );
