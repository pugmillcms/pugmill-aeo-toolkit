<?php
/**
 * AI orchestrator — loads AI infrastructure and free BYOK field generation modules.
 *
 * Supports: Anthropic (Claude), OpenAI (GPT), Google (Gemini)
 *
 * Modules loaded here:
 * - ai-utils.php        — Pure utility functions (JSON decode, paragraph helpers)
 * - ai-client.php       — Transport layer (aeopugmill_call_ai, request setup, token tracking)
 * - ai-generate-aeo.php — Individual AEO field generators (summary, Q&A, entities, keywords)
 * - ai-generate-seo.php — SEO title/meta, HowTo steps
 *
 * Pro add-on (Pugmill AEO Toolkit Pro) registers additional handlers:
 * - Generate AEO (one-click combined)
 * - Refine Content suite (Tone Check, Topic Focus, Reading Level, Headline Variants,
 *   Internal Links, Excerpt Generator, Social Draft, Keyword Coverage)
 * - Bulk AEO, Audit AEO, Schema Suggest, Site Summary
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

// Transport layer (aeopugmill_call_ai, aeopugmill_ai_request_setup, token tracking) → ai-client.php
require_once __DIR__ . '/ai-client.php';

// Individual AEO field generators (free BYOK)
require_once __DIR__ . '/ai-generate-aeo.php';
require_once __DIR__ . '/ai-generate-seo.php';

