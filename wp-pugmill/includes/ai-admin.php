<?php
/**
 * WP Pugmill — Admin-level AI AJAX handlers.
 *
 * Settings-page actions that require manage_options (not per-post):
 *   - wppugmill_ajax_generate_site_summary() — /llms.txt + Organization schema summary
 *   - wppugmill_ajax_improve_llms_score()    — llms.txt score improvement tips
 *   - wppugmill_ajax_compat_tips()           — step-by-step conflict resolution tips
 *   - wppugmill_ajax_test_api_key()          — validate the stored API key
 *
 * Depends on: ai-client.php (wppugmill_call_ai, wppugmill_ai_request_args,
 *             wppugmill_check_rate_limit, wppugmill_get_encrypted_option)
 *
 * @package WPPugmill
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
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

// =========================================================================
// llms.txt Score Improvement Suggestions
//
// 3rd-party AI service disclosure: sends the site's aggregate AEO coverage
// percentages (no post content, no visitor data) to the configured AI
// provider. Disclosed in readme.txt "External Services" section.
// =========================================================================

add_action( 'wp_ajax_wppugmill_improve_llms_score', 'wppugmill_ajax_improve_llms_score' );

/**
 * AJAX handler — return prioritized llms.txt improvement recommendations.
 *
 * Accepts the current score breakdown (already computed server-side and
 * passed back via form POST) and returns 3–5 actionable tips ordered by
 * impact.
 */
function wppugmill_ajax_improve_llms_score() {
	check_ajax_referer( 'wppugmill_improve_llms_score', 'nonce' );

	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'wp-pugmill' ) ), 403 );
	}

	$rate_check = wppugmill_check_rate_limit();
	if ( is_wp_error( $rate_check ) ) {
		wp_send_json_error( array( 'message' => $rate_check->get_error_message() ), 429 );
	}

	$provider = get_option( 'wppugmill_ai_provider', 'anthropic' );
	$api_key  = wppugmill_get_encrypted_option( 'wppugmill_ai_api_key', '' );
	if ( empty( $api_key ) ) {
		wp_send_json_error( array( 'message' => __( 'No API key configured. Add your key in Settings → WP Pugmill → AI Provider.', 'wp-pugmill' ) ), 400 );
	}

	$score        = absint( $_POST['score'] ?? 0 );         // phpcs:ignore WordPress.Security.NonceVerification.Missing
	$total        = absint( $_POST['total'] ?? 0 );         // phpcs:ignore WordPress.Security.NonceVerification.Missing
	$has_summary  = ! empty( $_POST['has_summary'] );       // phpcs:ignore WordPress.Security.NonceVerification.Missing
	$has_org      = ! empty( $_POST['has_org'] );           // phpcs:ignore WordPress.Security.NonceVerification.Missing
	$summary_pct  = absint( $_POST['summary_pct'] ?? 0 );  // phpcs:ignore WordPress.Security.NonceVerification.Missing
	$qa_pct       = absint( $_POST['qa_pct'] ?? 0 );       // phpcs:ignore WordPress.Security.NonceVerification.Missing
	$keywords_pct = absint( $_POST['keywords_pct'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
	$entities_pct = absint( $_POST['entities_pct'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Missing

	$system = 'You are an AEO (Answer Engine Optimization) expert. A WordPress site owner wants to improve their llms.txt quality score — a measure of how well their site\'s AEO metadata is filled in. AI answer engines (ChatGPT, Perplexity, Claude) read /llms.txt to understand the site and attribute answers to it. Give 3–5 specific, prioritized improvement tips based on the score breakdown provided. Order them by impact (highest first). Be direct and actionable — name the specific field to fill in and explain briefly why it helps. Return plain text only: no markdown, no bullet symbols, no headers. Separate each tip with a blank line. Keep the total response under 250 words.';

	$user  = "Current score: {$score}/100\n";
	$user .= "Total published posts: {$total}\n\n";
	$user .= "Site-level fields:\n";
	$user .= '- Site summary: ' . ( $has_summary ? 'Set' : 'MISSING' ) . " (worth 20 pts)\n";
	$user .= '- Organization name: ' . ( $has_org ? 'Set' : 'MISSING' ) . " (worth 5 pts)\n\n";
	$user .= "Post coverage:\n";
	$user .= "- Post summaries: {$summary_pct}% of posts (worth up to 30 pts)\n";
	$user .= "- Q&A pairs: {$qa_pct}% of posts (worth up to 20 pts)\n";
	$user .= "- Keywords: {$keywords_pct}% of posts (worth up to 15 pts)\n";
	$user .= "- Entities: {$entities_pct}% of posts (worth up to 10 pts)\n";

	$result = wppugmill_call_ai( $provider, $api_key, $system, $user, 350 );

	if ( is_wp_error( $result ) ) {
		wp_send_json_error( array( 'message' => $result->get_error_message() ), 500 );
	}

	wp_send_json_success( array( 'text' => sanitize_textarea_field( trim( $result ) ) ) );
}

// =========================================================================
// Compatibility Tips — per-conflict step-by-step instructions
//
// 3rd-party AI service disclosure: sends only the plugin name and a short
// task description (no post content, no visitor data) to the configured
// AI provider. Disclosed in readme.txt "External Services" section.
// =========================================================================

add_action( 'wp_ajax_wppugmill_compat_tips', 'wppugmill_ajax_compat_tips' );

/**
 * AJAX handler — return step-by-step instructions for resolving a plugin conflict.
 *
 * Accepts plugin_name and instruction, returns numbered steps.
 */
function wppugmill_ajax_compat_tips() {
	check_ajax_referer( 'wppugmill_compat_tips', 'nonce' );

	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'wp-pugmill' ) ), 403 );
	}

	$provider = get_option( 'wppugmill_ai_provider', 'anthropic' );
	$api_key  = wppugmill_get_encrypted_option( 'wppugmill_ai_api_key', '' );
	if ( empty( $api_key ) ) {
		wp_send_json_error( array( 'message' => __( 'No API key configured.', 'wp-pugmill' ) ), 400 );
	}

	$plugin_name = sanitize_text_field( wp_unslash( $_POST['plugin_name'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
	$instruction = sanitize_text_field( wp_unslash( $_POST['instruction'] ?? '' ) );  // phpcs:ignore WordPress.Security.NonceVerification.Missing

	if ( empty( $plugin_name ) || empty( $instruction ) ) {
		wp_send_json_error( array( 'message' => __( 'Missing required parameters.', 'wp-pugmill' ) ), 400 );
	}

	$system = 'You are a WordPress expert helping a site owner resolve a plugin conflict with WP Pugmill — a WordPress plugin that generates AEO (Answer Engine Optimization) files: /sitemap.xml, /llms.txt, and /robots.txt additions, plus structured JSON-LD schema and on-page SEO meta tags. The site owner wants to disable a conflicting feature in another plugin so WP Pugmill\'s version can serve instead.'
		. ' Give concise, numbered step-by-step instructions for the task described. Maximum 5 steps. Each step must be one plain-text sentence — no markdown, no bullet symbols, no headers.'
		. ' IMPORTANT SAFETY RULES you must follow: (1) Never instruct the user to edit any code file directly — not functions.php, wp-config.php, or any other file. These edits can break the site and are not safe for non-developers. If a task would normally require a code edit, instead recommend a free plugin that handles it with a UI (for example, "Code Snippets" for adding PHP filters). (2) Never instruct the user to edit WordPress core files. (3) Prefer WordPress admin UI steps over any workaround that requires touching files.'
		. ' Return only the numbered steps — nothing else.';
	$user   = "Plugin: {$plugin_name}\nTask: {$instruction}";

	$result = wppugmill_call_ai( $provider, $api_key, $system, $user, 300 );

	if ( is_wp_error( $result ) ) {
		wp_send_json_error( array( 'message' => $result->get_error_message() ), 500 );
	}

	wp_send_json_success( array( 'steps' => sanitize_textarea_field( trim( $result ) ) ) );
}

// =========================================================================
// API key connection test
// =========================================================================

add_action( 'wp_ajax_wppugmill_test_api_key', 'wppugmill_ajax_test_api_key' );

/**
 * AJAX handler — send a minimal test request to verify the stored API key works.
 *
 * Uses max_tokens=1 so the cost is negligible (a few input tokens).
 * Does NOT count against the hourly rate limit.
 */
function wppugmill_ajax_test_api_key() {
	check_ajax_referer( 'wppugmill_test_api_key', 'nonce' );

	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'wp-pugmill' ) ), 403 );
	}

	$provider = get_option( 'wppugmill_ai_provider', 'anthropic' );
	$api_key  = wppugmill_get_encrypted_option( 'wppugmill_ai_api_key', '' );

	if ( empty( $api_key ) ) {
		wp_send_json_error( array( 'message' => __( 'No API key saved yet.', 'wp-pugmill' ) ), 400 );
	}

	// Make a minimal request — just enough to validate auth. max_tokens=1 keeps cost negligible.
	switch ( $provider ) {
		case 'openai':
			$response = wp_remote_post(
				'https://api.openai.com/v1/chat/completions',
				wppugmill_ai_request_args(
					array( 'Content-Type' => 'application/json', 'Authorization' => 'Bearer ' . $api_key ),
					wp_json_encode( array(
						'model'      => 'gpt-4o-mini',
						'messages'   => array( array( 'role' => 'user', 'content' => 'Hi' ) ),
						'max_tokens' => 1,
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
						'contents'         => array( array( 'parts' => array( array( 'text' => 'Hi' ) ) ) ),
						'generationConfig' => array( 'maxOutputTokens' => 1 ),
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
						'max_tokens' => 1,
						'messages'   => array( array( 'role' => 'user', 'content' => 'Hi' ) ),
					) )
				)
			);
			break;
	}

	if ( is_wp_error( $response ) ) {
		wp_send_json_error( array( 'message' => __( 'Could not reach the AI provider. Check your server\'s internet connection.', 'wp-pugmill' ) ) );
	}

	$code = wp_remote_retrieve_response_code( $response );

	if ( 200 === $code ) {
		$provider_labels = array( 'anthropic' => 'Anthropic (Claude)', 'openai' => 'OpenAI (GPT)', 'gemini' => 'Google Gemini' );
		$label           = $provider_labels[ $provider ] ?? $provider;
		wp_send_json_success( array(
			'message' => sprintf(
				/* translators: %s: provider name */
				__( 'Connected to %s successfully.', 'wp-pugmill' ),
				$label
			),
		) );
	}

	$body   = json_decode( wp_remote_retrieve_body( $response ), true );
	$detail = $body['error']['message'] ?? '';

	if ( 401 === $code ) {
		wp_send_json_error( array( 'message' => __( 'Invalid API key — authentication failed.', 'wp-pugmill' ) ) );
	}
	if ( 403 === $code ) {
		wp_send_json_error( array( 'message' => __( 'API key does not have permission to use this model.', 'wp-pugmill' ) ) );
	}
	if ( 402 === $code || ( $detail && str_contains( strtolower( $detail ), 'credit' ) ) ) {
		wp_send_json_error( array( 'message' => __( 'API key is valid but your account has insufficient credits.', 'wp-pugmill' ) ) );
	}
	if ( 429 === $code ) {
		wp_send_json_error( array( 'message' => __( 'API key is valid but your account is currently rate limited.', 'wp-pugmill' ) ) );
	}

	wp_send_json_error( array(
		'message' => sprintf(
			/* translators: %d: HTTP status code */
			__( 'Provider returned an unexpected response (HTTP %d). The key may still be valid — try generating a field to confirm.', 'wp-pugmill' ),
			$code
		),
	) );
}
