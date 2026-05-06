<?php
/**
 * AI Bot Analytics — helper functions and redirect.
 *
 * The analytics dashboard is now rendered as the "Bot Analytics" tab on the
 * main Pugmill AEO Toolkit settings page (?page=aeo-pugmill&tab=analytics).
 *
 * This file:
 *  - Keeps the aeopugmill_bot_config() helper used by settings-page.php.
 *  - Redirects any direct visits to the old aeo-pugmill-bots page to the new tab.
 *
 * @package WPPugmill
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Bot display config: label, color, type (legacy), and category.
 *
 * Categories:
 *   'ai'       — AI companies doing both training crawling and real-time retrieval
 *   'training' — Pure foundation-model training crawlers (no direct user queries)
 *   'search'   — Traditional search engine spiders
 *   'seo'      — Commercial SEO / analytics bots
 *   'other'    — Catch-all for unknown bots detected by heuristic
 *
 * @return array<string, array{label: string, color: string, type: string, category: string}>
 */
function aeopugmill_bot_config() {
	return array(
		// ── OpenAI — three distinct crawlers with different purposes ──────
		'GPTBot'          => array( 'label' => 'GPTBot (OpenAI training)',       'color' => '#10a37f', 'type' => 'ai', 'category' => 'ai' ),
		'OAI-SearchBot'   => array( 'label' => 'OAI-SearchBot (ChatGPT search)', 'color' => '#0d8a6a', 'type' => 'ai', 'category' => 'ai' ),
		'ChatGPT-User'    => array( 'label' => 'ChatGPT-User (live browsing)',   'color' => '#1ac89e', 'type' => 'ai', 'category' => 'ai' ),
		// ── Anthropic/Claude — three distinct crawlers ────────────────────
		'ClaudeBot'       => array( 'label' => 'ClaudeBot (Anthropic training)', 'color' => '#d97706', 'type' => 'ai', 'category' => 'ai' ),
		'Claude-User'     => array( 'label' => 'Claude-User (live browsing)',    'color' => '#f59e0b', 'type' => 'ai', 'category' => 'ai' ),
		'anthropic-ai'    => array( 'label' => 'anthropic-ai (API access)',      'color' => '#fbbf24', 'type' => 'ai', 'category' => 'ai' ),
		// ── Perplexity — index vs live ────────────────────────────────────
		'PerplexityBot'   => array( 'label' => 'PerplexityBot',                  'color' => '#6366f1', 'type' => 'ai', 'category' => 'ai' ),
		'Perplexity-User' => array( 'label' => 'Perplexity-User (live)',         'color' => '#818cf8', 'type' => 'ai', 'category' => 'ai' ),
		// ── Other AI companies ────────────────────────────────────────────
		'Gemini'     => array( 'label' => 'Gemini / Google',  'color' => '#4285f4', 'type' => 'ai', 'category' => 'ai' ),
		'Amazonbot'  => array( 'label' => 'Amazon / Alexa',   'color' => '#ff9900', 'type' => 'ai', 'category' => 'ai' ),
		'Meta'       => array( 'label' => 'Meta',             'color' => '#0866ff', 'type' => 'ai', 'category' => 'ai' ),
		'Mistral'    => array( 'label' => 'Mistral AI',       'color' => '#f97316', 'type' => 'ai', 'category' => 'ai' ),
		// ── Foundation model training crawlers ────────────────────────────
		'Bytespider'  => array( 'label' => 'Bytespider (TikTok)', 'color' => '#69c9d0', 'type' => 'ai', 'category' => 'training' ),
		'Cohere'      => array( 'label' => 'Cohere',              'color' => '#39d353', 'type' => 'ai', 'category' => 'training' ),
		'DeepSeek'    => array( 'label' => 'DeepSeek',            'color' => '#1a6cf6', 'type' => 'ai', 'category' => 'training' ),
		'Grok'        => array( 'label' => 'Grok / xAI',          'color' => '#e5e5e5', 'type' => 'ai', 'category' => 'training' ),
		'CCBot'       => array( 'label' => 'CCBot (Common Crawl)','color' => '#8b5cf6', 'type' => 'ai', 'category' => 'training' ),
		// ── Traditional search engines ─────────────────────────────────────
		'Googlebot'         => array( 'label' => 'Googlebot',                          'color' => '#34a853', 'type' => 'search', 'category' => 'search' ),
		'GoogleOther'       => array( 'label' => 'GoogleOther',                        'color' => '#ea4335', 'type' => 'search', 'category' => 'search' ),
		'Bingbot'           => array( 'label' => 'Bingbot',                            'color' => '#00809d', 'type' => 'search', 'category' => 'search' ),
		'YandexBot'         => array( 'label' => 'YandexBot',                          'color' => '#ff0000', 'type' => 'search', 'category' => 'search' ),
		'BaiduBot'          => array( 'label' => 'BaiduSpider',                        'color' => '#2932e1', 'type' => 'search', 'category' => 'search' ),
		'Applebot-Extended' => array( 'label' => 'Applebot-Extended (Apple Intelligence)', 'color' => '#374151', 'type' => 'search', 'category' => 'search' ),
		'Applebot'          => array( 'label' => 'Applebot (Siri / Spotlight)',         'color' => '#555555', 'type' => 'search', 'category' => 'search' ),
		'DuckDuckBot'       => array( 'label' => 'DuckDuckGo',                         'color' => '#de5833', 'type' => 'search', 'category' => 'search' ),
		// ── Commercial SEO / analytics bots ──────────────────────────────
		'SemrushBot'  => array( 'label' => 'Semrush',     'color' => '#ff6900', 'type' => 'seo', 'category' => 'seo' ),
		'AhrefsBot'   => array( 'label' => 'Ahrefs',      'color' => '#0080ff', 'type' => 'seo', 'category' => 'seo' ),
		'DotBot'      => array( 'label' => 'Dotbot',      'color' => '#94a3b8', 'type' => 'seo', 'category' => 'seo' ),
		'MJ12bot'     => array( 'label' => 'Majestic',    'color' => '#7c3aed', 'type' => 'seo', 'category' => 'seo' ),
		'Barkrowler'  => array( 'label' => 'Barkrowler',  'color' => '#64748b', 'type' => 'seo', 'category' => 'seo' ),
		'AI2Bot'      => array( 'label' => 'AI2Bot',      'color' => '#0ea5e9', 'type' => 'seo', 'category' => 'seo' ),
	);
}

/**
 * Redirect legacy aeo-pugmill-bots page to the new analytics tab.
 */
function aeopugmill_redirect_legacy_bots_page() {
	if ( ! is_admin() ) {
		return;
	}
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended
	if ( isset( $_GET['page'] ) && 'aeo-pugmill-bots' === sanitize_key( $_GET['page'] ) ) {
		wp_safe_redirect( admin_url( 'options-general.php?page=aeo-pugmill&tab=analytics' ) );
		exit;
	}
}
add_action( 'admin_init', 'aeopugmill_redirect_legacy_bots_page' );
