<?php
/**
 * Pugmill AEO Toolkit — standalone unit tests
 *
 * Tests pure-PHP utility functions without a live WordPress environment.
 * WordPress API stubs are defined below.
 *
 * Run from the plugin root:
 *   php tests/test-plugin.php
 *
 * @package WPPugmill
 */

declare( strict_types=1 );

// ── Test runner ───────────────────────────────────────────────────────────

$_tests_run    = 0;
$_tests_passed = 0;
$_failures     = [];

function assert_equal( string $label, mixed $expected, mixed $actual ): void {
	global $_tests_run, $_tests_passed, $_failures;
	$_tests_run++;
	if ( $expected === $actual ) {
		$_tests_passed++;
		echo "  \033[32m✓\033[0m {$label}\n";
	} else {
		$_failures[] = $label;
		echo "  \033[31m✗\033[0m {$label}\n";
		echo "    Expected: " . var_export( $expected, true ) . "\n";
		echo "    Actual:   " . var_export( $actual, true ) . "\n";
	}
}

function assert_true( string $label, bool $actual ): void {
	assert_equal( $label, true, $actual );
}

function assert_false( string $label, bool $actual ): void {
	assert_equal( $label, false, $actual );
}

function assert_contains( string $label, string $needle, string $haystack ): void {
	assert_true( $label, str_contains( $haystack, $needle ) );
}

function section( string $name ): void {
	echo "\n\033[1;34m{$name}\033[0m\n";
}

// ── WordPress function stubs ──────────────────────────────────────────────

$GLOBALS['_wp_options']      = [];
$GLOBALS['_wp_transients']   = [];
$GLOBALS['_mock_post']        = null;
$GLOBALS['_mock_post_type']   = 'post';
$GLOBALS['_mock_aeo']         = [ 'summary' => '', 'questions' => [], 'entities' => [], 'keywords' => [] ];
$GLOBALS['_mock_thumbnail_id']       = 0;     // 0 = no featured image
$GLOBALS['_mock_thumbnail_alt']      = '';    // alt text for thumbnail
$GLOBALS['_mock_is_singular']        = true;  // controls is_singular() stub
$GLOBALS['_mock_queried_object_id']  = 1;     // controls get_queried_object_id() stub
$GLOBALS['_mock_seo_raw']            = '';    // JSON string for _aeopugmill_seo post meta

function add_action( ...$args ): void {}
function add_filter( ...$args ): void {}
function remove_action( ...$args ): void {}
function remove_filter( ...$args ): void {}
function register_activation_hook( ...$args ): void {}
function register_deactivation_hook( ...$args ): void {}
function do_action( ...$args ): void {}

function get_option( string $key, mixed $default = false ): mixed {
	return $GLOBALS['_wp_options'][ $key ] ?? $default;
}
function update_option( string $key, mixed $value ): bool {
	$GLOBALS['_wp_options'][ $key ] = $value;
	return true;
}
function get_transient( string $key ): mixed {
	return $GLOBALS['_wp_transients'][ $key ] ?? false;
}
function set_transient( string $key, mixed $value, int $expiry = 0 ): bool {
	$GLOBALS['_wp_transients'][ $key ] = $value;
	return true;
}
function delete_transient( string $key ): bool {
	unset( $GLOBALS['_wp_transients'][ $key ] );
	return true;
}

function get_current_user_id(): int { return 1; }
function current_user_can( string $cap, ...$args ): bool { return true; }
function check_ajax_referer( string $action, mixed $query_arg = false, bool $die = true ): int { return 1; }
function wp_verify_nonce( mixed $nonce, mixed $action = -1 ): int|false { return 1; }
function wp_create_nonce( string $action ): string { return 'test_nonce'; }
function is_wp_error( mixed $thing ): bool { return $thing instanceof WP_Error; }
function get_post( mixed $id = null ): ?object { return $GLOBALS['_mock_post']; }
function get_post_type( mixed $post = null ): string { return $GLOBALS['_mock_post_type'] ?? 'post'; }
function number_format_i18n( float $number, int $decimals = 0 ): string { return number_format( $number, $decimals ); }
function get_the_title( mixed $post = 0 ): string { return 'Test Post'; }
function get_post_thumbnail_id( mixed $post = null ): int { return $GLOBALS['_mock_thumbnail_id']; }
function get_post_meta( int $id, string $key = '', bool $single = false ): mixed {
	if ( '_wp_attachment_image_alt' === $key ) {
		return $GLOBALS['_mock_thumbnail_alt'];
	}
	if ( '_aeopugmill_seo' === $key ) {
		return $GLOBALS['_mock_seo_raw'] ?? '';
	}
	return '';
}
function get_bloginfo( string $show = '' ): string { return 'UTF-8'; }
function apply_filters( string $hook, mixed $value, ...$args ): mixed { return $value; }
function absint( mixed $value ): int { return abs( (int) $value ); }
function __( string $text, string $domain = 'default' ): string { return $text; }
function _x( string $text, string $context, string $domain = 'default' ): string { return $text; }
function _n( string $single, string $plural, int $number, string $domain = 'default' ): string {
	return $number === 1 ? $single : $plural;
}
function esc_html( string $text ): string { return htmlspecialchars( $text, ENT_QUOTES ); }
function esc_attr( string $text ): string { return htmlspecialchars( $text, ENT_QUOTES ); }
function esc_url( string $url ): string { return $url; }
function esc_url_raw( string $url ): string { return $url; }
function esc_js( string $text ): string { return $text; }
function wp_parse_url( string $url, int $component = -1 ): mixed { return parse_url( $url, $component ); }
function admin_url( string $path = '' ): string { return 'https://example.com/wp-admin/' . $path; }
function wp_json_encode( mixed $data, int $flags = 0 ): string|false { return json_encode( $data, $flags ); }
function is_singular( mixed $post_types = '' ): bool { return $GLOBALS['_mock_is_singular'] ?? true; }
function is_home(): bool { return false; }
function is_front_page(): bool { return false; }
function is_page(): bool { return false; }
function get_queried_object_id(): int { return $GLOBALS['_mock_queried_object_id'] ?? 1; }
function get_post_types( array $args = [] ): array { return [ 'post', 'page' ]; }
function register_post_meta( ...$args ): bool { return true; }
function update_post_meta( ...$args ): mixed { return true; }
function wp_parse_args( mixed $args, mixed $defaults = [] ): array {
	return array_merge( (array) $defaults, (array) $args );
}
function get_permalink( mixed $post = 0 ): string { return 'https://example.com/test-post/'; }

function wp_unslash( mixed $value ): mixed {
	return is_array( $value )
		? array_map( 'wp_unslash', $value )
		: stripslashes( (string) $value );
}

function sanitize_text_field( mixed $str ): string {
	$str = strip_tags( (string) $str );
	return trim( preg_replace( '/[\r\n\t ]+/', ' ', $str ) );
}

function sanitize_textarea_field( mixed $str ): string {
	$str = strip_tags( (string) $str );
	$str = preg_replace( '/\r\n|\r/', "\n", $str );       // normalize line endings
	$str = preg_replace( '/[^\S\n]+/', ' ', $str );       // collapse horizontal whitespace
	return trim( $str );
}

function wp_strip_all_tags( mixed $text, bool $remove_breaks = false ): string {
	if ( ! is_string( $text ) ) { return ''; }
	$text = preg_replace( '@<(script|style)[^>]*?>.*?</\1>@si', '', $text );
	$text = strip_tags( $text );
	if ( $remove_breaks ) {
		$text = preg_replace( '/[\r\n\t ]+/', ' ', $text );
	}
	return trim( $text );
}

/**
 * Minimal parse_blocks stub — handles standard Gutenberg opening/closing block comments.
 * Returns top-level blocks only (no inner block recursion), mirroring WP behaviour for
 * the functions under test.
 */
function parse_blocks( string $content ): array {
	$blocks  = [];
	// Opening + closing: <!-- wp:name {"attr":1} --> ... <!-- /wp:name -->
	$pattern = '/<!--\s+wp:([\w\-\/]+)(?:\s+\{[^}]*\})?\s+-->(.*?)<!--\s+\/wp:\1\s+-->/s';
	preg_match_all( $pattern, $content, $matches, PREG_SET_ORDER );
	foreach ( $matches as $m ) {
		$name = strpos( $m[1], '/' ) === false ? 'core/' . $m[1] : $m[1];
		$blocks[] = [ 'blockName' => $name, 'innerHTML' => $m[2], 'innerBlocks' => [] ];
	}
	// Self-closing: <!-- wp:name /-->
	preg_match_all( '/<!--\s+wp:([\w\-\/]+)(?:\s+\{[^}]*\})?\s+\/-->/', $content, $m2, PREG_SET_ORDER );
	foreach ( $m2 as $m ) {
		$name = strpos( $m[1], '/' ) === false ? 'core/' . $m[1] : $m[1];
		$blocks[] = [ 'blockName' => $name, 'innerHTML' => '', 'innerBlocks' => [] ];
	}
	return $blocks;
}

function wp_send_json_success( mixed $data = null, int $status = 200 ): never {
	throw new \RuntimeException( 'wp_send_json_success: ' . json_encode( $data ) );
}
function wp_send_json_error( mixed $data = null, int $status = 400 ): never {
	throw new \RuntimeException( 'wp_send_json_error: ' . json_encode( $data ) );
}
function wp_remote_post( string $url, array $args = [] ): array|WP_Error {
	return new WP_Error( 'not_implemented', 'Remote HTTP not available in tests.' );
}
function wp_remote_retrieve_body( mixed $response ): string { return ''; }
function wp_remote_retrieve_response_code( mixed $response ): int { return 0; }

class WP_Error {
	private string $code;
	private string $message;
	public function __construct( string $code = '', string $message = '', mixed $data = '' ) {
		$this->code    = $code;
		$this->message = $message;
	}
	public function get_error_message( string $code = '' ): string { return $this->message; }
	public function get_error_code(): string { return $this->code; }
}

// ── Constants ──────────────────────────────────────────────────────────────

define( 'ABSPATH',              '/fake/abspath/' );
define( 'HOUR_IN_SECONDS',      3600 );
define( 'AEOPUGMILL_VERSION',    '1.0.1' );
define( 'AEOPUGMILL_PLUGIN_DIR', dirname( __DIR__ ) . '/' );
define( 'AEOPUGMILL_PLUGIN_URL', 'https://example.com/wp-content/plugins/aeo-pugmill/' );
define( 'AEOPUGMILL_MAX_AI_INPUT', 8000 );
define( 'AEOPUGMILL_TEST_KEY',   'AEOPUGMILL-TEST-AI-KEY' );

// ── Dependency stubs (loaded before plugin files) ─────────────────────────

function aeopugmill_encrypt( string $value ): string        { return $value; }
function aeopugmill_decrypt( string $value ): string        { return $value; }
function aeopugmill_get_encrypted_option( string $key, string $default = '' ): string {
	return get_option( $key, $default );
}
function aeopugmill_mode(): string         { return 'ai'; }
function aeopugmill_is_licensed(): bool    { return true; }
function aeopugmill_is_block_editor(): bool { return true; }
function aeopugmill_get_aeo( int $post_id ): array {
	return $GLOBALS['_mock_aeo'];
}
// aeopugmill_voice_clause is defined in ai-content.php (not loaded by these tests — not needed)
// aeopugmill_record_token_usage and aeopugmill_get_token_usage are defined in ai-client.php

// ── Load plugin files under test ─────────────────────────────────────────
// rate-limit.php defines aeopugmill_get_rate_limit + aeopugmill_check_rate_limit.
// ai-utils.php defines aeopugmill_decode_ai_json, aeopugmill_remap_passage_to_raw,
//   and aeopugmill_get_paragraph_block_texts (pure helpers, no AJAX registrations).
// on-page-seo.php defines aeopugmill_get_seo + aeopugmill_save_seo.

require_once AEOPUGMILL_PLUGIN_DIR . 'includes/rate-limit.php';
require_once AEOPUGMILL_PLUGIN_DIR . 'includes/ai-utils.php';
require_once AEOPUGMILL_PLUGIN_DIR . 'includes/on-page-seo.php';
require_once AEOPUGMILL_PLUGIN_DIR . 'includes/bot-analytics.php';

// ═════════════════════════════════════════════════════════════════════════
// TEST SUITE
// ═════════════════════════════════════════════════════════════════════════

// ── 1. Rate limit ─────────────────────────────────────────────────────────

section( '1. Configurable rate limit' );

$GLOBALS['_wp_options']['aeopugmill_ai_rate_limit'] = 50;
assert_equal( 'Returns 50 when option is 50',  50,  aeopugmill_get_rate_limit() );

$GLOBALS['_wp_options']['aeopugmill_ai_rate_limit'] = 100;
assert_equal( 'Returns 100 when option is 100', 100, aeopugmill_get_rate_limit() );

$GLOBALS['_wp_options']['aeopugmill_ai_rate_limit'] = 200;
assert_equal( 'Returns 200 when option is 200', 200, aeopugmill_get_rate_limit() );

$GLOBALS['_wp_options']['aeopugmill_ai_rate_limit'] = 999;
assert_equal( 'Returns 50 for invalid value 999', 50, aeopugmill_get_rate_limit() );

$GLOBALS['_wp_options']['aeopugmill_ai_rate_limit'] = 0;
assert_equal( 'Returns 50 when option is 0',   50,  aeopugmill_get_rate_limit() );

unset( $GLOBALS['_wp_options']['aeopugmill_ai_rate_limit'] );
assert_equal( 'Returns 50 when option not set', 50, aeopugmill_get_rate_limit() );

// Rate limit enforcement: use limit=50, pre-seed transient to boundary - 1.
section( '2. Rate limit enforcement' );

$GLOBALS['_wp_options']['aeopugmill_ai_rate_limit'] = 50;
$GLOBALS['_wp_transients'] = [];

// Pre-seed to one below the limit so we can test the exact boundary.
$GLOBALS['_wp_transients']['aeopugmill_rl_1'] = 49;

$r_ok = aeopugmill_check_rate_limit();  // call #50 — allowed (49 stored, 49 < 50)
assert_true( 'Call at limit-1 stored (49): allowed', $r_ok === true );

$r_blocked = aeopugmill_check_rate_limit();  // call #51 — counter is now 50, blocked
assert_true( 'Call at limit (50): blocked', is_wp_error( $r_blocked ) );
assert_contains( 'Error message mentions the limit value (50)', '50', $r_blocked->get_error_message() );

// Confirm counter key is user-scoped (key includes user ID)
assert_true( 'Transient key includes user ID (aeopugmill_rl_1)',
	array_key_exists( 'aeopugmill_rl_1', $GLOBALS['_wp_transients'] )
);

// Reset for subsequent tests
$GLOBALS['_wp_options']['aeopugmill_ai_rate_limit'] = 50;
$GLOBALS['_wp_transients'] = [];

// ── 3. Passage entity remapping ───────────────────────────────────────────

section( '3. aeopugmill_remap_passage_to_raw — verbatim match' );

$block = "It&#8217;s a great post about widgets.";
assert_equal( 'Verbatim match returns passage unchanged',
	$block,
	aeopugmill_remap_passage_to_raw( $block, $block )
);

assert_equal( 'Empty passage returns empty string',
	'',
	aeopugmill_remap_passage_to_raw( '', 'some content' )
);

assert_equal( 'Empty block text returns passage unchanged',
	'test passage',
	aeopugmill_remap_passage_to_raw( 'test passage', '' )
);

section( '3b. Variant A — straight apostrophe → HTML entity (&#8217;)' );

$block_entity = "It&#8217;s a great post about widgets.";
$ai_straight  = "It's a great post about widgets.";
assert_equal( 'AI straight apostrophe remapped to &#8217;',
	$block_entity,
	aeopugmill_remap_passage_to_raw( $ai_straight, $block_entity )
);

$block_entity2 = "Don&#8217;t miss the &#8220;launch event&#8221; next week.";
$ai_straight2  = "Don't miss the \"launch event\" next week.";
assert_equal( 'Double-quote and apostrophe both remapped to entities',
	$block_entity2,
	aeopugmill_remap_passage_to_raw( $ai_straight2, $block_entity2 )
);

section( '3c. Variant B — straight apostrophe → Unicode curly (\u2019)' );

$block_unicode = "It\u{2019}s a great post about widgets.";
$ai_straight3  = "It's a great post about widgets.";
assert_equal( 'AI straight apostrophe remapped to Unicode curly',
	$block_unicode,
	aeopugmill_remap_passage_to_raw( $ai_straight3, $block_unicode )
);

$block_unicode2 = "She said \u{201C}hello\u{201D} and left.";
$ai_straight4   = "She said \"hello\" and left.";
assert_equal( 'Double straight-quote remapped to Unicode curly double-quotes',
	$block_unicode2,
	aeopugmill_remap_passage_to_raw( $ai_straight4, $block_unicode2 )
);

section( '3d. No match — returns original passage' );

$passage_no_match = 'This text does not appear in the block at all.';
$block_other      = 'Completely different content here.';
assert_equal( 'Unmatched passage returned as-is',
	$passage_no_match,
	aeopugmill_remap_passage_to_raw( $passage_no_match, $block_other )
);

section( '3e. Fuzzy match — normalised whitespace' );

// AI normalises double-space to single; block preserves double-space.
$block_dbl_space = 'Pugmill AEO Toolkit  scores  posts.';  // double spaces in block
$ai_sngl_space   = 'Pugmill AEO Toolkit scores posts.';    // single spaces from AI
$result = aeopugmill_remap_passage_to_raw( $ai_sngl_space, $block_dbl_space );
// Fuzzy match should extract the original from the block (double-space version)
assert_contains( 'Fuzzy match extracts text with original spacing from block',
	'Pugmill AEO Toolkit', $result
);

// ── 4. Block text extraction ──────────────────────────────────────────────

section( '4. aeopugmill_get_paragraph_block_texts — basic extraction' );

$two_paragraphs = <<<'HTML'
<!-- wp:paragraph -->
<p>First paragraph text here.</p>
<!-- /wp:paragraph -->

<!-- wp:paragraph -->
<p>Second paragraph text here.</p>
<!-- /wp:paragraph -->
HTML;

$texts = aeopugmill_get_paragraph_block_texts( $two_paragraphs );
assert_equal( 'Two paragraphs → two block texts', 2, count( $texts ) );
assert_equal( 'First text matches first paragraph', 'First paragraph text here.', $texts[0] );
assert_equal( 'Second text matches second paragraph', 'Second paragraph text here.', $texts[1] );

section( '4b. Non-paragraph blocks excluded' );

$mixed = <<<'HTML'
<!-- wp:heading {"level":2} -->
<h2>Section Heading</h2>
<!-- /wp:heading -->

<!-- wp:paragraph -->
<p>Only this paragraph should be returned.</p>
<!-- /wp:paragraph -->

<!-- wp:list -->
<ul><li>List item</li></ul>
<!-- /wp:list -->
HTML;

$texts_mixed = aeopugmill_get_paragraph_block_texts( $mixed );
assert_equal( 'Mixed blocks → only paragraph returned', 1, count( $texts_mixed ) );
assert_equal( 'Returned text is from paragraph block', 'Only this paragraph should be returned.', $texts_mixed[0] );

section( '4c. Inline HTML stripped from paragraph content' );

$bold_para = <<<'HTML'
<!-- wp:paragraph -->
<p>This is <strong>bold</strong> and <em>italic</em> text.</p>
<!-- /wp:paragraph -->
HTML;

$texts_bold = aeopugmill_get_paragraph_block_texts( $bold_para );
assert_equal( 'Inline tags stripped from paragraph text', 'This is bold and italic text.', $texts_bold[0] );

section( '4d. Empty / classic-editor content' );

$classic = 'This is plain content with no Gutenberg block markers.';
$texts_classic = aeopugmill_get_paragraph_block_texts( $classic );
assert_equal( 'Classic editor content → empty array (no swap targets)', [], $texts_classic );

$empty = '';
$texts_empty = aeopugmill_get_paragraph_block_texts( $empty );
assert_equal( 'Empty string → empty array', [], $texts_empty );

section( '4e. Empty paragraph block skipped' );

$empty_para = <<<'HTML'
<!-- wp:paragraph /-->

<!-- wp:paragraph -->
<p>Real content.</p>
<!-- /wp:paragraph -->
HTML;

$texts_ep = aeopugmill_get_paragraph_block_texts( $empty_para );
assert_equal( 'Empty self-closing paragraph skipped, real content kept', 1, count( $texts_ep ) );
assert_equal( 'Kept paragraph has correct text', 'Real content.', $texts_ep[0] );

section( '4f. Entities preserved through extraction' );

$entity_para = <<<'HTML'
<!-- wp:paragraph -->
<p>It&#8217;s an entity-encoded apostrophe.</p>
<!-- /wp:paragraph -->
HTML;

$texts_entity = aeopugmill_get_paragraph_block_texts( $entity_para );
assert_contains( 'HTML entity &#8217; preserved in block text',
	'&#8217;', $texts_entity[0]
);

// ── 5. End-to-end passage round-trip ─────────────────────────────────────

section( '5. End-to-end: block text extraction → passage remapping' );

$post_content = <<<'HTML'
<!-- wp:paragraph -->
<p>Pugmill AEO Toolkit is a WordPress plugin designed to score posts on both SEO and AEO health.</p>
<!-- /wp:paragraph -->

<!-- wp:heading {"level":2} -->
<h2>Answer Engine Optimization</h2>
<!-- /wp:heading -->

<!-- wp:paragraph -->
<p>Answer Engine Optimisation (AEO) is the practice of structuring web content so that AI-powered answer engines &#8212; such as ChatGPT, Perplexity, and Google&#8217;s AI Overviews &#8212; can extract, cite, and surface it as a direct answer.</p>
<!-- /wp:paragraph -->
HTML;

$para_texts = aeopugmill_get_paragraph_block_texts( $post_content );
assert_equal( 'Post has two paragraph blocks', 2, count( $para_texts ) );

// Simulate AI returning decoded apostrophes
$ai_passage = "Answer Engine Optimisation (AEO) is the practice of structuring web content so that AI-powered answer engines - such as ChatGPT, Perplexity, and Google's AI Overviews - can extract, cite, and surface it as a direct answer.";

$matched = false;
foreach ( $para_texts as $block_text ) {
	$remapped = aeopugmill_remap_passage_to_raw( $ai_passage, $block_text );
	if ( str_contains( $block_text, $remapped ) ) {
		$matched = true;
		// Verify the remapped passage contains entity-encoded characters
		assert_contains( 'Remapped passage contains &#8217; entity', '&#8217;', $remapped );
		assert_contains( 'Remapped passage contains &#8212; entity', '&#8212;', $remapped );
		break;
	}
}
assert_true( 'AI passage successfully matched to a paragraph block', $matched );

// Simulate first paragraph — no special chars, should match verbatim
$ai_p1    = 'Pugmill AEO Toolkit is a WordPress plugin designed to score posts on both SEO and AEO health.';
$matched2 = false;
foreach ( $para_texts as $block_text ) {
	$remapped2 = aeopugmill_remap_passage_to_raw( $ai_p1, $block_text );
	if ( str_contains( $block_text, $remapped2 ) ) {
		$matched2 = true;
		assert_equal( 'Plain ASCII passage returned verbatim', $ai_p1, $remapped2 );
		break;
	}
}
assert_true( 'Plain ASCII passage matched verbatim', $matched2 );

// Simulate a multi-paragraph passage (should NOT match any single block)
$ai_multi = 'Pugmill AEO Toolkit is a WordPress plugin designed to score posts on both SEO and AEO health. Answer Engine Optimisation (AEO) is the practice of structuring web content';
$matched3 = false;
foreach ( $para_texts as $block_text ) {
	$remapped3 = aeopugmill_remap_passage_to_raw( $ai_multi, $block_text );
	if ( str_contains( $block_text, $remapped3 ) ) {
		$matched3 = true;
		break;
	}
}
assert_false( 'Multi-paragraph passage NOT matched to any single block', $matched3 );

// ── 6. aeopugmill_decode_ai_json ───────────────────────────────────────────

section( '6. aeopugmill_decode_ai_json — single-key object is unwrapped' );

// {"suggestions":[...]} has exactly 1 key → unwrapped to the inner array
$raw_single_key = '{"suggestions":[{"quote":"some text","keyword":"foo","suggestion":"better text"}]}';
$decoded_sk = aeopugmill_decode_ai_json( $raw_single_key, 'anthropic' );
assert_true( 'Single-key object returns array', is_array( $decoded_sk ) );
assert_true( 'Inner array is indexed (element 0 exists)', isset( $decoded_sk[0] ) );
assert_equal( 'Item "quote" accessible via [0]', 'some text', $decoded_sk[0]['quote'] );

section( '6b. Already-indexed array not re-unwrapped' );

// An already-indexed array has $decoded[0] set → unwrap condition is false, returned as-is
$raw_indexed = '[{"quote":"x","keyword":"k","suggestion":"s"}]';
$decoded_idx = aeopugmill_decode_ai_json( $raw_indexed, 'openai' );
assert_true( 'Indexed array returned as-is', is_array( $decoded_idx ) );
assert_equal( 'Element 0 preserved', 'x', $decoded_idx[0]['quote'] );

section( '6c. Multi-key object not unwrapped' );

// Two or more top-level keys → returned as-is (suggestions key stays accessible)
$raw_multi = '{"suggestions":[{"quote":"q1"}],"total":1}';
$decoded_multi = aeopugmill_decode_ai_json( $raw_multi, 'anthropic' );
assert_true( 'Multi-key object returned as-is', is_array( $decoded_multi ) );
assert_true( '"suggestions" key still present', isset( $decoded_multi['suggestions'] ) );
assert_true( '"total" key still present', isset( $decoded_multi['total'] ) );

section( '6d. Invalid JSON returns WP_Error' );

$decoded_bad = aeopugmill_decode_ai_json( 'not json at all', 'anthropic' );
assert_true( 'Invalid JSON → WP_Error', is_wp_error( $decoded_bad ) );
assert_contains( 'Error message mentions format', 'unexpected', $decoded_bad->get_error_message() );

section( '6e. Single-key object with scalar value — not unwrapped (suggest_schema fix)' );

// {"type":""} is what suggest_schema gets for a general article — 1 key, string value.
// The old code unwrapped it to "" (a scalar) then returned WP_Error.
// The fix ensures only single-key objects whose value IS an array get unwrapped.
$raw_type_empty = '{"type":""}';
$decoded_te = aeopugmill_decode_ai_json( $raw_type_empty, 'anthropic' );
assert_true( '{"type":""} → returned as array (not WP_Error)', is_array( $decoded_te ) );
assert_true( '"type" key present', array_key_exists( 'type', $decoded_te ) );
assert_equal( 'type value is empty string', '', $decoded_te['type'] );

$raw_type_set = '{"type":"HowTo"}';
$decoded_ts = aeopugmill_decode_ai_json( $raw_type_set, 'anthropic' );
assert_true( '{"type":"HowTo"} → returned as array', is_array( $decoded_ts ) );
assert_equal( 'type value is HowTo', 'HowTo', $decoded_ts['type'] );

// ── 7. aeopugmill_get_seo / aeopugmill_save_seo — on-page SEO store ──────

section( '7. aeopugmill_get_seo — defaults when no meta stored' );

$seo_defaults = aeopugmill_get_seo( 1 );
assert_equal( 'title defaults to empty string',     '', $seo_defaults['title'] );
assert_equal( 'meta_desc defaults to empty string',  '', $seo_defaults['meta_desc'] );
assert_equal( 'canonical defaults to empty string',  '', $seo_defaults['canonical'] );
assert_equal( 'noindex defaults to false',           false, $seo_defaults['noindex'] );
assert_equal( 'nofollow defaults to false',          false, $seo_defaults['nofollow'] );
assert_equal( 'og_title defaults to empty string',   '', $seo_defaults['og_title'] );
assert_equal( 'og_desc defaults to empty string',    '', $seo_defaults['og_desc'] );
assert_equal( 'og_image defaults to empty string',   '', $seo_defaults['og_image'] );

section( '7b. aeopugmill_get_seo — parses stored JSON' );

$GLOBALS['_mock_seo_raw'] = json_encode( [ 'title' => 'My SEO Title', 'noindex' => true ] );
$seo_parsed = aeopugmill_get_seo( 1 );
assert_equal( 'title parsed from stored JSON',       'My SEO Title', $seo_parsed['title'] );
assert_equal( 'noindex parsed as true',              true,           $seo_parsed['noindex'] );
assert_equal( 'meta_desc filled with default',       '',             $seo_parsed['meta_desc'] );

// Reset
$GLOBALS['_mock_seo_raw'] = '';

section( '7c. aeopugmill_get_seo — invalid JSON returns defaults' );

$GLOBALS['_mock_seo_raw'] = '{broken-json';
$seo_broken = aeopugmill_get_seo( 1 );
assert_equal( 'invalid JSON returns default title',  '', $seo_broken['title'] );
assert_equal( 'invalid JSON returns default noindex', false, $seo_broken['noindex'] );

// Reset
$GLOBALS['_mock_seo_raw'] = '';

// ── 8. Bot analytics — v4 schema & pure helpers ───────────────────────────

section( '8. AEOPUGMILL_BOT_DB_VERSION is v4' );

assert_equal( 'BOT_DB_VERSION constant is "4"', '4', AEOPUGMILL_BOT_DB_VERSION );

section( '8a. aeopugmill_normalize_bot_name — canonical names pass through' );

assert_equal( 'Known bot "ChatGPT" returned verbatim',    'ChatGPT',    aeopugmill_normalize_bot_name( 'ChatGPT' ) );
assert_equal( 'Known bot "Googlebot" returned verbatim',  'Googlebot',  aeopugmill_normalize_bot_name( 'Googlebot' ) );
assert_equal( 'Known bot "AhrefsBot" returned verbatim',  'AhrefsBot',  aeopugmill_normalize_bot_name( 'AhrefsBot' ) );

section( '8b. aeopugmill_normalize_bot_name — unknown names collapsed to lowercase' );

assert_equal( 'Unknown "MysteryBot" lowercased',          'mysterybot', aeopugmill_normalize_bot_name( 'MysteryBot' ) );
assert_equal( 'Unknown "CrawlerZ" and "crawlerz" collide on same key',
	aeopugmill_normalize_bot_name( 'CrawlerZ' ),
	aeopugmill_normalize_bot_name( 'crawlerz' )
);
assert_equal( 'Whitespace trimmed before lowercasing',    'ahrefs.com', aeopugmill_normalize_bot_name( '  ahrefs.com  ' ) );

section( '8c. aeopugmill_normalize_bot_name — empty and control-char handling' );

assert_equal( 'Empty string → "unknown"',                 'unknown', aeopugmill_normalize_bot_name( '' ) );
assert_equal( 'Whitespace-only → "unknown"',              'unknown', aeopugmill_normalize_bot_name( "   \t  " ) );
assert_equal( 'Control chars stripped from unknown',      'bot',     aeopugmill_normalize_bot_name( "bot\x00\x01" ) );

section( '8d. aeopugmill_normalize_bot_name — clamp to 64 chars' );

$long_name    = str_repeat( 'a', 200 );
$normalized   = aeopugmill_normalize_bot_name( $long_name );
assert_true( '200-char unknown name clamped to ≤ 64 chars',
	strlen( $normalized ) <= 64
);
assert_equal( 'Clamped name is exactly 64 chars',          64, strlen( $normalized ) );

section( '8e. aeopugmill_detect_ai_bot — canonical UA fingerprints' );

assert_equal( 'GPTBot UA → "ChatGPT"',
	'ChatGPT',
	aeopugmill_detect_ai_bot( 'Mozilla/5.0 AppleWebKit/537.36 (compatible; GPTBot/1.0; +https://openai.com/gptbot)' )
);
assert_equal( 'ClaudeBot UA → "Claude"',
	'Claude',
	aeopugmill_detect_ai_bot( 'Mozilla/5.0 (compatible; ClaudeBot/1.0; +claudebot@anthropic.com)' )
);
assert_equal( 'Google-Extended beats Googlebot ordering',
	'Gemini',
	aeopugmill_detect_ai_bot( 'Mozilla/5.0 (compatible; Google-Extended; +http://www.google.com/bot.html)' )
);
assert_equal( 'Googlebot UA → "Googlebot"',
	'Googlebot',
	aeopugmill_detect_ai_bot( 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)' )
);
assert_equal( 'AhrefsBot UA → "AhrefsBot"',
	'AhrefsBot',
	aeopugmill_detect_ai_bot( 'Mozilla/5.0 (compatible; AhrefsBot/7.0; +http://ahrefs.com/robot/)' )
);
assert_equal( 'Perplexity-User UA → "Perplexity"',
	'Perplexity',
	aeopugmill_detect_ai_bot( 'Mozilla/5.0 (compatible; Perplexity-User/1.0; +https://perplexity.ai)' )
);

section( '8f. aeopugmill_detect_ai_bot — non-matches return false' );

assert_equal( 'Empty UA → false',           false, aeopugmill_detect_ai_bot( '' ) );
assert_equal( 'Chrome UA → false',          false, aeopugmill_detect_ai_bot( 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36' ) );
assert_equal( 'Unknown crawler → false',    false, aeopugmill_detect_ai_bot( 'Mozilla/5.0 (compatible; MysteryBot/1.0)' ) );

section( '8g. aeopugmill_detect_unknown_bot — heuristic catches unfingerprinted crawlers' );

assert_equal( 'curl/8.4 → "curl"',
	'curl',
	aeopugmill_detect_unknown_bot( 'curl/8.4.0' )
);
assert_equal( 'python-requests → "python-requests"',
	'python-requests',
	aeopugmill_detect_unknown_bot( 'python-requests/2.31.0' )
);
assert_equal( 'Mozilla-wrapped bot without embedded URL → inner token',
	'MysteryBot',
	aeopugmill_detect_unknown_bot( 'Mozilla/5.0 (compatible; MysteryBot/1.0)' )
);
assert_equal( 'UA with embedded URL prefers domain',
	'example.com',
	aeopugmill_detect_unknown_bot( 'SomeCrawler/2.0 (+https://example.com/about)' )
);

section( '8h. aeopugmill_detect_unknown_bot — browsers rejected' );

assert_equal( 'Chrome desktop → false',
	false,
	aeopugmill_detect_unknown_bot( 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36' )
);
assert_equal( 'Firefox → false',
	false,
	aeopugmill_detect_unknown_bot( 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:121.0) Gecko/20100101 Firefox/121.0' )
);
assert_equal( 'Empty UA → false',
	false,
	aeopugmill_detect_unknown_bot( '' )
);

section( '8i. aeopugmill_parse_bot_name_from_ua — leading token fallback' );

assert_equal( 'Leading token extracted',
	'curl',
	aeopugmill_parse_bot_name_from_ua( 'curl/8.4.0' )
);
assert_equal( 'Mozilla parenthetical → inner token',
	'XBot',
	aeopugmill_parse_bot_name_from_ua( 'Mozilla/5.0 (compatible; XBot/1.0)' )
);
assert_equal( 'Embedded URL → domain',
	'ahrefs.com',
	aeopugmill_parse_bot_name_from_ua( 'AhrefsBot/7.0 (+https://ahrefs.com/robot/)' )
);

section( '8j. aeopugmill_detect_resource_type — URI classification' );

// detect_resource_type reads $_SERVER and $_GET directly; populate those.
function _rt( string $uri, array $get = [] ): int {
	$_SERVER['REQUEST_URI'] = $uri;
	$_GET = $get;
	return aeopugmill_detect_resource_type();
}
function home_url( string $path = '' ): string { return 'https://example.com' . $path; }

assert_equal( 'Plain post URL → 0 (HTML)',                 0,  _rt( '/hello-world/' ) );
assert_equal( '/llms.txt → 1',                             1,  _rt( '/llms.txt' ) );
assert_equal( '/llms-full.txt → 2 (checked before llms.txt)', 2, _rt( '/llms-full.txt' ) );
assert_equal( '?aeopugmill_llm=1 on post → 3 (post markdown)',
	3,
	_rt( '/hello-world/?aeopugmill_llm=1', [ 'aeopugmill_llm' => '1' ] )
);
assert_equal( '?aeopugmill_llm=1 on home → 4 (site summary)',
	4,
	_rt( '/?aeopugmill_llm=1', [ 'aeopugmill_llm' => '1' ] )
);
assert_equal( '/sitemap.xml → 5',                          5,  _rt( '/sitemap.xml' ) );
assert_equal( '/wp-sitemap-posts-post-1.xml → 5',          5,  _rt( '/wp-sitemap-posts-post-1.xml' ) );
assert_equal( '/robots.txt → 6',                           6,  _rt( '/robots.txt' ) );
assert_equal( '/aeo/foo.jsonld → 8',                       8,  _rt( '/aeo/foo.jsonld' ) );
assert_equal( '/feed/ → 9',                                9,  _rt( '/feed/' ) );
assert_equal( '/tag/foo/feed/ → 9',                        9,  _rt( '/tag/foo/feed/' ) );
assert_equal( '/.well-known/security.txt → 10',            10, _rt( '/.well-known/security.txt' ) );
assert_equal( '/ads.txt → 10',                             10, _rt( '/ads.txt' ) );
assert_equal( '/security.txt → 10',                        10, _rt( '/security.txt' ) );

// Reset to avoid polluting later tests.
$_SERVER['REQUEST_URI'] = '';
$_GET = [];

// ── Summary ───────────────────────────────────────────────────────────────

echo "\n" . str_repeat( '─', 52 ) . "\n";
$color = $_tests_passed === $_tests_run ? "\033[32m" : "\033[31m";
echo "{$color}{$_tests_passed} / {$_tests_run} tests passed\033[0m\n";

if ( ! empty( $_failures ) ) {
	echo "\033[31mFailed:\033[0m\n";
	foreach ( $_failures as $f ) {
		echo "  - {$f}\n";
	}
	exit( 1 );
}

exit( 0 );
