<?php
/**
 * Migration tool — import SEO data from Yoast, Rank Math, AIOSEO, SEOPress.
 *
 * Non-destructive by default: skips posts that already have WP Pugmill SEO data
 * unless the overwrite flag is passed.
 *
 * @package WPPugmill
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// ── Source detection ──────────────────────────────────────────────────────────

/**
 * Count posts that have data from a given source plugin.
 *
 * @param  string $plugin  'yoast' | 'rankmath' | 'aioseo' | 'seopress'
 * @return int
 */
function wppugmill_migration_count( $plugin ) {
	global $wpdb;

	$keys = array(
		'yoast'    => '_yoast_wpseo_title',
		'rankmath' => 'rank_math_title',
		'aioseo'   => '_aioseo_title',
		'seopress' => '_seopress_titles_title',
	);

	if ( ! isset( $keys[ $plugin ] ) ) {
		return 0;
	}

	return (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->prepare(
			"SELECT COUNT(DISTINCT post_id) FROM {$wpdb->postmeta} WHERE meta_key = %s",
			$keys[ $plugin ]
		)
	);
}

/**
 * Detect which plugins have importable data and how many posts each has.
 *
 * @return array<string, array{label: string, count: int, active: bool}>
 */
function wppugmill_migration_sources() {
	if ( ! function_exists( 'is_plugin_active' ) ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}

	$plugins = array(
		'yoast'    => array(
			'label'  => 'Yoast SEO',
			'slug'   => 'wordpress-seo/wp-seo.php',
		),
		'rankmath' => array(
			'label'  => 'Rank Math SEO',
			'slug'   => 'seo-by-rank-math/rank-math.php',
		),
		'aioseo'   => array(
			'label'  => 'All in One SEO',
			'slug'   => 'all-in-one-seo-pack/all_in_one_seo_pack.php',
		),
		'seopress' => array(
			'label'  => 'SEOPress',
			'slug'   => 'wp-seopress/seopress.php',
		),
	);

	$results = array();
	foreach ( $plugins as $key => $info ) {
		$count = wppugmill_migration_count( $key );
		if ( $count > 0 ) {
			$results[ $key ] = array(
				'label'  => $info['label'],
				'count'  => $count,
				'active' => is_plugin_active( $info['slug'] ),
			);
		}
	}

	return $results;
}

// ── Field mappers ─────────────────────────────────────────────────────────────

/**
 * Map a single post's Yoast data into WP Pugmill SEO format.
 *
 * @param  int $post_id
 * @return array|false  Mapped SEO array, or false if no data found.
 */
function wppugmill_migrate_post_from_yoast( $post_id ) {
	$title     = get_post_meta( $post_id, '_yoast_wpseo_title',           true );
	$meta_desc = get_post_meta( $post_id, '_yoast_wpseo_metadesc',        true );
	$canonical = get_post_meta( $post_id, '_yoast_wpseo_canonical',       true );
	$noindex   = get_post_meta( $post_id, '_yoast_wpseo_meta-robots-noindex', true );
	$nofollow  = get_post_meta( $post_id, '_yoast_wpseo_meta-robots-nofollow', true );
	$og_title  = get_post_meta( $post_id, '_yoast_wpseo_opengraph-title', true );
	$og_desc   = get_post_meta( $post_id, '_yoast_wpseo_opengraph-description', true );
	$og_image  = get_post_meta( $post_id, '_yoast_wpseo_opengraph-image', true );

	if ( empty( $title ) && empty( $meta_desc ) ) {
		return false;
	}

	return array(
		'title'     => (string) $title,
		'meta_desc' => (string) $meta_desc,
		'canonical' => (string) $canonical,
		'noindex'   => ( '1' === (string) $noindex ),
		'nofollow'  => ( '1' === (string) $nofollow ),
		'og_title'  => (string) $og_title,
		'og_desc'   => (string) $og_desc,
		'og_image'  => (string) $og_image,
	);
}

/**
 * Map a single post's Rank Math data into WP Pugmill SEO format.
 *
 * @param  int $post_id
 * @return array|false
 */
function wppugmill_migrate_post_from_rankmath( $post_id ) {
	$title     = get_post_meta( $post_id, 'rank_math_title',              true );
	$meta_desc = get_post_meta( $post_id, 'rank_math_description',        true );
	$canonical = get_post_meta( $post_id, 'rank_math_canonical_url',      true );
	$robots    = get_post_meta( $post_id, 'rank_math_robots',             true ); // stored as array
	$og_image  = get_post_meta( $post_id, 'rank_math_facebook_image',     true );
	$og_title  = get_post_meta( $post_id, 'rank_math_facebook_title',     true );
	$og_desc   = get_post_meta( $post_id, 'rank_math_facebook_description', true );

	if ( empty( $title ) && empty( $meta_desc ) ) {
		return false;
	}

	$robots_arr = is_array( $robots ) ? $robots : array();

	return array(
		'title'     => (string) $title,
		'meta_desc' => (string) $meta_desc,
		'canonical' => (string) $canonical,
		'noindex'   => in_array( 'noindex', $robots_arr, true ),
		'nofollow'  => in_array( 'nofollow', $robots_arr, true ),
		'og_title'  => (string) $og_title,
		'og_desc'   => (string) $og_desc,
		'og_image'  => (string) $og_image,
	);
}

/**
 * Map a single post's AIOSEO data into WP Pugmill SEO format.
 *
 * @param  int $post_id
 * @return array|false
 */
function wppugmill_migrate_post_from_aioseo( $post_id ) {
	$title     = get_post_meta( $post_id, '_aioseo_title',                true );
	$meta_desc = get_post_meta( $post_id, '_aioseo_description',          true );
	$canonical = get_post_meta( $post_id, '_aioseo_canonical_url',        true );
	$noindex   = get_post_meta( $post_id, '_aioseo_robots_noindex',       true );
	$nofollow  = get_post_meta( $post_id, '_aioseo_robots_nofollow',      true );
	$og_image  = get_post_meta( $post_id, '_aioseo_og_image_custom_url',  true );
	$og_title  = get_post_meta( $post_id, '_aioseo_og_title',             true );
	$og_desc   = get_post_meta( $post_id, '_aioseo_og_description',       true );

	if ( empty( $title ) && empty( $meta_desc ) ) {
		return false;
	}

	return array(
		'title'     => (string) $title,
		'meta_desc' => (string) $meta_desc,
		'canonical' => (string) $canonical,
		'noindex'   => ( '1' === (string) $noindex ),
		'nofollow'  => ( '1' === (string) $nofollow ),
		'og_title'  => (string) $og_title,
		'og_desc'   => (string) $og_desc,
		'og_image'  => (string) $og_image,
	);
}

/**
 * Map a single post's SEOPress data into WP Pugmill SEO format.
 *
 * @param  int $post_id
 * @return array|false
 */
function wppugmill_migrate_post_from_seopress( $post_id ) {
	$title     = get_post_meta( $post_id, '_seopress_titles_title',       true );
	$meta_desc = get_post_meta( $post_id, '_seopress_titles_desc',        true );
	$canonical = get_post_meta( $post_id, '_seopress_robots_canonical',   true );
	$noindex   = get_post_meta( $post_id, '_seopress_robots_index',       true );
	$nofollow  = get_post_meta( $post_id, '_seopress_robots_follow',      true );
	$og_image  = get_post_meta( $post_id, '_seopress_social_fb_img',      true );
	$og_title  = get_post_meta( $post_id, '_seopress_social_fb_title',    true );
	$og_desc   = get_post_meta( $post_id, '_seopress_social_fb_desc',     true );

	if ( empty( $title ) && empty( $meta_desc ) ) {
		return false;
	}

	// SEOPress stores noindex as 'yes'/'no' string
	return array(
		'title'     => (string) $title,
		'meta_desc' => (string) $meta_desc,
		'canonical' => (string) $canonical,
		'noindex'   => ( 'yes' === (string) $noindex ),
		'nofollow'  => ( 'yes' === (string) $nofollow ),
		'og_title'  => (string) $og_title,
		'og_desc'   => (string) $og_desc,
		'og_image'  => (string) $og_image,
	);
}

// ── AJAX handler ──────────────────────────────────────────────────────────────

/**
 * AJAX: run one migration batch (50 posts).
 *
 * Expected POST params:
 *   source    string  'yoast' | 'rankmath' | 'aioseo' | 'seopress'
 *   offset    int     Pagination offset
 *   overwrite int     0 or 1
 *   nonce     string
 */
function wppugmill_ajax_run_migration() {
	check_ajax_referer( 'wppugmill_migration', 'nonce' );

	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( array( 'message' => 'Unauthorized.' ), 403 );
	}

	$source    = isset( $_POST['source'] )    ? sanitize_key( wp_unslash( $_POST['source'] ) )    : '';
	$offset    = isset( $_POST['offset'] )    ? absint( wp_unslash( $_POST['offset'] ) )           : 0;
	$overwrite = isset( $_POST['overwrite'] ) ? (bool) absint( wp_unslash( $_POST['overwrite'] ) ) : false;

	$allowed_sources = array( 'yoast', 'rankmath', 'aioseo', 'seopress' );
	if ( ! in_array( $source, $allowed_sources, true ) ) {
		wp_send_json_error( array( 'message' => 'Invalid source.' ), 400 );
	}

	// Source meta key used to find posts
	$source_meta_keys = array(
		'yoast'    => '_yoast_wpseo_title',
		'rankmath' => 'rank_math_title',
		'aioseo'   => '_aioseo_title',
		'seopress' => '_seopress_titles_title',
	);

	// Fetch a batch of post IDs that have source data
	$post_ids = get_posts( array(
		'post_type'      => get_post_types( array( 'public' => true ) ),
		'post_status'    => 'any',
		'posts_per_page' => 50,
		'offset'         => $offset,
		'fields'         => 'ids',
		'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery
			array(
				'key'     => $source_meta_keys[ $source ],
				'compare' => 'EXISTS',
			),
		),
		'no_found_rows'  => false,
	) );

	$migrated = 0;
	$skipped  = 0;

	foreach ( $post_ids as $post_id ) {
		// Skip if post already has WP Pugmill SEO data and overwrite is off
		if ( ! $overwrite ) {
			$existing = get_post_meta( $post_id, '_wppugmill_seo', true );
			if ( ! empty( $existing ) ) {
				++$skipped;
				continue;
			}
		}

		// Run the appropriate mapper
		$mapper = 'wppugmill_migrate_post_from_' . $source;
		$data   = function_exists( $mapper ) ? $mapper( $post_id ) : false;

		if ( false === $data ) {
			++$skipped;
			continue;
		}

		wppugmill_save_seo( $post_id, $data );
		++$migrated;
	}

	wp_send_json_success( array(
		'migrated'  => $migrated,
		'skipped'   => $skipped,
		'processed' => count( $post_ids ),
		'done'      => count( $post_ids ) < 50,
	) );
}
add_action( 'wp_ajax_wppugmill_run_migration', 'wppugmill_ajax_run_migration' );

/**
 * AJAX: return current source detection counts (for refreshing the UI).
 */
function wppugmill_ajax_migration_sources() {
	check_ajax_referer( 'wppugmill_migration', 'nonce' );

	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( array( 'message' => 'Unauthorized.' ), 403 );
	}

	wp_send_json_success( wppugmill_migration_sources() );
}
add_action( 'wp_ajax_wppugmill_migration_sources', 'wppugmill_ajax_migration_sources' );
