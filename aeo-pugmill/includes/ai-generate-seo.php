<?php
/**
 * AEO Pugmill — SEO and schema generation AJAX handlers.
 *
 * Covers:
 *   - aeopugmill_ajax_generate_seo()         — SEO title + meta description
 *   - aeopugmill_ajax_generate_howto_steps() — HowTo schema steps
 *   - aeopugmill_ajax_suggest_schema()        — detect Schema.org type + pre-fill fields
 *
 * Depends on: ai-client.php (aeopugmill_ai_request_setup, aeopugmill_call_ai),
 *             ai-utils.php (aeopugmill_decode_ai_json),
 *             ai-content.php (aeopugmill_voice_clause)
 *
 * @package WPPugmill
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// =========================================================================
// 9. SEO Fields Generator
// =========================================================================

add_action( 'wp_ajax_aeopugmill_generate_seo', 'aeopugmill_ajax_generate_seo' );

/**
 * AJAX handler — generate SEO title and meta description for a post.
 *
 * Returns: { title: string, meta_desc: string }
 */
function aeopugmill_ajax_generate_seo() {
	$r = aeopugmill_ai_request_setup( 'aeopugmill_generate_seo', 'SEO Generator', false );
	if ( empty( trim( $r['content'] ) ) ) {
		wp_send_json_error( array( 'message' => __( 'Post has no content to analyze.', 'aeo-pugmill' ) ), 400 );
	}

	// Pull existing AEO summary to improve meta description quality
	$aeo_meta = get_post_meta( $r['post']->ID, '_aeopugmill_aeo', true );
	$summary  = is_array( $aeo_meta ) && ! empty( $aeo_meta['summary'] ) ? $aeo_meta['summary'] : '';

	$system = 'You are an expert SEO copywriter. Generate an SEO title and meta description for the given content.

Rules:
- title: 40–60 characters, no brand suffix needed, front-load the primary keyword, compelling but not clickbait
- meta_desc: 120–155 characters, plain text only (no quotes or HTML), includes a natural call to action, does not repeat the title word for word

Return ONLY a JSON object: {"title":"...","meta_desc":"..."}. No markdown, no explanation outside the JSON.'
		. aeopugmill_voice_clause();

	$aeo_clause = $summary ? "\n\nAEO Summary (use as context): {$summary}" : '';
	$user       = "Post title: \"{$r['title']}\"\n\nPost content:\n{$r['content']}{$aeo_clause}";

	$result = aeopugmill_call_ai( $r['provider'], $r['api_key'], $system, $user, 200 );

	if ( is_wp_error( $result ) ) {
		wp_send_json_error( array( 'message' => $result->get_error_message() ), 500 );
	}

	$decoded = aeopugmill_decode_ai_json( $result, $r['provider'] );
	if ( is_wp_error( $decoded ) ) {
		wp_send_json_error( array( 'message' => $decoded->get_error_message() ), 500 );
	}

	$title     = sanitize_text_field( $decoded['title']     ?? '' );
	$meta_desc = sanitize_text_field( $decoded['meta_desc'] ?? '' );

	if ( empty( $title ) || empty( $meta_desc ) ) {
		wp_send_json_error( array( 'message' => __( 'AI returned an incomplete response. Please try again.', 'aeo-pugmill' ) ), 500 );
	}

	wp_send_json_success( array(
		'title'     => mb_substr( $title,     0, 80 ),
		'meta_desc' => mb_substr( $meta_desc, 0, 200 ),
	) );
}

// =========================================================================
// 10. HowTo Steps Generator
// =========================================================================

add_action( 'wp_ajax_aeopugmill_generate_howto_steps', 'aeopugmill_ajax_generate_howto_steps' );

/**
 * AJAX handler — draft a HowTo schema description and steps from post content.
 *
 * Returns: { description: string, steps: [{name: string, text: string}] }
 */
function aeopugmill_ajax_generate_howto_steps() {
	$r = aeopugmill_ai_request_setup( 'aeopugmill_generate_howto_steps', 'HowTo Steps Generator' );
	if ( empty( trim( $r['content'] ) ) ) {
		wp_send_json_error( array( 'message' => __( 'Post has no content to analyze.', 'aeo-pugmill' ) ), 400 );
	}

	$system = 'You are a structured-data specialist. Extract a HowTo schema from the given post content.

Rules:
- description: one sentence summarizing what the reader will accomplish (max 160 characters)
- steps: extract every distinct action step from the content in order
  - name: 2-5 word step title (e.g. "Preheat the oven", "Install the plugin")
  - text: 1-3 sentence instruction. Plain text only — no HTML, no markdown.
  - Minimum 2 steps, maximum 15 steps
  - Only include steps that are explicitly described in the content — do not invent steps
- Return ONLY a JSON object: {"description":"...","steps":[{"name":"...","text":"..."}]}
- No markdown fences, no explanation outside the JSON.'
		. aeopugmill_voice_clause();

	$user   = "Post title: \"{$r['title']}\"\n\nPost content:\n{$r['content']}";
	$result = aeopugmill_call_ai( $r['provider'], $r['api_key'], $system, $user, 800 );

	if ( is_wp_error( $result ) ) {
		wp_send_json_error( array( 'message' => $result->get_error_message() ), 500 );
	}

	$decoded = aeopugmill_decode_ai_json( $result, $r['provider'] );
	if ( is_wp_error( $decoded ) ) {
		wp_send_json_error( array( 'message' => $decoded->get_error_message() ), 500 );
	}

	$description = sanitize_text_field( $decoded['description'] ?? '' );
	$raw_steps   = is_array( $decoded['steps'] ?? null ) ? $decoded['steps'] : array();

	if ( empty( $raw_steps ) ) {
		wp_send_json_error( array( 'message' => __( 'AI could not identify steps in this content. Make sure the post describes a process.', 'aeo-pugmill' ) ), 400 );
	}

	$steps = array_values( array_filter(
		array_map( function( $step ) {
			return array(
				'name' => sanitize_text_field( $step['name'] ?? '' ),
				'text' => sanitize_textarea_field( $step['text'] ?? '' ),
			);
		}, $raw_steps ),
		function( $step ) { return ! empty( $step['text'] ); }
	) );

	wp_send_json_success( array(
		'description' => mb_substr( $description, 0, 200 ),
		'steps'       => $steps,
	) );
}

// =========================================================================
// Schema suggestion — detect schema type + pre-fill fields from post content
// =========================================================================

add_action( 'wp_ajax_aeopugmill_suggest_schema', 'aeopugmill_ajax_suggest_schema' );

/**
 * AJAX handler — analyze post content and suggest the best Schema.org type
 * plus pre-filled field values.
 */
function aeopugmill_ajax_suggest_schema() {
	check_ajax_referer( 'aeopugmill_suggest_schema', 'nonce' );

	if ( ! current_user_can( 'edit_posts' ) ) {
		wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'aeo-pugmill' ) ), 403 );
	}

	$rate_check = aeopugmill_check_rate_limit();
	if ( is_wp_error( $rate_check ) ) {
		wp_send_json_error( array( 'message' => $rate_check->get_error_message() ), 429 );
	}

	$post_id = absint( wp_unslash( $_POST['post_id'] ?? 0 ) );
	if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) ) {
		wp_send_json_error( array( 'message' => __( 'Invalid post.', 'aeo-pugmill' ) ), 400 );
	}

	$post = get_post( $post_id );
	if ( ! $post ) {
		wp_send_json_error( array( 'message' => __( 'Post not found.', 'aeo-pugmill' ) ), 404 );
	}

	$mode = aeopugmill_mode();
	if ( 'free' === $mode ) {
		wp_send_json_error( array(
			'message' => __( 'Schema suggestions require a AEO Pugmill AEO Pugmill Pro license.', 'aeo-pugmill' ),
		), 403 );
	}

	$provider = get_option( 'aeopugmill_ai_provider', 'anthropic' );
	$api_key  = aeopugmill_get_encrypted_option( 'aeopugmill_ai_api_key', '' );
	if ( empty( $api_key ) ) {
		wp_send_json_error( array( 'message' => __( 'No API key configured.', 'aeo-pugmill' ) ), 400 );
	}

	$title   = get_the_title( $post );
	$content = wp_strip_all_tags( apply_filters( 'the_content', $post->post_content ) );
	$content = html_entity_decode( $content, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	$content = mb_substr( $content, 0, AEOPUGMILL_MAX_AI_INPUT );

	if ( empty( trim( $content ) ) ) {
		wp_send_json_error( array( 'message' => __( 'Post has no content to analyze.', 'aeo-pugmill' ) ), 400 );
	}

	$system = 'You are a Schema.org structured data specialist. Analyze the post title and content to determine the single best additional Schema.org type — if any. Only choose a specific type if the content clearly warrants it. If it is a general article or blog post, return {"type":""}.

Available types and their exact field keys:
- "HowTo":         {"description":"string","total_time":"ISO 8601 e.g. PT30M","steps":[{"name":"string","text":"string"}]}
- "Product":       {"name":"string","description":"string","brand":"string","price":"string","currency":"USD","availability":"InStock|OutOfStock|PreOrder"}
- "Event":         {"name":"string","description":"string","start_date":"YYYY-MM-DDTHH:MM","end_date":"YYYY-MM-DDTHH:MM","location_name":"string","location_address":"string","organizer":"string"}
- "LocalBusiness": {"name":"string","description":"string","address":"string","phone":"string","hours":"string e.g. Mo-Fr 09:00-17:00","price_range":"string","business_type":"LocalBusiness"}
- "VideoObject":   {"name":"string","description":"string","upload_date":"YYYY-MM-DD","duration":"ISO 8601 e.g. PT5M30S","thumbnail_url":"string","embed_url":"string"}

Return ONLY a JSON object with key "type" and, when a type is chosen, a second key matching the lowercase type name (e.g. "howto", "product"). Populate only fields you can infer from the content — leave others as empty strings. No markdown, no explanation.';

	$user_prompt = "Title: {$title}\n\nContent:\n{$content}";

	$result = aeopugmill_call_ai( $provider, $api_key, $system, $user_prompt, 800 );

	if ( is_wp_error( $result ) ) {
		wp_send_json_error( array( 'message' => $result->get_error_message() ), 500 );
	}

	// Validate type
	$decoded = aeopugmill_decode_ai_json( $result, $provider );
	if ( is_wp_error( $decoded ) ) {
		wp_send_json_error( array( 'message' => $decoded->get_error_message() ), 500 );
	}

	$allowed = array( '', 'HowTo', 'Product', 'Event', 'LocalBusiness', 'VideoObject' );
	$type    = sanitize_text_field( $decoded['type'] ?? '' );
	if ( ! in_array( $type, $allowed, true ) ) {
		$type = '';
	}

	$sanitized = array( 'type' => $type );

	if ( 'HowTo' === $type && isset( $decoded['howto'] ) ) {
		$h = $decoded['howto'];
		$sanitized['howto'] = array(
			'description' => sanitize_textarea_field( $h['description'] ?? '' ),
			'total_time'  => sanitize_text_field( $h['total_time'] ?? '' ),
			'steps'       => array_values( array_filter(
				array_map( function( $s ) {
					return array(
						'name' => sanitize_text_field( $s['name'] ?? '' ),
						'text' => sanitize_textarea_field( $s['text'] ?? '' ),
					);
				}, is_array( $h['steps'] ?? null ) ? $h['steps'] : array() ),
				function( $s ) { return ! empty( $s['text'] ); }
			) ),
		);
	} elseif ( 'Product' === $type && isset( $decoded['product'] ) ) {
		$p     = $decoded['product'];
		$avail = sanitize_text_field( $p['availability'] ?? 'InStock' );
		if ( ! in_array( $avail, array( 'InStock', 'OutOfStock', 'PreOrder' ), true ) ) {
			$avail = 'InStock';
		}
		$sanitized['product'] = array(
			'name'         => sanitize_text_field( $p['name'] ?? '' ),
			'description'  => sanitize_textarea_field( $p['description'] ?? '' ),
			'brand'        => sanitize_text_field( $p['brand'] ?? '' ),
			'price'        => sanitize_text_field( $p['price'] ?? '' ),
			'currency'     => strtoupper( sanitize_text_field( $p['currency'] ?? 'USD' ) ),
			'availability' => $avail,
		);
	} elseif ( 'Event' === $type && isset( $decoded['event'] ) ) {
		$e = $decoded['event'];
		$sanitized['event'] = array(
			'name'             => sanitize_text_field( $e['name'] ?? '' ),
			'description'      => sanitize_textarea_field( $e['description'] ?? '' ),
			'start_date'       => sanitize_text_field( $e['start_date'] ?? '' ),
			'end_date'         => sanitize_text_field( $e['end_date'] ?? '' ),
			'location_name'    => sanitize_text_field( $e['location_name'] ?? '' ),
			'location_address' => sanitize_text_field( $e['location_address'] ?? '' ),
			'organizer'        => sanitize_text_field( $e['organizer'] ?? '' ),
		);
	} elseif ( 'LocalBusiness' === $type && isset( $decoded['localbusiness'] ) ) {
		$b = $decoded['localbusiness'];
		$sanitized['local_business'] = array(
			'name'          => sanitize_text_field( $b['name'] ?? '' ),
			'description'   => sanitize_textarea_field( $b['description'] ?? '' ),
			'address'       => sanitize_text_field( $b['address'] ?? '' ),
			'phone'         => sanitize_text_field( $b['phone'] ?? '' ),
			'hours'         => sanitize_text_field( $b['hours'] ?? '' ),
			'price_range'   => sanitize_text_field( $b['price_range'] ?? '' ),
			'business_type' => sanitize_text_field( $b['business_type'] ?? 'LocalBusiness' ),
		);
	} elseif ( 'VideoObject' === $type && isset( $decoded['videoobject'] ) ) {
		$v = $decoded['videoobject'];
		$sanitized['video'] = array(
			'name'          => sanitize_text_field( $v['name'] ?? '' ),
			'description'   => sanitize_textarea_field( $v['description'] ?? '' ),
			'upload_date'   => sanitize_text_field( $v['upload_date'] ?? '' ),
			'duration'      => sanitize_text_field( $v['duration'] ?? '' ),
			'thumbnail_url' => esc_url_raw( $v['thumbnail_url'] ?? '' ),
			'embed_url'     => esc_url_raw( $v['embed_url'] ?? '' ),
		);
	}

	wp_send_json_success( $sanitized );
}
