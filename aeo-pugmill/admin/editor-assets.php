<?php
/**
 * Gutenberg editor assets — enqueues the compiled sidebar JS.
 *
 * @package WPPugmill
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Enqueue the compiled sidebar JS for all block-editor screens.
 */
function aeopugmill_enqueue_editor_assets() {
	$asset_file = AEOPUGMILL_PLUGIN_DIR . 'build/index.asset.php';

	// Build hasn't been run yet — fail silently so the classic meta box still works.
	if ( ! file_exists( $asset_file ) ) {
		return;
	}

	$asset = include $asset_file;

	wp_enqueue_script(
		'aeopugmill-editor',
		AEOPUGMILL_PLUGIN_URL . 'build/index.js',
		$asset['dependencies'],
		$asset['version'],
		true
	);

	wp_localize_script(
		'aeopugmill-editor',
		'aeopugmill',
		array(
			'mode'          => aeopugmill_mode(),
			'hasApiKey'     => ! empty( aeopugmill_get_encrypted_option( 'aeopugmill_ai_api_key', '' ) ),
			'ajaxUrl'       => admin_url( 'admin-ajax.php' ),
			'nonce'         => wp_create_nonce( 'aeopugmill_generate_aeo' ),
			'toneNonce'          => wp_create_nonce( 'aeopugmill_tone_check' ),
			'readingLevelNonce'  => wp_create_nonce( 'aeopugmill_reading_level' ),
			'headlinesNonce'     => wp_create_nonce( 'aeopugmill_headline_variants' ),
			'topicFocusNonce'    => wp_create_nonce( 'aeopugmill_topic_focus' ),
	'refineFocusNonce'    => wp_create_nonce( 'aeopugmill_refine_focus' ),
			'swapFocusNonce'      => wp_create_nonce( 'aeopugmill_swap_focus_passage' ),
			'usageNonce'         => wp_create_nonce( 'aeopugmill_get_usage' ),
			'excerptNonce'       => wp_create_nonce( 'aeopugmill_generate_excerpt' ),
	'internalLinksNonce' => wp_create_nonce( 'aeopugmill_internal_links' ),
			'socialDraftNonce'   => wp_create_nonce( 'aeopugmill_social_draft' ),
			'summaryNonce'       => wp_create_nonce( 'aeopugmill_generate_summary' ),
			'qaNonce'            => wp_create_nonce( 'aeopugmill_generate_qa' ),
			'entitiesNonce'      => wp_create_nonce( 'aeopugmill_generate_entities' ),
			'keywordsNonce'      => wp_create_nonce( 'aeopugmill_generate_keywords' ),
			'fixKeywordsNonce'      => wp_create_nonce( 'aeopugmill_fix_keyword_coverage' ),
			'suggestHeadingsNonce'  => wp_create_nonce( 'aeopugmill_suggest_headings' ),
		'seoNonce'           => wp_create_nonce( 'aeopugmill_generate_seo' ),
		'howtoNonce'         => wp_create_nonce( 'aeopugmill_generate_howto_steps' ),
		'schemaAiNonce'      => wp_create_nonce( 'aeopugmill_suggest_schema' ),
			'pricingUrl'    => esc_url( 'https://aeopugmill.com/pricing' ),
		)
	);

	wp_enqueue_script(
		'aeopugmill-editor-resize',
		AEOPUGMILL_PLUGIN_URL . 'admin/js/editor-resize.js',
		array(),
		AEOPUGMILL_VERSION,
		true
	);

	wp_enqueue_style(
		'aeopugmill-editor-resize',
		AEOPUGMILL_PLUGIN_URL . 'admin/css/editor-resize.css',
		array(),
		AEOPUGMILL_VERSION
	);

	wp_add_inline_style(
		'aeopugmill-editor-resize',
		'.aeopugmill-panel { border-left: 3px solid #7c3aed; }
		.aeopugmill-panel > .components-panel__body-title { background: #f5f0ff; }'
	);
}
add_action( 'enqueue_block_editor_assets', 'aeopugmill_enqueue_editor_assets' );

/**
 * Whether the current admin screen is using the block editor.
 *
 * Safe to call from add_meta_boxes and later hooks.
 *
 * @return bool
 */
function aeopugmill_is_block_editor() {
	if ( ! function_exists( 'get_current_screen' ) ) {
		return false;
	}
	$screen = get_current_screen();
	return $screen && method_exists( $screen, 'is_block_editor' ) && $screen->is_block_editor();
}
