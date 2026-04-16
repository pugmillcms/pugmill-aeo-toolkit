<?php
/**
 * AEO Pugmill — AI transport layer.
 *
 * Everything that touches external AI providers or orchestrates a request:
 *   - aeopugmill_ai_request_setup()   — nonce/cap/rate-limit/key preamble
 *   - aeopugmill_call_ai()            — generic provider dispatcher
 *   - aeopugmill_ai_request_args()    — shared HTTP args (timeout, sslverify)
 *   - aeopugmill_record_token_usage() — persist per-user token counts
 *   - aeopugmill_get_token_usage()    — read per-user token counts
 *
 * No AJAX action registrations live here — this file is pure infrastructure.
 * Add a new provider by extending the switch in aeopugmill_call_ai().
 *
 * @package WPPugmill
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// ── Request setup ─────────────────────────────────────────────────────────────

/**
 * Common setup for all AI AJAX handlers.
 *
 * Verifies nonce, capability, rate limit, mode, and API key.
 * Sends a JSON error and exits on failure; returns data array on success.
 *
 * @param string $nonce_action   Nonce action string.
 * @param string $feature_label  Human-readable feature name for error messages.
 * @param bool   $require_license Whether to gate on a paid license (default true).
 * @return array { post, provider, api_key, title, content, post_type }
 */
function aeopugmill_ai_request_setup( $nonce_action, $feature_label, $require_license = true ) {
	check_ajax_referer( $nonce_action, 'nonce' );

	if ( ! current_user_can( 'edit_posts' ) ) {
		wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'aeo-pugmill' ) ), 403 );
	}

	$rate_check = aeopugmill_check_rate_limit();
	if ( is_wp_error( $rate_check ) ) {
		wp_send_json_error( array( 'message' => $rate_check->get_error_message() ), 429 );
	}

	if ( $require_license ) {
		$mode = aeopugmill_mode();
		if ( 'free' === $mode ) {
			wp_send_json_error( array(
				/* translators: %1$s: feature name */
				'message' => sprintf( __( '%1$s requires AEO Pugmill Pro. <a href="https://aeopugmill.com/pricing" target="_blank">Get your license →</a>', 'aeo-pugmill' ), $feature_label ),
			), 403 );
		}
		if ( 'pro' === $mode ) {
			wp_send_json_error( array( 'message' => __( 'Pro mode coming soon.', 'aeo-pugmill' ) ), 501 );
		}
	}

	$post_id = absint( wp_unslash( $_POST['post_id'] ?? 0 ) );
	if ( ! $post_id ) {
		wp_send_json_error( array( 'message' => __( 'Missing post ID.', 'aeo-pugmill' ) ), 400 );
	}
	if ( ! current_user_can( 'edit_post', $post_id ) ) {
		wp_send_json_error( array( 'message' => __( 'You do not have permission to edit this post.', 'aeo-pugmill' ) ), 403 );
	}

	$post = get_post( $post_id );
	if ( ! $post ) {
		wp_send_json_error( array( 'message' => __( 'Post not found.', 'aeo-pugmill' ) ), 404 );
	}

	$provider = get_option( 'aeopugmill_ai_provider', 'anthropic' );
	$api_key  = aeopugmill_get_encrypted_option( 'aeopugmill_ai_api_key', '' );
	if ( empty( $api_key ) ) {
		wp_send_json_error( array( 'message' => __( 'No API key configured. Add your key in Settings → AEO Pugmill.', 'aeo-pugmill' ) ), 400 );
	}

	$title = get_the_title( $post );
	if ( ! empty( $_POST['draft_content'] ) ) {
		$content = wp_strip_all_tags( sanitize_textarea_field( wp_unslash( $_POST['draft_content'] ) ) );
	} else {
		$content = wp_strip_all_tags( apply_filters( 'the_content', $post->post_content ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Applying core WP content filter.
		$content = html_entity_decode( $content, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	}
	$content = mb_substr( trim( $content ), 0, AEOPUGMILL_MAX_AI_INPUT );

	$post_type = $post->post_type;
	return compact( 'post', 'provider', 'api_key', 'title', 'content', 'post_type' );
}

// ── HTTP helpers ──────────────────────────────────────────────────────────────

/**
 * Common HTTP args for all AI provider calls.
 *
 * @param array  $headers  Provider-specific request headers.
 * @param string $body     JSON-encoded request body.
 * @return array
 */
function aeopugmill_ai_request_args( array $headers, $body ) {
	return array(
		'timeout'   => 30,
		'sslverify' => true,
		'headers'   => $headers,
		'body'      => $body,
	);
}

// ── Provider dispatcher ───────────────────────────────────────────────────────

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
 * @param string $provider    'anthropic' | 'openai' | 'gemini'
 * @param string $api_key     Decrypted API key.
 * @param string $system      System prompt.
 * @param string $user        User prompt.
 * @param int    $max_tokens  Maximum response tokens.
 * @return string|WP_Error
 */
function aeopugmill_call_ai( $provider, $api_key, $system, $user, $max_tokens = 400 ) {
	switch ( $provider ) {
		case 'openai':
			$response = wp_remote_post(
				'https://api.openai.com/v1/chat/completions',
				aeopugmill_ai_request_args(
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
				aeopugmill_ai_request_args(
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
				aeopugmill_ai_request_args(
					array( 'Content-Type' => 'application/json', 'x-api-key' => $api_key, 'anthropic-version' => '2023-06-01' ),
					wp_json_encode( array(
						'model'      => AEOPUGMILL_ANTHROPIC_MODEL,
						'max_tokens' => $max_tokens,
						'system'     => $system,
						'messages'   => array( array( 'role' => 'user', 'content' => $user ) ),
					) )
				)
			);
			break;
	}

	if ( is_wp_error( $response ) ) {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( 'AEO Pugmill AI error (' . $provider . '): ' . $response->get_error_message() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}
		return new WP_Error( 'request_failed', __( 'Could not reach AI provider. Please check your connection and try again.', 'aeo-pugmill' ) );
	}

	$code = wp_remote_retrieve_response_code( $response );
	$body = wp_remote_retrieve_body( $response );
	$data = json_decode( $body, true );

	if ( 200 !== $code ) {
		$detail = $data['error']['message'] ?? $body;
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( 'AEO Pugmill AI provider error (' . $provider . ' ' . $code . '): ' . $detail ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}
		if ( 401 === $code ) return new WP_Error( 'provider_error', __( 'Invalid API key. Please check your key in Settings → AEO Pugmill.', 'aeo-pugmill' ) );
		if ( 429 === $code ) return new WP_Error( 'provider_error', __( 'AI provider rate limit reached. Please wait and try again.', 'aeo-pugmill' ) );
		if ( 402 === $code ) return new WP_Error( 'provider_error', __( 'Insufficient credits on your AI provider account. Please top up and try again.', 'aeo-pugmill' ) );
		if ( 529 === $code ) return new WP_Error( 'provider_error', __( 'AI provider is momentarily overloaded. Please try again in a few seconds.', 'aeo-pugmill' ) );
		return new WP_Error( 'provider_error', __( 'AI provider returned an error. Please try again.', 'aeo-pugmill' ) );
	}

	$raw = '';
	switch ( $provider ) {
		case 'anthropic': $raw = $data['content'][0]['text'] ?? ''; break;
		case 'openai':    $raw = $data['choices'][0]['message']['content'] ?? ''; break;
		case 'gemini':    $raw = $data['candidates'][0]['content']['parts'][0]['text'] ?? ''; break;
	}

	if ( empty( trim( $raw ) ) ) {
		return new WP_Error( 'empty_response', __( 'AI returned an empty response. Please try again.', 'aeo-pugmill' ) );
	}

	// Track token usage as a side effect — store per-user, per-month in usermeta.
	// These values come directly from the provider response and are verifiable
	// against the user's own provider dashboard.
	aeopugmill_record_token_usage( $provider, $data );

	return aeopugmill_strip_ai_json_fences( $raw );
}

// ── Vision dispatcher ────────────────────────────────────────────────────────

/**
 * Send an image + text prompt to the configured AI provider for analysis.
 *
 * Used for featured image alt text generation. Anthropic and OpenAI receive
 * the image URL directly; Gemini fetches and base64-encodes it.
 *
 * Returns raw text string on success, WP_Error on failure.
 *
 * @param string $provider   'anthropic' | 'openai' | 'gemini'
 * @param string $api_key    Decrypted API key.
 * @param string $image_url  Publicly accessible image URL.
 * @param string $prompt     Text prompt describing the task.
 * @param int    $max_tokens Maximum response tokens.
 * @return string|WP_Error
 */
function aeopugmill_call_ai_vision( $provider, $api_key, $image_url, $prompt, $max_tokens = 100 ) {
	switch ( $provider ) {
		case 'openai':
			$response = wp_remote_post(
				'https://api.openai.com/v1/chat/completions',
				aeopugmill_ai_request_args(
					array( 'Content-Type' => 'application/json', 'Authorization' => 'Bearer ' . $api_key ),
					wp_json_encode( array(
						'model'      => 'gpt-4o',
						'max_tokens' => $max_tokens,
						'messages'   => array( array(
							'role'    => 'user',
							'content' => array(
								array( 'type' => 'image_url', 'image_url' => array( 'url' => $image_url ) ),
								array( 'type' => 'text', 'text' => $prompt ),
							),
						) ),
					) )
				)
			);
			break;

		case 'gemini':
			// Gemini requires base64-encoded image data for inline analysis.
			$img_response = wp_remote_get( $image_url, array( 'timeout' => 15, 'sslverify' => true ) );
			if ( is_wp_error( $img_response ) ) {
				return new WP_Error( 'image_fetch_failed', __( 'Could not fetch the featured image for analysis.', 'aeo-pugmill' ) );
			}
			$img_body  = wp_remote_retrieve_body( $img_response );
			$mime_type = wp_remote_retrieve_header( $img_response, 'content-type' ) ?: 'image/jpeg';
			$mime_type = strtok( $mime_type, ';' ); // strip charset if present

			$response = wp_remote_post(
				'https://generativelanguage.googleapis.com/v1beta/models/gemini-1.5-flash:generateContent',
				aeopugmill_ai_request_args(
					array( 'Content-Type' => 'application/json', 'x-goog-api-key' => $api_key ),
					wp_json_encode( array(
						'contents'         => array( array( 'parts' => array(
							array( 'inline_data' => array( 'mime_type' => $mime_type, 'data' => base64_encode( $img_body ) ) ),
							array( 'text' => $prompt ),
						) ) ),
						'generationConfig' => array( 'max_output_tokens' => $max_tokens ),
					) )
				)
			);
			break;

		case 'anthropic':
		default:
			// Anthropic only accepts HTTPS URLs for the 'url' source type.
			// For HTTP URLs (e.g. local dev), fetch and send as base64.
			if ( str_starts_with( $image_url, 'https://' ) ) {
				$anthr_image_block = array( 'type' => 'image', 'source' => array( 'type' => 'url', 'url' => $image_url ) );
			} else {
				$img_response = wp_remote_get( $image_url, array( 'timeout' => 15, 'sslverify' => false ) );
				if ( is_wp_error( $img_response ) ) {
					return new WP_Error( 'image_fetch_failed', __( 'Could not fetch the image for analysis.', 'aeo-pugmill' ) );
				}
				$img_body   = wp_remote_retrieve_body( $img_response );
				$mime_type  = wp_remote_retrieve_header( $img_response, 'content-type' ) ?: 'image/jpeg';
				$mime_type  = strtok( $mime_type, ';' );
				$anthr_image_block = array( 'type' => 'image', 'source' => array(
					'type'       => 'base64',
					'media_type' => $mime_type,
					'data'       => base64_encode( $img_body ),
				) );
			}
			$response = wp_remote_post(
				'https://api.anthropic.com/v1/messages',
				aeopugmill_ai_request_args(
					array( 'Content-Type' => 'application/json', 'x-api-key' => $api_key, 'anthropic-version' => '2023-06-01' ),
					wp_json_encode( array(
						'model'      => AEOPUGMILL_ANTHROPIC_MODEL,
						'max_tokens' => $max_tokens,
						'messages'   => array( array(
							'role'    => 'user',
							'content' => array(
								$anthr_image_block,
								array( 'type' => 'text', 'text' => $prompt ),
							),
						) ),
					) )
				)
			);
			break;
	}

	if ( is_wp_error( $response ) ) {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( 'AEO Pugmill vision error (' . $provider . '): ' . $response->get_error_message() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}
		return new WP_Error( 'request_failed', __( 'Could not reach AI provider. Please check your connection and try again.', 'aeo-pugmill' ) );
	}

	$code = wp_remote_retrieve_response_code( $response );
	$body = wp_remote_retrieve_body( $response );
	$data = json_decode( $body, true );

	if ( 200 !== $code ) {
		$detail = $data['error']['message'] ?? $body;
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( 'AEO Pugmill vision provider error (' . $provider . ' ' . $code . '): ' . $detail ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}
		if ( 401 === $code ) return new WP_Error( 'provider_error', __( 'Invalid API key.', 'aeo-pugmill' ) );
		if ( 429 === $code ) return new WP_Error( 'provider_error', __( 'AI provider rate limit reached. Please wait and try again.', 'aeo-pugmill' ) );
		return new WP_Error( 'provider_error', __( 'AI provider returned an error. Please try again.', 'aeo-pugmill' ) );
	}

	$raw = '';
	switch ( $provider ) {
		case 'anthropic': $raw = $data['content'][0]['text'] ?? ''; break;
		case 'openai':    $raw = $data['choices'][0]['message']['content'] ?? ''; break;
		case 'gemini':    $raw = $data['candidates'][0]['content']['parts'][0]['text'] ?? ''; break;
	}

	if ( empty( trim( $raw ) ) ) {
		return new WP_Error( 'empty_response', __( 'AI returned an empty response. Please try again.', 'aeo-pugmill' ) );
	}

	aeopugmill_record_token_usage( $provider, $data );

	return trim( $raw );
}

// ── Token tracking ────────────────────────────────────────────────────────────

/**
 * Record token usage for the current user, keyed by month.
 *
 * Data is stored in wp_usermeta as an array with keys: input, output, calls.
 * Values come directly from the provider's API response — they are verifiable
 * against the user's own provider dashboard (no estimates, no conversions).
 *
 * @param string $provider  'anthropic' | 'openai' | 'gemini'
 * @param array  $data      Decoded API response body.
 */
function aeopugmill_record_token_usage( $provider, $data ) {
	$user_id = get_current_user_id();
	if ( ! $user_id ) {
		return;
	}

	$input  = 0;
	$output = 0;

	switch ( $provider ) {
		case 'anthropic':
			$input  = (int) ( $data['usage']['input_tokens']  ?? 0 );
			$output = (int) ( $data['usage']['output_tokens'] ?? 0 );
			break;
		case 'openai':
			$input  = (int) ( $data['usage']['prompt_tokens']     ?? 0 );
			$output = (int) ( $data['usage']['completion_tokens'] ?? 0 );
			break;
		case 'gemini':
			$input  = (int) ( $data['usageMetadata']['promptTokenCount']     ?? 0 );
			$output = (int) ( $data['usageMetadata']['candidatesTokenCount'] ?? 0 );
			break;
	}

	if ( $input === 0 && $output === 0 ) {
		return;
	}

	$meta_key = 'aeopugmill_tokens_' . gmdate( 'Y_m' );
	$current  = get_user_meta( $user_id, $meta_key, true );
	if ( ! is_array( $current ) ) {
		$current = array( 'input' => 0, 'output' => 0, 'calls' => 0 );
	}

	$current['input']  += $input;
	$current['output'] += $output;
	$current['calls']  += 1;

	update_user_meta( $user_id, $meta_key, $current );
}

/**
 * Get token usage for the current user for a given month.
 *
 * @param  string $month  Format: 'YYYY_MM'. Defaults to current month.
 * @return array  { input: int, output: int, calls: int }
 */
function aeopugmill_get_token_usage( $month = '' ) {
	if ( ! $month ) {
		$month = gmdate( 'Y_m' );
	}
	$data = get_user_meta( get_current_user_id(), 'aeopugmill_tokens_' . $month, true );
	if ( ! is_array( $data ) ) {
		return array( 'input' => 0, 'output' => 0, 'calls' => 0 );
	}
	return $data;
}
