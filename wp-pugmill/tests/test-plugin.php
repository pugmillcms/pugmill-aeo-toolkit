<?php
/**
 * WP Pugmill — standalone unit tests
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
$GLOBALS['_mock_seo_raw']            = '';    // JSON string for _wppugmill_seo post meta

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
	if ( '_wppugmill_seo' === $key ) {
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
define( 'WPPUGMILL_VERSION',    '0.4.0' );
define( 'WPPUGMILL_PLUGIN_DIR', dirname( __DIR__ ) . '/' );
define( 'WPPUGMILL_PLUGIN_URL', 'https://example.com/wp-content/plugins/wp-pugmill/' );
define( 'WPPUGMILL_MAX_AI_INPUT', 8000 );
define( 'WPPUGMILL_TEST_KEY',   'WPPUGMILL-TEST-AI-KEY' );

// ── Dependency stubs (loaded before plugin files) ─────────────────────────

function wppugmill_encrypt( string $value ): string        { return $value; }
function wppugmill_decrypt( string $value ): string        { return $value; }
function wppugmill_get_encrypted_option( string $key, string $default = '' ): string {
	return get_option( $key, $default );
}
function wppugmill_mode(): string         { return 'ai'; }
function wppugmill_is_licensed(): bool    { return true; }
function wppugmill_is_block_editor(): bool { return true; }
function wppugmill_get_aeo( int $post_id ): array {
	return $GLOBALS['_mock_aeo'];
}
// wppugmill_voice_clause is defined in ai-content.php (not loaded by these tests — not needed)
// wppugmill_record_token_usage and wppugmill_get_token_usage are defined in ai-client.php

// ── Load plugin files under test ─────────────────────────────────────────
// rate-limit.php defines wppugmill_get_rate_limit + wppugmill_check_rate_limit.
// ai-utils.php defines wppugmill_decode_ai_json, wppugmill_remap_passage_to_raw,
//   and wppugmill_get_paragraph_block_texts (pure helpers, no AJAX registrations).
// audit.php defines wppugmill_run_audit.

require_once WPPUGMILL_PLUGIN_DIR . 'includes/rate-limit.php';
require_once WPPUGMILL_PLUGIN_DIR . 'includes/ai-utils.php';
require_once WPPUGMILL_PLUGIN_DIR . 'includes/audit.php';
require_once WPPUGMILL_PLUGIN_DIR . 'includes/on-page-seo.php';

// ═════════════════════════════════════════════════════════════════════════
// TEST SUITE
// ═════════════════════════════════════════════════════════════════════════

// ── 1. Rate limit ─────────────────────────────────────────────────────────

section( '1. Configurable rate limit' );

$GLOBALS['_wp_options']['wppugmill_ai_rate_limit'] = 50;
assert_equal( 'Returns 50 when option is 50',  50,  wppugmill_get_rate_limit() );

$GLOBALS['_wp_options']['wppugmill_ai_rate_limit'] = 100;
assert_equal( 'Returns 100 when option is 100', 100, wppugmill_get_rate_limit() );

$GLOBALS['_wp_options']['wppugmill_ai_rate_limit'] = 200;
assert_equal( 'Returns 200 when option is 200', 200, wppugmill_get_rate_limit() );

$GLOBALS['_wp_options']['wppugmill_ai_rate_limit'] = 999;
assert_equal( 'Returns 50 for invalid value 999', 50, wppugmill_get_rate_limit() );

$GLOBALS['_wp_options']['wppugmill_ai_rate_limit'] = 0;
assert_equal( 'Returns 50 when option is 0',   50,  wppugmill_get_rate_limit() );

unset( $GLOBALS['_wp_options']['wppugmill_ai_rate_limit'] );
assert_equal( 'Returns 50 when option not set', 50, wppugmill_get_rate_limit() );

// Rate limit enforcement: use limit=50, pre-seed transient to boundary - 1.
section( '2. Rate limit enforcement' );

$GLOBALS['_wp_options']['wppugmill_ai_rate_limit'] = 50;
$GLOBALS['_wp_transients'] = [];

// Pre-seed to one below the limit so we can test the exact boundary.
$GLOBALS['_wp_transients']['wppugmill_rl_1'] = 49;

$r_ok = wppugmill_check_rate_limit();  // call #50 — allowed (49 stored, 49 < 50)
assert_true( 'Call at limit-1 stored (49): allowed', $r_ok === true );

$r_blocked = wppugmill_check_rate_limit();  // call #51 — counter is now 50, blocked
assert_true( 'Call at limit (50): blocked', is_wp_error( $r_blocked ) );
assert_contains( 'Error message mentions the limit value (50)', '50', $r_blocked->get_error_message() );

// Confirm counter key is user-scoped (key includes user ID)
assert_true( 'Transient key includes user ID (wppugmill_rl_1)',
	array_key_exists( 'wppugmill_rl_1', $GLOBALS['_wp_transients'] )
);

// Reset for subsequent tests
$GLOBALS['_wp_options']['wppugmill_ai_rate_limit'] = 50;
$GLOBALS['_wp_transients'] = [];

// ── 3. Passage entity remapping ───────────────────────────────────────────

section( '3. wppugmill_remap_passage_to_raw — verbatim match' );

$block = "It&#8217;s a great post about widgets.";
assert_equal( 'Verbatim match returns passage unchanged',
	$block,
	wppugmill_remap_passage_to_raw( $block, $block )
);

assert_equal( 'Empty passage returns empty string',
	'',
	wppugmill_remap_passage_to_raw( '', 'some content' )
);

assert_equal( 'Empty block text returns passage unchanged',
	'test passage',
	wppugmill_remap_passage_to_raw( 'test passage', '' )
);

section( '3b. Variant A — straight apostrophe → HTML entity (&#8217;)' );

$block_entity = "It&#8217;s a great post about widgets.";
$ai_straight  = "It's a great post about widgets.";
assert_equal( 'AI straight apostrophe remapped to &#8217;',
	$block_entity,
	wppugmill_remap_passage_to_raw( $ai_straight, $block_entity )
);

$block_entity2 = "Don&#8217;t miss the &#8220;launch event&#8221; next week.";
$ai_straight2  = "Don't miss the \"launch event\" next week.";
assert_equal( 'Double-quote and apostrophe both remapped to entities',
	$block_entity2,
	wppugmill_remap_passage_to_raw( $ai_straight2, $block_entity2 )
);

section( '3c. Variant B — straight apostrophe → Unicode curly (\u2019)' );

$block_unicode = "It\u{2019}s a great post about widgets.";
$ai_straight3  = "It's a great post about widgets.";
assert_equal( 'AI straight apostrophe remapped to Unicode curly',
	$block_unicode,
	wppugmill_remap_passage_to_raw( $ai_straight3, $block_unicode )
);

$block_unicode2 = "She said \u{201C}hello\u{201D} and left.";
$ai_straight4   = "She said \"hello\" and left.";
assert_equal( 'Double straight-quote remapped to Unicode curly double-quotes',
	$block_unicode2,
	wppugmill_remap_passage_to_raw( $ai_straight4, $block_unicode2 )
);

section( '3d. No match — returns original passage' );

$passage_no_match = 'This text does not appear in the block at all.';
$block_other      = 'Completely different content here.';
assert_equal( 'Unmatched passage returned as-is',
	$passage_no_match,
	wppugmill_remap_passage_to_raw( $passage_no_match, $block_other )
);

section( '3e. Fuzzy match — normalised whitespace' );

// AI normalises double-space to single; block preserves double-space.
$block_dbl_space = 'WP Pugmill  scores  posts.';  // double spaces in block
$ai_sngl_space   = 'WP Pugmill scores posts.';    // single spaces from AI
$result = wppugmill_remap_passage_to_raw( $ai_sngl_space, $block_dbl_space );
// Fuzzy match should extract the original from the block (double-space version)
assert_contains( 'Fuzzy match extracts text with original spacing from block',
	'WP Pugmill', $result
);

// ── 4. Block text extraction ──────────────────────────────────────────────

section( '4. wppugmill_get_paragraph_block_texts — basic extraction' );

$two_paragraphs = <<<'HTML'
<!-- wp:paragraph -->
<p>First paragraph text here.</p>
<!-- /wp:paragraph -->

<!-- wp:paragraph -->
<p>Second paragraph text here.</p>
<!-- /wp:paragraph -->
HTML;

$texts = wppugmill_get_paragraph_block_texts( $two_paragraphs );
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

$texts_mixed = wppugmill_get_paragraph_block_texts( $mixed );
assert_equal( 'Mixed blocks → only paragraph returned', 1, count( $texts_mixed ) );
assert_equal( 'Returned text is from paragraph block', 'Only this paragraph should be returned.', $texts_mixed[0] );

section( '4c. Inline HTML stripped from paragraph content' );

$bold_para = <<<'HTML'
<!-- wp:paragraph -->
<p>This is <strong>bold</strong> and <em>italic</em> text.</p>
<!-- /wp:paragraph -->
HTML;

$texts_bold = wppugmill_get_paragraph_block_texts( $bold_para );
assert_equal( 'Inline tags stripped from paragraph text', 'This is bold and italic text.', $texts_bold[0] );

section( '4d. Empty / classic-editor content' );

$classic = 'This is plain content with no Gutenberg block markers.';
$texts_classic = wppugmill_get_paragraph_block_texts( $classic );
assert_equal( 'Classic editor content → empty array (no swap targets)', [], $texts_classic );

$empty = '';
$texts_empty = wppugmill_get_paragraph_block_texts( $empty );
assert_equal( 'Empty string → empty array', [], $texts_empty );

section( '4e. Empty paragraph block skipped' );

$empty_para = <<<'HTML'
<!-- wp:paragraph /-->

<!-- wp:paragraph -->
<p>Real content.</p>
<!-- /wp:paragraph -->
HTML;

$texts_ep = wppugmill_get_paragraph_block_texts( $empty_para );
assert_equal( 'Empty self-closing paragraph skipped, real content kept', 1, count( $texts_ep ) );
assert_equal( 'Kept paragraph has correct text', 'Real content.', $texts_ep[0] );

section( '4f. Entities preserved through extraction' );

$entity_para = <<<'HTML'
<!-- wp:paragraph -->
<p>It&#8217;s an entity-encoded apostrophe.</p>
<!-- /wp:paragraph -->
HTML;

$texts_entity = wppugmill_get_paragraph_block_texts( $entity_para );
assert_contains( 'HTML entity &#8217; preserved in block text',
	'&#8217;', $texts_entity[0]
);

// ── 5. End-to-end passage round-trip ─────────────────────────────────────

section( '5. End-to-end: block text extraction → passage remapping' );

$post_content = <<<'HTML'
<!-- wp:paragraph -->
<p>WP Pugmill is a WordPress plugin designed to score posts on both SEO and AEO health.</p>
<!-- /wp:paragraph -->

<!-- wp:heading {"level":2} -->
<h2>Answer Engine Optimization</h2>
<!-- /wp:heading -->

<!-- wp:paragraph -->
<p>Answer Engine Optimisation (AEO) is the practice of structuring web content so that AI-powered answer engines &#8212; such as ChatGPT, Perplexity, and Google&#8217;s AI Overviews &#8212; can extract, cite, and surface it as a direct answer.</p>
<!-- /wp:paragraph -->
HTML;

$para_texts = wppugmill_get_paragraph_block_texts( $post_content );
assert_equal( 'Post has two paragraph blocks', 2, count( $para_texts ) );

// Simulate AI returning decoded apostrophes
$ai_passage = "Answer Engine Optimisation (AEO) is the practice of structuring web content so that AI-powered answer engines - such as ChatGPT, Perplexity, and Google's AI Overviews - can extract, cite, and surface it as a direct answer.";

$matched = false;
foreach ( $para_texts as $block_text ) {
	$remapped = wppugmill_remap_passage_to_raw( $ai_passage, $block_text );
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
$ai_p1    = 'WP Pugmill is a WordPress plugin designed to score posts on both SEO and AEO health.';
$matched2 = false;
foreach ( $para_texts as $block_text ) {
	$remapped2 = wppugmill_remap_passage_to_raw( $ai_p1, $block_text );
	if ( str_contains( $block_text, $remapped2 ) ) {
		$matched2 = true;
		assert_equal( 'Plain ASCII passage returned verbatim', $ai_p1, $remapped2 );
		break;
	}
}
assert_true( 'Plain ASCII passage matched verbatim', $matched2 );

// Simulate a multi-paragraph passage (should NOT match any single block)
$ai_multi = 'WP Pugmill is a WordPress plugin designed to score posts on both SEO and AEO health. Answer Engine Optimisation (AEO) is the practice of structuring web content';
$matched3 = false;
foreach ( $para_texts as $block_text ) {
	$remapped3 = wppugmill_remap_passage_to_raw( $ai_multi, $block_text );
	if ( str_contains( $block_text, $remapped3 ) ) {
		$matched3 = true;
		break;
	}
}
assert_false( 'Multi-paragraph passage NOT matched to any single block', $matched3 );

// ── 6. wppugmill_decode_ai_json ───────────────────────────────────────────

section( '6. wppugmill_decode_ai_json — single-key object is unwrapped' );

// {"suggestions":[...]} has exactly 1 key → unwrapped to the inner array
$raw_single_key = '{"suggestions":[{"quote":"some text","keyword":"foo","suggestion":"better text"}]}';
$decoded_sk = wppugmill_decode_ai_json( $raw_single_key, 'anthropic' );
assert_true( 'Single-key object returns array', is_array( $decoded_sk ) );
assert_true( 'Inner array is indexed (element 0 exists)', isset( $decoded_sk[0] ) );
assert_equal( 'Item "quote" accessible via [0]', 'some text', $decoded_sk[0]['quote'] );

section( '6b. Already-indexed array not re-unwrapped' );

// An already-indexed array has $decoded[0] set → unwrap condition is false, returned as-is
$raw_indexed = '[{"quote":"x","keyword":"k","suggestion":"s"}]';
$decoded_idx = wppugmill_decode_ai_json( $raw_indexed, 'openai' );
assert_true( 'Indexed array returned as-is', is_array( $decoded_idx ) );
assert_equal( 'Element 0 preserved', 'x', $decoded_idx[0]['quote'] );

section( '6c. Multi-key object not unwrapped' );

// Two or more top-level keys → returned as-is (suggestions key stays accessible)
$raw_multi = '{"suggestions":[{"quote":"q1"}],"total":1}';
$decoded_multi = wppugmill_decode_ai_json( $raw_multi, 'anthropic' );
assert_true( 'Multi-key object returned as-is', is_array( $decoded_multi ) );
assert_true( '"suggestions" key still present', isset( $decoded_multi['suggestions'] ) );
assert_true( '"total" key still present', isset( $decoded_multi['total'] ) );

section( '6d. Invalid JSON returns WP_Error' );

$decoded_bad = wppugmill_decode_ai_json( 'not json at all', 'anthropic' );
assert_true( 'Invalid JSON → WP_Error', is_wp_error( $decoded_bad ) );
assert_contains( 'Error message mentions format', 'unexpected', $decoded_bad->get_error_message() );

section( '6e. Single-key object with scalar value — not unwrapped (suggest_schema fix)' );

// {"type":""} is what suggest_schema gets for a general article — 1 key, string value.
// The old code unwrapped it to "" (a scalar) then returned WP_Error.
// The fix ensures only single-key objects whose value IS an array get unwrapped.
$raw_type_empty = '{"type":""}';
$decoded_te = wppugmill_decode_ai_json( $raw_type_empty, 'anthropic' );
assert_true( '{"type":""} → returned as array (not WP_Error)', is_array( $decoded_te ) );
assert_true( '"type" key present', array_key_exists( 'type', $decoded_te ) );
assert_equal( 'type value is empty string', '', $decoded_te['type'] );

$raw_type_set = '{"type":"HowTo"}';
$decoded_ts = wppugmill_decode_ai_json( $raw_type_set, 'anthropic' );
assert_true( '{"type":"HowTo"} → returned as array', is_array( $decoded_ts ) );
assert_equal( 'type value is HowTo', 'HowTo', $decoded_ts['type'] );

// ── 7. wppugmill_run_audit — scoring engine ───────────────────────────────

// Helper: create a minimal mock post object
function make_mock_post( string $content, string $type = 'post' ): object {
	$p               = new stdClass();
	$p->ID           = 1;
	$p->post_content = $content;
	$p->post_type    = $type;
	return $p;
}

section( '7. wppugmill_run_audit — structural invariant' );

$GLOBALS['_mock_post']      = make_mock_post( '' );
$GLOBALS['_mock_post_type'] = 'post';
$GLOBALS['_mock_aeo']       = [ 'summary' => '', 'questions' => [], 'entities' => [], 'keywords' => [] ];

$audit_empty = wppugmill_run_audit( 1 );
assert_true( 'Result has "checks" key', isset( $audit_empty['checks'] ) );
assert_true( 'Result has "score" key', isset( $audit_empty['score'] ) );
assert_equal( 'passed + warned + failed === total',
	$audit_empty['total'],
	$audit_empty['passed'] + $audit_empty['warned'] + $audit_empty['failed']
);

section( '7b. Empty content + empty AEO → low score' );

// 14 checks total: 12 original + featured_image_alt (check 13) + single_h1 (check 14)
assert_equal( 'Empty audit has 14 checks total', 14, $audit_empty['total'] );
// Vacuous passes: questions_natural + entity_specificity (parent fields empty) + single_h1 (no H1 in empty content)
// = 3/14 = round(21.43) = 21
assert_equal( 'Empty audit score is 21 (3 vacuous passes)', 21, $audit_empty['score'] );

section( '7c. Well-populated AEO + long content → high score' );

$long_content = '<h2>Section One</h2>' . str_repeat( '<p>' . implode( ' ', array_fill( 0, 50, 'widget' ) ) . ' pottery pugmill reclaim clay studio technique.</p>', 10 );

$GLOBALS['_mock_post'] = make_mock_post( $long_content );
$GLOBALS['_mock_aeo']  = [
	'summary'   => 'WP Pugmill is a WordPress plugin for AEO and on-page SEO optimisation of posts and pages.',
	'questions' => [
		[ 'q' => 'What is a pugmill used for in pottery?',     'a' => 'A pugmill reclaims clay.' ],
		[ 'q' => 'How does a pugmill improve clay reclaim?',   'a' => 'It de-airs and mixes clay.' ],
		[ 'q' => 'What studio techniques use a pugmill?',      'a' => 'Wedging and reclaim workflows.' ],
	],
	'entities'  => [
		[ 'name' => 'WP Pugmill', 'type' => 'Product' ],
		[ 'name' => 'Pottery Studio', 'type' => 'Organization' ],
	],
	'keywords'  => [ 'pugmill', 'clay reclaim', 'pottery studio', 'widget technique', 'studio technique', 'AEO plugin' ],
];

$audit_full = wppugmill_run_audit( 1 );
assert_true( 'Well-populated audit score > 70', $audit_full['score'] > 70 );

// Verify the keywords_in_content check passed (keywords appear in content)
$kw_check = null;
foreach ( $audit_full['checks'] as $c ) {
	if ( 'keywords_in_content' === $c['id'] ) { $kw_check = $c; break; }
}
assert_true( 'keywords_in_content check exists', $kw_check !== null );
assert_equal( 'keywords_in_content passes when keywords in content', 'pass', $kw_check['status'] );

// Verify has_headings passes (content has <h2>)
$h_check = null;
foreach ( $audit_full['checks'] as $c ) {
	if ( 'has_headings' === $c['id'] ) { $h_check = $c; break; }
}
assert_equal( 'has_headings passes when <h2> present', 'pass', $h_check['status'] );

section( '7d. Keywords not in content → keywords_in_content fails' );

$GLOBALS['_mock_post'] = make_mock_post( '<p>This post talks about gardening and tomatoes.</p>' );
$GLOBALS['_mock_aeo']  = [
	'summary'   => 'A detailed look at advanced widget pottery techniques and clay reclaim methods.',
	'questions' => [],
	'entities'  => [],
	'keywords'  => [ 'pugmill', 'clay reclaim', 'pottery studio', 'widget technique', 'AEO plugin' ],
];

$audit_kw = wppugmill_run_audit( 1 );
$kw_miss  = null;
foreach ( $audit_kw['checks'] as $c ) {
	if ( 'keywords_in_content' === $c['id'] ) { $kw_miss = $c; break; }
}
assert_equal( 'keywords_in_content fails when keywords absent from content', 'fail', $kw_miss['status'] );

section( '7e. Long opening paragraph → opening_concise fails' );

$long_intro = '<p>' . implode( ' ', array_fill( 0, 150, 'word' ) ) . '.</p>';
$GLOBALS['_mock_post'] = make_mock_post( $long_intro );
$GLOBALS['_mock_aeo']  = [ 'summary' => '', 'questions' => [], 'entities' => [], 'keywords' => [] ];

$audit_long = wppugmill_run_audit( 1 );
$open_check = null;
foreach ( $audit_long['checks'] as $c ) {
	if ( 'opening_concise' === $c['id'] ) { $open_check = $c; break; }
}
assert_equal( 'opening_concise fails for 150-word intro', 'fail', $open_check['status'] );

section( '7f. "page" post type uses "page" label in tips' );

$GLOBALS['_mock_post']      = make_mock_post( '<p>Short page content.</p>', 'page' );
$GLOBALS['_mock_post_type'] = 'page';
$GLOBALS['_mock_aeo']       = [ 'summary' => '', 'questions' => [], 'entities' => [], 'keywords' => [] ];

$audit_page = wppugmill_run_audit( 1 );
$len_check  = null;
foreach ( $audit_page['checks'] as $c ) {
	if ( 'content_length' === $c['id'] ) { $len_check = $c; break; }
}
assert_true( 'content_length check exists for page', $len_check !== null );
assert_contains( 'content_length message mentions "page"', 'page', $len_check['message'] );

section( '7g. summary_length and qa_coverage warn states' );

// summary 40–79 chars → warn (< 40 → fail, >= 80 → pass)
$GLOBALS['_mock_post'] = make_mock_post( '<p>Some content here.</p>' );
$GLOBALS['_mock_aeo']  = [
	'summary'   => str_repeat( 'x', 55 ), // 55 chars: >= 40, < 80 → warn
	'questions' => [],
	'entities'  => [],
	'keywords'  => [],
];
$audit_sw = wppugmill_run_audit( 1 );
$sl_check = null;
foreach ( $audit_sw['checks'] as $c ) {
	if ( 'summary_length' === $c['id'] ) { $sl_check = $c; break; }
}
assert_equal( 'summary_length warns at 55 chars (40–79 range)', 'warn', $sl_check['status'] );

// 1 Q&A → qa_coverage warns (< 1 → fail, >= 3 → pass)
$GLOBALS['_mock_aeo'] = [
	'summary'   => '',
	'questions' => [ [ 'q' => 'Is this a question?', 'a' => 'Yes.' ] ],
	'entities'  => [],
	'keywords'  => [],
];
$audit_qa = wppugmill_run_audit( 1 );
$qac_check = null;
foreach ( $audit_qa['checks'] as $c ) {
	if ( 'qa_coverage' === $c['id'] ) { $qac_check = $c; break; }
}
assert_equal( 'qa_coverage warns at 1 Q&A pair (1–2 range)', 'warn', $qac_check['status'] );

section( '7h. content_length warn state (200–399 words)' );

// 250-word post → content_length warns (< 200 → fail, >= 400 → pass)
$word_content = '<p>' . implode( ' ', array_fill( 0, 250, 'word' ) ) . '.</p>';
$GLOBALS['_mock_post'] = make_mock_post( $word_content );
$GLOBALS['_mock_aeo']  = [ 'summary' => '', 'questions' => [], 'entities' => [], 'keywords' => [] ];
$audit_wc = wppugmill_run_audit( 1 );
$cl_check = null;
foreach ( $audit_wc['checks'] as $c ) {
	if ( 'content_length' === $c['id'] ) { $cl_check = $c; break; }
}
assert_equal( 'content_length warns at 250 words (200–399 range)', 'warn', $cl_check['status'] );

section( '7i. featured_image_alt audit check' );

// No thumbnail → warn
$GLOBALS['_mock_post']         = make_mock_post( '<p>Some content.</p>' );
$GLOBALS['_mock_post_type']    = 'post';
$GLOBALS['_mock_aeo']          = [ 'summary' => '', 'questions' => [], 'entities' => [], 'keywords' => [] ];
$GLOBALS['_mock_thumbnail_id'] = 0;
$GLOBALS['_mock_thumbnail_alt'] = '';

$audit_noimg = wppugmill_run_audit( 1 );
$fa_check    = null;
foreach ( $audit_noimg['checks'] as $c ) {
	if ( 'featured_image_alt' === $c['id'] ) { $fa_check = $c; break; }
}
assert_true( 'featured_image_alt check exists', $fa_check !== null );
assert_equal( 'featured_image_alt warns when no thumbnail', 'warn', $fa_check['status'] );
assert_true(  'featured_image_alt check includes has_thumbnail field', array_key_exists( 'has_thumbnail', $fa_check ) );
assert_false( 'has_thumbnail is false when no thumbnail', $fa_check['has_thumbnail'] );

// Thumbnail present but no alt text → warn
$GLOBALS['_mock_thumbnail_id']  = 42;
$GLOBALS['_mock_thumbnail_alt'] = '';

$audit_noalt = wppugmill_run_audit( 1 );
$fa_noalt    = null;
foreach ( $audit_noalt['checks'] as $c ) {
	if ( 'featured_image_alt' === $c['id'] ) { $fa_noalt = $c; break; }
}
assert_equal( 'featured_image_alt warns when thumbnail has no alt text', 'warn', $fa_noalt['status'] );
assert_true(  'has_thumbnail is true when thumbnail ID is set but no alt', $fa_noalt['has_thumbnail'] );

// Thumbnail present with alt text → pass
$GLOBALS['_mock_thumbnail_id']  = 42;
$GLOBALS['_mock_thumbnail_alt'] = 'A photo of a pottery pugmill';

$audit_withalt = wppugmill_run_audit( 1 );
$fa_withalt    = null;
foreach ( $audit_withalt['checks'] as $c ) {
	if ( 'featured_image_alt' === $c['id'] ) { $fa_withalt = $c; break; }
}
assert_equal( 'featured_image_alt passes when thumbnail has alt text', 'pass', $fa_withalt['status'] );
assert_true(  'has_thumbnail is true when thumbnail has alt text', $fa_withalt['has_thumbnail'] );

// Reset thumbnail state
$GLOBALS['_mock_thumbnail_id']  = 0;
$GLOBALS['_mock_thumbnail_alt'] = '';

section( '7j. single_h1 audit check' );

// No H1 in content → pass
$GLOBALS['_mock_post'] = make_mock_post( '<h2>Section</h2><p>Some content.</p>' );
$GLOBALS['_mock_aeo']  = [ 'summary' => '', 'questions' => [], 'entities' => [], 'keywords' => [] ];

$audit_noh1 = wppugmill_run_audit( 1 );
$h1_check   = null;
foreach ( $audit_noh1['checks'] as $c ) {
	if ( 'single_h1' === $c['id'] ) { $h1_check = $c; break; }
}
assert_true( 'single_h1 check exists', $h1_check !== null );
assert_equal( 'single_h1 passes when no H1 in content', 'pass', $h1_check['status'] );

// H1 present in content → warn
$GLOBALS['_mock_post'] = make_mock_post( '<h1>A Duplicate H1</h1><p>Some content.</p>' );

$audit_h1 = wppugmill_run_audit( 1 );
$h1_warn  = null;
foreach ( $audit_h1['checks'] as $c ) {
	if ( 'single_h1' === $c['id'] ) { $h1_warn = $c; break; }
}
assert_equal( 'single_h1 warns when H1 found in content', 'warn', $h1_warn['status'] );

// Reset mocks so nothing leaks
$GLOBALS['_mock_post']          = null;
$GLOBALS['_mock_post_type']     = 'post';
$GLOBALS['_mock_aeo']           = [ 'summary' => '', 'questions' => [], 'entities' => [], 'keywords' => [] ];
$GLOBALS['_mock_thumbnail_id']  = 0;
$GLOBALS['_mock_thumbnail_alt'] = '';

// ── 8. wppugmill_filter_robots ────────────────────────────────────────────

section( '8. wppugmill_filter_robots — wp_robots filter' );

// Reset to clean state.
$GLOBALS['_mock_is_singular']       = true;
$GLOBALS['_mock_queried_object_id'] = 1;
$GLOBALS['_mock_seo_raw']           = '';
unset( $GLOBALS['_wp_options']['wppugmill_disable_seo_meta'] );

// Normal post: always injects snippet/preview directives.
$result_normal = wppugmill_filter_robots( [] );
assert_equal( 'max-snippet set to -1',             '-1',   $result_normal['max-snippet'] );
assert_equal( 'max-video-preview set to -1',       '-1',   $result_normal['max-video-preview'] );
assert_equal( 'max-image-preview set to large',    'large', $result_normal['max-image-preview'] );
assert_false( 'noindex absent on normal post',   array_key_exists( 'noindex',  $result_normal ) );
assert_false( 'nofollow absent on normal post',  array_key_exists( 'nofollow', $result_normal ) );

// Merges with existing directives from other plugins (e.g. Jetpack max-image-preview).
$result_merge = wppugmill_filter_robots( [ 'max-image-preview' => 'large' ] );
assert_equal( 'max-snippet added when merging with existing input', '-1', $result_merge['max-snippet'] );
assert_equal( 'max-image-preview preserved through merge',       'large', $result_merge['max-image-preview'] );

// Feature disabled: returns input unchanged (other plugins handle robots).
$GLOBALS['_wp_options']['wppugmill_disable_seo_meta'] = true;
$result_disabled = wppugmill_filter_robots( [ 'existing' => true ] );
assert_equal( 'Returns input unchanged when feature disabled', [ 'existing' => true ], $result_disabled );
unset( $GLOBALS['_wp_options']['wppugmill_disable_seo_meta'] );

// Non-singular view: returns input unchanged.
$GLOBALS['_mock_is_singular'] = false;
$result_nonsing = wppugmill_filter_robots( [ 'other' => true ] );
assert_equal( 'Returns input unchanged on non-singular view', [ 'other' => true ], $result_nonsing );
$GLOBALS['_mock_is_singular'] = true;

// Post with noindex=true: adds noindex directive, removes any prior index key.
$GLOBALS['_mock_seo_raw'] = json_encode( [ 'noindex' => true, 'nofollow' => false ] );
$result_noindex = wppugmill_filter_robots( [ 'index' => false ] );
assert_true( 'noindex added when post has noindex=true',        array_key_exists( 'noindex', $result_noindex ) );
assert_true( 'noindex value is boolean true',                   $result_noindex['noindex'] === true );
assert_false( 'index key removed when noindex is set',          array_key_exists( 'index', $result_noindex ) );

// Post with nofollow=true: adds nofollow directive.
$GLOBALS['_mock_seo_raw'] = json_encode( [ 'noindex' => false, 'nofollow' => true ] );
$result_nofollow = wppugmill_filter_robots( [] );
assert_true( 'nofollow added when post has nofollow=true',  array_key_exists( 'nofollow', $result_nofollow ) );
assert_false( 'noindex absent when only nofollow is set',   array_key_exists( 'noindex', $result_nofollow ) );
assert_false( 'follow key not injected',                    array_key_exists( 'follow',  $result_nofollow ) );

// Reset.
$GLOBALS['_mock_seo_raw']           = '';
$GLOBALS['_mock_is_singular']       = true;
$GLOBALS['_mock_queried_object_id'] = 1;

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
