<?php
/**
 * WP Pugmill — encryption integration tests.
 *
 * Loads the real encryption.php (not stubs) and exercises the full
 * OpenSSL encrypt → store → retrieve → decrypt path. This is the
 * one path the main unit tests cannot cover because test-plugin.php
 * replaces all three encryption functions with pass-through stubs.
 *
 * Run from the plugin root:
 *   php tests/test-encryption.php
 *
 * Requires: PHP 8.0+, OpenSSL extension
 *
 * @package WPPugmill
 */

declare( strict_types=1 );

// ── Test runner (same helpers as test-plugin.php) ─────────────────────────

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

function assert_not_equal( string $label, mixed $unexpected, mixed $actual ): void {
	global $_tests_run, $_tests_passed, $_failures;
	$_tests_run++;
	if ( $unexpected !== $actual ) {
		$_tests_passed++;
		echo "  \033[32m✓\033[0m {$label}\n";
	} else {
		$_failures[] = $label;
		echo "  \033[31m✗\033[0m {$label}\n";
		echo "    Should NOT be: " . var_export( $unexpected, true ) . "\n";
	}
}

function section( string $name ): void {
	echo "\n\033[1;34m{$name}\033[0m\n";
}

// ── WordPress stubs (minimal — only what encryption.php touches) ──────────

$GLOBALS['_wp_options'] = [];

function get_option( string $key, mixed $default = false ): mixed {
	return $GLOBALS['_wp_options'][ $key ] ?? $default;
}
function update_option( string $key, mixed $value ): bool {
	$GLOBALS['_wp_options'][ $key ] = $value;
	return true;
}
function add_action( ...$args ): void {}
function esc_html__( string $text, string $domain = 'default' ): string { return $text; }

// ── Constants ─────────────────────────────────────────────────────────────

define( 'ABSPATH',          '/fake/abspath/' );
define( 'SECURE_AUTH_KEY',  'test-secure-auth-key-for-unit-tests-only-not-production' );
define( 'WPPUGMILL_PLUGIN_DIR', dirname( __DIR__ ) . '/' );

// ── Load real encryption code ─────────────────────────────────────────────

require_once WPPUGMILL_PLUGIN_DIR . 'includes/encryption.php';

// ── Precondition check ────────────────────────────────────────────────────

if ( ! function_exists( 'openssl_encrypt' ) ) {
	echo "\033[31mSKIPPED: OpenSSL extension not available on this PHP build.\033[0m\n";
	exit( 0 );
}

// ═════════════════════════════════════════════════════════════════════════
// TEST SUITE
// ═════════════════════════════════════════════════════════════════════════

// ── 1. Pure encrypt / decrypt round-trip ─────────────────────────────────

section( '1. Pure encrypt / decrypt round-trip' );

$key = 'sk-ant-api03-test-key-value';
$enc = wppugmill_encrypt( $key );

assert_true(  'encrypt() returns a non-empty string',       ! empty( $enc ) );
assert_not_equal( 'ciphertext differs from plaintext',      $key, $enc );
assert_equal( 'decrypt(encrypt(key)) recovers original',    $key, wppugmill_decrypt( $enc ) );

$short = 'abc';
assert_equal( 'short value round-trips correctly', $short, wppugmill_decrypt( wppugmill_encrypt( $short ) ) );

$long = str_repeat( 'sk-ant-longkey-', 20 );
assert_equal( 'long value round-trips correctly', $long, wppugmill_decrypt( wppugmill_encrypt( $long ) ) );

// ── 2. IV randomness — each encryption is unique ─────────────────────────

section( '2. IV randomness — two encryptions of the same value differ' );

$enc1 = wppugmill_encrypt( $key );
$enc2 = wppugmill_encrypt( $key );

assert_not_equal( 'two encryptions of the same value produce different ciphertext', $enc1, $enc2 );
assert_equal( 'both decrypt to the original value (enc1)', $key, wppugmill_decrypt( $enc1 ) );
assert_equal( 'both decrypt to the original value (enc2)', $key, wppugmill_decrypt( $enc2 ) );

// ── 3. Save / retrieve round-trip via option storage ─────────────────────

section( '3. save_encrypted_option → get_encrypted_option round-trip' );

$GLOBALS['_wp_options'] = [];  // clean slate

wppugmill_save_encrypted_option( 'wppugmill_ai_api_key', $key );

$stored = get_option( 'wppugmill_ai_api_key', '' );
assert_true(  'stored value is not the plaintext key',  $stored !== $key );
assert_true(  'stored value is a non-empty string',     ! empty( $stored ) );
assert_equal( 'get_encrypted_option recovers original', $key, wppugmill_get_encrypted_option( 'wppugmill_ai_api_key' ) );

// ── 4. Default returned for missing / empty option ────────────────────────

section( '4. Default returned when option is absent or empty' );

assert_equal( 'missing option returns default',
	'fallback-default',
	wppugmill_get_encrypted_option( 'wppugmill_nonexistent_option', 'fallback-default' )
);

wppugmill_save_encrypted_option( 'wppugmill_ai_api_key', '' );  // empty string
assert_equal( 'empty plaintext stored as empty, default returned on retrieve',
	'my-default',
	wppugmill_get_encrypted_option( 'wppugmill_ai_api_key', 'my-default' )
);

// ── 5. Legacy plaintext graceful handling ────────────────────────────────

section( '5. Legacy plaintext — short/invalid base64 passes through intact' );

// A legacy install may have stored a plaintext key before encryption was added.
// decrypt() should detect the non-base64 / too-short value and return it as-is.
$legacy_key = 'sk-ant-legacy-plain';
$GLOBALS['_wp_options']['wppugmill_ai_api_key'] = $legacy_key;

$retrieved = wppugmill_get_encrypted_option( 'wppugmill_ai_api_key', '' );
assert_equal( 'plaintext legacy value passes through decrypt unchanged', $legacy_key, $retrieved );

// ── 6. Ciphertext tamper — truncated ciphertext returns empty ─────────────

section( '6. Tampered ciphertext returns empty string' );

// A 16-byte payload is exactly at the boundary (IV only, no cipher body).
// decrypt() checks strlen($raw) < 17 and returns the ciphertext as-is
// (treating it as a legacy plaintext). Anything >= 17 that fails
// openssl_decrypt returns ''.
$truncated = base64_encode( str_repeat( "\x00", 16 ) );
$result    = wppugmill_decrypt( $truncated );
// 16 bytes decoded → strlen < 17 → treated as legacy plaintext
assert_equal( 'exactly-16-byte payload treated as legacy (not decrypted)', $truncated, $result );

$bad_cipher = base64_encode( str_repeat( "\x00", 32 ) ); // 16 IV + 16 garbage cipher
$result2    = wppugmill_decrypt( $bad_cipher );
assert_equal( 'garbage ciphertext returns empty string', '', $result2 );

// ── 7. wppugmill_mask_secret ──────────────────────────────────────────────

section( '7. wppugmill_mask_secret — display masking' );

assert_equal( 'empty value returns empty string',         '',             wppugmill_mask_secret( '' ) );
assert_equal( 'short value (≤8 chars) fully masked',      '••••••••',     wppugmill_mask_secret( 'abcdefgh' ) );
assert_equal( '13-char value: first 4 + 5 dots + last 4',  'sk-a•••••xyz0', wppugmill_mask_secret( 'sk-abcdefxyz0' ) );
// Real API key shape: "sk-ant-api03-abc123xyz456"
$api_key_sample = 'sk-ant-api03-abc123xyz456';
$masked         = wppugmill_mask_secret( $api_key_sample );
assert_true(  'masked starts with first 4 chars', str_starts_with( $masked, 'sk-a' ) );
assert_true(  'masked ends with last 4 chars',    str_ends_with( $masked, 'z456' ) );
assert_true(  'masked contains bullet dots',       str_contains( $masked, '•' ) );
assert_false( 'masked does not contain middle characters', str_contains( $masked, 'api03' ) );

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
