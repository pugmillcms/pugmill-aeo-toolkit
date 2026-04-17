<?php
/**
 * AEO Pugmill — Distribution AI AJAX handlers.
 *
 * Content distribution features:
 *   - aeopugmill_ajax_generate_excerpt() — 1-2 sentence post excerpt
 *   - aeopugmill_ajax_internal_links()   — internal linking suggestions
 *   - aeopugmill_ajax_social_draft()     — social media post draft (LinkedIn/X/Facebook/Substack)
 *
 * Depends on: ai-client.php (aeopugmill_ai_request_setup, aeopugmill_call_ai)
 *             ai-content.php (aeopugmill_voice_clause)
 *
 * @package WPPugmill
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// =========================================================================
// 4. Excerpt Generator
// =========================================================================

add_action( 'wp_ajax_aeopugmill_generate_excerpt', 'aeopugmill_ajax_generate_excerpt' );

function aeopugmill_ajax_generate_excerpt() {
	$r = aeopugmill_ai_request_setup( 'aeopugmill_generate_excerpt', 'Excerpt Generator' );
	if ( empty( trim( $r['content'] ) ) ) {
		wp_send_json_error( array( 'message' => __( 'Post has no content to analyze.', 'aeo-pugmill' ) ), 400 );
	}

	$system = 'You are an expert copywriter. Generate a compelling 1-2 sentence excerpt (max 160 characters) for the given blog post. Return ONLY the excerpt text, nothing else.'
		. aeopugmill_voice_clause();

	$user   = "Post title: \"{$r['title']}\"\n\nPost content:\n{$r['content']}";
	$result = aeopugmill_call_ai( $r['provider'], $r['api_key'], $system, $user, 100 );

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

add_action( 'wp_ajax_aeopugmill_internal_links', 'aeopugmill_ajax_internal_links' );

function aeopugmill_ajax_internal_links() {
	$r = aeopugmill_ai_request_setup( 'aeopugmill_internal_links', 'Internal Links' );
	if ( empty( trim( $r['content'] ) ) ) {
		wp_send_json_error( array( 'message' => __( 'Post has no content to analyze.', 'aeo-pugmill' ) ), 400 );
	}

	// Build index of other published posts for the prompt.
	// Fetch one extra and filter the current post after the query — avoids
	// exclusionary parameters (post__not_in / exclude) which add a slow subquery.
	$other_posts = get_posts( array(
		'post_type'      => array( 'post', 'page' ),
		'post_status'    => 'publish',
		'posts_per_page' => 41,
		'orderby'        => 'date',
		'order'          => 'DESC',
	) );
	$other_posts = array_values( array_filter( $other_posts, function( $p ) use ( $r ) {
		return $p->ID !== $r['post']->ID;
	} ) );
	$other_posts = array_slice( $other_posts, 0, 40 );

	if ( empty( $other_posts ) ) {
		wp_send_json_error( array( 'message' => __( 'No other published posts found to link to.', 'aeo-pugmill' ) ), 400 );
	}

	$index_lines = array();
	foreach ( $other_posts as $p ) {
		$excerpt      = get_the_excerpt( $p );
		$excerpt_snip = $excerpt ? ' | ' . mb_substr( wp_strip_all_tags( $excerpt ), 0, 120 ) : '';
		$index_lines[] = '- "' . get_the_title( $p ) . '" → ' . get_permalink( $p ) . $excerpt_snip;
	}
	$post_index = implode( "\n", $index_lines );

	$content_label = 'page' === $r['post_type'] ? 'page' : 'post';
	$system = 'You are an internal linking expert for a website. Given a ' . $content_label . '\'s content and an index of other published content on the same site, identify 3–5 natural internal linking opportunities.

For each suggestion return:
- url: the exact URL from the index
- title: the linked post title
- anchorText: 2–6 words COPIED EXACTLY verbatim from the post content — same characters, same capitalisation, same spacing — so they can be located programmatically. Never paraphrase or combine phrases.
- context: the exact sentence from the content where the anchor text appears

Return ONLY a JSON array: [{"url":"exact-url-from-index","title":"Title","anchorText":"exact verbatim phrase","context":"The exact sentence."}]
Only suggest content that is genuinely topically relevant. Fewer than 3 suggestions is fine. No markdown fences, no explanation outside the JSON.';
	$user   = "Post title: \"{$r['title']}\"\n\nPost content:\n{$r['content']}\n\n---\nPublished posts index:\n{$post_index}";

	$result = aeopugmill_call_ai( $r['provider'], $r['api_key'], $system, $user, 600 );

	if ( is_wp_error( $result ) ) {
		wp_send_json_error( array( 'message' => $result->get_error_message() ), 500 );
	}

	$decoded = aeopugmill_decode_ai_json( aeopugmill_strip_ai_json_fences( $result ), 'internal_links' );
	if ( is_wp_error( $decoded ) ) {
		wp_send_json_error( array( 'message' => $decoded->get_error_message() ), 500 );
	}

	// Validate each anchor against paragraph block texts — mirrors the JS block-by-block
	// insertion strategy and resolves HTML entity variants (e.g. Q&amp;A vs Q&A).
	// Prefer draft_content (current editor state) over the saved DB version so this
	// works correctly without requiring a save first. Falls through without validation
	// for classic-editor posts (no block structure).
	// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified in aeopugmill_ai_request_setup().
	$raw_blocks = ! empty( $_POST['draft_content'] )
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce verified above; content sanitized downstream.
		? wp_unslash( $_POST['draft_content'] )
		: $r['post']->post_content;
	$para_texts = aeopugmill_get_paragraph_block_texts( $raw_blocks );
	$validated  = array();

	foreach ( $decoded as $item ) {
		$anchor = sanitize_text_field( $item['anchorText'] ?? '' );
		$url    = esc_url_raw( $item['url'] ?? '' );
		if ( empty( $anchor ) || empty( $url ) ) {
			continue;
		}

		if ( empty( $para_texts ) ) {
			// Classic editor — no block structure to validate against; pass through as-is.
			$validated[] = array(
				'url'        => $url,
				'title'      => sanitize_text_field( $item['title']   ?? '' ),
				'anchorText' => $anchor,
				'context'    => sanitize_text_field( $item['context'] ?? '' ),
			);
			continue;
		}

		foreach ( $para_texts as $block_text ) {
			$remapped = aeopugmill_remap_passage_to_raw( $anchor, $block_text );
			if ( strpos( $block_text, $remapped ) !== false ) {
				$validated[] = array(
					'url'        => $url,
					'title'      => sanitize_text_field( $item['title']   ?? '' ),
					'anchorText' => $remapped,
					'context'    => sanitize_text_field( $item['context'] ?? '' ),
				);
				break;
			}
		}
		// Anchor not matched to any paragraph block — omit silently to prevent
		// "Anchor text not found" insertion error in the editor.
	}

	wp_send_json_success( array( 'links' => $validated ) );
}

// =========================================================================
// 7. Social Media Draft
// =========================================================================

add_action( 'wp_ajax_aeopugmill_social_draft', 'aeopugmill_ajax_social_draft' );

function aeopugmill_ajax_social_draft() {
	$r        = aeopugmill_ai_request_setup( 'aeopugmill_social_draft', 'Social Draft' );
	// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified in aeopugmill_ai_request_setup().
	$platform = sanitize_text_field( wp_unslash( $_POST['platform'] ?? '' ) );

	$allowed = array( 'linkedin', 'x', 'facebook', 'substack' );
	if ( ! in_array( $platform, $allowed, true ) ) {
		wp_send_json_error( array( 'message' => __( 'Invalid platform.', 'aeo-pugmill' ) ), 400 );
	}

	if ( empty( trim( $r['content'] ) ) ) {
		wp_send_json_error( array( 'message' => __( 'Content has nothing to draft from.', 'aeo-pugmill' ) ), 400 );
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
		. aeopugmill_voice_clause();

	$aeo_meta    = get_post_meta( $r['post']->ID, '_aeopugmill_aeo', true );
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
	$result     = aeopugmill_call_ai( $r['provider'], $r['api_key'], $system, $user, $max_tokens );

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
