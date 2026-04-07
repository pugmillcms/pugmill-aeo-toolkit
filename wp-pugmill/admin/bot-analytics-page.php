<?php
/**
 * AI Bot Analytics — helper functions and redirect.
 *
 * The analytics dashboard is now rendered as the "Bot Analytics" tab on the
 * main WP Pugmill settings page (?page=wp-pugmill&tab=analytics).
 *
 * This file:
 *  - Keeps the wppugmill_bot_config() helper used by settings-page.php.
 *  - Redirects any direct visits to the old wp-pugmill-bots page to the new tab.
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
function wppugmill_bot_config() {
	return array(
		// ── AI companies — training + real-time retrieval ─────────────────
		'ChatGPT'    => array( 'label' => 'ChatGPT / OpenAI', 'color' => '#10a37f', 'type' => 'ai', 'category' => 'ai' ),
		'Claude'     => array( 'label' => 'Claude / Anthropic','color' => '#d97706', 'type' => 'ai', 'category' => 'ai' ),
		'Perplexity' => array( 'label' => 'Perplexity',       'color' => '#6366f1', 'type' => 'ai', 'category' => 'ai' ),
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
		'Googlebot'   => array( 'label' => 'Googlebot',   'color' => '#34a853', 'type' => 'search', 'category' => 'search' ),
		'GoogleOther' => array( 'label' => 'GoogleOther', 'color' => '#ea4335', 'type' => 'search', 'category' => 'search' ),
		'Bingbot'     => array( 'label' => 'Bingbot',     'color' => '#00809d', 'type' => 'search', 'category' => 'search' ),
		'YandexBot'   => array( 'label' => 'YandexBot',   'color' => '#ff0000', 'type' => 'search', 'category' => 'search' ),
		'BaiduBot'    => array( 'label' => 'BaiduSpider', 'color' => '#2932e1', 'type' => 'search', 'category' => 'search' ),
		'Applebot'    => array( 'label' => 'Applebot',    'color' => '#555555', 'type' => 'search', 'category' => 'search' ),
		'DuckDuckBot' => array( 'label' => 'DuckDuckGo',  'color' => '#de5833', 'type' => 'search', 'category' => 'search' ),
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
 * Redirect legacy wp-pugmill-bots page to the new analytics tab.
 */
function wppugmill_redirect_legacy_bots_page() {
	if ( ! is_admin() ) {
		return;
	}
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended
	if ( isset( $_GET['page'] ) && 'wp-pugmill-bots' === sanitize_key( $_GET['page'] ) ) {
		wp_safe_redirect( admin_url( 'options-general.php?page=wp-pugmill&tab=analytics' ) );
		exit;
	}
}
add_action( 'admin_init', 'wppugmill_redirect_legacy_bots_page' );
