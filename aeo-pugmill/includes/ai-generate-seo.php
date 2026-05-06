<?php
/**
 * Pugmill AEO Toolkit — SEO and schema generation AJAX handlers.
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

// aeopugmill_ajax_suggest_schema() moved to Pugmill AEO Toolkit Pro (includes/ai-generate-seo.php).
