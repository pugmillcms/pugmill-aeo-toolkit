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
	$r = wppugmill_ai_request_setup( 'wppugmill_generate_aeo', 'AEO generation' );

	if ( empty( $r['content'] ) ) {
		wp_send_json_error( array( 'message' => __( 'Post has no content to analyze. Add some content and try again.', 'wp-pugmill' ) ), 400 );
	}

	$raw = wppugmill_call_ai(
		$r['provider'],
		$r['api_key'],
		wppugmill_aeo_system_prompt(),
		wppugmill_aeo_user_prompt( $r['title'], $r['content'] ),
		2048
	);

	if ( is_wp_error( $raw ) ) {
		wp_send_json_error( array( 'message' => $raw->get_error_message() ), 500 );
	}

	$aeo = wppugmill_decode_ai_json( $raw, $r['provider'] );
	if ( is_wp_error( $aeo ) ) {
		wp_send_json_error( array( 'message' => $aeo->get_error_message() ), 500 );
	}

	$allowed_types = array( 'Thing', 'Person', 'Organization', 'Product', 'Place', 'Event', 'Technology', 'DefinedTerm' );

	wp_send_json_success( array(
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
				$type   = sanitize_text_field( $entity['type'] ?? 'Thing' );
				$mapped = array(
					'name'        => sanitize_text_field( $entity['name'] ?? '' ),
					'type'        => in_array( $type, $allowed_types, true ) ? $type : 'Thing',
					'description' => sanitize_text_field( $entity['description'] ?? '' ),
				);
				$same_as = wppugmill_validate_same_as_url( $entity['same_as'] ?? '' );
				if ( $same_as ) {
					$mapped['same_as'] = $same_as;
				}
				return $mapped;
			}, is_array( $aeo['entities'] ?? null ) ? $aeo['entities'] : array() ),
			function( $e ) { return ! empty( $e['name'] ); }
		) ),
		'keywords'  => array_values( array_filter(
			array_map( 'sanitize_text_field', is_array( $aeo['keywords'] ?? null ) ? $aeo['keywords'] : array() )
		) ),
	) );
}

require_once __DIR__ . '/ai-content.php';
require_once __DIR__ . '/ai-focus.php';
require_once __DIR__ . '/ai-distribute.php';
require_once __DIR__ . '/ai-generate-aeo.php';
require_once __DIR__ . '/ai-generate-seo.php';
require_once __DIR__ . '/ai-admin.php';

