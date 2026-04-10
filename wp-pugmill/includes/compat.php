<?php
/**
 * Plugin Compatibility — SEO plugin detection helpers.
 *
 * WP Pugmill is an AEO plugin, not an SEO plugin. The outputs it shares
 * with SEO plugins (meta description, Open Graph, Twitter Cards, Breadcrumb
 * JSON-LD) are secondary features. When a dedicated SEO plugin is active, the
 * admin can suppress these overlapping outputs and let the SEO plugin own them.
 *
 * Pugmill's AEO-exclusive outputs — FAQPage schema, entity mentions, citation
 * extraction, keywords in JSON-LD — are NEVER suppressed regardless of which
 * SEO plugin is active, because no major SEO plugin generates them.
 *
 * @package WPPugmill
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Return an array of active SEO plugins detected via their version constants.
 *
 * Uses compile-time constants rather than is_plugin_active() so it works on
 * any hook (including wp_head) without loading the plugin admin API.
 *
 * @return array<string, string> Keyed by plugin slug, valued by display name + version.
 *                               Empty array when no SEO plugin is detected.
 */
function wppugmill_detected_seo_plugins() {
	static $cache = null;

	if ( null !== $cache ) {
		return $cache;
	}

	$found = array();

	if ( defined( 'WPSEO_VERSION' ) ) {
		// Yoast SEO (free and premium share the same constant).
		$found['yoast'] = 'Yoast SEO ' . WPSEO_VERSION;
	}

	if ( defined( 'RANK_MATH_VERSION' ) ) {
		$found['rankmath'] = 'Rank Math ' . RANK_MATH_VERSION;
	}

	if ( defined( 'AIOSEO_VERSION' ) ) {
		$found['aioseo'] = 'All in One SEO ' . AIOSEO_VERSION;
	}

	if ( defined( 'THE_SEO_FRAMEWORK_VERSION' ) ) {
		$found['tsf'] = 'The SEO Framework ' . THE_SEO_FRAMEWORK_VERSION;
	}

	if ( defined( 'SEOPRESS_VERSION' ) ) {
		$found['seopress'] = 'SEOPress ' . SEOPRESS_VERSION;
	}

	$cache = $found;
	return $found;
}

/**
 * Return true if at least one recognised SEO plugin is active.
 *
 * @return bool
 */
function wppugmill_has_active_seo_plugin() {
	return ! empty( wppugmill_detected_seo_plugins() );
}

/**
 * Return a comma-separated display string of detected SEO plugin names.
 *
 * @return string E.g. "Yoast SEO 23.1, Rank Math 1.0.99" or "".
 */
function wppugmill_seo_plugin_names() {
	return implode( ', ', wppugmill_detected_seo_plugins() );
}

/**
 * Return a list of Pugmill output keys that are currently active.
 *
 * AEO-exclusive outputs (FAQPage, mentions, citations, keywords, llms.txt,
 * post markdown, site summary, robots.txt) are always listed — they cannot
 * be suppressed by any setting.
 *
 * SEO-overlap outputs (article JSON-LD, breadcrumbs, meta description,
 * open graph) are listed only when Pugmill is currently handling them,
 * i.e. the corresponding disable toggle is NOT set.
 *
 * This list is used in the network intelligence payload so pugmillaeo.com
 * knows which outputs each contributing site is running.
 *
 * @return string[]
 */
function wppugmill_active_outputs() {
	// AEO-exclusive: always active, no SEO plugin can own these.
	$active = array(
		'faqpage',
		'mentions',
		'citations',
		'keywords',
		'llms_txt',
		'llms_full',
		'post_markdown',
		'site_summary',
		'robots_txt',
	);

	// SEO-overlap outputs: active unless suppressed via Compatibility settings.
	$disable_json_ld    = (bool) get_option( 'wppugmill_disable_json_ld', 0 );
	$disable_breadcrumb = (bool) get_option( 'wppugmill_disable_breadcrumbs', 0 );
	$disable_meta       = (bool) get_option( 'wppugmill_disable_seo_meta', 0 );

	if ( ! $disable_json_ld ) {
		$active[] = 'article_json_ld';
	}
	// Breadcrumbs are suppressed when either json_ld or breadcrumbs toggle is set.
	if ( ! $disable_json_ld && ! $disable_breadcrumb ) {
		$active[] = 'breadcrumbs';
	}
	if ( ! $disable_meta ) {
		$active[] = 'meta_description';
		$active[] = 'open_graph';
	}

	return $active;
}
