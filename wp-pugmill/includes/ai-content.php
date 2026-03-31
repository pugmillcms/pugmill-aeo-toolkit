<?php
/**
 * WP Pugmill — Content rewriting and editing AI AJAX handlers.
 *
 * Covers:
 *   - wppugmill_ajax_rewrite_draft()   — Write from Draft (Answer Unit rewrite)
 *   - wppugmill_voice_clause()          — shared voice helper used across all AI features
 *   - wppugmill_draft_system_prompt()   — Write from Draft system prompt
 *   - wppugmill_draft_user_prompt()     — Write from Draft user prompt
 *   - wppugmill_rewrite_via_anthropic() — provider-specific draft rewrite
 *   - wppugmill_rewrite_via_openai()    — provider-specific draft rewrite
 *   - wppugmill_rewrite_via_gemini()    — provider-specific draft rewrite
 *   - wppugmill_parse_draft_response()  — draft response parser
 *   - wppugmill_ajax_tone_check()       — Tone Check handler
 *   - wppugmill_tone_system_prompt()    — Tone Check system prompt
 *   - wppugmill_tone_user_prompt()      — Tone Check user prompt
 *   - wppugmill_tone_via_anthropic()    — provider-specific tone check
 *   - wppugmill_tone_via_openai()       — provider-specific tone check
 *   - wppugmill_tone_via_gemini()       — provider-specific tone check
 *   - wppugmill_parse_tone_response()   — tone check response parser
 *   - wppugmill_ajax_reading_level()    — Reading Level handler
 *   - wppugmill_ajax_headline_variants() — Headline Variants handler
 *   - wppugmill_ajax_generate_image_alt() — Featured image alt text via AI vision
 *
 * Depends on: ai-client.php (wppugmill_ai_request_setup, wppugmill_call_ai,
 *             wppugmill_call_ai_vision, wppugmill_ai_request_args),
 *             ai-utils.php (wppugmill_decode_ai_json)
 *
 * @package WPPugmill
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// =========================================================================
// Write from Draft — AJAX handler + prompts + provider calls + parser
//
// 3rd-party AI service disclosure:
// The handler below sends post title and draft body text to an external AI
// API (Anthropic, OpenAI, or Google Gemini) selected and configured by the
// site administrator via the BYOK (Bring Your Own Key) settings page.
// No visitor data is transmitted. The external services are fully disclosed
// in the plugin's readme.txt "External Services" section.
// =========================================================================

add_action( 'wp_ajax_wppugmill_rewrite_draft', 'wppugmill_ajax_rewrite_draft' );

/**
 * AJAX handler — rewrite a draft post into AEO Answer Unit structure.
 *
 * Accepts an optional `draft_content` parameter from Gutenberg (unsaved
 * editor content). Falls back to the saved post_content if absent —
 * preserving compatibility with the classic editor flow.
 */
function wppugmill_ajax_rewrite_draft() {
	check_ajax_referer( 'wppugmill_rewrite_draft', 'nonce' );

	if ( ! current_user_can( 'edit_posts' ) ) {
		wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'wp-pugmill' ) ), 403 );
	}

	$rate_check = wppugmill_check_rate_limit();
	if ( is_wp_error( $rate_check ) ) {
		wp_send_json_error( array( 'message' => $rate_check->get_error_message() ), 429 );
	}

	$post_id = absint( wp_unslash( $_POST['post_id'] ?? 0 ) );
	if ( ! $post_id ) {
		wp_send_json_error( array( 'message' => __( 'Missing post ID.', 'wp-pugmill' ) ), 400 );
	}

	if ( ! current_user_can( 'edit_post', $post_id ) ) {
		wp_send_json_error( array( 'message' => __( 'You do not have permission to edit this post.', 'wp-pugmill' ) ), 403 );
	}

	$post = get_post( $post_id );
	if ( ! $post ) {
		wp_send_json_error( array( 'message' => __( 'Post not found.', 'wp-pugmill' ) ), 404 );
	}

	$mode = wppugmill_mode();
	if ( 'free' === $mode ) {
		wp_send_json_error( array(
			'message' => __( 'Rewrite from Draft requires a WP Pugmill AI Connector license. <a href="https://wppugmill.com/pricing" target="_blank">Get your license →</a>', 'wp-pugmill' ),
		), 403 );
	}

	if ( 'pro' === $mode ) {
		wp_send_json_error( array( 'message' => __( 'Pro mode coming soon.', 'wp-pugmill' ) ), 501 );
	}

	$provider = get_option( 'wppugmill_ai_provider', 'anthropic' );
	$api_key  = wppugmill_get_encrypted_option( 'wppugmill_ai_api_key', '' );

	if ( empty( $api_key ) ) {
		wp_send_json_error( array( 'message' => __( 'No API key configured. Add your key in Settings → WP Pugmill.', 'wp-pugmill' ) ), 400 );
	}

	$title = get_the_title( $post );

	// Gutenberg passes unsaved editor content via draft_content.
	// Classic editor omits it — we read the saved post_content instead.
	if ( ! empty( $_POST['draft_content'] ) ) {
		$content = wp_strip_all_tags( sanitize_textarea_field( wp_unslash( $_POST['draft_content'] ) ) );
	} else {
		$content = wp_strip_all_tags( apply_filters( 'the_content', $post->post_content ) );
		$content = html_entity_decode( $content, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	}

	$content   = mb_substr( trim( $content ), 0, 8000 );
	$post_type = get_post_type( $post );

	if ( empty( $content ) ) {
		$label = ( 'page' === $post_type ) ? 'page' : 'post';
		wp_send_json_error( array( 'message' => sprintf( __( '%s has no content to rewrite. Add a draft and try again.', 'wp-pugmill' ), ucfirst( $label ) ) ), 400 );
	}

	$instructions = isset( $_POST['user_instructions'] )
		? mb_substr( sanitize_textarea_field( wp_unslash( $_POST['user_instructions'] ) ), 0, 500 )
		: '';

	switch ( $provider ) {
		case 'openai':
			$result = wppugmill_rewrite_via_openai( $api_key, $title, $content, $post_type, $instructions );
			break;
		case 'gemini':
			$result = wppugmill_rewrite_via_gemini( $api_key, $title, $content, $post_type, $instructions );
			break;
		case 'anthropic':
		default:
			$result = wppugmill_rewrite_via_anthropic( $api_key, $title, $content, $post_type, $instructions );
			break;
	}

	if ( is_wp_error( $result ) ) {
		wp_send_json_error( array( 'message' => $result->get_error_message() ), 500 );
	}

	wp_send_json_success( $result );
}

// ── Shared voice helper ────────────────────────────────────────────────────

/**
 * Return the author voice clause to append to AI system prompts.
 *
 * Reads the wppugmill_author_voice option set in Settings → WP Pugmill.
 * Returns a formatted instruction block when a voice guide is configured,
 * or a generic tone fallback when none is set.
 *
 * @return string Voice clause string, ready to concatenate onto a system prompt.
 */
function wppugmill_voice_clause() {
	$voice = get_option( 'wppugmill_author_voice', '' );
	if ( $voice ) {
		return "\n\nAuthor voice and style guide — you MUST follow this when writing any text fields:\n" . $voice;
	}
	return "\n\nMaintain a clear, engaging, and professional tone throughout.";
}

// ── Draft prompts ──────────────────────────────────────────────────────────

/**
 * System prompt for Write from Draft — instructs the LLM to produce a strict
 * Answer Unit formatted for AI answer engine discovery and citation.
 *
 * Structure maps to JSON fields:
 *   primary_question — the Target Query (H2 level, becomes post title / Q&A question)
 *   direct_answer    — the Direct Answer snippet (1-3 sentences, cited verbatim by AI)
 *   context          — the Nuanced Context as HTML (H3 sections + paragraphs, becomes post body)
 *   summary          — AEO crawler description, different framing from direct_answer
 *   keywords         — 5-15 specific search terms
 *
 * 3rd-party AI service disclosure:
 * This string is transmitted as the system prompt to the configured external
 * AI provider. It contains no user or visitor data.
 */
function wppugmill_draft_system_prompt( $post_type = 'post' ) {
	$is_page    = ( 'page' === $post_type );
	$avoid_open = $is_page
		? '"This page...", "On this page...", "Welcome to...", etc.'
		: '"This article...", "In this post...", "This blog post...", etc.';
	return 'You are an expert AEO (Answer Engine Optimization) editor. Your task is to rewrite the provided draft into a strict Answer Unit — the format that AI answer engines (Perplexity, ChatGPT, Gemini) parse, extract, and cite most effectively.

== ANSWER UNIT STRUCTURE ==

The Answer Unit has three layers:

1. TARGET QUERY (primary_question field)
   The single most specific, search-intent question this content directly answers.
   Must end with "?". One question only.

2. DIRECT ANSWER (direct_answer field)
   A 1–3 sentence definitive, factual answer to the target query.
   This is the snippet AI engines will cite verbatim. Rules:
   - State the answer immediately. Never begin with ' . $avoid_open . '
   - Be authoritative and specific. No hedging, no filler.
   - If the draft introduces a specific concept, methodology, or product, define it in this answer.

3. NUANCED CONTEXT (context field — HTML)
   The full substantive body that supports and expands the Direct Answer.
   Organize using <h3> subheadings and short paragraphs. Rules:
   - Strip all marketing speak, "sales-y" language, and long introductions.
   - Every paragraph must add signal. Remove noise.
   - Define any entities (concepts, products, methodologies) on first mention.
   - Use <ul>/<li> for lists of 3 or more parallel items.
   - ZERO HALLUCINATION: reorganize, copyedit, and rephrase for clarity, but do NOT add
     facts, claims, statistics, or information not present in the original draft.
   - Do NOT include an <h2> — the post title serves as the H2.
   - Structure: open with the Direct Answer as a <p>, then <h3> sections for each major theme.

== TONE AND STYLE ==
- No fluff. Strip introductions, anecdotes, and transition filler that add no information.
- Be definitive. Use active voice and concrete language.
- Short paragraphs (2–4 sentences max per <p>).

== OUTPUT FORMAT ==
Return ONLY a valid JSON object with exactly these five fields:
{
  "primary_question": "The specific question this content directly answers?",
  "direct_answer": "1–3 sentence direct, citable answer. Defines the core concept if applicable.",
  "context": "<p>Direct answer repeated as opening paragraph.</p><h3>First Theme</h3><p>Supporting detail.</p><h3>Second Theme</h3><p>More detail.</p>",
  "summary": "2–3 sentence AEO summary for AI crawlers. Different framing from direct_answer — complements, does not repeat.",
  "keywords": ["specific-term", "another-term"]
}

Field rules:
- primary_question: ends with "?", one question, specific not generic
- direct_answer: 1–3 sentences, no preamble, factual, citable
- context: valid HTML using only <p>, <h3>, <strong>, <em>, <ul>, <ol>, <li>, <code>, <a> tags
- summary: 2–3 sentences, complements direct_answer, used by AI crawlers not human readers
- keywords: 5–15 specific search terms, not generic words
- Return ONLY the JSON, no markdown code fences, no extra text'
		. wppugmill_voice_clause();
}

/**
 * User prompt for Write from Draft — wraps the post title and stripped body.
 *
 * 3rd-party AI service disclosure:
 * The $title and $draft values (post title and body text, admin-authored)
 * are sent to the external AI provider. No visitor data is included.
 */
function wppugmill_draft_user_prompt( $title, $draft, $instructions = '' ) {
	$base = "Title: {$title}\n\nDraft:\n{$draft}";
	if ( $instructions ) {
		$base .= "\n\nUser instructions (apply when rewriting): {$instructions}";
	}
	return $base;
}

// ── Draft provider calls ───────────────────────────────────────────────────

function wppugmill_rewrite_via_anthropic( $api_key, $title, $content, $post_type = 'post', $instructions = '' ) {
	$response = wp_remote_post(
		'https://api.anthropic.com/v1/messages',
		wppugmill_ai_request_args(
			array(
				'Content-Type'      => 'application/json',
				'x-api-key'         => $api_key,
				'anthropic-version' => '2023-06-01',
			),
			wp_json_encode( array(
				'model'      => WPPUGMILL_ANTHROPIC_MODEL,
				'max_tokens' => 3000,
				'system'     => wppugmill_draft_system_prompt( $post_type ),
				'messages'   => array(
					array( 'role' => 'user', 'content' => wppugmill_draft_user_prompt( $title, $content, $instructions ) ),
				),
			) )
		)
	);
	return wppugmill_parse_draft_response( $response, 'anthropic' );
}

function wppugmill_rewrite_via_openai( $api_key, $title, $content, $post_type = 'post', $instructions = '' ) {
	$response = wp_remote_post(
		'https://api.openai.com/v1/chat/completions',
		wppugmill_ai_request_args(
			array(
				'Content-Type'  => 'application/json',
				'Authorization' => 'Bearer ' . $api_key,
			),
			wp_json_encode( array(
				'model'           => 'gpt-4o',
				'messages'        => array(
					array( 'role' => 'system', 'content' => wppugmill_draft_system_prompt( $post_type ) ),
					array( 'role' => 'user',   'content' => wppugmill_draft_user_prompt( $title, $content, $instructions ) ),
				),
				'max_tokens'      => 3000,
				'response_format' => array( 'type' => 'json_object' ),
			) )
		)
	);
	return wppugmill_parse_draft_response( $response, 'openai' );
}

function wppugmill_rewrite_via_gemini( $api_key, $title, $content, $post_type = 'post', $instructions = '' ) {
	$response = wp_remote_post(
		'https://generativelanguage.googleapis.com/v1beta/models/gemini-1.5-flash:generateContent',
		wppugmill_ai_request_args(
			array(
				'Content-Type'   => 'application/json',
				'x-goog-api-key' => $api_key,
			),
			wp_json_encode( array(
				'contents' => array(
					array(
						'parts' => array(
							array( 'text' => wppugmill_draft_system_prompt( $post_type ) . "\n\n" . wppugmill_draft_user_prompt( $title, $content, $instructions ) ),
						),
					),
				),
				'generationConfig' => array(
					'response_mime_type' => 'application/json',
					'max_output_tokens'  => 3000,
				),
			) )
		)
	);
	return wppugmill_parse_draft_response( $response, 'gemini' );
}

// ── Draft response parser ──────────────────────────────────────────────────

/**
 * Parse and sanitize the AI response for Write from Draft.
 *
 * context is returned as wp_kses-filtered HTML (paragraph-level tags only)
 * so it can be safely passed to the Gutenberg rawHandler on the client.
 */
function wppugmill_parse_draft_response( $response, $provider ) {
	if ( is_wp_error( $response ) ) {
		error_log( 'WP Pugmill draft rewrite error (' . $provider . '): ' . $response->get_error_message() );
		return new WP_Error( 'request_failed', __( 'Could not reach AI provider. Please check your connection and try again.', 'wp-pugmill' ) );
	}

	$code = wp_remote_retrieve_response_code( $response );
	$body = wp_remote_retrieve_body( $response );
	$data = json_decode( $body, true );

	if ( 200 !== $code ) {
		$detail = $data['error']['message'] ?? $body;
		error_log( 'WP Pugmill draft rewrite provider error (' . $provider . ' ' . $code . '): ' . $detail );
		if ( 401 === $code ) return new WP_Error( 'provider_error', __( 'Invalid API key. Please check your key in Settings → WP Pugmill.', 'wp-pugmill' ) );
		if ( 429 === $code ) return new WP_Error( 'provider_error', __( 'AI provider rate limit reached. Please wait and try again.', 'wp-pugmill' ) );
		if ( 402 === $code ) return new WP_Error( 'provider_error', __( 'Insufficient credits on your AI provider account. Please top up and try again.', 'wp-pugmill' ) );
		return new WP_Error( 'provider_error', __( 'AI provider returned an error. Please try again.', 'wp-pugmill' ) );
	}

	$raw_text = '';
	switch ( $provider ) {
		case 'anthropic': $raw_text = $data['content'][0]['text'] ?? ''; break;
		case 'openai':    $raw_text = $data['choices'][0]['message']['content'] ?? ''; break;
		case 'gemini':    $raw_text = $data['candidates'][0]['content']['parts'][0]['text'] ?? ''; break;
	}

	if ( empty( $raw_text ) ) {
		return new WP_Error( 'empty_response', __( 'AI returned an empty response. Please try again.', 'wp-pugmill' ) );
	}

	// Strip markdown code fences if the model wraps output anyway (defensive)
	$raw_text = preg_replace( '/^```(?:json)?\s*/i', '', trim( $raw_text ) );
	$raw_text = preg_replace( '/\s*```$/', '', $raw_text );

	// If there's still preamble text, extract the JSON object directly.
	if ( ! is_array( json_decode( $raw_text, true ) ) ) {
		if ( preg_match( '/\{[\s\S]*\}/s', $raw_text, $m ) ) {
			$raw_text = $m[0];
		}
	}

	$draft = json_decode( $raw_text, true );
	if ( ! is_array( $draft ) ) {
		error_log( 'WP Pugmill: draft rewrite invalid JSON from ' . $provider . ': ' . substr( $raw_text, 0, 200 ) );
		return new WP_Error( 'invalid_json', __( 'AI returned an unexpected response format. Please try again.', 'wp-pugmill' ) );
	}

	// context is HTML — restrict to safe structural/inline tags via wp_kses.
	// h3 is included because the Answer Unit spec uses <h3> subheadings for
	// the Nuanced Context sections.
	$allowed_html = array(
		'h3'     => array(),
		'p'      => array(),
		'strong' => array(),
		'em'     => array(),
		'code'   => array(),
		'ul'     => array(),
		'ol'     => array(),
		'li'     => array(),
		'br'     => array(),
		'a'      => array( 'href' => array(), 'title' => array() ),
	);

	return array(
		'primary_question' => sanitize_text_field( $draft['primary_question'] ?? '' ),
		'direct_answer'    => sanitize_textarea_field( $draft['direct_answer'] ?? '' ),
		'context'          => wp_kses( $draft['context'] ?? '', $allowed_html ),
		'summary'          => sanitize_textarea_field( $draft['summary'] ?? '' ),
		'keywords'         => array_values( array_filter(
			array_map( 'sanitize_text_field', is_array( $draft['keywords'] ?? null ) ? $draft['keywords'] : array() )
		) ),
	);
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

add_action( 'wp_ajax_wppugmill_tone_check', 'wppugmill_ajax_tone_check' );

/**
 * AJAX handler — check post content tone against the Author Voice guide.
 */
function wppugmill_ajax_tone_check() {
	check_ajax_referer( 'wppugmill_tone_check', 'nonce' );

	if ( ! current_user_can( 'edit_posts' ) ) {
		wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'wp-pugmill' ) ), 403 );
	}

	$rate_check = wppugmill_check_rate_limit();
	if ( is_wp_error( $rate_check ) ) {
		wp_send_json_error( array( 'message' => $rate_check->get_error_message() ), 429 );
	}

	$post_id = absint( wp_unslash( $_POST['post_id'] ?? 0 ) );
	if ( ! $post_id ) {
		wp_send_json_error( array( 'message' => __( 'Missing post ID.', 'wp-pugmill' ) ), 400 );
	}

	if ( ! current_user_can( 'edit_post', $post_id ) ) {
		wp_send_json_error( array( 'message' => __( 'You do not have permission to edit this post.', 'wp-pugmill' ) ), 403 );
	}

	$post = get_post( $post_id );
	if ( ! $post ) {
		wp_send_json_error( array( 'message' => __( 'Post not found.', 'wp-pugmill' ) ), 404 );
	}

	$mode = wppugmill_mode();
	if ( 'free' === $mode ) {
		wp_send_json_error( array(
			'message' => __( 'Tone Check requires a WP Pugmill AI Connector license. <a href="https://wppugmill.com/pricing" target="_blank">Get your license →</a>', 'wp-pugmill' ),
		), 403 );
	}

	if ( 'pro' === $mode ) {
		wp_send_json_error( array( 'message' => __( 'Pro mode coming soon.', 'wp-pugmill' ) ), 501 );
	}

	$provider = get_option( 'wppugmill_ai_provider', 'anthropic' );
	$api_key  = wppugmill_get_encrypted_option( 'wppugmill_ai_api_key', '' );

	if ( empty( $api_key ) ) {
		wp_send_json_error( array( 'message' => __( 'No API key configured. Add your key in Settings → WP Pugmill.', 'wp-pugmill' ) ), 400 );
	}

	$title   = get_the_title( $post );
	$content = wp_strip_all_tags( apply_filters( 'the_content', $post->post_content ) );
	$content = html_entity_decode( $content, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	$content = mb_substr( $content, 0, WPPUGMILL_MAX_AI_INPUT );

	if ( empty( trim( $content ) ) ) {
		wp_send_json_error( array( 'message' => __( 'Post has no content to analyze. Add some content and try again.', 'wp-pugmill' ) ), 400 );
	}

	switch ( $provider ) {
		case 'openai':
			$result = wppugmill_tone_via_openai( $api_key, $title, $content );
			break;
		case 'gemini':
			$result = wppugmill_tone_via_gemini( $api_key, $title, $content );
			break;
		case 'anthropic':
		default:
			$result = wppugmill_tone_via_anthropic( $api_key, $title, $content );
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
function wppugmill_tone_system_prompt() {
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
		. wppugmill_voice_clause();
}

/**
 * User prompt for Tone Check.
 *
 * 3rd-party AI service disclosure:
 * The $title and $content values (post title and body text, admin-authored)
 * are sent to the external AI provider. No visitor data is included.
 */
function wppugmill_tone_user_prompt( $title, $content ) {
	return "Title: {$title}\n\nContent:\n{$content}";
}

function wppugmill_tone_via_anthropic( $api_key, $title, $content ) {
	$response = wp_remote_post(
		'https://api.anthropic.com/v1/messages',
		wppugmill_ai_request_args(
			array(
				'Content-Type'      => 'application/json',
				'x-api-key'         => $api_key,
				'anthropic-version' => '2023-06-01',
			),
			wp_json_encode( array(
				'model'      => WPPUGMILL_ANTHROPIC_MODEL,
				'max_tokens' => 1024,
				'system'     => wppugmill_tone_system_prompt(),
				'messages'   => array(
					array( 'role' => 'user', 'content' => wppugmill_tone_user_prompt( $title, $content ) ),
				),
			) )
		)
	);
	return wppugmill_parse_tone_response( $response, 'anthropic' );
}

function wppugmill_tone_via_openai( $api_key, $title, $content ) {
	$response = wp_remote_post(
		'https://api.openai.com/v1/chat/completions',
		wppugmill_ai_request_args(
			array(
				'Content-Type'  => 'application/json',
				'Authorization' => 'Bearer ' . $api_key,
			),
			wp_json_encode( array(
				'model'    => 'gpt-4o',
				'messages' => array(
					array( 'role' => 'system', 'content' => wppugmill_tone_system_prompt() ),
					array( 'role' => 'user',   'content' => wppugmill_tone_user_prompt( $title, $content ) ),
				),
				'max_tokens'      => 1024,
				'response_format' => array( 'type' => 'json_object' ),
			) )
		)
	);
	return wppugmill_parse_tone_response( $response, 'openai' );
}

function wppugmill_tone_via_gemini( $api_key, $title, $content ) {
	$response = wp_remote_post(
		'https://generativelanguage.googleapis.com/v1beta/models/gemini-1.5-flash:generateContent',
		wppugmill_ai_request_args(
			array(
				'Content-Type'   => 'application/json',
				'x-goog-api-key' => $api_key,
			),
			wp_json_encode( array(
				'contents' => array(
					array(
						'parts' => array(
							array( 'text' => wppugmill_tone_system_prompt() . "\n\n" . wppugmill_tone_user_prompt( $title, $content ) ),
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
	return wppugmill_parse_tone_response( $response, 'gemini' );
}

/**
 * Parse and sanitize the AI tone check response.
 *
 * Returns an array of sanitized { quote, issue, suggestion } objects,
 * or a WP_Error on failure.
 */
function wppugmill_parse_tone_response( $response, $provider ) {
	if ( is_wp_error( $response ) ) {
		error_log( 'WP Pugmill tone check error (' . $provider . '): ' . $response->get_error_message() );
		return new WP_Error( 'request_failed', __( 'Could not reach AI provider. Please check your connection and try again.', 'wp-pugmill' ) );
	}

	$code = wp_remote_retrieve_response_code( $response );
	$body = wp_remote_retrieve_body( $response );
	$data = json_decode( $body, true );

	if ( 200 !== $code ) {
		$detail = $data['error']['message'] ?? $body;
		error_log( 'WP Pugmill tone check provider error (' . $provider . ' ' . $code . '): ' . $detail );
		if ( 401 === $code ) return new WP_Error( 'provider_error', __( 'Invalid API key. Please check your key in Settings → WP Pugmill.', 'wp-pugmill' ) );
		if ( 429 === $code ) return new WP_Error( 'provider_error', __( 'AI provider rate limit reached. Please wait and try again.', 'wp-pugmill' ) );
		if ( 402 === $code ) return new WP_Error( 'provider_error', __( 'Insufficient credits on your AI provider account. Please top up and try again.', 'wp-pugmill' ) );
		return new WP_Error( 'provider_error', __( 'AI provider returned an error. Please try again.', 'wp-pugmill' ) );
	}

	$raw_text = '';
	switch ( $provider ) {
		case 'anthropic': $raw_text = $data['content'][0]['text'] ?? ''; break;
		case 'openai':    $raw_text = $data['choices'][0]['message']['content'] ?? ''; break;
		case 'gemini':    $raw_text = $data['candidates'][0]['content']['parts'][0]['text'] ?? ''; break;
	}

	if ( empty( $raw_text ) ) {
		return new WP_Error( 'empty_response', __( 'AI returned an empty response. Please try again.', 'wp-pugmill' ) );
	}

	$raw_text = preg_replace( '/^```(?:json)?\s*/i', '', trim( $raw_text ) );
	$raw_text = preg_replace( '/\s*```$/', '', $raw_text );

	// OpenAI json_object mode may wrap the array in an object key
	$decoded = json_decode( $raw_text, true );
	if ( is_array( $decoded ) && ! isset( $decoded[0] ) ) {
		// Unwrap first value if it's a keyed object containing the array
		$decoded = array_values( $decoded )[0] ?? $decoded;
	}

	if ( ! is_array( $decoded ) ) {
		error_log( 'WP Pugmill: tone check invalid JSON from ' . $provider . ': ' . substr( $raw_text, 0, 200 ) );
		return new WP_Error( 'invalid_json', __( 'AI returned an unexpected response format. Please try again.', 'wp-pugmill' ) );
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

add_action( 'wp_ajax_wppugmill_reading_level', 'wppugmill_ajax_reading_level' );

function wppugmill_ajax_reading_level() {
	$r = wppugmill_ai_request_setup( 'wppugmill_reading_level', 'Reading Level' );
	if ( empty( trim( $r['content'] ) ) ) {
		wp_send_json_error( array( 'message' => __( 'Post has no content to analyze.', 'wp-pugmill' ) ), 400 );
	}

	$system = 'You are a readability expert. Analyse the reading level of the given post content. Return ONLY a JSON object: {"level":"e.g. General Audience / High School / College / Specialist","gradeLevel":number,"note":"one factual sentence on clarity and pace — describe what the reader experiences, no judgement"}.'
		. ( get_option( 'wppugmill_author_voice', '' ) ? ' If an Author\'s Voice is provided, add a "fit" field that factually describes how the reading level compares to the voice guide — one of: "aligns with voice guide", "higher register than voice guide", "lower register than voice guide".' : '' )
		. ' No explanation outside the JSON.'
		. wppugmill_voice_clause();

	$user   = "Post title: \"{$r['title']}\"\n\nPost content:\n{$r['content']}";
	$result = wppugmill_call_ai( $r['provider'], $r['api_key'], $system, $user, 200 );

	if ( is_wp_error( $result ) ) {
		wp_send_json_error( array( 'message' => $result->get_error_message() ), 500 );
	}

	$decoded = wppugmill_decode_ai_json( $result, $r['provider'] );
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

add_action( 'wp_ajax_wppugmill_simplify_draft', 'wppugmill_ajax_simplify_draft' );

/**
 * AJAX handler — rewrite post content to a target reading level.
 *
 * Accepts:
 *   target_level — e.g. "8th grade", "High school", "General audience", or custom text
 *   notes        — optional extra instruction (max 300 chars)
 *
 * Returns: { content: "HTML string" }
 */
function wppugmill_ajax_simplify_draft() {
	$r = wppugmill_ai_request_setup( 'wppugmill_simplify_draft', 'Simplify Draft' );

	if ( empty( trim( $r['content'] ) ) ) {
		wp_send_json_error( array( 'message' => __( 'Post has no content to rewrite.', 'wp-pugmill' ) ), 400 );
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
		. wppugmill_voice_clause();

	$user   = "Post title: \"{$r['title']}\"\n\nPost content:\n{$r['content']}";
	$result = wppugmill_call_ai( $r['provider'], $r['api_key'], $system, $user, 3000 );

	if ( is_wp_error( $result ) ) {
		wp_send_json_error( array( 'message' => $result->get_error_message() ), 500 );
	}

	// Strip any markdown fences the AI might have added despite instructions.
	$html = trim( preg_replace( '/^```(?:html)?\s*/i', '', preg_replace( '/\s*```$/i', '', trim( $result ) ) ) );

	if ( empty( $html ) ) {
		wp_send_json_error( array( 'message' => __( 'AI returned an empty response. Please try again.', 'wp-pugmill' ) ), 500 );
	}

	wp_send_json_success( array( 'content' => wp_kses_post( $html ) ) );
}

// =========================================================================
// 2. Headline Variants
// =========================================================================

add_action( 'wp_ajax_wppugmill_headline_variants', 'wppugmill_ajax_headline_variants' );

function wppugmill_ajax_headline_variants() {
	$r = wppugmill_ai_request_setup( 'wppugmill_headline_variants', 'Headline Variants' );
	if ( empty( trim( $r['content'] ) ) ) {
		wp_send_json_error( array( 'message' => __( 'Post has no content to analyze.', 'wp-pugmill' ) ), 400 );
	}

	$system = 'You are a copywriting expert. Generate two headline variants for the given post: one curiosity-driven (creates intrigue, uses a knowledge gap) and one utility-driven (clearly states the benefit or outcome). Return ONLY a JSON object: {"curiosity":"...","utility":"..."}. No explanation.'
		. wppugmill_voice_clause();

	$user   = "Post title: \"{$r['title']}\"\n\nPost content:\n{$r['content']}";
	$result = wppugmill_call_ai( $r['provider'], $r['api_key'], $system, $user, 200 );

	if ( is_wp_error( $result ) ) {
		wp_send_json_error( array( 'message' => $result->get_error_message() ), 500 );
	}

	$decoded = wppugmill_decode_ai_json( $result, $r['provider'] );
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
function wppugmill_ajax_generate_image_alt() {
	check_ajax_referer( 'wppugmill_generate_image_alt', 'nonce' );

	if ( ! current_user_can( 'edit_posts' ) ) {
		wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'wp-pugmill' ) ), 403 );
	}

	$rate_check = wppugmill_check_rate_limit();
	if ( is_wp_error( $rate_check ) ) {
		wp_send_json_error( array( 'message' => $rate_check->get_error_message() ), 429 );
	}

	$mode = wppugmill_mode();
	if ( 'free' === $mode ) {
		wp_send_json_error( array(
			'message' => __( 'AI alt text generation requires a WP Pugmill AI Connector license. <a href="https://wppugmill.com/pricing" target="_blank">Get your license →</a>', 'wp-pugmill' ),
		), 403 );
	}
	if ( 'pro' === $mode ) {
		wp_send_json_error( array( 'message' => __( 'Pro mode coming soon.', 'wp-pugmill' ) ), 501 );
	}

	$post_id = absint( wp_unslash( $_POST['post_id'] ?? 0 ) );
	if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) ) {
		wp_send_json_error( array( 'message' => __( 'Invalid post.', 'wp-pugmill' ) ), 403 );
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
			wp_send_json_error( array( 'message' => __( 'Invalid attachment.', 'wp-pugmill' ) ), 400 );
		}
		$thumbnail_id = $attachment_id;
		$image_url    = wp_get_attachment_image_url( $thumbnail_id, 'large' );
		if ( ! $image_url ) {
			wp_send_json_error( array( 'message' => __( 'Could not get image URL.', 'wp-pugmill' ) ), 400 );
		}
	} elseif ( $raw_image_url ) {
		// External image URL — validate it looks like an image.
		$parsed = wp_parse_url( $raw_image_url );
		if ( empty( $parsed['scheme'] ) || ! in_array( $parsed['scheme'], array( 'http', 'https' ), true ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid image URL.', 'wp-pugmill' ) ), 400 );
		}
		$image_url = $raw_image_url;
	} else {
		$thumbnail_id = get_post_thumbnail_id( $post_id );
		if ( ! $thumbnail_id ) {
			wp_send_json_error( array( 'message' => __( 'No featured image found.', 'wp-pugmill' ) ), 400 );
		}
		$image_url = wp_get_attachment_image_url( $thumbnail_id, 'large' );
		if ( ! $image_url ) {
			wp_send_json_error( array( 'message' => __( 'Could not get image URL.', 'wp-pugmill' ) ), 400 );
		}
	}

	$api_key = wppugmill_get_encrypted_option( 'wppugmill_ai_api_key', '' );
	if ( empty( $api_key ) ) {
		wp_send_json_error( array( 'message' => __( 'No API key configured. Add your key in Settings → WP Pugmill.', 'wp-pugmill' ) ), 400 );
	}

	$provider = get_option( 'wppugmill_ai_provider', 'anthropic' );
	$title    = get_the_title( $post_id );

	$prompt = sprintf(
		'Write concise alt text for this image from a blog post titled "%s". '
		. 'Requirements: under 125 characters, describe what is visually present, suitable for screen readers and AI engines, '
		. 'do not start with "Image of" or "Photo of". Reply with the alt text only — no quotes, no explanation.',
		$title
	);

	$result = wppugmill_call_ai_vision( $provider, $api_key, $image_url, $prompt, 60 );

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
add_action( 'wp_ajax_wppugmill_generate_image_alt', 'wppugmill_ajax_generate_image_alt' );
