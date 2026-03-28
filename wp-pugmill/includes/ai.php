<?php
/**
 * AI generation — AJAX handler for AEO field generation.
 *
 * Supports: Anthropic (Claude), OpenAI (GPT), Google (Gemini)
 * Called via: wp_ajax_wppugmill_generate_aeo
 *
 * Security:
 * - Nonce verified on every request
 * - Capability check (edit_posts)
 * - Rate limited to 20 requests/hour/user (via rate-limit.php)
 * - API keys retrieved encrypted (via encryption.php)
 * - All external calls use explicit sslverify: true
 * - Gemini key sent as header, never in URL
 * - Provider error details logged server-side, not exposed to client
 *
 * @package WPPugmill
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'wp_ajax_wppugmill_generate_aeo', 'wppugmill_ajax_generate_aeo' );

/**
 * AJAX handler — generate AEO metadata for a post using AI.
 */
function wppugmill_ajax_generate_aeo() {
	check_ajax_referer( 'wppugmill_generate_aeo', 'nonce' );

	if ( ! current_user_can( 'edit_posts' ) ) {
		wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'wp-pugmill' ) ), 403 );
	}

	// Rate limiting
	$rate_check = wppugmill_check_rate_limit();
	if ( is_wp_error( $rate_check ) ) {
		wp_send_json_error( array( 'message' => $rate_check->get_error_message() ), 429 );
	}

	$post_id = absint( wp_unslash( $_POST['post_id'] ?? 0 ) );
	if ( ! $post_id ) {
		wp_send_json_error( array( 'message' => __( 'Missing post ID.', 'wp-pugmill' ) ), 400 );
	}

	// Verify user can edit this specific post
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
			'message' => __( 'AI generation requires a WP Pugmill AI Connector license. <a href="https://wppugmill.com/pricing" target="_blank">Get your license →</a>', 'wp-pugmill' ),
		), 403 );
	}

	if ( 'pro' === $mode ) {
		wp_send_json_error( array( 'message' => __( 'Pro mode coming soon.', 'wp-pugmill' ) ), 501 );
	}

	// AI (licensed BYOK) mode — retrieve decrypted key
	$provider = get_option( 'wppugmill_ai_provider', 'anthropic' );
	$api_key  = wppugmill_get_encrypted_option( 'wppugmill_ai_api_key', '' );

	if ( empty( $api_key ) ) {
		wp_send_json_error( array( 'message' => __( 'No API key configured. Add your key in Settings → WP Pugmill.', 'wp-pugmill' ) ), 400 );
	}

	// Build content for the prompt — prefer unsaved Gutenberg editor content
	// when passed (same pattern as rewrite_draft), fall back to saved DB content.
	$title = get_the_title( $post );
	if ( ! empty( $_POST['draft_content'] ) ) {
		$content = wp_strip_all_tags( sanitize_textarea_field( wp_unslash( $_POST['draft_content'] ) ) );
	} else {
		$content = wp_strip_all_tags( apply_filters( 'the_content', $post->post_content ) );
		$content = html_entity_decode( $content, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	}
	$content = mb_substr( trim( $content ), 0, WPPUGMILL_MAX_AI_INPUT );

	if ( empty( $content ) ) {
		wp_send_json_error( array( 'message' => __( 'Post has no content to analyze. Add some content and try again.', 'wp-pugmill' ) ), 400 );
	}

	switch ( $provider ) {
		case 'openai':
			$result = wppugmill_generate_via_openai( $api_key, $title, $content );
			break;
		case 'gemini':
			$result = wppugmill_generate_via_gemini( $api_key, $title, $content );
			break;
		case 'anthropic':
		default:
			$result = wppugmill_generate_via_anthropic( $api_key, $title, $content );
			break;
	}

	if ( is_wp_error( $result ) ) {
		wp_send_json_error( array( 'message' => $result->get_error_message() ), 500 );
	}

	wp_send_json_success( $result );
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
			'message' => __( 'Write from Draft requires a WP Pugmill AI Connector license. <a href="https://wppugmill.com/pricing" target="_blank">Get your license →</a>', 'wp-pugmill' ),
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

	switch ( $provider ) {
		case 'openai':
			$result = wppugmill_rewrite_via_openai( $api_key, $title, $content, $post_type );
			break;
		case 'gemini':
			$result = wppugmill_rewrite_via_gemini( $api_key, $title, $content, $post_type );
			break;
		case 'anthropic':
		default:
			$result = wppugmill_rewrite_via_anthropic( $api_key, $title, $content, $post_type );
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
function wppugmill_draft_user_prompt( $title, $draft ) {
	return "Title: {$title}\n\nDraft:\n{$draft}";
}

// ── Draft provider calls ───────────────────────────────────────────────────

function wppugmill_rewrite_via_anthropic( $api_key, $title, $content, $post_type = 'post' ) {
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
					array( 'role' => 'user', 'content' => wppugmill_draft_user_prompt( $title, $content ) ),
				),
			) )
		)
	);
	return wppugmill_parse_draft_response( $response, 'anthropic' );
}

function wppugmill_rewrite_via_openai( $api_key, $title, $content, $post_type = 'post' ) {
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
					array( 'role' => 'user',   'content' => wppugmill_draft_user_prompt( $title, $content ) ),
				),
				'max_tokens'      => 3000,
				'response_format' => array( 'type' => 'json_object' ),
			) )
		)
	);
	return wppugmill_parse_draft_response( $response, 'openai' );
}

function wppugmill_rewrite_via_gemini( $api_key, $title, $content, $post_type = 'post' ) {
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
							array( 'text' => wppugmill_draft_system_prompt( $post_type ) . "\n\n" . wppugmill_draft_user_prompt( $title, $content ) ),
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
// Shared helpers — used by Reading Level, Headlines, Topic Focus, Excerpt,
// Content Brief, and Internal Links handlers below.
// =========================================================================

/**
 * Common setup for all AI AJAX handlers.
 *
 * Verifies nonce, capability, rate limit, mode, and API key.
 * Sends a JSON error and exits on failure; returns data array on success.
 *
 * @param string $nonce_action  Nonce action string.
 * @param string $feature_label Human-readable feature name for error messages.
 * @return array { post, provider, api_key, title, content }
 */
function wppugmill_ai_request_setup( $nonce_action, $feature_label, $require_license = true ) {
	check_ajax_referer( $nonce_action, 'nonce' );

	if ( ! current_user_can( 'edit_posts' ) ) {
		wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'wp-pugmill' ) ), 403 );
	}

	$rate_check = wppugmill_check_rate_limit();
	if ( is_wp_error( $rate_check ) ) {
		wp_send_json_error( array( 'message' => $rate_check->get_error_message() ), 429 );
	}

	if ( $require_license ) {
		$mode = wppugmill_mode();
		if ( 'free' === $mode ) {
			wp_send_json_error( array(
				/* translators: %1$s: feature name */
				'message' => sprintf( __( '%1$s requires a WP Pugmill AI Connector license. <a href="https://wppugmill.com/pricing" target="_blank">Get your license →</a>', 'wp-pugmill' ), $feature_label ),
			), 403 );
		}
		if ( 'pro' === $mode ) {
			wp_send_json_error( array( 'message' => __( 'Pro mode coming soon.', 'wp-pugmill' ) ), 501 );
		}
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

	$provider = get_option( 'wppugmill_ai_provider', 'anthropic' );
	$api_key  = wppugmill_get_encrypted_option( 'wppugmill_ai_api_key', '' );
	if ( empty( $api_key ) ) {
		wp_send_json_error( array( 'message' => __( 'No API key configured. Add your key in Settings → WP Pugmill.', 'wp-pugmill' ) ), 400 );
	}

	$title = get_the_title( $post );
	if ( ! empty( $_POST['draft_content'] ) ) {
		$content = wp_strip_all_tags( sanitize_textarea_field( wp_unslash( $_POST['draft_content'] ) ) );
	} else {
		$content = wp_strip_all_tags( apply_filters( 'the_content', $post->post_content ) );
		$content = html_entity_decode( $content, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	}
	$content = mb_substr( trim( $content ), 0, WPPUGMILL_MAX_AI_INPUT );

	$post_type = $post->post_type;
	return compact( 'post', 'provider', 'api_key', 'title', 'content', 'post_type' );
}

/**
 * Generic AI dispatcher — sends system + user prompt to the configured provider.
 *
 * Returns raw text string on success, WP_Error on failure.
 *
 * 3rd-party AI service disclosure:
 * This function transmits the provided prompts (which contain post title and
 * body text, admin-authored) to the external AI provider. No visitor data
 * is included. External services are disclosed in the plugin's readme.txt.
 *
 * @param string $provider     'anthropic' | 'openai' | 'gemini'
 * @param string $api_key      Decrypted API key.
 * @param string $system       System prompt.
 * @param string $user         User prompt.
 * @param int    $max_tokens   Maximum response tokens.
 * @return string|WP_Error
 */
function wppugmill_call_ai( $provider, $api_key, $system, $user, $max_tokens = 400 ) {
	switch ( $provider ) {
		case 'openai':
			$response = wp_remote_post(
				'https://api.openai.com/v1/chat/completions',
				wppugmill_ai_request_args(
					array( 'Content-Type' => 'application/json', 'Authorization' => 'Bearer ' . $api_key ),
					wp_json_encode( array(
						'model'      => 'gpt-4o',
						'messages'   => array(
							array( 'role' => 'system', 'content' => $system ),
							array( 'role' => 'user',   'content' => $user ),
						),
						'max_tokens' => $max_tokens,
					) )
				)
			);
			break;
		case 'gemini':
			$response = wp_remote_post(
				'https://generativelanguage.googleapis.com/v1beta/models/gemini-1.5-flash:generateContent',
				wppugmill_ai_request_args(
					array( 'Content-Type' => 'application/json', 'x-goog-api-key' => $api_key ),
					wp_json_encode( array(
						'contents'         => array( array( 'parts' => array( array( 'text' => $system . "\n\n" . $user ) ) ) ),
						'generationConfig' => array( 'max_output_tokens' => $max_tokens ),
					) )
				)
			);
			break;
		case 'anthropic':
		default:
			$response = wp_remote_post(
				'https://api.anthropic.com/v1/messages',
				wppugmill_ai_request_args(
					array( 'Content-Type' => 'application/json', 'x-api-key' => $api_key, 'anthropic-version' => '2023-06-01' ),
					wp_json_encode( array(
						'model'      => WPPUGMILL_ANTHROPIC_MODEL,
						'max_tokens' => $max_tokens,
						'system'     => $system,
						'messages'   => array( array( 'role' => 'user', 'content' => $user ) ),
					) )
				)
			);
			break;
	}

	if ( is_wp_error( $response ) ) {
		error_log( 'WP Pugmill AI error (' . $provider . '): ' . $response->get_error_message() );
		return new WP_Error( 'request_failed', __( 'Could not reach AI provider. Please check your connection and try again.', 'wp-pugmill' ) );
	}

	$code = wp_remote_retrieve_response_code( $response );
	$body = wp_remote_retrieve_body( $response );
	$data = json_decode( $body, true );

	if ( 200 !== $code ) {
		$detail = $data['error']['message'] ?? $body;
		error_log( 'WP Pugmill AI provider error (' . $provider . ' ' . $code . '): ' . $detail );
		if ( 401 === $code ) return new WP_Error( 'provider_error', __( 'Invalid API key. Please check your key in Settings → WP Pugmill.', 'wp-pugmill' ) );
		if ( 429 === $code ) return new WP_Error( 'provider_error', __( 'AI provider rate limit reached. Please wait and try again.', 'wp-pugmill' ) );
		if ( 402 === $code ) return new WP_Error( 'provider_error', __( 'Insufficient credits on your AI provider account. Please top up and try again.', 'wp-pugmill' ) );
		return new WP_Error( 'provider_error', __( 'AI provider returned an error. Please try again.', 'wp-pugmill' ) );
	}

	$raw = '';
	switch ( $provider ) {
		case 'anthropic': $raw = $data['content'][0]['text'] ?? ''; break;
		case 'openai':    $raw = $data['choices'][0]['message']['content'] ?? ''; break;
		case 'gemini':    $raw = $data['candidates'][0]['content']['parts'][0]['text'] ?? ''; break;
	}

	if ( empty( trim( $raw ) ) ) {
		return new WP_Error( 'empty_response', __( 'AI returned an empty response. Please try again.', 'wp-pugmill' ) );
	}

	// Strip markdown fences defensively
	$raw = preg_replace( '/^```(?:json)?\s*/i', '', trim( $raw ) );
	$raw = preg_replace( '/\s*```$/', '', $raw );

	// If preamble text remains, extract the JSON object or array directly.
	if ( null === json_decode( $raw ) ) {
		if ( preg_match( '/(\{[\s\S]*\}|\[[\s\S]*\])/s', $raw, $m ) ) {
			$raw = $m[1];
		}
	}

	return $raw;
}

/**
 * Decode a JSON string returned by wppugmill_call_ai().
 * Returns the decoded array, or WP_Error on invalid JSON.
 *
 * @param string $raw
 * @param string $provider For error logging.
 * @return array|WP_Error
 */
function wppugmill_decode_ai_json( $raw, $provider ) {
	$decoded = json_decode( $raw, true );
	// OpenAI without json_object mode may wrap the array in a key — unwrap.
	if ( is_array( $decoded ) && ! isset( $decoded[0] ) && count( $decoded ) === 1 ) {
		$decoded = array_values( $decoded )[0];
	}
	if ( ! is_array( $decoded ) ) {
		error_log( 'WP Pugmill: invalid JSON from ' . $provider . ': ' . substr( $raw, 0, 200 ) );
		return new WP_Error( 'invalid_json', __( 'AI returned an unexpected response format. Please try again.', 'wp-pugmill' ) );
	}
	return $decoded;
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

	$system = 'You are a readability expert. Analyse the reading level of the given post content. Return ONLY a JSON object: {"level":"e.g. High School / College / Expert","gradeLevel":number,"note":"one sentence on clarity and pace"}.'
		. ( get_option( 'wppugmill_author_voice', '' ) ? ' If an Author\'s Voice is provided, add a "fit" field: "fits voice" or "too complex" or "too simple".' : '' )
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
// 3. Topic Focus
// =========================================================================

add_action( 'wp_ajax_wppugmill_topic_focus', 'wppugmill_ajax_topic_focus' );

function wppugmill_ajax_topic_focus() {
	$r = wppugmill_ai_request_setup( 'wppugmill_topic_focus', 'Topic Focus' );
	if ( empty( trim( $r['content'] ) ) ) {
		wp_send_json_error( array( 'message' => __( 'Post has no content to analyze.', 'wp-pugmill' ) ), 400 );
	}

	$system = 'You are a content analyst. Identify the primary topic of the given post and evaluate how coherently the content covers that topic. Return ONLY a JSON object: {"topic":"primary topic in 3-5 words","score":1,"note":"one sentence observation about focus or coherence"}. score is an integer 1-5 where 5 = laser-focused, 1 = scattered. No explanation outside the JSON.';
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
		'topic' => sanitize_text_field( $decoded['topic'] ?? '' ),
		'score' => min( 5, max( 1, absint( $decoded['score'] ?? 3 ) ) ),
		'note'  => sanitize_text_field( $decoded['note']  ?? '' ),
	) );
}


add_action( 'wp_ajax_wppugmill_swap_focus_passage', 'wppugmill_ajax_swap_focus_passage' );

function wppugmill_ajax_swap_focus_passage() {
	$r = wppugmill_ai_request_setup( 'wppugmill_swap_focus_passage', 'Swap Focus' );

	$passage        = sanitize_textarea_field( wp_unslash( $_POST['passage']        ?? '' ) );
	$recommendation = sanitize_textarea_field( wp_unslash( $_POST['recommendation'] ?? '' ) );

	if ( empty( $passage ) || empty( $recommendation ) ) {
		wp_send_json_error( array( 'message' => __( 'Missing passage or recommendation.', 'wp-pugmill' ) ), 400 );
	}

	$system = 'You are a precise content editor. Rewrite the given passage to implement the recommendation provided. Preserve the approximate length and tone unless the recommendation says otherwise. Return ONLY the rewritten passage text — no labels, no explanation, nothing else.'
		. wppugmill_voice_clause();
	$user   = "Original passage:\n{$passage}\n\nRecommendation:\n{$recommendation}";
	$result = wppugmill_call_ai( $r['provider'], $r['api_key'], $system, $user, 300 );

	if ( is_wp_error( $result ) ) {
		wp_send_json_error( array( 'message' => $result->get_error_message() ), 500 );
	}

	wp_send_json_success( array( 'rewritten' => sanitize_textarea_field( trim( $result ) ) ) );
}

add_action( 'wp_ajax_wppugmill_refine_focus', 'wppugmill_ajax_refine_focus' );

function wppugmill_ajax_refine_focus() {
	$r = wppugmill_ai_request_setup( 'wppugmill_refine_focus', 'Refine Focus' );
	if ( empty( trim( $r['content'] ) ) ) {
		wp_send_json_error( array( 'message' => __( 'Post has no content to analyze.', 'wp-pugmill' ) ), 400 );
	}

	$system = 'You are a content editor. Analyze the post and identify 2-3 specific sections, paragraphs, or sentences that dilute or distract from the main topic. For each issue, provide a short label, a direct quote or description of the problematic passage, and a concrete recommendation to fix it. Return ONLY a JSON object: {"issues":[{"label":"Short label","passage":"The problematic text or description","recommendation":"What to do about it"}]}. Be specific and actionable. No explanation outside the JSON.';
	$user   = "Post title: \"{$r['title']}\"\n\nPost content:\n{$r['content']}";
	$result = wppugmill_call_ai( $r['provider'], $r['api_key'], $system, $user, 600 );

	if ( is_wp_error( $result ) ) {
		wp_send_json_error( array( 'message' => $result->get_error_message() ), 500 );
	}

	$decoded = wppugmill_decode_ai_json( $result, $r['provider'] );
	if ( is_wp_error( $decoded ) ) {
		wp_send_json_error( array( 'message' => $decoded->get_error_message() ), 500 );
	}

	// wppugmill_decode_ai_json may unwrap single-key JSON {"issues":[...]} → [...]
	$raw_issues = $decoded['issues'] ?? ( isset( $decoded[0] ) ? $decoded : array() );
	$issues     = array_slice( (array) $raw_issues, 0, 3 );
	$issues = array_map( function( $issue ) {
		return array(
			'label'          => sanitize_text_field( $issue['label']          ?? '' ),
			'passage'        => sanitize_textarea_field( $issue['passage']    ?? '' ),
			'recommendation' => sanitize_textarea_field( $issue['recommendation'] ?? '' ),
		);
	}, $issues );

	if ( empty( $issues ) ) {
		wp_send_json_error( array( 'message' => __( 'AI returned no focus issues.', 'wp-pugmill' ) ), 500 );
	}

	wp_send_json_success( array( 'issues' => $issues ) );
}

// =========================================================================
// 4. Excerpt Generator
// =========================================================================

add_action( 'wp_ajax_wppugmill_generate_excerpt', 'wppugmill_ajax_generate_excerpt' );

function wppugmill_ajax_generate_excerpt() {
	$r = wppugmill_ai_request_setup( 'wppugmill_generate_excerpt', 'Excerpt Generator' );
	if ( empty( trim( $r['content'] ) ) ) {
		wp_send_json_error( array( 'message' => __( 'Post has no content to analyze.', 'wp-pugmill' ) ), 400 );
	}

	$system = 'You are an expert copywriter. Generate a compelling 1-2 sentence excerpt (max 160 characters) for the given blog post. Return ONLY the excerpt text, nothing else.'
		. wppugmill_voice_clause();

	$user   = "Post title: \"{$r['title']}\"\n\nPost content:\n{$r['content']}";
	$result = wppugmill_call_ai( $r['provider'], $r['api_key'], $system, $user, 100 );

	if ( is_wp_error( $result ) ) {
		wp_send_json_error( array( 'message' => $result->get_error_message() ), 500 );
	}

	wp_send_json_success( array(
		'excerpt' => sanitize_textarea_field( mb_substr( trim( $result ), 0, 300 ) ),
	) );
}

// =========================================================================
// 6. Internal Links
// =========================================================================

add_action( 'wp_ajax_wppugmill_internal_links', 'wppugmill_ajax_internal_links' );

function wppugmill_ajax_internal_links() {
	$r = wppugmill_ai_request_setup( 'wppugmill_internal_links', 'Internal Links' );
	if ( empty( trim( $r['content'] ) ) ) {
		wp_send_json_error( array( 'message' => __( 'Post has no content to analyze.', 'wp-pugmill' ) ), 400 );
	}

	// Build index of other published posts for the prompt
	$other_posts = get_posts( array(
		'post_type'      => array( 'post', 'page' ),
		'post_status'    => 'publish',
		'posts_per_page' => 40,
		'exclude'        => array( $r['post']->ID ),
		'orderby'        => 'date',
		'order'          => 'DESC',
	) );

	if ( empty( $other_posts ) ) {
		wp_send_json_error( array( 'message' => __( 'No other published posts found to link to.', 'wp-pugmill' ) ), 400 );
	}

	$index_lines = array();
	foreach ( $other_posts as $p ) {
		$excerpt      = get_the_excerpt( $p );
		$excerpt_snip = $excerpt ? ' | ' . mb_substr( wp_strip_all_tags( $excerpt ), 0, 120 ) : '';
		$index_lines[] = '- "' . get_the_title( $p ) . '" → ' . get_permalink( $p ) . $excerpt_snip;
	}
	$post_index = implode( "\n", $index_lines );

	$content_label = 'page' === $r['post_type'] ? 'page' : 'post';
	$system = 'You are an internal linking expert for a website. Given a ' . $content_label . '\'s content and an index of other published content on the same site, identify 3–5 natural internal linking opportunities. For each suggestion return: the exact URL from the index, the title, the best anchor text (2–6 words from the content), and the context sentence where the link fits. Return ONLY a JSON array: [{"url":"exact-url-from-index","title":"Title","anchorText":"natural anchor text","context":"The exact sentence from the content."}]. Only suggest content that is genuinely topically relevant. Fewer than 3 suggestions is fine if that is all that is relevant. No markdown fences, no explanation outside the JSON.';
	$user   = "Post title: \"{$r['title']}\"\n\nPost content:\n{$r['content']}\n\n---\nPublished posts index:\n{$post_index}";

	$result = wppugmill_call_ai( $r['provider'], $r['api_key'], $system, $user, 600 );

	if ( is_wp_error( $result ) ) {
		wp_send_json_error( array( 'message' => $result->get_error_message() ), 500 );
	}

	$decoded = json_decode( $result, true );
	if ( ! is_array( $decoded ) ) {
		error_log( 'WP Pugmill: internal links invalid JSON: ' . substr( $result, 0, 200 ) );
		wp_send_json_error( array( 'message' => __( 'AI returned an unexpected response format. Please try again.', 'wp-pugmill' ) ), 500 );
	}

	wp_send_json_success( array(
		'links' => array_values( array_filter(
			array_map( function( $item ) {
				return array(
					'url'        => esc_url_raw( $item['url']        ?? '' ),
					'title'      => sanitize_text_field( $item['title']      ?? '' ),
					'anchorText' => sanitize_text_field( $item['anchorText'] ?? '' ),
					'context'    => sanitize_text_field( $item['context']    ?? '' ),
				);
			}, $decoded ),
			function( $item ) { return ! empty( $item['url'] ) && ! empty( $item['anchorText'] ); }
		) ),
	) );
}

// =========================================================================
// 7. Social Media Draft
// =========================================================================

add_action( 'wp_ajax_wppugmill_social_draft', 'wppugmill_ajax_social_draft' );

function wppugmill_ajax_social_draft() {
	$r        = wppugmill_ai_request_setup( 'wppugmill_social_draft', 'Social Draft' );
	$platform = sanitize_text_field( wp_unslash( $_POST['platform'] ?? '' ) );

	$allowed = array( 'linkedin', 'x', 'facebook', 'substack' );
	if ( ! in_array( $platform, $allowed, true ) ) {
		wp_send_json_error( array( 'message' => __( 'Invalid platform.', 'wp-pugmill' ) ), 400 );
	}

	if ( empty( trim( $r['content'] ) ) ) {
		wp_send_json_error( array( 'message' => __( 'Content has nothing to draft from.', 'wp-pugmill' ) ), 400 );
	}

	$is_page       = 'page' === $r['post_type'];
	$content_label = $is_page ? 'page' : 'post';

	$limits = array(
		'linkedin' => 700,
		'x'        => 280,
		'facebook' => 500,
		'substack' => 300,
	);

	$constraints = array(
		'linkedin'  => 'LinkedIn post (professional tone, HARD LIMIT: 700 characters — count every character including spaces and hashtags before outputting, no hashtag spam — 2-3 relevant hashtags max, can include a short question to prompt engagement)',
		'x'         => 'X/Twitter post (punchy, hook-first, HARD LIMIT: 280 characters including any hashtags — if you go over you will be truncated, 1-2 hashtags max)',
		'facebook'  => 'Facebook post (conversational and warm, HARD LIMIT: 500 characters, can end with a question, 0-2 hashtags)',
		'substack'  => 'Substack Note (thoughtful newsletter-audience tone, HARD LIMIT: 300 characters, no hashtags, ends with a subtle hook to read the full piece)',
	);

	$system = 'You are a social media copywriter. Write a single ' . $constraints[ $platform ] . ' based on the ' . $content_label . ' below. Use the AEO metadata (summary, Q&A, keywords) as your primary source — it is already the distilled signal of the content. Return ONLY the social post text, nothing else — no labels, no quotes around it, no explanation.'
		. wppugmill_voice_clause();

	$aeo_meta    = get_post_meta( $r['post']->ID, '_wppugmill_aeo', true );
	$aeo_snippet = '';
	if ( is_array( $aeo_meta ) ) {
		if ( ! empty( $aeo_meta['summary'] ) ) {
			$aeo_snippet .= 'Summary: ' . $aeo_meta['summary'] . "\n";
		}
		if ( ! empty( $aeo_meta['questions'] ) ) {
			foreach ( array_slice( $aeo_meta['questions'], 0, 2 ) as $qa ) {
				$aeo_snippet .= 'Q: ' . ( $qa['q'] ?? '' ) . "\nA: " . ( $qa['a'] ?? '' ) . "\n";
			}
		}
		if ( ! empty( $aeo_meta['keywords'] ) ) {
			$aeo_snippet .= 'Keywords: ' . implode( ', ', array_slice( $aeo_meta['keywords'], 0, 8 ) ) . "\n";
		}
	}

	$title_label = $is_page ? 'Page title' : 'Post title';
	$user = "{$title_label}: \"{$r['title']}\"\n\n"
		. ( $aeo_snippet ? "AEO metadata:\n{$aeo_snippet}\n" : '' )
		. ucfirst( $content_label ) . " content:\n{$r['content']}";

	$max_tokens = ( 'x' === $platform || 'substack' === $platform ) ? 120 : 250;
	$result     = wppugmill_call_ai( $r['provider'], $r['api_key'], $system, $user, $max_tokens );

	if ( is_wp_error( $result ) ) {
		wp_send_json_error( array( 'message' => $result->get_error_message() ), 500 );
	}

	$draft = sanitize_textarea_field( trim( $result ) );
	$limit = $limits[ $platform ];

	// Backstop: if AI still exceeded the limit, trim at the last word boundary.
	if ( mb_strlen( $draft ) > $limit ) {
		$draft = mb_substr( $draft, 0, $limit );
		// Trim back to the last space to avoid cutting mid-word.
		$last_space = mb_strrpos( $draft, ' ' );
		if ( $last_space !== false ) {
			$draft = mb_substr( $draft, 0, $last_space );
		}
		$draft = rtrim( $draft, ',.;: ' ) . '…';
	}

	wp_send_json_success( array(
		'draft'    => $draft,
		'platform' => $platform,
	) );
}

// =========================================================================
// 8. Individual AEO Field Generators (free tier — BYOK, no license required)
// =========================================================================

add_action( 'wp_ajax_wppugmill_generate_summary',  'wppugmill_ajax_generate_summary' );
add_action( 'wp_ajax_wppugmill_generate_qa',       'wppugmill_ajax_generate_qa' );
add_action( 'wp_ajax_wppugmill_generate_entities', 'wppugmill_ajax_generate_entities' );
add_action( 'wp_ajax_wppugmill_generate_keywords', 'wppugmill_ajax_generate_keywords' );

function wppugmill_ajax_generate_summary() {
	$r = wppugmill_ai_request_setup( 'wppugmill_generate_summary', 'Summary', false );
	if ( empty( trim( $r['content'] ) ) ) {
		wp_send_json_error( array( 'message' => __( 'Post has no content to analyze.', 'wp-pugmill' ) ), 400 );
	}

	$system = 'You are an AEO (Answer Engine Optimization) specialist. Write a 2-3 sentence plain-language summary of the given content, optimized for AI answer engines (ChatGPT, Perplexity, Gemini). Be specific and factual — no marketing language. Return ONLY a JSON object: {"summary": "your summary here"}. No markdown, no explanation outside the JSON.';
	$result = wppugmill_call_ai( $r['provider'], $r['api_key'], $system, wppugmill_aeo_user_prompt( $r['title'], $r['content'] ), 200 );

	if ( is_wp_error( $result ) ) {
		wp_send_json_error( array( 'message' => $result->get_error_message() ), 500 );
	}

	$decoded = json_decode( $result, true );
	$summary = sanitize_textarea_field( $decoded['summary'] ?? '' );
	if ( empty( $summary ) ) {
		wp_send_json_error( array( 'message' => __( 'AI returned an unexpected response. Please try again.', 'wp-pugmill' ) ), 500 );
	}

	wp_send_json_success( array( 'summary' => $summary ) );
}

function wppugmill_ajax_generate_qa() {
	$r = wppugmill_ai_request_setup( 'wppugmill_generate_qa', 'Q&A Pairs', false );
	if ( empty( trim( $r['content'] ) ) ) {
		wp_send_json_error( array( 'message' => __( 'Post has no content to analyze.', 'wp-pugmill' ) ), 400 );
	}

	$system = 'You are an AEO (Answer Engine Optimization) specialist. Generate 3-5 Q&A pairs that real users might search for based on this content. Questions should be specific and answerable from the content. Return ONLY a JSON object: {"questions": [{"q": "question", "a": "clear direct answer"}]}. No markdown, no explanation outside the JSON.';
	$result = wppugmill_call_ai( $r['provider'], $r['api_key'], $system, wppugmill_aeo_user_prompt( $r['title'], $r['content'] ), 600 );

	if ( is_wp_error( $result ) ) {
		wp_send_json_error( array( 'message' => $result->get_error_message() ), 500 );
	}

	$decoded   = json_decode( $result, true );
	$raw_pairs = $decoded['questions'] ?? ( isset( $decoded[0] ) ? $decoded : array() );

	if ( ! is_array( $raw_pairs ) ) {
		wp_send_json_error( array( 'message' => __( 'AI returned an unexpected response. Please try again.', 'wp-pugmill' ) ), 500 );
	}

	$questions = array_values( array_filter(
		array_map( function( $qa ) {
			return array(
				'q' => sanitize_text_field( $qa['q'] ?? '' ),
				'a' => sanitize_textarea_field( $qa['a'] ?? '' ),
			);
		}, $raw_pairs ),
		function( $qa ) { return ! empty( $qa['q'] ) && ! empty( $qa['a'] ); }
	) );

	wp_send_json_success( array( 'questions' => $questions ) );
}

function wppugmill_ajax_generate_entities() {
	$r = wppugmill_ai_request_setup( 'wppugmill_generate_entities', 'Named Entities', false );
	if ( empty( trim( $r['content'] ) ) ) {
		wp_send_json_error( array( 'message' => __( 'Post has no content to analyze.', 'wp-pugmill' ) ), 400 );
	}

	$allowed_types = array( 'Thing', 'Person', 'Organization', 'Product', 'Place', 'Event', 'Technology', 'DefinedTerm' );
	$system = 'You are an AEO (Answer Engine Optimization) specialist. Extract 3-8 named entities actually mentioned in this content. For each entity provide a name, type, and brief description. Type must be one of: Thing, Person, Organization, Product, Place, Event, Technology, DefinedTerm. Return ONLY a JSON object: {"entities": [{"name": "entity name", "type": "Type", "description": "brief description"}]}. No markdown, no explanation outside the JSON.';
	$result = wppugmill_call_ai( $r['provider'], $r['api_key'], $system, wppugmill_aeo_user_prompt( $r['title'], $r['content'] ), 500 );

	if ( is_wp_error( $result ) ) {
		wp_send_json_error( array( 'message' => $result->get_error_message() ), 500 );
	}

	$decoded      = json_decode( $result, true );
	$raw_entities = $decoded['entities'] ?? ( isset( $decoded[0] ) ? $decoded : array() );

	if ( ! is_array( $raw_entities ) ) {
		wp_send_json_error( array( 'message' => __( 'AI returned an unexpected response. Please try again.', 'wp-pugmill' ) ), 500 );
	}

	$entities = array_values( array_filter(
		array_map( function( $entity ) use ( $allowed_types ) {
			$type = sanitize_text_field( $entity['type'] ?? 'Thing' );
			return array(
				'name'        => sanitize_text_field( $entity['name'] ?? '' ),
				'type'        => in_array( $type, $allowed_types, true ) ? $type : 'Thing',
				'description' => sanitize_text_field( $entity['description'] ?? '' ),
			);
		}, $raw_entities ),
		function( $e ) { return ! empty( $e['name'] ); }
	) );

	wp_send_json_success( array( 'entities' => $entities ) );
}

function wppugmill_ajax_generate_keywords() {
	$r = wppugmill_ai_request_setup( 'wppugmill_generate_keywords', 'Keywords', false );
	if ( empty( trim( $r['content'] ) ) ) {
		wp_send_json_error( array( 'message' => __( 'Post has no content to analyze.', 'wp-pugmill' ) ), 400 );
	}

	$system = 'You are an AEO (Answer Engine Optimization) specialist. Extract 5-15 specific, search-focused keywords from this content. Avoid generic words — prefer specific terms readers would actually search for. Return ONLY a JSON object: {"keywords": ["keyword1", "keyword2"]}. No markdown, no explanation outside the JSON.';
	$result = wppugmill_call_ai( $r['provider'], $r['api_key'], $system, wppugmill_aeo_user_prompt( $r['title'], $r['content'] ), 200 );

	if ( is_wp_error( $result ) ) {
		wp_send_json_error( array( 'message' => $result->get_error_message() ), 500 );
	}

	$decoded      = json_decode( $result, true );
	$raw_keywords = $decoded['keywords'] ?? ( isset( $decoded[0] ) ? $decoded : array() );

	if ( ! is_array( $raw_keywords ) ) {
		wp_send_json_error( array( 'message' => __( 'AI returned an unexpected response. Please try again.', 'wp-pugmill' ) ), 500 );
	}

	$keywords = array_values( array_filter(
		array_map( 'sanitize_text_field', $raw_keywords )
	) );

	wp_send_json_success( array( 'keywords' => $keywords ) );
}

// =========================================================================
// 9. SEO Fields Generator
// =========================================================================

add_action( 'wp_ajax_wppugmill_generate_seo', 'wppugmill_ajax_generate_seo' );

/**
 * AJAX handler — generate SEO title and meta description for a post.
 *
 * Returns: { title: string, meta_desc: string }
 */
function wppugmill_ajax_generate_seo() {
	$r = wppugmill_ai_request_setup( 'wppugmill_generate_seo', 'SEO Generator' );
	if ( empty( trim( $r['content'] ) ) ) {
		wp_send_json_error( array( 'message' => __( 'Post has no content to analyze.', 'wp-pugmill' ) ), 400 );
	}

	// Pull existing AEO summary to improve meta description quality
	$aeo_meta = get_post_meta( $r['post']->ID, '_wppugmill_aeo', true );
	$summary  = is_array( $aeo_meta ) && ! empty( $aeo_meta['summary'] ) ? $aeo_meta['summary'] : '';

	$system = 'You are an expert SEO copywriter. Generate an SEO title and meta description for the given content.

Rules:
- title: 40–60 characters, no brand suffix needed, front-load the primary keyword, compelling but not clickbait
- meta_desc: 120–155 characters, plain text only (no quotes or HTML), includes a natural call to action, does not repeat the title word for word

Return ONLY a JSON object: {"title":"...","meta_desc":"..."}. No markdown, no explanation outside the JSON.'
		. wppugmill_voice_clause();

	$aeo_clause = $summary ? "\n\nAEO Summary (use as context): {$summary}" : '';
	$user       = "Post title: \"{$r['title']}\"\n\nPost content:\n{$r['content']}{$aeo_clause}";

	$result = wppugmill_call_ai( $r['provider'], $r['api_key'], $system, $user, 200 );

	if ( is_wp_error( $result ) ) {
		wp_send_json_error( array( 'message' => $result->get_error_message() ), 500 );
	}

	$decoded = wppugmill_decode_ai_json( $result, $r['provider'] );
	if ( is_wp_error( $decoded ) ) {
		wp_send_json_error( array( 'message' => $decoded->get_error_message() ), 500 );
	}

	$title     = sanitize_text_field( $decoded['title']     ?? '' );
	$meta_desc = sanitize_text_field( $decoded['meta_desc'] ?? '' );

	if ( empty( $title ) || empty( $meta_desc ) ) {
		wp_send_json_error( array( 'message' => __( 'AI returned an incomplete response. Please try again.', 'wp-pugmill' ) ), 500 );
	}

	wp_send_json_success( array(
		'title'     => mb_substr( $title,     0, 80 ),
		'meta_desc' => mb_substr( $meta_desc, 0, 200 ),
	) );
}

// =========================================================================
// 10. HowTo Steps Generator
// =========================================================================

add_action( 'wp_ajax_wppugmill_generate_howto_steps', 'wppugmill_ajax_generate_howto_steps' );

/**
 * AJAX handler — draft a HowTo schema description and steps from post content.
 *
 * Returns: { description: string, steps: [{name: string, text: string}] }
 */
function wppugmill_ajax_generate_howto_steps() {
	$r = wppugmill_ai_request_setup( 'wppugmill_generate_howto_steps', 'HowTo Steps Generator' );
	if ( empty( trim( $r['content'] ) ) ) {
		wp_send_json_error( array( 'message' => __( 'Post has no content to analyze.', 'wp-pugmill' ) ), 400 );
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
		. wppugmill_voice_clause();

	$user   = "Post title: \"{$r['title']}\"\n\nPost content:\n{$r['content']}";
	$result = wppugmill_call_ai( $r['provider'], $r['api_key'], $system, $user, 800 );

	if ( is_wp_error( $result ) ) {
		wp_send_json_error( array( 'message' => $result->get_error_message() ), 500 );
	}

	$decoded = wppugmill_decode_ai_json( $result, $r['provider'] );
	if ( is_wp_error( $decoded ) ) {
		wp_send_json_error( array( 'message' => $decoded->get_error_message() ), 500 );
	}

	$description = sanitize_text_field( $decoded['description'] ?? '' );
	$raw_steps   = is_array( $decoded['steps'] ?? null ) ? $decoded['steps'] : array();

	if ( empty( $raw_steps ) ) {
		wp_send_json_error( array( 'message' => __( 'AI could not identify steps in this content. Make sure the post describes a process.', 'wp-pugmill' ) ), 400 );
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
// Prompts (original AEO metadata generation)
// =========================================================================

function wppugmill_aeo_system_prompt() {
	return 'You are an AEO (Answer Engine Optimization) specialist. Your job is to analyze content and generate structured metadata that makes it maximally discoverable and citable by AI answer engines like ChatGPT, Perplexity, and Gemini.

Return ONLY a valid JSON object with exactly these fields:
{
  "summary": "2-3 sentence plain-language description optimized for AI crawlers. Be specific and informative.",
  "questions": [
    {"q": "A question a reader might ask", "a": "A clear, direct answer from the content"}
  ],
  "entities": [
    {"name": "Entity name", "type": "One of: Thing, Person, Organization, Product, Place, Event, Technology", "description": "Brief description of this entity in context"}
  ],
  "keywords": ["keyword1", "keyword2"]
}

Rules:
- summary: 2-3 sentences, factual, no marketing fluff
- questions: 3-5 Q&A pairs that real users might search for
- entities: 3-8 named entities actually mentioned in the content
- keywords: 5-15 specific search terms, not generic words
- Return ONLY the JSON object, no explanation, no markdown code blocks'
		. wppugmill_voice_clause();
}

function wppugmill_aeo_user_prompt( $title, $content ) {
	return "Title: {$title}\n\nContent:\n{$content}";
}

// -------------------------------------------------------------------------
// Provider calls
// -------------------------------------------------------------------------

/**
 * Common HTTP args for all AI provider calls.
 */
function wppugmill_ai_request_args( array $headers, $body ) {
	return array(
		'timeout'   => 30,
		'sslverify' => true,
		'headers'   => $headers,
		'body'      => $body,
	);
}

function wppugmill_generate_via_anthropic( $api_key, $title, $content ) {
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
				'max_tokens' => 2048,
				'system'     => wppugmill_aeo_system_prompt(),
				'messages'   => array(
					array( 'role' => 'user', 'content' => wppugmill_aeo_user_prompt( $title, $content ) ),
				),
			) )
		)
	);

	return wppugmill_parse_ai_response( $response, 'anthropic' );
}

function wppugmill_generate_via_openai( $api_key, $title, $content ) {
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
					array( 'role' => 'system', 'content' => wppugmill_aeo_system_prompt() ),
					array( 'role' => 'user',   'content' => wppugmill_aeo_user_prompt( $title, $content ) ),
				),
				'max_tokens'      => 2048,
				'response_format' => array( 'type' => 'json_object' ),
			) )
		)
	);

	return wppugmill_parse_ai_response( $response, 'openai' );
}

function wppugmill_generate_via_gemini( $api_key, $title, $content ) {
	// Key passed as header, never in URL
	$response = wp_remote_post(
		'https://generativelanguage.googleapis.com/v1beta/models/gemini-1.5-flash:generateContent',
		wppugmill_ai_request_args(
			array(
				'Content-Type'  => 'application/json',
				'x-goog-api-key' => $api_key,
			),
			wp_json_encode( array(
				'contents' => array(
					array(
						'parts' => array(
							array( 'text' => wppugmill_aeo_system_prompt() . "\n\n" . wppugmill_aeo_user_prompt( $title, $content ) ),
						),
					),
				),
				'generationConfig' => array(
					'response_mime_type' => 'application/json',
					'max_output_tokens'  => 2048,
				),
			) )
		)
	);

	return wppugmill_parse_ai_response( $response, 'gemini' );
}

// -------------------------------------------------------------------------
// Response parsing
// -------------------------------------------------------------------------

function wppugmill_parse_ai_response( $response, $provider ) {
	if ( is_wp_error( $response ) ) {
		// Log detail, return generic message to client
		error_log( 'WP Pugmill AI error (' . $provider . '): ' . $response->get_error_message() );
		return new WP_Error( 'request_failed', __( 'Could not reach AI provider. Please check your connection and try again.', 'wp-pugmill' ) );
	}

	$code = wp_remote_retrieve_response_code( $response );
	$body = wp_remote_retrieve_body( $response );
	$data = json_decode( $body, true );

	if ( 200 !== $code ) {
		// Log provider-specific error detail, return generic message to client
		$detail = '';
		switch ( $provider ) {
			case 'anthropic': $detail = $data['error']['message'] ?? $body; break;
			case 'openai':    $detail = $data['error']['message'] ?? $body; break;
			case 'gemini':    $detail = $data['error']['message'] ?? $body; break;
		}
		error_log( 'WP Pugmill AI provider error (' . $provider . ' ' . $code . '): ' . $detail );

		// Surface safe, actionable messages for known error codes
		if ( 401 === $code ) {
			return new WP_Error( 'provider_error', __( 'Invalid API key. Please check your key in Settings → WP Pugmill.', 'wp-pugmill' ) );
		}
		if ( 429 === $code ) {
			return new WP_Error( 'provider_error', __( 'AI provider rate limit reached. Please wait a moment and try again.', 'wp-pugmill' ) );
		}
		if ( 402 === $code ) {
			return new WP_Error( 'provider_error', __( 'Insufficient credits on your AI provider account. Please top up and try again.', 'wp-pugmill' ) );
		}
		return new WP_Error( 'provider_error', __( 'AI provider returned an error. Please try again.', 'wp-pugmill' ) );
	}

	// Extract text from provider response
	$raw_text = '';
	switch ( $provider ) {
		case 'anthropic': $raw_text = $data['content'][0]['text'] ?? ''; break;
		case 'openai':    $raw_text = $data['choices'][0]['message']['content'] ?? ''; break;
		case 'gemini':    $raw_text = $data['candidates'][0]['content']['parts'][0]['text'] ?? ''; break;
	}

	if ( empty( $raw_text ) ) {
		return new WP_Error( 'empty_response', __( 'AI returned an empty response. Please try again.', 'wp-pugmill' ) );
	}

	// Strip markdown code fences if present (defensive)
	$raw_text = preg_replace( '/^```(?:json)?\s*/i', '', trim( $raw_text ) );
	$raw_text = preg_replace( '/\s*```$/', '', $raw_text );

	// If there's still preamble text, extract the JSON object directly.
	if ( ! is_array( json_decode( $raw_text, true ) ) ) {
		if ( preg_match( '/\{[\s\S]*\}/s', $raw_text, $m ) ) {
			$raw_text = $m[0];
		}
	}

	$aeo = json_decode( $raw_text, true );
	if ( ! is_array( $aeo ) ) {
		error_log( 'WP Pugmill: AI returned invalid JSON from ' . $provider . ': ' . substr( $raw_text, 0, 200 ) );
		return new WP_Error( 'invalid_json', __( 'AI returned an unexpected response format. Please try again.', 'wp-pugmill' ) );
	}

	$allowed_types = array( 'Thing', 'Person', 'Organization', 'Product', 'Place', 'Event', 'Technology', 'DefinedTerm' );

	return array(
		'summary'   => sanitize_textarea_field( $aeo['summary'] ?? '' ),
		'questions' => array_values( array_filter(
			array_map( function( $qa ) {
				return array(
					'q' => sanitize_text_field( $qa['q'] ?? '' ),
					'a' => sanitize_textarea_field( $qa['a'] ?? '' ),
				);
			}, is_array( $aeo['questions'] ?? null ) ? $aeo['questions'] : array() ),
			function( $qa ) { return ! empty( $qa['q'] ) && ! empty( $qa['a'] ); }
		) ),
		'entities'  => array_values( array_filter(
			array_map( function( $entity ) use ( $allowed_types ) {
				$type = sanitize_text_field( $entity['type'] ?? 'Thing' );
				return array(
					'name'        => sanitize_text_field( $entity['name'] ?? '' ),
					'type'        => in_array( $type, $allowed_types, true ) ? $type : 'Thing',
					'description' => sanitize_text_field( $entity['description'] ?? '' ),
				);
			}, is_array( $aeo['entities'] ?? null ) ? $aeo['entities'] : array() ),
			function( $e ) { return ! empty( $e['name'] ); }
		) ),
		'keywords'  => array_values( array_filter(
			array_map( 'sanitize_text_field', is_array( $aeo['keywords'] ?? null ) ? $aeo['keywords'] : array() )
		) ),
	);
}

// =========================================================================
// Schema suggestion — detect schema type + pre-fill fields from post content
// =========================================================================

add_action( 'wp_ajax_wppugmill_suggest_schema', 'wppugmill_ajax_suggest_schema' );

/**
 * AJAX handler — analyse post content and suggest the best Schema.org type
 * plus pre-filled field values.
 */
function wppugmill_ajax_suggest_schema() {
	check_ajax_referer( 'wppugmill_suggest_schema', 'nonce' );

	if ( ! current_user_can( 'edit_posts' ) ) {
		wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'wp-pugmill' ) ), 403 );
	}

	$rate_check = wppugmill_check_rate_limit();
	if ( is_wp_error( $rate_check ) ) {
		wp_send_json_error( array( 'message' => $rate_check->get_error_message() ), 429 );
	}

	$post_id = absint( wp_unslash( $_POST['post_id'] ?? 0 ) );
	if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) ) {
		wp_send_json_error( array( 'message' => __( 'Invalid post.', 'wp-pugmill' ) ), 400 );
	}

	$post = get_post( $post_id );
	if ( ! $post ) {
		wp_send_json_error( array( 'message' => __( 'Post not found.', 'wp-pugmill' ) ), 404 );
	}

	$mode = wppugmill_mode();
	if ( 'free' === $mode ) {
		wp_send_json_error( array(
			'message' => __( 'Schema suggestions require a WP Pugmill AI Connector license.', 'wp-pugmill' ),
		), 403 );
	}

	$provider = get_option( 'wppugmill_ai_provider', 'anthropic' );
	$api_key  = wppugmill_get_encrypted_option( 'wppugmill_ai_api_key', '' );
	if ( empty( $api_key ) ) {
		wp_send_json_error( array( 'message' => __( 'No API key configured.', 'wp-pugmill' ) ), 400 );
	}

	$title   = get_the_title( $post );
	$content = wp_strip_all_tags( apply_filters( 'the_content', $post->post_content ) );
	$content = html_entity_decode( $content, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	$content = mb_substr( $content, 0, WPPUGMILL_MAX_AI_INPUT );

	if ( empty( trim( $content ) ) ) {
		wp_send_json_error( array( 'message' => __( 'Post has no content to analyse.', 'wp-pugmill' ) ), 400 );
	}

	$system = 'You are a Schema.org structured data specialist. Analyse the post title and content to determine the single best additional Schema.org type — if any. Only choose a specific type if the content clearly warrants it. If it is a general article or blog post, return {"type":""}.

Available types and their exact field keys:
- "HowTo":         {"description":"string","total_time":"ISO 8601 e.g. PT30M","steps":[{"name":"string","text":"string"}]}
- "Product":       {"name":"string","description":"string","brand":"string","price":"string","currency":"USD","availability":"InStock|OutOfStock|PreOrder"}
- "Event":         {"name":"string","description":"string","start_date":"YYYY-MM-DDTHH:MM","end_date":"YYYY-MM-DDTHH:MM","location_name":"string","location_address":"string","organizer":"string"}
- "LocalBusiness": {"name":"string","description":"string","address":"string","phone":"string","hours":"string e.g. Mo-Fr 09:00-17:00","price_range":"string","business_type":"LocalBusiness"}
- "VideoObject":   {"name":"string","description":"string","upload_date":"YYYY-MM-DD","duration":"ISO 8601 e.g. PT5M30S","thumbnail_url":"string","embed_url":"string"}

Return ONLY a JSON object with key "type" and, when a type is chosen, a second key matching the lowercase type name (e.g. "howto", "product"). Populate only fields you can infer from the content — leave others as empty strings. No markdown, no explanation.';

	$user_prompt = "Title: {$title}\n\nContent:\n{$content}";

	$result = wppugmill_call_ai( $provider, $api_key, $system, $user_prompt, 800 );

	if ( is_wp_error( $result ) ) {
		wp_send_json_error( array( 'message' => $result->get_error_message() ), 500 );
	}

	// Validate type
	$allowed = array( '', 'HowTo', 'Product', 'Event', 'LocalBusiness', 'VideoObject' );
	$type    = sanitize_text_field( $result['type'] ?? '' );
	if ( ! in_array( $type, $allowed, true ) ) {
		$type = '';
	}

	$sanitized = array( 'type' => $type );

	if ( 'HowTo' === $type && isset( $result['howto'] ) ) {
		$h = $result['howto'];
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
	} elseif ( 'Product' === $type && isset( $result['product'] ) ) {
		$p     = $result['product'];
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
	} elseif ( 'Event' === $type && isset( $result['event'] ) ) {
		$e = $result['event'];
		$sanitized['event'] = array(
			'name'             => sanitize_text_field( $e['name'] ?? '' ),
			'description'      => sanitize_textarea_field( $e['description'] ?? '' ),
			'start_date'       => sanitize_text_field( $e['start_date'] ?? '' ),
			'end_date'         => sanitize_text_field( $e['end_date'] ?? '' ),
			'location_name'    => sanitize_text_field( $e['location_name'] ?? '' ),
			'location_address' => sanitize_text_field( $e['location_address'] ?? '' ),
			'organizer'        => sanitize_text_field( $e['organizer'] ?? '' ),
		);
	} elseif ( 'LocalBusiness' === $type && isset( $result['localbusiness'] ) ) {
		$b = $result['localbusiness'];
		$sanitized['local_business'] = array(
			'name'          => sanitize_text_field( $b['name'] ?? '' ),
			'description'   => sanitize_textarea_field( $b['description'] ?? '' ),
			'address'       => sanitize_text_field( $b['address'] ?? '' ),
			'phone'         => sanitize_text_field( $b['phone'] ?? '' ),
			'hours'         => sanitize_text_field( $b['hours'] ?? '' ),
			'price_range'   => sanitize_text_field( $b['price_range'] ?? '' ),
			'business_type' => sanitize_text_field( $b['business_type'] ?? 'LocalBusiness' ),
		);
	} elseif ( 'VideoObject' === $type && isset( $result['videoobject'] ) ) {
		$v = $result['videoobject'];
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

// =========================================================================
// Site Summary Generator — settings page AI action
//
// 3rd-party AI service disclosure:
// The handler below sends site name, tagline, and recent post titles (all
// admin-authored, public site metadata) to the configured external AI
// provider. No visitor data is transmitted. External services are disclosed
// in the plugin's readme.txt "External Services" section.
// =========================================================================

add_action( 'wp_ajax_wppugmill_generate_site_summary', 'wppugmill_ajax_generate_site_summary' );

/**
 * AJAX handler — generate a site summary for /llms.txt and Organization schema.
 *
 * Requires manage_options (settings-level action, not per-post).
 * Uses blog name, tagline, and recent post titles as context.
 */
function wppugmill_ajax_generate_site_summary() {
	check_ajax_referer( 'wppugmill_generate_site_summary', 'nonce' );

	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'wp-pugmill' ) ), 403 );
	}

	$rate_check = wppugmill_check_rate_limit();
	if ( is_wp_error( $rate_check ) ) {
		wp_send_json_error( array( 'message' => $rate_check->get_error_message() ), 429 );
	}

	$mode = wppugmill_mode();
	if ( 'free' === $mode ) {
		wp_send_json_error( array(
			'message' => __( 'AI generation requires a WP Pugmill AI Connector license. <a href="https://wppugmill.com/pricing" target="_blank">Get your license →</a>', 'wp-pugmill' ),
		), 403 );
	}

	$provider = get_option( 'wppugmill_ai_provider', 'anthropic' );
	$api_key  = wppugmill_get_encrypted_option( 'wppugmill_ai_api_key', '' );
	if ( empty( $api_key ) ) {
		wp_send_json_error( array( 'message' => __( 'No API key configured. Add your key in Settings → WP Pugmill → AI Provider.', 'wp-pugmill' ) ), 400 );
	}

	$blog_name = get_bloginfo( 'name' );
	$tagline   = get_bloginfo( 'description' );
	$org_name  = get_option( 'wppugmill_org_name', '' );

	// Gather recent post titles for topic context
	$recent_posts = get_posts( array( 'numberposts' => 8, 'post_status' => 'publish', 'fields' => 'ids' ) );
	$titles       = array_map( 'get_the_title', $recent_posts );
	$titles_str   = implode( '; ', array_filter( $titles ) );

	$system = 'You are an AEO (Answer Engine Optimization) expert. Write a concise, factual site summary for use in /llms.txt and Organization schema — read by AI crawlers, not human visitors. The summary must describe what the site is about in 2–3 sentences. Be specific and factual: state the topics covered, the type of content, and who it is for. No marketing language, no superlatives, no calls to action. Return ONLY the summary text — no preamble, no quotes, no markdown.';

	$user = "Site name: {$blog_name}";
	if ( $tagline ) {
		$user .= "\nTagline: {$tagline}";
	}
	if ( $org_name ) {
		$user .= "\nOrganization: {$org_name}";
	}
	if ( $titles_str ) {
		$user .= "\nRecent content: {$titles_str}";
	}

	$result = wppugmill_call_ai( $provider, $api_key, $system, $user, 200 );

	if ( is_wp_error( $result ) ) {
		wp_send_json_error( array( 'message' => $result->get_error_message() ), 500 );
	}

	wp_send_json_success( array( 'summary' => sanitize_textarea_field( trim( $result ) ) ) );
}
