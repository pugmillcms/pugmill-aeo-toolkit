<?php
/**
 * Settings — register plugin options.
 *
 * Sensitive values (API key, license key) are encrypted at rest via
 * includes/encryption.php. The registered sanitize callbacks handle
 * encrypt-on-save. Retrieval always goes through wppugmill_get_encrypted_option().
 *
 * @package WPPugmill
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function wppugmill_register_settings() {
	// Site-level AEO (plain text, not sensitive)
	register_setting( 'wppugmill_settings', 'wppugmill_site_summary', array(
		'sanitize_callback' => 'sanitize_textarea_field',
	) );
	register_setting( 'wppugmill_settings', 'wppugmill_author_voice', array(
		'sanitize_callback' => 'sanitize_textarea_field',
	) );
	register_setting( 'wppugmill_settings', 'wppugmill_org_name', array(
		'sanitize_callback' => 'sanitize_text_field',
	) );
	register_setting( 'wppugmill_settings', 'wppugmill_org_type', array(
		'sanitize_callback' => 'sanitize_text_field',
	) );

	// AI provider selection (plain text, not sensitive)
	register_setting( 'wppugmill_settings', 'wppugmill_ai_provider', array(
		'sanitize_callback' => function( $value ) {
			$allowed = array( 'anthropic', 'openai', 'gemini' );
			return in_array( $value, $allowed, true ) ? $value : 'anthropic';
		},
	) );

	// AI API key — encrypted at rest
	register_setting( 'wppugmill_settings', 'wppugmill_ai_api_key', array(
		'sanitize_callback' => function( $value ) {
			$value = sanitize_text_field( $value );
			if ( empty( $value ) ) {
				return '';
			}
			// Validate minimum length
			if ( strlen( $value ) < 20 ) {
				add_settings_error( 'wppugmill_ai_api_key', 'invalid_key', __( 'API key appears too short. Please check and try again.', 'wp-pugmill' ) );
				return get_option( 'wppugmill_ai_api_key', '' ); // keep existing
			}
			// If the submitted value is a masked display value, don't re-encrypt
			if ( strpos( $value, '•' ) !== false ) {
				return get_option( 'wppugmill_ai_api_key', '' ); // keep existing encrypted value
			}
			return wppugmill_encrypt( $value );
		},
	) );

	// License key — encrypted at rest
	register_setting( 'wppugmill_settings', 'wppugmill_license_key', array(
		'sanitize_callback' => function( $value ) {
			$value = sanitize_text_field( $value );
			if ( empty( $value ) ) {
				return '';
			}
			if ( strpos( $value, '•' ) !== false ) {
				return get_option( 'wppugmill_license_key', '' );
			}
			// Basic format validation (20-100 chars)
			if ( strlen( $value ) < 20 || strlen( $value ) > 100 ) {
				add_settings_error( 'wppugmill_license_key', 'invalid_key', __( 'License key format is invalid. Please check your key.', 'wp-pugmill' ) );
				return get_option( 'wppugmill_license_key', '' );
			}
			return wppugmill_encrypt( $value );
		},
	) );

	// Feature disable flags — set from Plugin Compatibility section
	register_setting( 'wppugmill_settings', 'wppugmill_disable_json_ld', array(
		'sanitize_callback' => function( $value ) { return ! empty( $value ) ? 1 : 0; },
		'default'           => 0,
	) );
	register_setting( 'wppugmill_settings', 'wppugmill_disable_llms_txt', array(
		'sanitize_callback' => function( $value ) { return ! empty( $value ) ? 1 : 0; },
		'default'           => 0,
	) );
	register_setting( 'wppugmill_settings', 'wppugmill_disable_seo_meta', array(
		'sanitize_callback' => function( $value ) { return ! empty( $value ) ? 1 : 0; },
		'default'           => 0,
	) );

	// Robots.txt custom content
	register_setting( 'wppugmill_settings', 'wppugmill_robots_txt_custom', array(
		'sanitize_callback' => 'sanitize_textarea_field',
		'default'           => '',
	) );
}
add_action( 'admin_init', 'wppugmill_register_settings' );
