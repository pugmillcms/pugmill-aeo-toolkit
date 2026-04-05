<?php
/**
 * AEO Metadata — save/retrieve per-post AEO fields.
 *
 * Stores: summary, Q&A pairs, named entities, keywords.
 *
 * @package WPPugmill
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Register the AEO meta fields for posts and pages.
 */
function wppugmill_register_meta() {
	$post_types = get_post_types( array( 'public' => true ) );

	foreach ( $post_types as $post_type ) {
		$auth = function( $allowed, $meta_key, $post_id ) {
			return current_user_can( 'edit_post', (int) $post_id );
		};

		register_post_meta(
			$post_type,
			'_wppugmill_aeo',
			array(
				'single'        => true,
				'type'          => 'string',
				'show_in_rest'  => true,
				'auth_callback' => $auth,
			)
		);

		register_post_meta(
			$post_type,
			'_wppugmill_schema',
			array(
				'single'        => true,
				'type'          => 'string',
				'show_in_rest'  => true,
				'auth_callback' => $auth,
			)
		);

		register_post_meta(
			$post_type,
			'_wppugmill_seo',
			array(
				'single'        => true,
				'type'          => 'string',
				'show_in_rest'  => true,
				'auth_callback' => $auth,
			)
		);
	}
}
add_action( 'init', 'wppugmill_register_meta' );

/**
 * Get AEO metadata for a post.
 *
 * @param int $post_id
 * @return array{summary: string, questions: array, entities: array, keywords: array}
 */
function wppugmill_get_aeo( $post_id ) {
	$raw = get_post_meta( $post_id, '_wppugmill_aeo', true );
	$defaults = array(
		'summary'   => '',
		'questions' => array(),
		'entities'  => array(),
		'keywords'  => array(),
	);

	if ( empty( $raw ) ) {
		return $defaults;
	}

	$data = json_decode( $raw, true );
	return is_array( $data ) ? wp_parse_args( $data, $defaults ) : $defaults;
}

/**
 * Save AEO metadata for a post.
 *
 * @param int   $post_id
 * @param array $aeo
 */
function wppugmill_save_aeo( $post_id, array $aeo ) {
	update_post_meta( $post_id, '_wppugmill_aeo', wp_json_encode( $aeo ) );
}

/**
 * Refresh the cached _wppugmill_score whenever AEO or SEO meta is written.
 * The score is stored as a plain integer so the list table can sort by it.
 *
 * @param int    $meta_id   ID of the updated meta row (unused).
 * @param int    $post_id
 * @param string $meta_key
 */
function wppugmill_refresh_score_cache( $meta_id, $post_id, $meta_key ) {
	if ( ! in_array( $meta_key, array( '_wppugmill_aeo', '_wppugmill_seo' ), true ) ) {
		return;
	}
	if ( ! function_exists( 'wppugmill_health_score' ) ) {
		return;
	}
	$health = wppugmill_health_score( $post_id );
	update_post_meta( $post_id, '_wppugmill_score', (int) $health['score'] );
}
add_action( 'updated_post_meta', 'wppugmill_refresh_score_cache', 10, 3 );
add_action( 'added_post_meta',   'wppugmill_refresh_score_cache', 10, 3 );
