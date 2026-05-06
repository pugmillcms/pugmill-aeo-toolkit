<?php
/**
 * Pugmill AEO Toolkit — AI utility functions (pure helpers, no side effects).
 *
 * These functions are stateless and have no WordPress AJAX registrations.
 * Kept separate so they can be require_once'd in tests without loading
 * the full AJAX handler surface.
 *
 * @package WPPugmill
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Strip markdown fences and extract JSON from a raw AI response string.
 *
 * Handles three cases where the AI wraps or prefixes the JSON:
 *   1. Leading ```json or ``` fences.
 *   2. Trailing ``` fences.
 *   3. Preamble text before the JSON — extracts the first object or array.
 *
 * Returns the cleaned string ready for json_decode(). Does not decode.
 *
 * @param  string $raw Raw text returned by the AI provider.
 * @return string
 */
function aeopugmill_strip_ai_json_fences( $raw ) {
	$raw = preg_replace( '/^```(?:json)?\s*/i', '', trim( $raw ) );
	$raw = preg_replace( '/\s*```$/', '', $raw );

	// If the result still isn't valid JSON, extract the first object or array.
	if ( null === json_decode( $raw ) ) {
		if ( preg_match( '/(\{[\s\S]*\}|\[[\s\S]*\])/s', $raw, $m ) ) {
			$raw = $m[1];
		}
	}

	return $raw;
}

/**
 * Decode a JSON string returned by aeopugmill_call_ai().
 * Returns the decoded array, or WP_Error on invalid JSON.
 *
 * @param string $raw
 * @param string $provider For error logging.
 * @return array|WP_Error
 */
function aeopugmill_decode_ai_json( $raw, $provider ) {
	$decoded = json_decode( $raw, true );
	// OpenAI without json_object mode may wrap an array in a single key — unwrap.
	// Only trigger when the inner value is itself an array; skip objects like
	// {"type":""} whose single value is a scalar (those must be returned intact).
	if ( is_array( $decoded ) && ! isset( $decoded[0] ) && count( $decoded ) === 1 ) {
		$inner = array_values( $decoded )[0];
		if ( is_array( $inner ) ) {
			$decoded = $inner;
		}
	}
	if ( ! is_array( $decoded ) ) {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( 'Pugmill AEO Toolkit: invalid JSON from ' . $provider . ': ' . substr( $raw, 0, 200 ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}
		return new WP_Error( 'invalid_json', __( 'AI returned an unexpected response format. Please try again.', 'aeo-pugmill' ) );
	}
	return $decoded;
}

/**
 * Extract tag-stripped text from each top-level core/paragraph block.
 *
 * Mirrors the JS getBlocks() + core/paragraph filter in the Gutenberg sidebar —
 * only top-level paragraph blocks are returned (no innerBlocks recursion).
 * If the content has no Gutenberg block markers (classic editor or parse_blocks
 * not available), falls back to the full stripped content as a single element.
 *
 * @param  string $post_content Raw post_content from the database.
 * @return string[]             Array of tag-stripped paragraph texts.
 */
function aeopugmill_get_paragraph_block_texts( $post_content ) {
	if ( ! function_exists( 'parse_blocks' ) ) {
		return array(); // parse_blocks requires WP 5.0+ — no blocks, no swap targets
	}

	$blocks = parse_blocks( $post_content );
	$texts  = array();

	foreach ( $blocks as $block ) {
		if ( 'core/paragraph' !== ( $block['blockName'] ?? '' ) ) {
			continue;
		}
		if ( empty( $block['innerHTML'] ) ) {
			continue;
		}
		$text = trim( wp_strip_all_tags( $block['innerHTML'] ) );
		if ( '' !== $text ) {
			$texts[] = $text;
		}
	}

	// No paragraph blocks found (e.g. classic editor freeform block).
	// Return empty — the JS swap loop also skips non-core/paragraph blocks,
	// so flat content passages would always fail the JS lookup anyway.
	return $texts;
}

/**
 * Given a passage the AI returned (potentially with decoded typography), find and
 * return the entity-encoded version that matches the raw block content verbatim.
 *
 * The JS passage-matcher does not decode HTML entities, so passages must use the
 * same encoding as the stored block content (e.g. &#8217; not the decoded char).
 *
 * @param  string $passage     Passage text from AI (may have decoded entities).
 * @param  string $raw_content Tag-stripped content with HTML entities preserved.
 * @return string              Entity-encoded version of the passage, or original if not found.
 */
function aeopugmill_remap_passage_to_raw( $passage, $raw_content ) {
	if ( empty( $passage ) || empty( $raw_content ) ) {
		return $passage;
	}

	// Verbatim match — no remapping needed.
	if ( strpos( $raw_content, $passage ) !== false ) {
		return $passage;
	}

	// The block editor stores apostrophes/quotes one of two ways depending on the
	// WordPress version and how content was entered:
	//   A) HTML entity form:   &#8217;  (classic editor / wptexturize)
	//   B) Unicode char form:  \u2019  (Gutenberg, typed directly)
	//
	// The AI commonly returns a straight apostrophe (') regardless of input.
	// Try both target forms so the remapped passage matches whichever the block uses.

	// Variant A: AI output → entity form (&#8217; etc.)
	$to_entity = array(
		"\u{2019}" => '&#8217;',  "\u{2018}" => '&#8216;',
		"\u{201D}" => '&#8221;',  "\u{201C}" => '&#8220;',
		"\u{2014}" => '&#8212;',  "\u{2013}" => '&#8211;',
		"'"        => '&#8217;',  '"'        => '&#8221;',
	);
	$candidate_a = strtr( $passage, $to_entity );
	if ( $candidate_a !== $passage && strpos( $raw_content, $candidate_a ) !== false ) {
		return $candidate_a;
	}

	// Variant B: AI output → Unicode curly form (\u2019 etc.)
	$to_unicode = array(
		'&#8217;'  => "\u{2019}", '&#8216;'  => "\u{2018}",
		'&#8221;'  => "\u{201D}", '&#8220;'  => "\u{201C}",
		'&#8212;'  => "\u{2014}", '&#8211;'  => "\u{2013}",
		"'"        => "\u{2019}", '"'        => "\u{201D}",
	);
	$candidate_b = strtr( $passage, $to_unicode );
	if ( $candidate_b !== $passage && strpos( $raw_content, $candidate_b ) !== false ) {
		return $candidate_b;
	}

	// Last resort: try to find a fuzzy match in the raw content by normalizing both
	// strings (lowercase + whitespace). If found, return the matched raw substring.
	$normalize = function( $s ) {
		// Normalize typography AND whitespace for comparison.
		static $typo_plain = array(
			"\u{2019}" => "'", "\u{2018}" => "'",
			"\u{201D}" => '"', "\u{201C}" => '"',
			"\u{2014}" => '-', "\u{2013}" => '-',
			'&#8217;' => "'", '&#8216;' => "'",
			'&#8220;' => '"', '&#8221;' => '"',
			'&#8211;' => '-', '&#8212;' => '-',
			'&rsquo;' => "'", '&lsquo;' => "'",
			'&rdquo;' => '"', '&ldquo;' => '"',
		);
		return strtolower( preg_replace( '/\s+/', ' ', trim( strtr( $s, $typo_plain ) ) ) );
	};

	$p_norm = $normalize( $passage );
	$r_norm = $normalize( $raw_content );
	$pos    = strpos( $r_norm, $p_norm );

	if ( false === $pos ) {
		return $passage; // no match found — return as-is, JS will show "Edit manually"
	}

	// Extract the matching substring from raw_content at the same byte offset.
	// Because the normalize function may change byte lengths (entities vs chars),
	// we use the position in $r_norm to find the start in $raw_content by scanning.
	$r_norm_chars = preg_split( '//u', $r_norm, -1, PREG_SPLIT_NO_EMPTY );
	$raw_words    = preg_split( '/(\s+)/u', $raw_content, -1, PREG_SPLIT_DELIM_CAPTURE );

	// Map normalized char offsets back to raw_content using word-level alignment.
	// Walk r_norm to find which word the match starts/ends in, then slice raw_words.
	$norm_offset = 0;
	$word_start  = -1;
	$word_end    = -1;
	$end_pos     = $pos + strlen( $p_norm );

	foreach ( $raw_words as $wi => $word ) {
		if ( '' === trim( $word ) ) { continue; } // skip whitespace tokens
		$w_norm      = $normalize( $word );
		$w_len       = strlen( $w_norm );
		$next_offset = $norm_offset + $w_len + 1; // +1 for the space separator in $r_norm

		if ( $word_start < 0 && $norm_offset >= $pos ) {
			$word_start = $wi;
		}
		if ( $word_start >= 0 && $norm_offset + $w_len >= $end_pos ) {
			$word_end = $wi;
			break;
		}
		$norm_offset = $next_offset;
	}

	if ( $word_start >= 0 && $word_end >= $word_start ) {
		$found = implode( '', array_slice( $raw_words, $word_start, $word_end - $word_start + 1 ) );
		return trim( $found ) ?: $passage;
	}

	return $passage;
}

/**
 * Return the author voice clause to append to AI system prompts.
 *
 * @return string
 */
function aeopugmill_voice_clause() {
	$voice = get_option( 'aeopugmill_author_voice', '' );
	if ( $voice ) {
		return "

Author voice and style guide — you MUST follow this when writing any text fields:
" . $voice;
	}
	return "

Maintain a clear, engaging, and professional tone throughout.";
}
