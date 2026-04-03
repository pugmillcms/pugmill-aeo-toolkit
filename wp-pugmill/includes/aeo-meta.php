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
