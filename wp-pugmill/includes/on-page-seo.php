<?php
/**
 * On-Page SEO — save/retrieve per-post SEO fields.
 *
 * Stores: title, meta description, canonical, noindex/nofollow flags, OG fields.
 *
 * @package WPPugmill
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Get SEO metadata for a post.
 *
 * @param  int   $post_id
 * @return array{title: string, meta_desc: string, canonical: string, noindex: bool, nofollow: bool, og_title: string, og_desc: string, og_image: string}
 */
function wppugmill_get_seo( $post_id ) {
	$raw = get_post_meta( $post_id, '_wppugmill_seo', true );
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
	update_post_meta( $post_id, '_wppugmill_seo', wp_json_encode( $seo ) );
}
