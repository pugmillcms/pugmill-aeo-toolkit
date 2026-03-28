<?php
/**
 * AEO Content Audit — per-post LLM-readability analysis.
 *
 * Runs 12 checks against the post's AEO metadata and content, returning
 * structured pass/warn/fail results via a REST endpoint.
 *
 * Endpoint: GET /wp-json/wp-pugmill/v1/audit/{post_id}
 *
 * @package WPPugmill
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// ── REST route ────────────────────────────────────────────────────────────────

add_action( 'rest_api_init', function() {
	register_rest_route(
		'wp-pugmill/v1',
		'/audit/(?P<id>\d+)',
		array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => 'wppugmill_rest_audit',
			'permission_callback' => function( $request ) {
				return current_user_can( 'edit_post', (int) $request['id'] );
			},
			'args' => array(
				'id' => array(
					'validate_callback' => function( $v ) { return is_numeric( $v ); },
				),
			),
		)
	);
} );

/**
 * REST callback: run audit and return results.
 *
 * @param  WP_REST_Request $request
 * @return WP_REST_Response
 */
function wppugmill_rest_audit( $request ) {
	$post_id = (int) $request['id'];
	$results = wppugmill_run_audit( $post_id );
	return rest_ensure_response( $results );
}

// ── Audit engine ──────────────────────────────────────────────────────────────

/**
 * Run all audit checks for a post.
 *
 * @param  int $post_id
 * @return array{ checks: array, passed: int, total: int, score: int }
 */
function wppugmill_run_audit( $post_id ) {
	$aeo       = wppugmill_get_aeo( $post_id );
	$post      = get_post( $post_id );
	$post_type = get_post_type( $post_id );
	$is_page   = ( 'page' === $post_type );
	$label     = $is_page ? 'page' : 'post';

	// Plain-text content (strips blocks + HTML)
	$raw_content = $post ? $post->post_content : '';
	$plain       = wp_strip_all_tags( $raw_content );
	$words       = $plain ? str_word_count( $plain ) : 0;

	$checks = array();

	// ── 1. Summary present ────────────────────────────────────────────────────
	$summary     = trim( $aeo['summary'] );
	$has_summary = ! empty( $summary );
	$checks[]    = array(
		'id'      => 'summary_present',
		'status'  => $has_summary ? 'pass' : 'fail',
		'label'   => 'Summary written',
		'message' => 'AI engines use your summary as a first-pass citation snippet.',
		'tip'     => 'Write a 1–2 sentence summary in the AEO panel.',
	);

	// ── 2. Summary length ─────────────────────────────────────────────────────
	$summary_len = strlen( $summary );
	$s_status    = $summary_len >= 80 ? 'pass' : ( $summary_len >= 40 ? 'warn' : 'fail' );
	$checks[]    = array(
		'id'      => 'summary_length',
		'status'  => $s_status,
		'label'   => 'Summary is descriptive (80+ chars)',
		'message' => 'Longer summaries give AI enough context to cite accurately without hallucinating.',
		'tip'     => 'Expand your summary — aim for at least 80 characters.',
	);

	// ── 3. Q&A present ───────────────────────────────────────────────────────
	$questions = array_filter( $aeo['questions'], function( $q ) {
		return ! empty( $q['q'] );
	} );
	$qa_count  = count( $questions );
	$checks[]  = array(
		'id'      => 'qa_present',
		'status'  => $qa_count >= 1 ? 'pass' : 'fail',
		'label'   => 'At least one Q&A pair',
		'message' => 'Q&A pairs match how people ask AI engines questions — they improve citation likelihood.',
		'tip'     => 'Add at least one question and answer in the AEO panel.',
	);

	// ── 4. Q&A coverage ──────────────────────────────────────────────────────
	$checks[] = array(
		'id'      => 'qa_coverage',
		'status'  => $qa_count >= 3 ? 'pass' : ( $qa_count >= 1 ? 'warn' : 'fail' ),
		'label'   => 'Q&A coverage (3+ pairs)',
		'message' => 'Three or more Q&A pairs cover multiple angles AI engines might query.',
		'tip'     => 'Add more Q&A pairs — think about the follow-up questions your readers have.',
	);

	// ── 5. Questions are full natural-language sentences ──────────────────────
	$unnatural = 0;
	foreach ( $questions as $q ) {
		$text      = trim( $q['q'] );
		$wc        = str_word_count( $text );
		$ends_q    = substr( $text, -1 ) === '?';
		if ( $wc < 5 || ! $ends_q ) {
			++$unnatural;
		}
	}
	$nl_status = ( $qa_count === 0 || $unnatural === 0 ) ? 'pass'
		: ( $unnatural < $qa_count ? 'warn' : 'fail' );
	$checks[] = array(
		'id'      => 'questions_natural',
		'status'  => $nl_status,
		'label'   => 'Questions are natural-language',
		'message' => 'AI engines match your questions against user queries verbatim — full sentences with a "?" work best.',
		'tip'     => 'Rephrase short questions as complete sentences ending with a question mark.',
	);

	// ── 6. Named entities present ─────────────────────────────────────────────
	$entities     = array_filter( $aeo['entities'], function( $e ) {
		return ! empty( $e['name'] );
	} );
	$entity_count = count( $entities );
	$checks[]     = array(
		'id'      => 'entities_present',
		'status'  => $entity_count >= 1 ? 'pass' : 'fail',
		'label'   => 'Named entities listed',
		'message' => 'Named entities anchor your content in the AI\'s knowledge graph — people, places, orgs, products.',
		'tip'     => 'Add at least one named entity (person, org, place, or product) in the AEO panel.',
	);

	// ── 7. Entity specificity ─────────────────────────────────────────────────
	$vague = 0;
	foreach ( $entities as $e ) {
		$name = trim( $e['name'] );
		// Flag single short words (< 4 chars) or single-word generic terms
		if ( str_word_count( $name ) === 1 && strlen( $name ) < 5 ) {
			++$vague;
		}
	}
	$spec_status = ( $entity_count === 0 || $vague === 0 ) ? 'pass'
		: ( $vague < $entity_count ? 'warn' : 'fail' );
	$checks[] = array(
		'id'      => 'entity_specificity',
		'status'  => $spec_status,
		'label'   => 'Entities are specific',
		'message' => 'Specific entity names (full names, brand names) tie your content to real-world nodes AI knows.',
		'tip'     => 'Use full names rather than short abbreviations or generic single words.',
	);

	// ── 8. Keywords present ───────────────────────────────────────────────────
	$keywords  = array_filter( $aeo['keywords'], function( $k ) { return ! empty( trim( $k ) ); } );
	$kw_count  = count( $keywords );
	$checks[]  = array(
		'id'      => 'keywords_present',
		'status'  => $kw_count >= 5 ? 'pass' : ( $kw_count >= 1 ? 'warn' : 'fail' ),
		'label'   => 'Keywords listed (5+)',
		'message' => 'Keywords signal the semantic field of your content to AI indexers.',
		'tip'     => 'Add at least 5 keywords in the AEO panel.',
	);

	// ── 9. Keywords appear in content ─────────────────────────────────────────
	// Keywords are often multi-word SEO phrases (e.g. "pugmill clay reclaim").
	// An exact phrase match is too strict — instead, a keyword "covers" the
	// content when the majority of its meaningful words appear in the body.
	// Stopwords (≤ 3 chars, or common connectors) are skipped.
	$kw_stopwords = array( 'a', 'an', 'the', 'and', 'or', 'but', 'in', 'on',
		'at', 'to', 'for', 'of', 'vs', 'vs.', 'with', 'by', 'from', 'is',
		'are', 'was', 'be', 'how', 'why', 'what', 'when', 'do', 'not', 'no' );

	$kw_in_content = 0;
	if ( $kw_count > 0 && ! empty( $plain ) ) {
		$plain_lower = mb_strtolower( $plain );
		foreach ( $keywords as $kw ) {
			$kw_words = preg_split( '/[\s\-\/]+/u', mb_strtolower( trim( $kw ) ), -1, PREG_SPLIT_NO_EMPTY );
			// Keep only meaningful words (length > 3 and not a stopword)
			$meaningful = array_values( array_filter( $kw_words, function( $w ) use ( $kw_stopwords ) {
				return mb_strlen( $w ) > 3 && ! in_array( $w, $kw_stopwords, true );
			} ) );
			if ( empty( $meaningful ) ) {
				// Fallback: no meaningful words — try exact phrase
				if ( false !== mb_stripos( $plain_lower, trim( $kw ) ) ) {
					++$kw_in_content;
				}
				continue;
			}
			$found = 0;
			foreach ( $meaningful as $word ) {
				if ( false !== mb_stripos( $plain_lower, $word ) ) {
					++$found;
				}
			}
			// Keyword "covered" if ≥ 60% of meaningful words appear in content
			if ( ( $found / count( $meaningful ) ) >= 0.6 ) {
				++$kw_in_content;
			}
		}
	}
	$kw_pct      = $kw_count > 0 ? ( $kw_in_content / $kw_count ) : 1;
	$kw_status   = $kw_count === 0 ? 'fail'
		: ( $kw_pct >= 0.7 ? 'pass' : ( $kw_pct >= 0.4 ? 'warn' : 'fail' ) );
	$checks[] = array(
		'id'      => 'keywords_in_content',
		'status'  => $kw_status,
		'label'   => 'Keyword topics covered in content (70%+)',
		'message' => 'AI engines verify keyword relevance against your body text — the key concepts should appear in the ' . $label . '.',
		'tip'     => 'Make sure the main topics from your keyword list are actually discussed in the ' . $label . ' content.',
	);

	// ── 10. Content length ────────────────────────────────────────────────────
	$checks[] = array(
		'id'      => 'content_length',
		'status'  => $words >= 400 ? 'pass' : ( $words >= 200 ? 'warn' : 'fail' ),
		'label'   => 'Content length (400+ words)',
		'message' => 'Longer ' . $label . 's give AI engines more signal and increase the chance of being cited as a primary source.',
		'tip'     => 'Aim for at least 400 words. Your ' . $label . ' currently has ' . number_format_i18n( $words ) . ' word(s).',
	);

	// ── 11. Headings present ──────────────────────────────────────────────────
	$has_headings = (bool) preg_match( '/<h[23][^>]*>/i', $raw_content )
		|| (bool) strpos( $raw_content, '<!-- wp:heading' );
	$checks[] = array(
		'id'      => 'has_headings',
		'status'  => $has_headings ? 'pass' : 'warn',
		'label'   => 'Uses H2 / H3 headings',
		'message' => 'Headings break content into answer-sized chunks that AI engines can extract independently.',
		'tip'     => 'Add H2 or H3 subheadings to organise your content into clear sections.',
	);

	// ── 12. Opening paragraph is concise ─────────────────────────────────────
	// Extract first non-empty paragraph from stripped content
	$paragraphs  = array_filter( array_map( 'trim', preg_split( '/\n{2,}/', $plain ) ) );
	$first_para  = $paragraphs ? reset( $paragraphs ) : '';
	$first_words = $first_para ? str_word_count( $first_para ) : 0;
	$open_status = $first_words === 0 ? 'fail'
		: ( $first_words <= 80 ? 'pass' : ( $first_words <= 140 ? 'warn' : 'fail' ) );
	$checks[] = array(
		'id'      => 'opening_concise',
		'status'  => $open_status,
		'label'   => 'Opening paragraph is concise (≤ 80 words)',
		'message' => 'AI engines prefer a direct, short opener — it\'s the first thing extracted for a citation snippet.',
		'tip'     => 'Trim your opening paragraph to 80 words or fewer. Lead with the answer, not the context.',
	);

	// ── Totals ────────────────────────────────────────────────────────────────
	$passed = count( array_filter( $checks, function( $c ) { return 'pass' === $c['status']; } ) );
	$total  = count( $checks );

	return array(
		'checks' => $checks,
		'passed' => $passed,
		'warned' => count( array_filter( $checks, function( $c ) { return 'warn' === $c['status']; } ) ),
		'failed' => count( array_filter( $checks, function( $c ) { return 'fail' === $c['status']; } ) ),
		'total'  => $total,
		'score'  => (int) round( ( $passed / $total ) * 100 ),
	);
}
