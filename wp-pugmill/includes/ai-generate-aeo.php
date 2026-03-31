<?php
/**
 * WP Pugmill — AEO generation AJAX handlers and support functions.
 *
 * Covers:
 *   - wppugmill_ajax_generate_aeo()        — combined AEO handler (registered in ai.php)
 *   - wppugmill_ajax_generate_summary()    — individual summary generator (free tier)
 *   - wppugmill_ajax_generate_qa()         — individual Q&A generator (free tier)
 *   - wppugmill_ajax_generate_entities()   — individual entity extractor (free tier)
 *   - wppugmill_ajax_generate_keywords()   — individual keyword extractor (free tier)
 *   - wppugmill_aeo_system_prompt()        — shared system prompt for combined AEO generation
 *   - wppugmill_aeo_user_prompt()          — shared user prompt builder
 *   - wppugmill_generate_via_anthropic()   — legacy per-provider dispatch (combined AEO)
 *   - wppugmill_generate_via_openai()      — legacy per-provider dispatch (combined AEO)
 *   - wppugmill_generate_via_gemini()      — legacy per-provider dispatch (combined AEO)
 *   - wppugmill_parse_ai_response()        — legacy response parser (combined AEO)
 *
 * Depends on: ai-client.php (wppugmill_ai_request_setup, wppugmill_call_ai,
 *             wppugmill_ai_request_args), ai-content.php (wppugmill_voice_clause)
 *
 * @package WPPugmill
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// =========================================================================
// Individual AEO Field Generators (free tier — BYOK, no license required)
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
// Provider calls (legacy — used by the combined wppugmill_ajax_generate_aeo handler)
// -------------------------------------------------------------------------

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
				'Content-Type'   => 'application/json',
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
// Response parsing (legacy — used by the combined wppugmill_ajax_generate_aeo handler)
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
