<?php
/**
 * AEO Pugmill - Content refinement AI AJAX handlers.
 *
 * Covers:
 *   - aeopugmill_voice_clause()           - shared voice helper used across all AI features
 *   - aeopugmill_ajax_tone_check()        - Tone Check handler
 *   - aeopugmill_ajax_reading_level()     - Reading Level handler
 *   - aeopugmill_ajax_simplify_draft()    - Simplify Draft handler
 *   - aeopugmill_ajax_headline_variants() - Headline Variants handler
 *   - aeopugmill_ajax_generate_image_alt() - Featured image alt text via AI vision
 *
 * Depends on: ai-client.php (aeopugmill_ai_request_setup, aeopugmill_call_ai,
 *             aeopugmill_call_ai_vision, aeopugmill_ai_request_args),
 *             ai-utils.php (aeopugmill_decode_ai_json)
 *
 * @package WPPugmill
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// ── Shared voice helper ────────────────────────────────────────────────────

/**
 * Return the author voice clause to append to AI system prompts.
 *
 * Reads the aeopugmill_author_voice option set in Settings → AEO Pugmill.
 * Returns a formatted instruction block when a voice guide is configured,
 * or a generic tone fallback when none is set.
 *
 * @return string Voice clause string, ready to concatenate onto a system prompt.
 */
function aeopugmill_voice_clause() {
	$voice = get_option( 'aeopugmill_author_voice', '' );
	if ( $voice ) {
		return "\n\nAuthor voice and style guide — you MUST follow this when writing any text fields:\n" . $voice;
	}
	return "\n\nMaintain a clear, engaging, and professional tone throughout.";
}

// =========================================================================
// Tone Check — AJAX handler + prompts + provider calls + parser
//
// 3rd-party AI service disclosure:
// The handler below sends post title and body text to an external AI API
// (Anthropic, OpenAI, or Google Gemini) selected and configured by the site
// administrator. No visitor data is transmitted. External services are fully
// disclosed in the plugin's readme.txt "External Services" section.
// =========================================================================

add_action( 'wp_ajax_aeopugmill_tone_check', 'aeopugmill_ajax_tone_check' );

/**
 * AJAX handler — check post content tone against the Author Voice guide.
 */
function aeopugmill_ajax_tone_check() {
	check_ajax_referer( 'aeopugmill_tone_check', 'nonce' );

	if ( ! current_user_can( 'edit_posts' ) ) {
		wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'aeo-pugmill' ) ), 403 );
	}

	$rate_check = aeopugmill_check_rate_limit();
	if ( is_wp_error( $rate_check ) ) {
		wp_send_json_error( array( 'message' => $rate_check->get_error_message() ), 429 );
	}

	$post_id = absint( wp_unslash( $_POST['post_id'] ?? 0 ) );
	if ( ! $post_id ) {
		wp_send_json_error( array( 'message' => __( 'Missing post ID.', 'aeo-pugmill' ) ), 400 );
	}

	if ( ! current_user_can( 'edit_post', $post_id ) ) {
		wp_send_json_error( array( 'message' => __( 'You do not have permission to edit this post.', 'aeo-pugmill' ) ), 403 );
	}

	$post = get_post( $post_id );
	if ( ! $post ) {
		wp_send_json_error( array( 'message' => __( 'Post not found.', 'aeo-pugmill' ) ), 404 );
	}

	$mode = aeopugmill_mode();
	if ( 'free' === $mode ) {
		wp_send_json_error( array(
			'message' => __( 'Tone Check requires a AEO Pugmill Pro license. <a href="https://aeopugmill.com/pricing" target="_blank">Get your license →</a>', 'aeo-pugmill' ),
		), 403 );
	}

	if ( 'pro' === $mode ) {
		wp_send_json_error( array( 'message' => __( 'Pro mode coming soon.', 'aeo-pugmill' ) ), 501 );
	}

	$provider = get_option( 'aeopugmill_ai_provider', 'anthropic' );
	$api_key  = aeopugmill_get_encrypted_option( 'aeopugmill_ai_api_key', '' );

	if ( empty( $api_key ) ) {
		wp_send_json_error( array( 'message' => __( 'No API key configured. Add your key in Settings → AEO Pugmill.', 'aeo-pugmill' ) ), 400 );
	}

	$title = get_the_title( $post );
	if ( ! empty( $_POST['draft_content'] ) ) {
		$content = wp_strip_all_tags( sanitize_textarea_field( wp_unslash( $_POST['draft_content'] ) ) );
	} else {
		$content = wp_strip_all_tags( apply_filters( 'the_content', $post->post_content ) );
		$content = html_entity_decode( $content, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	}
	$content = mb_substr( $content, 0, AEOPUGMILL_MAX_AI_INPUT );

	if ( empty( trim( $content ) ) ) {
		wp_send_json_error( array( 'message' => __( 'Post has no content to analyze. Add some content and try again.', 'aeo-pugmill' ) ), 400 );
	}

	switch ( $provider ) {
		case 'openai':
			$result = aeopugmill_tone_via_openai( $api_key, $title, $content );
			break;
		case 'gemini':
			$result = aeopugmill_tone_via_gemini( $api_key, $title, $content );
			break;
		case 'anthropic':
		default:
			$result = aeopugmill_tone_via_anthropic( $api_key, $title, $content );
			break;
	}

	if ( is_wp_error( $result ) ) {
		wp_send_json_error( array( 'message' => $result->get_error_message() ), 500 );
	}

	wp_send_json_success( $result );
}

/**
 * System prompt for Tone Check.
 *
 * Instructs the AI to compare the post against the Author Voice guide and
 * return specific passages with issues and suggested rewrites.
 *
 * 3rd-party AI service disclosure:
 * This string is transmitted as the system prompt to the configured external
 * AI provider. It contains no user or visitor data.
 */
function aeopugmill_tone_system_prompt() {
	return 'You are a tone and style editor. Analyse the provided post content against the Author Voice guide. Identify passages where the tone, vocabulary, or style deviates from the guide.

Return ONLY a JSON array of objects with exactly these fields:
[{"quote":"exact passage from the content (20-120 characters)","issue":"one sentence describing the tone problem","suggestion":"rewritten version of the passage that matches the voice guide"}]

Rules:
- quote: must be an exact verbatim substring of the provided content, 20-120 characters
- issue: one concise sentence describing the specific tone or style problem
- suggestion: a rewrite of the quote that matches the voice guide
- Return an empty array [] if the content already matches the guide well
- Return 0-6 items maximum — only the most significant deviations
- No markdown fences, no explanation outside the JSON array'
		. aeopugmill_voice_clause();
}

/**
 * User prompt for Tone Check.
 *
 * 3rd-party AI service disclosure:
 * The $title and $content values (post title and body text, admin-authored)
 * are sent to the external AI provider. No visitor data is included.
 */
function aeopugmill_tone_user_prompt( $title, $content ) {
	return "Title: {$title}\n\nContent:\n{$content}";
}

function aeopugmill_tone_via_anthropic( $api_key, $title, $content ) {
	$response = wp_remote_post(
		'https://api.anthropic.com/v1/messages',
		aeopugmill_ai_request_args(
			array(
				'Content-Type'      => 'application/json',
				'x-api-key'         => $api_key,
				'anthropic-version' => '2023-06-01',
			),
			wp_json_encode( array(
				'model'      => AEOPUGMILL_ANTHROPIC_MODEL,
				'max_tokens' => 1024,
				'system'     => aeopugmill_tone_system_prompt(),
				'messages'   => array(
					array( 'role' => 'user', 'content' => aeopugmill_tone_user_prompt( $title, $content ) ),
				),
			) )
		)
	);
	return aeopugmill_parse_tone_response( $response, 'anthropic' );
}

function aeopugmill_tone_via_openai( $api_key, $title, $content ) {
	$response = wp_remote_post(
		'https://api.openai.com/v1/chat/completions',
		aeopugmill_ai_request_args(
			array(
				'Content-Type'  => 'application/json',
				'Authorization' => 'Bearer ' . $api_key,
			),
			wp_json_encode( array(
				'model'    => 'gpt-4o',
				'messages' => array(
					array( 'role' => 'system', 'content' => aeopugmill_tone_system_prompt() ),
					array( 'role' => 'user',   'content' => aeopugmill_tone_user_prompt( $title, $content ) ),
				),
				'max_tokens'      => 1024,
				'response_format' => array( 'type' => 'json_object' ),
			) )
		)
	);
	return aeopugmill_parse_tone_response( $response, 'openai' );
}

function aeopugmill_tone_via_gemini( $api_key, $title, $content ) {
	$response = wp_remote_post(
		'https://generativelanguage.googleapis.com/v1beta/models/gemini-1.5-flash:generateContent',
		aeopugmill_ai_request_args(
			array(
				'Content-Type'   => 'application/json',
				'x-goog-api-key' => $api_key,
			),
			wp_json_encode( array(
				'contents' => array(
					array(
						'parts' => array(
							array( 'text' => aeopugmill_tone_system_prompt() . "\n\n" . aeopugmill_tone_user_prompt( $title, $content ) ),
						),
					),
				),
				'generationConfig' => array(
					'response_mime_type' => 'application/json',
					'max_output_tokens'  => 1024,
				),
			) )
		)
	);
	return aeopugmill_parse_tone_response( $response, 'gemini' );
}

/**
 * Parse and sanitize the AI tone check response.
 *
 * Returns an array of sanitized { quote, issue, suggestion } objects,
 * or a WP_Error on failure.
 */
function aeopugmill_parse_tone_response( $response, $provider ) {
	if ( is_wp_error( $response ) ) {
		error_log( 'AEO Pugmill tone check error (' . $provider . '): ' . $response->get_error_message() );
		return new WP_Error( 'request_failed', __( 'Could not reach AI provider. Please check your connection and try again.', 'aeo-pugmill' ) );
	}

	$code = wp_remote_retrieve_response_code( $response );
	$body = wp_remote_retrieve_body( $response );
	$data = json_decode( $body, true );

	if ( 200 !== $code ) {
		$detail = $data['error']['message'] ?? $body;
		error_log( 'AEO Pugmill tone check provider error (' . $provider . ' ' . $code . '): ' . $detail );
		if ( 401 === $code ) return new WP_Error( 'provider_error', __( 'Invalid API key. Please check your key in Settings → AEO Pugmill.', 'aeo-pugmill' ) );
		if ( 429 === $code ) return new WP_Error( 'provider_error', __( 'AI provider rate limit reached. Please wait and try again.', 'aeo-pugmill' ) );
		if ( 402 === $code ) return new WP_Error( 'provider_error', __( 'Insufficient credits on your AI provider account. Please top up and try again.', 'aeo-pugmill' ) );
		return new WP_Error( 'provider_error', __( 'AI provider returned an error. Please try again.', 'aeo-pugmill' ) );
	}

	$raw_text = '';
	switch ( $provider ) {
		case 'anthropic': $raw_text = $data['content'][0]['text'] ?? ''; break;
		case 'openai':    $raw_text = $data['choices'][0]['message']['content'] ?? ''; break;
		case 'gemini':    $raw_text = $data['candidates'][0]['content']['parts'][0]['text'] ?? ''; break;
	}

	if ( empty( $raw_text ) ) {
		return new WP_Error( 'empty_response', __( 'AI returned an empty response. Please try again.', 'aeo-pugmill' ) );
	}

	$raw_text = aeopugmill_strip_ai_json_fences( $raw_text );

	// OpenAI json_object mode may wrap the array in an object key
	$decoded = json_decode( $raw_text, true );
	if ( is_array( $decoded ) && ! isset( $decoded[0] ) ) {
		// Unwrap first value if it's a keyed object containing the array
		$decoded = array_values( $decoded )[0] ?? $decoded;
	}

	if ( ! is_array( $decoded ) ) {
		error_log( 'AEO Pugmill: tone check invalid JSON from ' . $provider . ': ' . substr( $raw_text, 0, 200 ) );
		return new WP_Error( 'invalid_json', __( 'AI returned an unexpected response format. Please try again.', 'aeo-pugmill' ) );
	}

	return array(
		'items' => array_values( array_filter(
			array_map( function( $item ) {
				return array(
					'quote'      => sanitize_text_field( $item['quote']      ?? '' ),
					'issue'      => sanitize_text_field( $item['issue']      ?? '' ),
					'suggestion' => sanitize_text_field( $item['suggestion'] ?? '' ),
				);
			}, $decoded ),
			function( $item ) {
				return ! empty( $item['quote'] ) && ! empty( $item['issue'] ) && ! empty( $item['suggestion'] );
			}
		) ),
	);
}

// =========================================================================
// 1. Reading Level
// =========================================================================

add_action( 'wp_ajax_aeopugmill_reading_level', 'aeopugmill_ajax_reading_level' );

function aeopugmill_ajax_reading_level() {
	$r = aeopugmill_ai_request_setup( 'aeopugmill_reading_level', 'Reading Level' );
	if ( empty( trim( $r['content'] ) ) ) {
		wp_send_json_error( array( 'message' => __( 'Post has no content to analyze.', 'aeo-pugmill' ) ), 400 );
	}

	$system = 'You are a readability expert. Analyse the reading level of the given post content. Return ONLY a JSON object: {"level":"e.g. General Audience / High School / College / Specialist","gradeLevel":number,"note":"one factual sentence on clarity and pace — describe what the reader experiences, no judgement"}.'
		. ( get_option( 'aeopugmill_author_voice', '' ) ? ' If an Author\'s Voice is provided, add a "fit" field that factually describes how the reading level compares to the voice guide — one of: "aligns with voice guide", "higher register than voice guide", "lower register than voice guide".' : '' )
		. ' No explanation outside the JSON.'
		. aeopugmill_voice_clause();

	$user   = "Post title: \"{$r['title']}\"\n\nPost content:\n{$r['content']}";
	$result = aeopugmill_call_ai( $r['provider'], $r['api_key'], $system, $user, 200 );

	if ( is_wp_error( $result ) ) {
		wp_send_json_error( array( 'message' => $result->get_error_message() ), 500 );
	}

	$decoded = aeopugmill_decode_ai_json( $result, $r['provider'] );
	if ( is_wp_error( $decoded ) ) {
		wp_send_json_error( array( 'message' => $decoded->get_error_message() ), 500 );
	}

	wp_send_json_success( array(
		'level'      => sanitize_text_field( $decoded['level']      ?? '' ),
		'gradeLevel' => absint( $decoded['gradeLevel']              ?? 0 ),
		'note'       => sanitize_text_field( $decoded['note']       ?? '' ),
		'fit'        => sanitize_text_field( $decoded['fit']        ?? '' ),
	) );
}

// =========================================================================
// 1b. Simplify Draft — rewrite for a target reading level
// =========================================================================

add_action( 'wp_ajax_aeopugmill_simplify_draft', 'aeopugmill_ajax_simplify_draft' );

/**
 * AJAX handler — rewrite post content to a target reading level.
 *
 * Accepts:
 *   target_level — e.g. "8th grade", "High school", "General audience", or custom text
 *   notes        — optional extra instruction (max 300 chars)
 *
 * Returns: { content: "HTML string" }
 */
function aeopugmill_ajax_simplify_draft() {
	$r = aeopugmill_ai_request_setup( 'aeopugmill_simplify_draft', 'Simplify Draft' );

	if ( empty( trim( $r['content'] ) ) ) {
		wp_send_json_error( array( 'message' => __( 'Post has no content to rewrite.', 'aeo-pugmill' ) ), 400 );
	}

	$target_level = sanitize_text_field( wp_unslash( $_POST['target_level'] ?? 'General audience' ) );
	$notes        = sanitize_textarea_field( wp_unslash( $_POST['notes'] ?? '' ) );
	$target_level = mb_substr( $target_level, 0, 100 );
	$notes        = mb_substr( $notes,        0, 300 );

	$notes_clause = $notes ? "\n\nAdditional instructions from the author: {$notes}" : '';

	$system = 'You are an expert editor. Rewrite the given post content to target a "' . $target_level . '" reading level.

Rules:
- Preserve ALL facts, information, and meaning — do not add, remove, or change any factual claims
- Preserve the document structure: keep headings, lists, and paragraph order intact
- Simplify vocabulary, sentence length, and phrasing to match the target level
- Break long or complex sentences into shorter, clearer ones
- Replace jargon with plain-language equivalents (keep proper nouns and defined terms)
- Return ONLY the rewritten content as valid HTML using <p>, <h2>, <h3>, <strong>, <em>, <ul>, <ol>, <li> tags
- No markdown fences, no explanation outside the HTML' . $notes_clause
		. aeopugmill_voice_clause();

	$user   = "Post title: \"{$r['title']}\"\n\nPost content:\n{$r['content']}";
	$result = aeopugmill_call_ai( $r['provider'], $r['api_key'], $system, $user, 3000 );

	if ( is_wp_error( $result ) ) {
		wp_send_json_error( array( 'message' => $result->get_error_message() ), 500 );
	}

	// Strip any markdown fences the AI might have added despite instructions.
	$html = trim( preg_replace( '/^```(?:html)?\s*/i', '', preg_replace( '/\s*```$/i', '', trim( $result ) ) ) );

	if ( empty( $html ) ) {
		wp_send_json_error( array( 'message' => __( 'AI returned an empty response. Please try again.', 'aeo-pugmill' ) ), 500 );
	}

	wp_send_json_success( array( 'content' => wp_kses_post( $html ) ) );
}

// =========================================================================
// 2. Headline Variants
// =========================================================================

add_action( 'wp_ajax_aeopugmill_headline_variants', 'aeopugmill_ajax_headline_variants' );

function aeopugmill_ajax_headline_variants() {
	$r = aeopugmill_ai_request_setup( 'aeopugmill_headline_variants', 'Headline Variants' );
	if ( empty( trim( $r['content'] ) ) ) {
		wp_send_json_error( array( 'message' => __( 'Post has no content to analyze.', 'aeo-pugmill' ) ), 400 );
	}

	$system = 'You are a copywriting expert. Generate two headline variants for the given post: one curiosity-driven (creates intrigue, uses a knowledge gap) and one utility-driven (clearly states the benefit or outcome). Return ONLY a JSON object: {"curiosity":"...","utility":"..."}. No explanation.'
		. aeopugmill_voice_clause();

	$user   = "Post title: \"{$r['title']}\"\n\nPost content:\n{$r['content']}";
	$result = aeopugmill_call_ai( $r['provider'], $r['api_key'], $system, $user, 200 );

	if ( is_wp_error( $result ) ) {
		wp_send_json_error( array( 'message' => $result->get_error_message() ), 500 );
	}

	$decoded = aeopugmill_decode_ai_json( $result, $r['provider'] );
	if ( is_wp_error( $decoded ) ) {
		wp_send_json_error( array( 'message' => $decoded->get_error_message() ), 500 );
	}

	wp_send_json_success( array(
		'curiosity' => sanitize_text_field( $decoded['curiosity'] ?? '' ),
		'utility'   => sanitize_text_field( $decoded['utility']   ?? '' ),
	) );
}


// =========================================================================
// Featured image alt text — AI vision AJAX handler
// =========================================================================

/**
 * Generate alt text for the post's featured image using AI vision.
 *
 * Sends the featured image URL to the configured AI provider, receives a
 * concise description, and saves it directly to _wp_attachment_image_alt
 * on the attachment. Works for Anthropic (URL source), OpenAI (image_url),
 * and Gemini (base64 inline_data).
 */
function aeopugmill_ajax_generate_image_alt() {
	check_ajax_referer( 'aeopugmill_generate_image_alt', 'nonce' );

	if ( ! current_user_can( 'edit_posts' ) ) {
		wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'aeo-pugmill' ) ), 403 );
	}

	$rate_check = aeopugmill_check_rate_limit();
	if ( is_wp_error( $rate_check ) ) {
		wp_send_json_error( array( 'message' => $rate_check->get_error_message() ), 429 );
	}

	$mode = aeopugmill_mode();
	if ( 'free' === $mode ) {
		wp_send_json_error( array(
			'message' => __( 'AI alt text generation requires a AEO Pugmill Pro license. <a href="https://aeopugmill.com/pricing" target="_blank">Get your license →</a>', 'aeo-pugmill' ),
		), 403 );
	}
	if ( 'pro' === $mode ) {
		wp_send_json_error( array( 'message' => __( 'Pro mode coming soon.', 'aeo-pugmill' ) ), 501 );
	}

	$post_id = absint( wp_unslash( $_POST['post_id'] ?? 0 ) );
	if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) ) {
		wp_send_json_error( array( 'message' => __( 'Invalid post.', 'aeo-pugmill' ) ), 403 );
	}

	// Resolve the image URL from one of three sources (in priority order):
	//   1. attachment_id — media-library image passed directly from the editor
	//   2. image_url     — raw URL for external/inline images (not in the media library)
	//   3. post featured image (saved state)
	$attachment_id = absint( wp_unslash( $_POST['attachment_id'] ?? 0 ) );
	$raw_image_url = isset( $_POST['image_url'] ) ? esc_url_raw( wp_unslash( $_POST['image_url'] ) ) : '';
	$thumbnail_id  = 0;

	if ( $attachment_id ) {
		if ( 'attachment' !== get_post_type( $attachment_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid attachment.', 'aeo-pugmill' ) ), 400 );
		}
		$thumbnail_id = $attachment_id;
		$image_url    = wp_get_attachment_image_url( $thumbnail_id, 'large' );
		if ( ! $image_url ) {
			wp_send_json_error( array( 'message' => __( 'Could not get image URL.', 'aeo-pugmill' ) ), 400 );
		}
	} elseif ( $raw_image_url ) {
		// External image URL — validate it looks like an image.
		$parsed = wp_parse_url( $raw_image_url );
		if ( empty( $parsed['scheme'] ) || ! in_array( $parsed['scheme'], array( 'http', 'https' ), true ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid image URL.', 'aeo-pugmill' ) ), 400 );
		}
		$image_url = $raw_image_url;
	} else {
		$thumbnail_id = get_post_thumbnail_id( $post_id );
		if ( ! $thumbnail_id ) {
			wp_send_json_error( array( 'message' => __( 'No featured image found.', 'aeo-pugmill' ) ), 400 );
		}
		$image_url = wp_get_attachment_image_url( $thumbnail_id, 'large' );
		if ( ! $image_url ) {
			wp_send_json_error( array( 'message' => __( 'Could not get image URL.', 'aeo-pugmill' ) ), 400 );
		}
	}

	$api_key = aeopugmill_get_encrypted_option( 'aeopugmill_ai_api_key', '' );
	if ( empty( $api_key ) ) {
		wp_send_json_error( array( 'message' => __( 'No API key configured. Add your key in Settings → AEO Pugmill.', 'aeo-pugmill' ) ), 400 );
	}

	$provider = get_option( 'aeopugmill_ai_provider', 'anthropic' );
	$title    = get_the_title( $post_id );

	$prompt = sprintf(
		'Write concise alt text for this image from a blog post titled "%s". '
		. 'Requirements: under 125 characters, describe what is visually present, suitable for screen readers and AI engines, '
		. 'do not start with "Image of" or "Photo of". Reply with the alt text only — no quotes, no explanation.',
		$title
	);

	$result = aeopugmill_call_ai_vision( $provider, $api_key, $image_url, $prompt, 60 );

	if ( is_wp_error( $result ) ) {
		wp_send_json_error( array( 'message' => $result->get_error_message() ), 500 );
	}

	// Strip any surrounding quotes the model may have added.
	$alt_text = trim( $result, " \t\n\r\0\x0B\"'" );
	$alt_text = sanitize_text_field( $alt_text );

	// Save to media library only when we have a real attachment.
	if ( $thumbnail_id ) {
		update_post_meta( $thumbnail_id, '_wp_attachment_image_alt', $alt_text );
	}

	wp_send_json_success( array( 'alt_text' => $alt_text ) );
}
add_action( 'wp_ajax_aeopugmill_generate_image_alt', 'aeopugmill_ajax_generate_image_alt' );
