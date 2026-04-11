<?php
/**
 * REST API — expose AEO metadata on WordPress REST API post responses.
 *
 * Adds `aeo_metadata` field to all public post type responses.
 *
 * @package WPPugmill
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function aeopugmill_register_rest_fields() {
	$post_types = get_post_types( array( 'public' => true, 'show_in_rest' => true ) );

	foreach ( $post_types as $post_type ) {
		register_rest_field(
			$post_type,
			'aeo_metadata',
			array(
				'get_callback' => function( $post ) {
					return aeopugmill_get_aeo( $post['id'] );
				},
				'schema' => array(
					'description' => 'AEO (Answer Engine Optimization) metadata',
					'type'        => 'object',
				),
			)
		);
	}
}
add_action( 'rest_api_init', 'aeopugmill_register_rest_fields' );
