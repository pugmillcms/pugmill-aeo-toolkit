<?php
/**
 * AI orchestrator — loads all AI feature modules and handles AEO field generation.
 *
 * Supports: Anthropic (Claude), OpenAI (GPT), Google (Gemini)
 *
 * Modules loaded here:
 * - ai-utils.php        — Pure utility functions (JSON decode, paragraph helpers)
 * - ai-client.php       — Transport layer (wppugmill_call_ai, request setup, token tracking)
 * - ai-content.php      — Content rewriting, tone check, reading level, headline variants
 * - ai-focus.php        — Topic focus, passage swap, keyword coverage, heading suggestions
 * - ai-distribute.php   — Excerpt, internal links, social draft
 * - ai-generate-aeo.php — AEO field generators, prompts, providers, parser
 * - ai-generate-seo.php — SEO title/meta, HowTo steps, schema suggestion
 * - ai-admin.php        — Site summary, API key test
 *
 * Security (applies to all modules):
 * - Nonce verified on every request
 * - Capability check (edit_posts)
 * - Rate limited per user (configurable; default 50 requests/hour)
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

// Pure utility functions → ai-utils.php
require_once __DIR__ . '/ai-utils.php';

// Transport layer (wppugmill_call_ai, wppugmill_ai_request_setup, token tracking) → ai-client.php
require_once __DIR__ . '/ai-client.php';

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

require_once __DIR__ . '/ai-content.php';
require_once __DIR__ . '/ai-focus.php';
require_once __DIR__ . '/ai-distribute.php';
require_once __DIR__ . '/ai-generate-aeo.php';
require_once __DIR__ . '/ai-generate-seo.php';
require_once __DIR__ . '/ai-admin.php';

