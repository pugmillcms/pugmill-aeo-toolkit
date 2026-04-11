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
 * Recalculate and store _wppugmill_score as a plain integer whenever any
 * input to the score changes:
 *
 *   - _wppugmill_aeo meta written      (AEO fields changed)
 *   - _thumbnail_id meta written       (featured image changed)
 *   - save_post fired for a published  (post content changed)
 *
 * Storing the score on write keeps Audit AEO queries fast — no per-row
 * recalculation needed at read time.
 */

/**
 * Refresh score when AEO meta or featured image changes.
 *
 * @param int    $meta_id  Unused.
 * @param int    $post_id
 * @param string $meta_key
 */
function wppugmill_refresh_score_on_meta( $meta_id, $post_id, $meta_key ) {
	if ( ! in_array( $meta_key, array( '_wppugmill_aeo', '_thumbnail_id' ), true ) ) {
		return;
	}
	if ( ! function_exists( 'wppugmill_health_score' ) ) {
		return;
	}
	$health = wppugmill_health_score( $post_id );
	update_post_meta( $post_id, '_wppugmill_score', (int) $health['score'] );
	// Content score doesn't change when AEO meta or thumbnail changes,
	// but keep it initialised if it hasn't been stored yet.
	if ( '' === get_post_meta( $post_id, '_wppugmill_content_score', true ) ) {
		update_post_meta( $post_id, '_wppugmill_content_score', (int) wppugmill_content_score( $post_id ) );
	}
}
add_action( 'updated_post_meta', 'wppugmill_refresh_score_on_meta', 10, 3 );
add_action( 'added_post_meta',   'wppugmill_refresh_score_on_meta', 10, 3 );

/**
 * Refresh score when post content is saved (word count, headings, etc. change).
 *
 * @param int      $post_id
 * @param \WP_Post $post
 */
function wppugmill_refresh_score_on_save( $post_id, $post ) {
	if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
		return;
	}
	if ( wp_is_post_revision( $post_id ) ) {
		return;
	}
	if ( ! in_array( $post->post_status, array( 'publish', 'draft', 'future' ), true ) ) {
		return;
	}
	if ( ! function_exists( 'wppugmill_health_score' ) ) {
		return;
	}
	$content = $post->post_content;
	$health  = wppugmill_health_score( $post_id );
	update_post_meta( $post_id, '_wppugmill_score',         (int) $health['score'] );
	update_post_meta( $post_id, '_wppugmill_content_score', (int) wppugmill_content_score( $post_id, $content ) );
}
add_action( 'save_post', 'wppugmill_refresh_score_on_save', 20, 2 );
