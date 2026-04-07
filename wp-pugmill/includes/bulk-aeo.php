<?php
/**
 * Bulk AEO — generate AEO metadata for all published posts in one run.
 *
 * Two AJAX endpoints:
 *   wppugmill_bulk_aeo_get_queue  — return eligible post IDs + coverage stats
 *   wppugmill_bulk_aeo_process    — generate + save AEO for one post (Pro only)
 *
 * Both require manage_options capability and nonce verification.
 * Process endpoint additionally requires Pro mode (valid license + API key).
 *
 * Saves directly to post meta via wppugmill_save_aeo() — no editor state involved.
 *
 * @package WPPugmill
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'wp_ajax_wppugmill_bulk_aeo_get_queue', 'wppugmill_ajax_bulk_aeo_get_queue' );
add_action( 'wp_ajax_wppugmill_bulk_aeo_process',   'wppugmill_ajax_bulk_aeo_process' );

/**
 * Return eligible post IDs and AEO coverage stats.
 *
 * POST params:
 *   post_types    string  'all' | 'post' | 'page'  (default: 'all')
 *   skip_existing string  '1' = exclude posts that already have a summary (default: '1')
 *
 * Response:
 *   { ids: int[], stats: { total: int, have_aeo: int, missing_aeo: int } }
 */
function wppugmill_ajax_bulk_aeo_get_queue() {
	check_ajax_referer( 'wppugmill_bulk_aeo', 'nonce' );

	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'wp-pugmill' ) ), 403 );
	}

	$post_types_param = sanitize_text_field( wp_unslash( $_POST['post_types'] ?? 'all' ) );
	$skip_existing    = isset( $_POST['skip_existing'] ) && '1' === $_POST['skip_existing'];
	$sort_by          = sanitize_text_field( wp_unslash( $_POST['sort_by'] ?? 'newest' ) );

	if ( 'post' === $post_types_param ) {
		$post_types = array( 'post' );
	} elseif ( 'page' === $post_types_param ) {
		$post_types = array( 'page' );
	} else {
		$post_types = array( 'post', 'page' );
	}

	// Map sort_by to a safe, whitelisted ORDER BY clause.
	$order_map = array(
		'newest'    => 'p.post_date DESC',
		'commented' => 'p.comment_count DESC, p.post_date DESC',
		'oldest'    => 'p.post_date ASC',
	);
	$order_by = $order_map[ $sort_by ] ?? $order_map['newest'];

	global $wpdb;

	// Build post-type placeholder list for the IN clause.
	$type_placeholders = implode( ', ', array_fill( 0, count( $post_types ), '%s' ) );

	// Single query: all published IDs + their AEO meta value in one LEFT JOIN.
	// Much faster than get_posts( fields=>ids ) + N individual get_post_meta() calls.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
	$rows = $wpdb->get_results(
		$wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			"SELECT p.ID, pm.meta_value AS aeo_meta
			 FROM {$wpdb->posts} p
			 LEFT JOIN {$wpdb->postmeta} pm
			   ON pm.post_id = p.ID AND pm.meta_key = '_wppugmill_aeo'
			 WHERE p.post_status = 'publish'
			   AND p.post_type IN ( {$type_placeholders} )
			 ORDER BY {$order_by}",
			...$post_types
		),
		ARRAY_A
	);

	$total    = 0;
	$have_aeo = 0;
	$eligible = array();

	foreach ( (array) $rows as $row ) {
		$post_id = (int) $row['ID'];

		// Respect noindex — same filter used by llms.txt.
		if ( function_exists( 'wppugmill_post_is_noindexed' ) && wppugmill_post_is_noindexed( $post_id ) ) {
			continue;
		}

		$total++;

		// A post "has AEO" if a non-empty summary exists.
		$decoded = $row['aeo_meta'] ? json_decode( $row['aeo_meta'], true ) : null;
		$has_aeo = ! empty( $decoded['summary'] );

		if ( $has_aeo ) {
			$have_aeo++;
		}

		if ( ! $has_aeo || ! $skip_existing ) {
			$eligible[] = $post_id;
		}
	}

	wp_send_json_success( array(
		'ids'   => $eligible,
		'stats' => array(
			'total'       => $total,
			'have_aeo'    => $have_aeo,
			'missing_aeo' => $total - $have_aeo,
		),
	) );
}

/**
 * Generate and save AEO metadata for a single post. Pro only.
 *
 * POST params:
 *   post_id  int  The post to process.
 *
 * Response on success:  { post_title: string, summary: string }
 * Response on skip:     { skipped: true, post_title: string }
 */
function wppugmill_ajax_bulk_aeo_process() {
	check_ajax_referer( 'wppugmill_bulk_aeo', 'nonce' );

	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'wp-pugmill' ) ), 403 );
	}

	if ( 'ai' !== wppugmill_mode() ) {
		wp_send_json_error( array( 'message' => __( 'WP Pugmill Pro required.', 'wp-pugmill' ) ), 403 );
	}

	$post_id = absint( wp_unslash( $_POST['post_id'] ?? 0 ) );
	if ( ! $post_id ) {
		wp_send_json_error( array( 'message' => __( 'Invalid post ID.', 'wp-pugmill' ) ), 400 );
	}

	$post = get_post( $post_id );
	if ( ! $post || 'publish' !== $post->post_status ) {
		wp_send_json_error( array( 'message' => __( 'Post not found or not published.', 'wp-pugmill' ) ), 404 );
	}

	$provider = get_option( 'wppugmill_ai_provider', 'anthropic' );
	$api_key  = wppugmill_get_encrypted_option( 'wppugmill_ai_api_key', '' );

	if ( empty( $api_key ) ) {
		wp_send_json_error( array( 'message' => __( 'No AI API key configured. Add one under Settings → AI Provider.', 'wp-pugmill' ) ), 400 );
	}

	// Build clean plain-text content from saved post — strip block delimiters then HTML.
	$content = preg_replace( '/<!--[\s\S]*?-->/', ' ', $post->post_content );
	$content = wp_strip_all_tags( $content );
	$content = preg_replace( '/\s+/', ' ', trim( $content ) );
	$content = mb_substr( $content, 0, WPPUGMILL_MAX_AI_INPUT );

	if ( empty( $content ) ) {
		wp_send_json_error( array( 'message' => __( 'Post has no content to analyze.', 'wp-pugmill' ) ), 400 );
	}

	$title = get_the_title( $post_id );

	$raw = wppugmill_call_ai(
		$provider,
		$api_key,
		wppugmill_aeo_system_prompt(),
		wppugmill_aeo_user_prompt( $title, $content ),
		2048
	);

	if ( is_wp_error( $raw ) ) {
		wp_send_json_error( array( 'message' => $raw->get_error_message() ), 500 );
	}

	$aeo = wppugmill_decode_ai_json( $raw, $provider );
	if ( is_wp_error( $aeo ) ) {
		wp_send_json_error( array( 'message' => $aeo->get_error_message() ), 500 );
	}

	// Sanitize — identical rules to the per-post editor handler in ai.php.
	$allowed_types = array( 'Thing', 'Person', 'Organization', 'Product', 'Place', 'Event', 'Technology', 'DefinedTerm' );

	$clean = array(
		'summary'   => sanitize_textarea_field( $aeo['summary'] ?? '' ),
		'questions' => array_values( array_filter(
			array_map(
				function( $qa ) {
					return array(
						'q' => sanitize_text_field( $qa['q'] ?? '' ),
						'a' => sanitize_textarea_field( $qa['a'] ?? '' ),
					);
				},
				is_array( $aeo['questions'] ?? null ) ? $aeo['questions'] : array()
			),
			function( $qa ) { return ! empty( $qa['q'] ) && ! empty( $qa['a'] ); }
		) ),
		'entities'  => array_values( array_filter(
			array_map(
				function( $entity ) use ( $allowed_types ) {
					$type   = sanitize_text_field( $entity['type'] ?? 'Thing' );
					$mapped = array(
						'name'        => sanitize_text_field( $entity['name'] ?? '' ),
						'type'        => in_array( $type, $allowed_types, true ) ? $type : 'Thing',
						'description' => sanitize_text_field( $entity['description'] ?? '' ),
					);
					if ( function_exists( 'wppugmill_validate_same_as_url' ) ) {
						$same_as = wppugmill_validate_same_as_url( $entity['same_as'] ?? '' );
						if ( $same_as ) {
							$mapped['same_as'] = $same_as;
						}
					}
					return $mapped;
				},
				is_array( $aeo['entities'] ?? null ) ? $aeo['entities'] : array()
			),
			function( $e ) { return ! empty( $e['name'] ); }
		) ),
		'keywords'  => array_values( array_filter(
			array_map(
				'sanitize_text_field',
				is_array( $aeo['keywords'] ?? null ) ? $aeo['keywords'] : array()
			)
		) ),
	);

	// Save directly to post meta — no editor state involved.
	wppugmill_save_aeo( $post_id, $clean );

	wp_send_json_success( array(
		'post_title' => esc_html( $title ),
		'summary'    => esc_html( mb_substr( $clean['summary'], 0, 120 ) ),
	) );
}
