<?php
/**
 * Pugmill Agent — PNA-style conversational assistant for the editor sidebar.
 *
 * Injects live post context (AEO, SEO, health, audit) into a system prompt
 * at session start (context injection, not RAG), then relays multi-turn
 * conversation history to the configured AI provider. The AI embeds
 * <<ACTION:id>> signals that the JS frontend intercepts and executes against
 * existing AJAX endpoints — no new backend actions needed.
 *
 * Endpoint: POST /wp-json/wp-pugmill/v1/chat
 * Request:  { post_id: int, messages: [{role, content}, ...] }
 * Response: { message: string, actions: [{id, params}, ...] }
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
		'/chat',
		array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => 'wppugmill_rest_agent_chat',
			'permission_callback' => function( $request ) {
				$post_id = absint( $request['post_id'] ?? 0 );
				return $post_id && current_user_can( 'edit_post', $post_id );
			},
			'args' => array(
				'post_id'  => array(
					'required'          => true,
					'sanitize_callback' => 'absint',
					'validate_callback' => function( $v ) { return is_numeric( $v ) && $v > 0; },
				),
				'messages' => array(
					'required'          => true,
					'validate_callback' => function( $v ) { return is_array( $v ) && ! empty( $v ); },
				),
			),
		)
	);
} );

/**
 * REST callback: run one chat turn and return the assistant reply.
 *
 * @param  WP_REST_Request $request
 * @return WP_REST_Response|WP_Error
 */
function wppugmill_rest_agent_chat( $request ) {
	$post_id  = (int) $request['post_id'];
	$messages = (array) $request['messages'];

	$provider = get_option( 'wppugmill_ai_provider', 'anthropic' );
	$api_key  = wppugmill_get_encrypted_option( 'wppugmill_ai_api_key', '' );

	if ( empty( $api_key ) ) {
		return new WP_Error(
			'no_api_key',
			__( 'No API key configured. Add your key in Settings → WP Pugmill.', 'wp-pugmill' ),
			array( 'status' => 400 )
		);
	}

	// Shared rate limit (counts against the same hourly budget as other AI features)
	$rate = wppugmill_check_rate_limit();
	if ( is_wp_error( $rate ) ) {
		return new WP_Error( 'rate_limit', $rate->get_error_message(), array( 'status' => 429 ) );
	}

	// Build context + system prompt
	$context = wppugmill_agent_build_context( $post_id );
	$system  = wppugmill_agent_system_prompt( $context );

	// Sanitize messages: keep last 20 turns, valid roles only
	$allowed_roles = array( 'user', 'assistant' );
	$clean         = array();
	foreach ( array_slice( $messages, -20 ) as $m ) {
		$role    = in_array( $m['role'] ?? '', $allowed_roles, true ) ? $m['role'] : 'user';
		$content = sanitize_textarea_field( wp_unslash( $m['content'] ?? '' ) );
		if ( '' !== $content ) {
			$clean[] = array( 'role' => $role, 'content' => $content );
		}
	}

	if ( empty( $clean ) ) {
		return new WP_Error( 'empty_messages', __( 'No messages provided.', 'wp-pugmill' ), array( 'status' => 400 ) );
	}

	// Call AI
	$result = wppugmill_call_ai_chat( $provider, $api_key, $system, $clean, 800 );

	if ( is_wp_error( $result ) ) {
		return new WP_Error( 'ai_error', $result->get_error_message(), array( 'status' => 500 ) );
	}

	// Parse and strip action signals before sending to client
	list( $clean_text, $actions ) = wppugmill_agent_parse_actions( $result );

	return rest_ensure_response( array(
		'message' => wp_kses_post( $clean_text ),
		'actions' => $actions,
	) );
}

// ── Context builder ───────────────────────────────────────────────────────────

/**
 * Build the live context payload that is injected into the system prompt.
 *
 * Deliberately lean: summaries of counts rather than full field text, so the
 * token cost stays low while the AI still has specific, actionable data.
 *
 * @param  int $post_id
 * @return array
 */
function wppugmill_agent_build_context( $post_id ) {
	$post   = get_post( $post_id );
	$aeo    = wppugmill_get_aeo( $post_id );
	$seo    = wppugmill_get_seo( $post_id );
	$health = wppugmill_health_score( $post_id );
	$audit  = wppugmill_run_audit( $post_id );

	$plain = $post ? wp_strip_all_tags( apply_filters( 'the_content', $post->post_content ) ) : '';
	$plain = $plain ? html_entity_decode( trim( $plain ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) : '';
	$words = $plain ? str_word_count( $plain ) : 0;

	// Include a content preview so the agent can reference what the post actually says.
	// Capped at ~800 characters (~150 words) to keep token cost low.
	$content_preview = $plain ? mb_substr( $plain, 0, 800 ) : '';

	// Collect failing health check labels
	$health_failing = array_values( array_map(
		function( $item ) { return $item['label']; },
		array_filter( $health['items'], function( $item ) { return ! $item['pass']; } )
	) );

	// Collect failing audit check labels
	$audit_failing = array_values( array_map(
		function( $c ) { return $c['label']; },
		array_filter( $audit['checks'], function( $c ) { return 'fail' === $c['status']; } )
	) );

	$question_count = count( array_filter( $aeo['questions'], function( $q ) {
		return ! empty( $q['q'] );
	} ) );
	$entity_count   = count( array_filter( $aeo['entities'], function( $e ) {
		return ! empty( $e['name'] );
	} ) );
	$keywords       = array_values( array_filter( $aeo['keywords'], 'strlen' ) );

	return array(
		'plugin'  => 'WP Pugmill v' . WPPUGMILL_VERSION,
		'mode'    => wppugmill_mode(),
		'post'    => array(
			'id'              => $post_id,
			'title'           => $post ? get_the_title( $post ) : '',
			'status'          => $post ? $post->post_status : 'unknown',
			'word_count'      => $words,
			'content_preview' => $content_preview,
		),
		'aeo'     => array(
			'summary'        => $aeo['summary'],
			'question_count' => $question_count,
			'entity_count'   => $entity_count,
			'keyword_count'  => count( $keywords ),
			'keywords'       => $keywords,
		),
		'seo'     => array(
			'title'     => $seo['title'],
			'meta_desc' => $seo['meta_desc'],
			'noindex'   => (bool) $seo['noindex'],
		),
		'health'  => array(
			'score'   => $health['score'],
			'grade'   => $health['grade'],
			'failing' => $health_failing,
		),
		'audit'   => array(
			'score'   => $audit['score'],
			'failing' => $audit_failing,
		),
	);
}

// ── System prompt (PNA-style) ─────────────────────────────────────────────────

/**
 * Build the full system prompt with injected post context.
 *
 * Follows PNA principles: identity declaration, full context injection,
 * explicit action grammar, strict grounding rules, and conciseness constraint.
 *
 * @param  array $context  Output of wppugmill_agent_build_context().
 * @return string
 */
function wppugmill_agent_system_prompt( $context ) {
	$ctx_json = wp_json_encode( $context, JSON_PRETTY_PRINT );
	$voice    = trim( get_option( 'wppugmill_author_voice', '' ) );
	$mode     = $context['mode'] ?? wppugmill_mode();

	$voice_section = $voice
		? "\n\nAUTHOR VOICE (apply when generating content):\n" . $voice
		: '';

	// Mode-specific instructions injected into the system prompt so the agent
	// can guide free users toward paid features conversationally.
	if ( 'free' === $mode ) {
		$mode_section = '

PLAN TIER: FREE
The user is on the free plan. AI generation requires the AI Connector plan.

AVAILABLE to this user (chat and advice only — no generation actions):
- You can answer questions about the post\'s SEO and AEO performance.
- You can explain scores, failing checks, and what specifically needs to change.
- You CANNOT trigger any action signals on the free plan.

NOT available on the free plan (do NOT trigger these actions):
- generate_summary   — AEO summary (paid)
- generate_qa        — Q&A pairs (paid)
- generate_entities  — Named entities (paid)
- generate_keywords  — Keywords (paid)
- generate_seo       — SEO title + meta description (paid)
- generate_all       — One-click full generation (paid)
- write_from_draft   — Write or rewrite post content (paid)
- tone_check         — Tone check against author voice (paid)
- reading_level      — Reading level analysis (paid)
- topic_focus        — Topic focus score (paid)
- internal_links     — Internal link suggestions (paid)
- suggest_titles     — Suggest alternative titles (paid)
- suggest_excerpt    — Generate excerpt (paid)
- social_draft       — Social media post draft (paid)

When the user asks for a paid feature, acknowledge what they want, explain it requires the AI Connector plan (BYOK — bring your own API key), and share this link: https://wppugmill.com/pricing
Be friendly and specific — name the feature they asked for and what it does, so they know what they are getting.';
	} else {
		$mode_section = '

PLAN TIER: AI CONNECTOR (BYOK — bring your own API key, all features unlocked)
All generation actions and content tools are available to this user.';
	}

	return "You are the Pugmill Agent — a focused assistant for SEO and AEO built into the WordPress post editor.

Your responsibilities:
1. Answer questions about this post's SEO and AEO performance, grounded strictly in the context data below.
2. Explain scores, failing checks, and what specifically needs to change.
3. Trigger plugin actions when the user asks — by embedding action signals.

CURRENT POST CONTEXT:
{$ctx_json}{$voice_section}{$mode_section}

AUDIT CHECK → ACTION MAPPING:
When the user asks you to fix failing checks or improve the audit score, use this mapping.

Checks you CAN fix by generating (use the corresponding action):
- summary_present, summary_length            → generate_summary
- qa_present, qa_coverage, questions_natural → generate_qa
- entities_present, entity_specificity       → generate_entities
- keywords_present, keywords_in_content      → generate_keywords
- SEO title or meta description empty        → generate_seo
- Multiple fields missing across both        → generate_all (covers all AEO + SEO, paid only)

Checks that require MANUAL editing — be explicit that you cannot fix these automatically:
- content_length: the post needs more written content (400+ words minimum)
- has_headings: direct the user to the Audit panel — it has an AI Suggest Headings tool that proposes H2/H3 headings and lets them insert each one directly into the editor
- opening_concise: the user must shorten the opening paragraph to 80 words or fewer

AVAILABLE ACTIONS:
When you decide to perform a plugin action, embed exactly one signal at the very end of your response.
The signal is automatically stripped before the user sees the message.
Only trigger actions that are available on the user's current plan (see PLAN TIER above).

  <<ACTION:generate_all>>          — Generate all AEO fields (summary, Q&A, entities, keywords) + SEO title & description (paid only)
  <<ACTION:generate_seo>>          — Generate SEO title and meta description only
  <<ACTION:generate_summary>>      — Generate the AEO summary only
  <<ACTION:generate_qa>>           — Generate Q&A pairs only
  <<ACTION:generate_entities>>     — Generate named entities only
  <<ACTION:generate_keywords>>     — Generate keywords only
  <<ACTION:write_from_draft prompt=\"user brief here\">> — Write or rewrite the post content (paid only)
  <<ACTION:tone_check>>            — Run a tone check against the author voice guide (paid only)
  <<ACTION:reading_level>>         — Analyze the reading level and grade of the post (paid only)
  <<ACTION:topic_focus>>           — Analyze topic focus score and flag off-topic passages (paid only)
  <<ACTION:internal_links>>        — Find internal linking opportunities in published posts (paid only)
  <<ACTION:suggest_titles>>        — Suggest alternative curiosity- and utility-driven titles (paid only)
  <<ACTION:suggest_excerpt>>       — Generate a post excerpt (paid only)
  <<ACTION:social_draft platform=\"twitter\">> — Generate a social media draft; platform can be twitter, linkedin, facebook, or instagram (paid only)

REWRITE GUIDANCE (write_from_draft):
The context includes post.content_preview — the opening text of the post. Use it.
When the user asks to rewrite, improve, or redo the content WITHOUT a new direction:
  - Synthesize a brief from what you already know: title + content_preview + AEO summary + keywords.
  - Trigger write_from_draft immediately with that synthesized brief. Do NOT ask for more information.
  - Example synthesized brief: \"Rewrite this post about [topic from title/preview] to improve clarity, structure, and SEO for [keywords].\"
When the user gives a specific new direction (new topic, different audience, new angle), use that as the brief.
Only ask for a brief if the post has no content at all (word_count is 0 AND no title or summary).

RULES:
- SAVE REMINDER: After triggering any generation action, always end your visible message with: \"Save the post (Ctrl+S) before running the audit — the audit reads from saved data, not the editor preview.\"
- FIX PLANNING: When asked to fix the audit or improve the score, first name which failing checks you can generate vs which need manual editing. Then trigger the most impactful single action.
- DATA FRESHNESS: Do not speculate about why scores differ between sources. Report the current audit result and suggest re-running if there is confusion. Never say your data \"hasn't refreshed\" — context is rebuilt fresh on every message.
- Every score, count, or field value you mention must come from the injected context — never invent data.
- Keep replies to 2–4 sentences. This is a sidebar chat, not a blog post.
- If generating would overwrite existing non-empty content, say so in one sentence first.
- Only one action signal per response.
- If asked about something outside WP Pugmill (e.g. general WordPress admin), decline politely in one sentence.
- Never echo the context JSON back to the user.";
}

// ── Multi-turn AI call ────────────────────────────────────────────────────────

/**
 * Send a multi-turn conversation to the configured AI provider.
 *
 * Unlike wppugmill_call_ai() (single user string), this function accepts a
 * full messages array to maintain proper conversation history across turns.
 *
 * @param  string $provider    'anthropic' | 'openai' | 'gemini'
 * @param  string $api_key
 * @param  string $system      System prompt (injected context).
 * @param  array  $messages    [{role: 'user'|'assistant', content: string}, ...]
 * @param  int    $max_tokens
 * @return string|WP_Error     Raw AI text, or WP_Error on failure.
 */
function wppugmill_call_ai_chat( $provider, $api_key, $system, $messages, $max_tokens = 800 ) {
	switch ( $provider ) {

		case 'openai':
			$body_messages = array_merge(
				array( array( 'role' => 'system', 'content' => $system ) ),
				$messages
			);
			$response = wp_remote_post(
				'https://api.openai.com/v1/chat/completions',
				wppugmill_ai_request_args(
					array(
						'Content-Type'  => 'application/json',
						'Authorization' => 'Bearer ' . $api_key,
					),
					wp_json_encode( array(
						'model'      => 'gpt-4o',
						'messages'   => $body_messages,
						'max_tokens' => $max_tokens,
					) )
				)
			);
			break;

		case 'gemini':
			// Gemini uses 'model' (not 'assistant') for AI turns and puts the
			// system prompt in a separate systemInstruction block.
			$contents = array_map( function( $m ) {
				return array(
					'role'  => 'assistant' === $m['role'] ? 'model' : 'user',
					'parts' => array( array( 'text' => $m['content'] ) ),
				);
			}, $messages );
			$response = wp_remote_post(
				'https://generativelanguage.googleapis.com/v1beta/models/gemini-1.5-flash:generateContent',
				wppugmill_ai_request_args(
					array(
						'Content-Type'   => 'application/json',
						'x-goog-api-key' => $api_key,
					),
					wp_json_encode( array(
						'contents'          => $contents,
						'systemInstruction' => array( 'parts' => array( array( 'text' => $system ) ) ),
						'generationConfig'  => array( 'maxOutputTokens' => $max_tokens ),
					) )
				)
			);
			break;

		case 'anthropic':
		default:
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
						'max_tokens' => $max_tokens,
						'system'     => $system,
						'messages'   => $messages,
					) )
				)
			);
			break;
	}

	if ( is_wp_error( $response ) ) {
		error_log( 'WP Pugmill Agent (' . $provider . '): ' . $response->get_error_message() );
		return new WP_Error( 'request_failed', __( 'Could not reach AI provider. Please try again.', 'wp-pugmill' ) );
	}

	$code = wp_remote_retrieve_response_code( $response );
	$body = wp_remote_retrieve_body( $response );
	$data = json_decode( $body, true );

	if ( 200 !== $code ) {
		$detail = $data['error']['message'] ?? $body;
		error_log( 'WP Pugmill Agent provider error (' . $provider . ' ' . $code . '): ' . $detail );
		if ( 401 === $code ) return new WP_Error( 'provider_error', __( 'Invalid API key. Check Settings → WP Pugmill.', 'wp-pugmill' ) );
		if ( 429 === $code ) return new WP_Error( 'provider_error', __( 'Rate limit reached. Please wait a moment.', 'wp-pugmill' ) );
		if ( 402 === $code ) return new WP_Error( 'provider_error', __( 'Insufficient credits on your AI account.', 'wp-pugmill' ) );
		return new WP_Error( 'provider_error', __( 'AI provider returned an error. Please try again.', 'wp-pugmill' ) );
	}

	$raw = '';
	switch ( $provider ) {
		case 'anthropic': $raw = $data['content'][0]['text']                           ?? ''; break;
		case 'openai':    $raw = $data['choices'][0]['message']['content']             ?? ''; break;
		case 'gemini':    $raw = $data['candidates'][0]['content']['parts'][0]['text'] ?? ''; break;
	}

	if ( empty( trim( $raw ) ) ) {
		return new WP_Error( 'empty_response', __( 'AI returned an empty response. Please try again.', 'wp-pugmill' ) );
	}

	// Track token usage — same approach as wppugmill_call_ai().
	wppugmill_record_token_usage( $provider, $data );

	return $raw;
}

// ── Action signal parser ──────────────────────────────────────────────────────

/**
 * Extract and remove <<ACTION:...>> signals from an AI response.
 *
 * Supports:
 *   <<ACTION:action_id>>
 *   <<ACTION:action_id key="value" key2="value2">>
 *
 * @param  string $text  Raw AI response text.
 * @return array  [ string $clean_text, array $actions ]
 *                $actions: [ ['id' => string, 'params' => array], ... ]
 */
function wppugmill_agent_parse_actions( $text ) {
	$actions = array();

	$clean = preg_replace_callback(
		'/<<ACTION:(\w+)(?:\s+([^>]*))?>>/',
		function( $matches ) use ( &$actions ) {
			$params = array();
			if ( ! empty( $matches[2] ) ) {
				preg_match_all( '/(\w+)="([^"]*)"/', $matches[2], $pm, PREG_SET_ORDER );
				foreach ( $pm as $pair ) {
					$params[ sanitize_key( $pair[1] ) ] = sanitize_textarea_field( $pair[2] );
				}
			}
			$actions[] = array(
				'id'     => sanitize_key( $matches[1] ),
				'params' => $params,
			);
			return '';
		},
		$text
	);

	return array( trim( $clean ), $actions );
}
