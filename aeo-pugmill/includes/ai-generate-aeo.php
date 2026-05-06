<?php
/**
 * Pugmill AEO Toolkit — AEO generation AJAX handlers and support functions.
 *
 * Covers:
 *   - aeopugmill_ajax_generate_summary()    — individual summary generator
 *   - aeopugmill_ajax_generate_qa()         — individual Q&A generator
 *   - aeopugmill_ajax_generate_entities()   — individual entity extractor
 *   - aeopugmill_ajax_generate_keywords()   — individual keyword extractor
 *   - aeopugmill_aeo_system_prompt()        — shared system prompt for combined AEO generation
 *   - aeopugmill_aeo_user_prompt()          — shared user prompt builder
 *   - aeopugmill_validate_same_as_url()     — entity sameAs URL validator
 *
 * Depends on: ai-client.php (aeopugmill_ai_request_setup, aeopugmill_call_ai,
 *             aeopugmill_ai_request_args), ai-content.php (aeopugmill_voice_clause)
 *
 * @package WPPugmill
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// =========================================================================
// Helpers
// =========================================================================

/**
 * Validate and sanitize a candidate sameAs URL for entity schema.
 *
 * Only accepts Wikidata and Wikipedia URLs — the two authoritative knowledge-
 * graph sources. Returns the sanitized URL on success, empty string otherwise.
 * This prevents AI-hallucinated URLs from poisoning JSON-LD output.
 *
 * @param  string $url
 * @return string
 */
function aeopugmill_validate_same_as_url( $url ) {
	$url = trim( (string) $url );
	if ( empty( $url ) ) {
		return '';
	}

	// Must be a valid URL.
	if ( ! filter_var( $url, FILTER_VALIDATE_URL ) ) {
		return '';
	}

	// Must use HTTPS.
	$parsed = wp_parse_url( $url );
	if ( empty( $parsed['scheme'] ) || 'https' !== $parsed['scheme'] ) {
		return '';
	}

	// Host must be wikidata.org or a *.wikipedia.org subdomain.
	$host = strtolower( $parsed['host'] ?? '' );
	$allowed = (
		$host === 'www.wikidata.org' ||
		$host === 'wikidata.org'     ||
		substr( $host, -14 ) === '.wikipedia.org'
	);

	if ( ! $allowed ) {
		return '';
	}

	return esc_url_raw( $url );
}

// =========================================================================
// Individual AEO Field Generators (free tier — BYOK, no license required)
// =========================================================================

add_action( 'wp_ajax_aeopugmill_generate_summary',  'aeopugmill_ajax_generate_summary' );
add_action( 'wp_ajax_aeopugmill_generate_qa',       'aeopugmill_ajax_generate_qa' );
add_action( 'wp_ajax_aeopugmill_generate_entities', 'aeopugmill_ajax_generate_entities' );
add_action( 'wp_ajax_aeopugmill_generate_keywords', 'aeopugmill_ajax_generate_keywords' );

/**
 * Merge a single generated AEO field into the stored meta and refresh the score.
 * Called only when the autosave flag is present (e.g. from the Audit AEO page).
 * The post editor omits the flag so users can review generated content before saving.
 *
 * @param int    $post_id
 * @param string $field   summary|questions|entities|keywords
 * @param mixed  $value
 */
function aeopugmill_autosave_aeo_field( $post_id, $field, $value ) {
	$existing          = aeopugmill_get_aeo( $post_id );
	$existing[ $field ] = $value;
	aeopugmill_save_aeo( $post_id, $existing );
	$health = aeopugmill_health_score( $post_id );
	update_post_meta( $post_id, '_aeopugmill_score', (int) $health['score'] );
}

function aeopugmill_ajax_generate_summary() {
	$r = aeopugmill_ai_request_setup( 'aeopugmill_generate_summary', 'Summary', false );
	if ( empty( trim( $r['content'] ) ) ) {
		wp_send_json_error( array( 'message' => __( 'Post has no content to analyze.', 'aeo-pugmill' ) ), 400 );
	}

	$system = 'You are an AEO (Answer Engine Optimization) specialist. Write a 2-3 sentence plain-language summary of the given content, optimized for AI answer engines (ChatGPT, Perplexity, Gemini). Be specific and factual — no marketing language. Return ONLY a JSON object: {"summary": "your summary here"}. No markdown, no explanation outside the JSON.';
	$result = aeopugmill_call_ai( $r['provider'], $r['api_key'], $system, aeopugmill_aeo_user_prompt( $r['title'], $r['content'] ), 200 );

	if ( is_wp_error( $result ) ) {
		wp_send_json_error( array( 'message' => $result->get_error_message() ), 500 );
	}

	$decoded = json_decode( $result, true );
	$summary = sanitize_textarea_field( $decoded['summary'] ?? '' );
	if ( empty( $summary ) ) {
		wp_send_json_error( array( 'message' => __( 'AI returned an unexpected response. Please try again.', 'aeo-pugmill' ) ), 500 );
	}

	if ( ! empty( $_POST['autosave'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
		aeopugmill_autosave_aeo_field( $r['post']->ID, 'summary', $summary );
	}

	wp_send_json_success( array( 'summary' => $summary ) );
}

function aeopugmill_ajax_generate_qa() {
	$r = aeopugmill_ai_request_setup( 'aeopugmill_generate_qa', 'Q&A Pairs', false );
	if ( empty( trim( $r['content'] ) ) ) {
		wp_send_json_error( array( 'message' => __( 'Post has no content to analyze.', 'aeo-pugmill' ) ), 400 );
	}

	$system = 'You are an AEO (Answer Engine Optimization) specialist. Generate 3-5 Q&A pairs that real users might search for based on this content. Questions should be specific and answerable from the content. Return ONLY a JSON object: {"questions": [{"q": "question", "a": "clear direct answer"}]}. No markdown, no explanation outside the JSON.';
	$result = aeopugmill_call_ai( $r['provider'], $r['api_key'], $system, aeopugmill_aeo_user_prompt( $r['title'], $r['content'] ), 600 );

	if ( is_wp_error( $result ) ) {
		wp_send_json_error( array( 'message' => $result->get_error_message() ), 500 );
	}

	$decoded   = json_decode( $result, true );
	$raw_pairs = $decoded['questions'] ?? ( isset( $decoded[0] ) ? $decoded : array() );

	if ( ! is_array( $raw_pairs ) ) {
		wp_send_json_error( array( 'message' => __( 'AI returned an unexpected response. Please try again.', 'aeo-pugmill' ) ), 500 );
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

	if ( ! empty( $_POST['autosave'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
		aeopugmill_autosave_aeo_field( $r['post']->ID, 'questions', $questions );
	}

	wp_send_json_success( array( 'questions' => $questions ) );
}

function aeopugmill_ajax_generate_entities() {
	$r = aeopugmill_ai_request_setup( 'aeopugmill_generate_entities', 'Named Entities', false );
	if ( empty( trim( $r['content'] ) ) ) {
		wp_send_json_error( array( 'message' => __( 'Post has no content to analyze.', 'aeo-pugmill' ) ), 400 );
	}

	$allowed_types = array( 'Thing', 'Person', 'Organization', 'Product', 'Place', 'Event', 'Technology', 'DefinedTerm' );
	$system = 'You are an AEO (Answer Engine Optimization) specialist. Extract 3-8 named entities actually mentioned in this content. For each entity provide a name, type, and brief description. Type must be one of: Thing, Person, Organization, Product, Place, Event, Technology, DefinedTerm. For well-known public entities that have a Wikidata or Wikipedia page, include a "same_as" field with the canonical URL (https://www.wikidata.org/wiki/Q... or https://en.wikipedia.org/wiki/...). Only include same_as when you are highly confident in the exact URL — omit the field entirely rather than guess. Return ONLY a JSON object: {"entities": [{"name": "entity name", "type": "Type", "description": "brief description", "same_as": "https://..."}]}. No markdown, no explanation outside the JSON.';
	$result = aeopugmill_call_ai( $r['provider'], $r['api_key'], $system, aeopugmill_aeo_user_prompt( $r['title'], $r['content'] ), 600 );

	if ( is_wp_error( $result ) ) {
		wp_send_json_error( array( 'message' => $result->get_error_message() ), 500 );
	}

	$decoded      = json_decode( $result, true );
	$raw_entities = $decoded['entities'] ?? ( isset( $decoded[0] ) ? $decoded : array() );

	if ( ! is_array( $raw_entities ) ) {
		wp_send_json_error( array( 'message' => __( 'AI returned an unexpected response. Please try again.', 'aeo-pugmill' ) ), 500 );
	}

	$entities = array_values( array_filter(
		array_map( function( $entity ) use ( $allowed_types ) {
			$type   = sanitize_text_field( $entity['type'] ?? 'Thing' );
			$mapped = array(
				'name'        => sanitize_text_field( $entity['name'] ?? '' ),
				'type'        => in_array( $type, $allowed_types, true ) ? $type : 'Thing',
				'description' => sanitize_text_field( $entity['description'] ?? '' ),
			);
			$same_as = aeopugmill_validate_same_as_url( $entity['same_as'] ?? '' );
			if ( $same_as ) {
				$mapped['same_as'] = $same_as;
			}
			return $mapped;
		}, $raw_entities ),
		function( $e ) { return ! empty( $e['name'] ); }
	) );

	if ( ! empty( $_POST['autosave'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
		aeopugmill_autosave_aeo_field( $r['post']->ID, 'entities', $entities );
	}

	wp_send_json_success( array( 'entities' => $entities ) );
}

function aeopugmill_ajax_generate_keywords() {
	$r = aeopugmill_ai_request_setup( 'aeopugmill_generate_keywords', 'Keywords', false );
	if ( empty( trim( $r['content'] ) ) ) {
		wp_send_json_error( array( 'message' => __( 'Post has no content to analyze.', 'aeo-pugmill' ) ), 400 );
	}

	$system = 'You are an AEO (Answer Engine Optimization) specialist. Extract 5-15 specific, search-focused keywords from this content. Avoid generic words — prefer specific terms readers would actually search for. Return ONLY a JSON object: {"keywords": ["keyword1", "keyword2"]}. No markdown, no explanation outside the JSON.';
	$result = aeopugmill_call_ai( $r['provider'], $r['api_key'], $system, aeopugmill_aeo_user_prompt( $r['title'], $r['content'] ), 200 );

	if ( is_wp_error( $result ) ) {
		wp_send_json_error( array( 'message' => $result->get_error_message() ), 500 );
	}

	$decoded      = json_decode( $result, true );
	$raw_keywords = $decoded['keywords'] ?? ( isset( $decoded[0] ) ? $decoded : array() );

	if ( ! is_array( $raw_keywords ) ) {
		wp_send_json_error( array( 'message' => __( 'AI returned an unexpected response. Please try again.', 'aeo-pugmill' ) ), 500 );
	}

	$keywords = array_values( array_filter(
		array_map( 'sanitize_text_field', $raw_keywords )
	) );

	if ( ! empty( $_POST['autosave'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
		aeopugmill_autosave_aeo_field( $r['post']->ID, 'keywords', $keywords );
	}

	wp_send_json_success( array( 'keywords' => $keywords ) );
}

// =========================================================================
// Prompts (original AEO metadata generation)
// =========================================================================

function aeopugmill_aeo_system_prompt() {
	return 'You are an AEO (Answer Engine Optimization) specialist. Your job is to analyze content and generate structured metadata that makes it maximally discoverable and citable by AI answer engines like ChatGPT, Perplexity, and Gemini.

Return ONLY a valid JSON object with exactly these fields:
{
  "summary": "2-3 sentence plain-language description optimized for AI crawlers. Be specific and informative.",
  "questions": [
    {"q": "A question a reader might ask", "a": "A clear, direct answer from the content"}
  ],
  "entities": [
    {"name": "Entity name", "type": "One of: Thing, Person, Organization, Product, Place, Event, Technology, DefinedTerm", "description": "Brief description of this entity in context", "same_as": "Wikidata or Wikipedia URL if highly confident — omit field entirely if uncertain"}
  ],
  "keywords": ["keyword1", "keyword2"]
}

Rules:
- summary: 2-3 sentences, factual, no marketing fluff
- questions: 3-5 Q&A pairs that real users might search for
- entities: 3-8 named entities actually mentioned in the content
- keywords: 5-15 specific search terms, not generic words
- Return ONLY the JSON object, no explanation, no markdown code blocks'
		. aeopugmill_voice_clause();
}

function aeopugmill_aeo_user_prompt( $title, $content ) {
	return "Title: {$title}\n\nContent:\n{$content}";
}

