<?php
/**
 * Plugin Compatibility — SEO plugin detection helpers.
 *
 * Pugmill AEO Toolkit is an AEO plugin, not an SEO plugin. The outputs it shares
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
function aeopugmill_detected_seo_plugins() {
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
function aeopugmill_has_active_seo_plugin() {
	return ! empty( aeopugmill_detected_seo_plugins() );
}

/**
 * Return a comma-separated display string of detected SEO plugin names.
 *
 * @return string E.g. "Yoast SEO 23.1, Rank Math 1.0.99" or "".
 */
function aeopugmill_seo_plugin_names() {
	return implode( ', ', aeopugmill_detected_seo_plugins() );
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
 * This list is used in the network intelligence payload so aeopugmill.com
 * knows which outputs each contributing site is running.
 *
 * @return string[]
 */
function aeopugmill_active_outputs() {
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
	$disable_json_ld    = (bool) get_option( 'aeopugmill_disable_json_ld', 0 );
	$disable_breadcrumb = (bool) get_option( 'aeopugmill_disable_breadcrumbs', 0 );
	$disable_meta       = (bool) get_option( 'aeopugmill_disable_seo_meta', 0 );
	$disable_rss        = (bool) get_option( 'aeopugmill_disable_rss_enrichment', 0 );

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
	if ( ! $disable_rss ) {
		$active[] = 'rss_enrichment';
	}

	return $active;
}

/**
 * Detect whether a known SEO plugin is actively enriching the RSS feed.
 *
 * Yoast SEO and Rank Math can inject content before/after each RSS item.
 * Our enrichment is purely additive (new namespace + new elements) so there
 * is no true XML conflict, but the admin should know both plugins are active
 * on the feed so they can make an informed decision.
 *
 * @return array<string, string> Keyed by plugin slug, valued by display name.
 *                               Empty array when no RSS-modifying plugin is detected.
 */
function aeopugmill_detected_rss_plugins() {
	$found = array();

	// Yoast SEO — enriches RSS when rssbefore or rssafter options are non-empty.
	if ( defined( 'WPSEO_VERSION' ) ) {
		$wpseo = get_option( 'wpseo', array() );
		if ( ! empty( $wpseo['rssbefore'] ) || ! empty( $wpseo['rssafter'] ) ) {
			$found['yoast'] = 'Yoast SEO';
		}
	}

	// Rank Math — has dedicated RSS content options.
	if ( defined( 'RANK_MATH_VERSION' ) ) {
		$rm_before = get_option( 'rank_math_rss_before_content', '' );
		$rm_after  = get_option( 'rank_math_rss_after_content', '' );
		if ( ! empty( $rm_before ) || ! empty( $rm_after ) ) {
			$found['rankmath'] = 'Rank Math';
		}
	}

	// All in One SEO — check if active (it always modifies RSS when enabled).
	if ( defined( 'AIOSEO_VERSION' ) ) {
		$found['aioseo'] = 'All in One SEO';
	}

	return $found;
}
