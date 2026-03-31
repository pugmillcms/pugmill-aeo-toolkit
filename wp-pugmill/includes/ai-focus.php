<?php
/**
 * WP Pugmill — Content focus and optimization AI AJAX handlers.
 *
 * Covers:
 *   - wppugmill_ajax_topic_focus()          — Topic Focus analysis
 *   - wppugmill_ajax_swap_focus_passage()   — AI passage rewrite for focus fixes
 *   - wppugmill_ajax_refine_focus()         — Identify off-topic passages
 *   - wppugmill_ajax_fix_keyword_coverage() — Keyword coverage gap fix
 *   - wppugmill_ajax_suggest_headings()     — Heading insertion suggestions
 *
 * Depends on: ai-client.php (wppugmill_ai_request_setup, wppugmill_call_ai),
 *             ai-utils.php (wppugmill_decode_ai_json, wppugmill_get_paragraph_block_texts,
 *                           wppugmill_remap_passage_to_raw),
 *             ai-content.php (wppugmill_voice_clause)
 *
 * @package WPPugmill
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
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

	// The JS passage-swapper (build/index.js) iterates top-level core/paragraph
	// blocks only and matches passage text against block.attributes.content
	// (the innerHTML of the <p> tag, entities preserved, no decoding).
	//
	// Strategy:
	// 1. Send entity-preserved content to the AI so it quotes entities verbatim.
	// 2. Validate each returned passage against individual paragraph block texts
	//    (mirrors the JS block-by-block search). Drop any passage that cannot be
	//    matched — this prevents the "Original passage not found" JS error.
	// 3. Remap per-block so entity variants (&#8217; vs \u2019 vs ') resolve
	//    against the specific block that contains the passage.
	$raw_content = mb_substr( trim( wp_strip_all_tags( $r['post']->post_content ) ), 0, WPPUGMILL_MAX_AI_INPUT );
	$para_texts  = wppugmill_get_paragraph_block_texts( $r['post']->post_content );

	$system = 'You are a content editor. Analyze the post and identify 2-3 specific paragraphs or sentences that dilute or distract from the main topic. For each issue, provide a short label, a direct quote of the problematic passage, and a concrete recommendation to fix it.

CRITICAL rules for "passage":
- Must be copied EXACTLY from the post content — same characters, same HTML entities (e.g. &#8217; not \'), same spacing.
- Must come from a SINGLE paragraph — never quote text that spans across multiple paragraphs.
- Keep each passage under 200 characters.
The passage will be used to locate and replace text programmatically; an inexact or multi-paragraph quote will fail.

Return ONLY a JSON object: {"issues":[{"label":"Short label","passage":"Exact single-paragraph quote","recommendation":"What to do about it"}]}. Be specific and actionable. No explanation outside the JSON.';
	$user   = "Post title: \"{$r['title']}\"\n\nPost content:\n{$raw_content}";
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

	// Remap and validate each passage against individual paragraph block texts.
	// JS searches block-by-block (top-level core/paragraph only), so a passage
	// that spans two blocks will pass a flat strpos check but fail the JS lookup.
	// Remapping per-block also resolves entity variants more precisely.
	$validated = array();
	foreach ( $issues as $issue ) {
		$passage = sanitize_textarea_field( $issue['passage'] ?? '' );
		if ( empty( $passage ) ) {
			continue;
		}
		foreach ( $para_texts as $block_text ) {
			$remapped = wppugmill_remap_passage_to_raw( $passage, $block_text );
			if ( strpos( $block_text, $remapped ) !== false ) {
				$validated[] = array(
					'label'          => sanitize_text_field( $issue['label']          ?? '' ),
					'passage'        => $remapped,
					'recommendation' => sanitize_textarea_field( $issue['recommendation'] ?? '' ),
				);
				break;
			}
		}
		// Passage not matched to any paragraph block — omit silently to prevent JS error.
	}

	if ( empty( $validated ) ) {
		wp_send_json_error( array( 'message' => __( 'AI suggestions could not be matched to post content. Please try again.', 'wp-pugmill' ) ), 500 );
	}

	wp_send_json_success( array( 'issues' => $validated ) );
}

add_action( 'wp_ajax_wppugmill_fix_keyword_coverage', 'wppugmill_ajax_fix_keyword_coverage' );

/**
 * AJAX handler — AI-powered keyword coverage fix.
 *
 * Reads the post content and AEO keyword list, asks the AI to find passages
 * that could be naturally rewritten to incorporate missing keywords, then
 * validates each returned quote against paragraph blocks (same per-block
 * validation used by Refine Focus) before returning to the JS swap UI.
 *
 * Returns: { suggestions: [ { quote, keyword, suggestion } ] }
 */
function wppugmill_ajax_fix_keyword_coverage() {
	$r = wppugmill_ai_request_setup( 'wppugmill_fix_keyword_coverage', 'Keyword Coverage Fix' );

	if ( empty( trim( $r['content'] ) ) ) {
		wp_send_json_error( array( 'message' => __( 'Post has no content to analyze.', 'wp-pugmill' ) ), 400 );
	}

	$aeo      = wppugmill_get_aeo( $r['post']->ID );
	$keywords = array_filter( $aeo['keywords'], function( $k ) { return ! empty( trim( $k ) ); } );

	if ( empty( $keywords ) ) {
		wp_send_json_error( array( 'message' => __( 'No keywords found in the AEO panel. Add keywords first, then run this fix.', 'wp-pugmill' ) ), 400 );
	}

	$para_texts = wppugmill_get_paragraph_block_texts( $r['post']->post_content );
	$kw_list    = implode( ', ', array_map( 'sanitize_text_field', $keywords ) );

	$system = 'You are a content editor. You will be given a post title, its body text, and a list of keywords it should cover. Identify up to 4 specific passages that could be naturally rewritten to better incorporate one of the listed keywords.

For each passage, return:
- quote: an exact verbatim substring of the post content (20–200 characters), from a single paragraph
- keyword: the keyword from the provided list that this passage should better incorporate
- suggestion: a rewritten version of the passage that naturally includes the keyword

CRITICAL rules for "quote":
- Must be copied EXACTLY from the post content — same characters, same HTML entities, same spacing
- Must come from a SINGLE paragraph — never span multiple paragraphs
- Must be 20–200 characters

Return ONLY a JSON object: {"suggestions":[{"quote":"...","keyword":"...","suggestion":"..."}]}
Return an empty array if the keywords are already well-covered: {"suggestions":[]}
No markdown fences, no explanation outside the JSON.';

	$user   = "Title: \"{$r['title']}\"\nKeywords to cover: {$kw_list}\n\nPost content:\n{$r['content']}";
	$result = wppugmill_call_ai( $r['provider'], $r['api_key'], $system, $user, 1024 );

	if ( is_wp_error( $result ) ) {
		wp_send_json_error( array( 'message' => $result->get_error_message() ), 500 );
	}

	$decoded = wppugmill_decode_ai_json( $result, $r['provider'] );
	if ( is_wp_error( $decoded ) ) {
		wp_send_json_error( array( 'message' => $decoded->get_error_message() ), 500 );
	}

	$raw_suggs = $decoded['suggestions'] ?? ( isset( $decoded[0] ) ? $decoded : array() );
	$raw_suggs = array_slice( (array) $raw_suggs, 0, 4 );

	// Validate each quote against paragraph blocks (mirrors the JS block-by-block
	// swap loop). Drops any suggestion whose quote cannot be matched — prevents
	// "passage not found" errors in the editor.
	$validated = array();
	foreach ( $raw_suggs as $sugg ) {
		$quote = sanitize_textarea_field( $sugg['quote'] ?? '' );
		if ( empty( $quote ) ) {
			continue;
		}
		foreach ( $para_texts as $block_text ) {
			$remapped = wppugmill_remap_passage_to_raw( $quote, $block_text );
			if ( strpos( $block_text, $remapped ) !== false ) {
				$validated[] = array(
					'quote'      => $remapped,
					'keyword'    => sanitize_text_field( $sugg['keyword']    ?? '' ),
					'suggestion' => sanitize_textarea_field( $sugg['suggestion'] ?? '' ),
				);
				break;
			}
		}
		// Quote not matched to any paragraph block — omit silently to prevent JS swap error.
	}

	wp_send_json_success( array( 'suggestions' => $validated ) );
}

add_action( 'wp_ajax_wppugmill_suggest_headings', 'wppugmill_ajax_suggest_headings' );

/**
 * AJAX handler — AI-powered heading suggestions.
 *
 * Analyzes post content and returns 2–3 suggested H2/H3 headings with
 * verbatim paragraph anchors. The JS inserts new core/heading blocks
 * immediately before the paragraph that matches each anchor.
 *
 * Returns: { headings: [ { anchor, level, heading } ] }
 */
function wppugmill_ajax_suggest_headings() {
	$r = wppugmill_ai_request_setup( 'wppugmill_suggest_headings', 'Heading Suggestions' );

	if ( empty( trim( $r['content'] ) ) ) {
		wp_send_json_error( array( 'message' => __( 'Post has no content to analyze.', 'wp-pugmill' ) ), 400 );
	}

	$para_texts = wppugmill_get_paragraph_block_texts( $r['post']->post_content );

	if ( empty( $para_texts ) ) {
		wp_send_json_error( array( 'message' => __( 'No paragraph blocks found. Heading insertion requires the block editor.', 'wp-pugmill' ) ), 400 );
	}

	$system = 'You are a content editor. Analyze the post content and identify 2–3 natural section breaks where an H2 or H3 heading would improve scannability and help AI engines extract distinct answers.

For each heading, return:
- anchor: an exact verbatim substring from the FIRST SENTENCE of the paragraph where the heading should appear BEFORE it (20–120 characters)
- level: 2 for a main section heading, 3 for a sub-section heading
- heading: the heading text to insert (3–8 words, no punctuation at the end)

CRITICAL rules for "anchor":
- Must be copied EXACTLY from the post content — same characters, same HTML entities, same spacing
- Must come from the beginning of a single paragraph
- Must be 20–120 characters

Return ONLY a JSON object: {"headings":[{"anchor":"...","level":2,"heading":"..."}]}
Return an empty array if the content is short, single-topic, or already well-structured without headings: {"headings":[]}
No markdown fences, no explanation outside the JSON.';

	$user   = "Title: \"{$r['title']}\"\n\nPost content:\n{$r['content']}";
	$result = wppugmill_call_ai( $r['provider'], $r['api_key'], $system, $user, 600 );

	if ( is_wp_error( $result ) ) {
		wp_send_json_error( array( 'message' => $result->get_error_message() ), 500 );
	}

	$decoded = wppugmill_decode_ai_json( $result, $r['provider'] );
	if ( is_wp_error( $decoded ) ) {
		wp_send_json_error( array( 'message' => $decoded->get_error_message() ), 500 );
	}

	$raw_headings = $decoded['headings'] ?? ( isset( $decoded[0] ) ? $decoded : array() );
	$raw_headings = array_slice( (array) $raw_headings, 0, 3 );

	// Validate each anchor against paragraph blocks. Drop any whose anchor
	// cannot be matched — prevents JS insertion failure.
	$validated = array();
	foreach ( $raw_headings as $h ) {
		$anchor = sanitize_textarea_field( $h['anchor'] ?? '' );
		if ( empty( $anchor ) ) {
			continue;
		}
		foreach ( $para_texts as $block_text ) {
			$remapped = wppugmill_remap_passage_to_raw( $anchor, $block_text );
			if ( strpos( $block_text, $remapped ) !== false ) {
				$level = (int) ( $h['level'] ?? 2 );
				$validated[] = array(
					'anchor'  => $remapped,
					'level'   => ( 2 === $level || 3 === $level ) ? $level : 2,
					'heading' => sanitize_text_field( $h['heading'] ?? '' ),
				);
				break;
			}
		}
	}

	wp_send_json_success( array( 'headings' => $validated ) );
}
