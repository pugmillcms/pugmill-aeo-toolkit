<?php
/**
 * On-Page SEO — per-post title, meta description, canonical, robots, and Open Graph overrides.
 *
 * Stores all fields in a single JSON meta key (_wppugmill_seo) mirroring the
 * AEO pattern. Fields cascade gracefully:
 *   title      → custom title   → post title
 *   meta_desc  → custom desc    → AEO summary  → excerpt
 *   canonical  → custom URL     → permalink
 *   og_title   → custom og_title → title (above)
 *   og_desc    → custom og_desc  → meta_desc (above)
 *   og_image   → custom URL     → featured image
 *
 * @package WPPugmill
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// =========================================================================
// Meta registration
// =========================================================================

/**
 * Register the _wppugmill_seo meta field for all public post types.
 */
function wppugmill_register_seo_meta() {
	$post_types = get_post_types( array( 'public' => true ) );

	foreach ( $post_types as $post_type ) {
		register_post_meta(
			$post_type,
			'_wppugmill_seo',
			array(
				'single'        => true,
				'type'          => 'string',
				'show_in_rest'  => true,
				'auth_callback' => function( $allowed, $meta_key, $post_id ) {
					return current_user_can( 'edit_post', (int) $post_id );
				},
			)
		);
	}
}
add_action( 'init', 'wppugmill_register_seo_meta' );

// =========================================================================
// Helpers
// =========================================================================

/**
 * Get SEO metadata for a post.
 *
 * @param int $post_id
 * @return array{title:string, meta_desc:string, canonical:string, noindex:bool, nofollow:bool, og_title:string, og_desc:string, og_image:string}
 */
function wppugmill_get_seo( $post_id ) {
	$defaults = array(
		'title'     => '',
		'meta_desc' => '',
		'canonical' => '',
		'noindex'   => false,
		'nofollow'  => false,
		'og_title'  => '',
		'og_desc'   => '',
		'og_image'  => '',
	);

	$raw = get_post_meta( (int) $post_id, '_wppugmill_seo', true );
	if ( empty( $raw ) ) {
		return $defaults;
	}

	$data = json_decode( $raw, true );
	return is_array( $data ) ? wp_parse_args( $data, $defaults ) : $defaults;
}

/**
 * Save SEO metadata for a post.
 *
 * @param int   $post_id
 * @param array $seo
 */
function wppugmill_save_seo( $post_id, array $seo ) {
	update_post_meta( (int) $post_id, '_wppugmill_seo', wp_json_encode( $seo ) );
}

// =========================================================================
// Title tag override
// =========================================================================

/**
 * Override the document <title> tag when a custom SEO title is set.
 *
 * Hooks at priority 10 so theme/plugin title filters still run first,
 * but our explicit override wins when present.
 *
 * @param  string $title Candidate title (may already be modified by other filters).
 * @return string
 */
function wppugmill_filter_document_title( $title ) {
	if ( get_option( 'wppugmill_disable_seo_meta' ) ) {
		return $title;
	}

	if ( ! is_singular() ) {
		return $title;
	}

	$post_id   = (int) get_queried_object_id();
	$seo       = wppugmill_get_seo( $post_id );
	$custom    = trim( $seo['title'] );

	return $custom !== '' ? $custom : $title;
}
add_filter( 'pre_get_document_title', 'wppugmill_filter_document_title', 10 );

// =========================================================================
// Canonical URL
// =========================================================================

/**
 * Remove WordPress's default rel_canonical output so we can control it.
 *
 * We only do this when our on-page SEO output is active; if the feature is
 * disabled (e.g. Yoast is handling it) we leave WP's canonical in place.
 */
function wppugmill_maybe_remove_wp_canonical() {
	if ( ! get_option( 'wppugmill_disable_seo_meta' ) ) {
		remove_action( 'wp_head', 'rel_canonical' );
	}
}
add_action( 'wp_head', 'wppugmill_maybe_remove_wp_canonical', 0 );

/**
 * Output a canonical <link> tag for singular views.
 *
 * Uses the per-post override when set; falls back to WordPress's permalink.
 */
function wppugmill_output_canonical() {
	if ( get_option( 'wppugmill_disable_seo_meta' ) ) {
		return;
	}

	if ( ! is_singular() ) {
		return;
	}

	$post_id  = (int) get_queried_object_id();
	$seo      = wppugmill_get_seo( $post_id );
	$override = trim( $seo['canonical'] );

	$canonical = $override !== '' ? $override : get_permalink( $post_id );

	if ( $canonical ) {
		echo '<link rel="canonical" href="' . esc_url( $canonical ) . '" />' . "\n";
	}
}
add_action( 'wp_head', 'wppugmill_output_canonical', 2 );

// =========================================================================
// AEO discovery link tags
// =========================================================================

/**
 * Output <link rel="alternate" type="text/markdown"> tags in <head>.
 *
 * - Every page gets a site-level link to /llms.txt so AI parsers can
 *   always find the content index from any entry point.
 * - Singular posts/pages additionally get a per-post link pointing at
 *   ?wppugmill_llm=1 — a clean markdown view with AEO metadata.
 *
 * These complement the robots.txt LLMs-Txt: directive and the sitemap
 * entry, creating a three-signal AEO discovery system.
 */
function wppugmill_output_aeo_link_tags() {
	if ( get_option( 'wppugmill_disable_llms_txt' ) ) {
		return;
	}

	// Site-level llms.txt — present on every page.
	echo '<link rel="alternate" type="text/markdown" href="' . esc_url( home_url( '/llms.txt' ) ) . '" title="AI Content Index" />' . "\n";

	// Per-post AEO endpoint — only on singular views.
	// Use get_queried_object() directly rather than is_singular() to avoid
	// "called before query is run" notices in edge-case contexts.
	$queried = get_queried_object();
	if ( $queried instanceof WP_Post && 'publish' === $queried->post_status ) {
		$post_id = $queried->ID;
		if ( ! wppugmill_own_noindex( $post_id ) && ! wppugmill_post_is_noindexed( $post_id ) ) {
			$llm_url = add_query_arg( 'wppugmill_llm', '1', get_permalink( $post_id ) );
			echo '<link rel="alternate" type="text/markdown" href="' . esc_url( $llm_url ) . '" title="AI-Optimised Content" />' . "\n";
		}
	}
}
add_action( 'wp_head', 'wppugmill_output_aeo_link_tags', 3 );

/**
 * Prevent WordPress's redirect_canonical from firing on /llms.txt and /llms-full.txt.
 *
 * Without this, WordPress can 301-redirect the llms.txt request to a
 * non-existent page, breaking AI crawler access.
 */
function wppugmill_remove_llms_canonical_redirect() {
	$path = isset( $_SERVER['REQUEST_URI'] ) ? strtok( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ), '?' ) : '';
	if ( preg_match( '~/(llms(?:-full)?\.txt)$~i', $path ) ) {
		remove_filter( 'template_redirect', 'redirect_canonical' );
	}
}
add_action( 'template_redirect', 'wppugmill_remove_llms_canonical_redirect', 0 );

// =========================================================================
// Meta robots
// =========================================================================

/**
 * Inject robots directives via the wp_robots filter (WordPress 5.7+).
 *
 * This merges our directives into WordPress's single <meta name="robots"> tag,
 * preventing duplicate tags from plugins like Jetpack that also use this filter.
 * Always adds max-snippet, max-video-preview, and max-image-preview directives;
 * overrides noindex/nofollow when explicitly set per-post.
 *
 * @param  array $robots Existing robots directives from WP core and other plugins.
 * @return array
 */
function wppugmill_filter_robots( $robots ) {
	if ( get_option( 'wppugmill_disable_seo_meta' ) ) {
		return $robots;
	}

	// Always allow full snippet/preview access for AI and search engines on every page.
	$robots['max-snippet']       = '-1';
	$robots['max-video-preview'] = '-1';
	$robots['max-image-preview'] = 'large';

	// Per-post noindex/nofollow overrides only apply on singular views.
	if ( ! is_singular() ) {
		return $robots;
	}

	$post_id  = (int) get_queried_object_id();
	$seo      = wppugmill_get_seo( $post_id );
	$noindex  = ! empty( $seo['noindex'] );
	$nofollow = ! empty( $seo['nofollow'] );

	// Override index/noindex.
	if ( $noindex ) {
		$robots['noindex'] = true;
		unset( $robots['index'] );
	} else {
		unset( $robots['noindex'] );
	}

	// Override follow/nofollow.
	if ( $nofollow ) {
		$robots['nofollow'] = true;
		unset( $robots['follow'] );
	} else {
		unset( $robots['nofollow'] );
	}

	return $robots;
}
add_filter( 'wp_robots', 'wppugmill_filter_robots', 20 );

// =========================================================================
// noindex helper (used by llms-txt.php and health.php)
// =========================================================================

/**
 * Return true if WP Pugmill's own noindex flag is set for a post.
 *
 * @param  int  $post_id
 * @return bool
 */
function wppugmill_own_noindex( $post_id ) {
	$seo = wppugmill_get_seo( (int) $post_id );
	return ! empty( $seo['noindex'] );
}
