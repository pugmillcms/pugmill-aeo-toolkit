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
		'sanitize_callback' => function( $value ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing
			if ( ! isset( $_POST['wppugmill_site_summary'] ) ) {
				return get_option( 'wppugmill_site_summary', '' );
			}
			return sanitize_textarea_field( $value );
		},
	) );
	register_setting( 'wppugmill_settings', 'wppugmill_author_voice', array(
		'sanitize_callback' => function( $value ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing
			if ( ! isset( $_POST['wppugmill_author_voice'] ) ) {
				return get_option( 'wppugmill_author_voice', '' );
			}
			return sanitize_textarea_field( $value );
		},
	) );
	register_setting( 'wppugmill_settings', 'wppugmill_org_name', array(
		'sanitize_callback' => function( $value ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing
			if ( ! isset( $_POST['wppugmill_org_name'] ) ) {
				return get_option( 'wppugmill_org_name', '' );
			}
			return sanitize_text_field( $value );
		},
	) );
	register_setting( 'wppugmill_settings', 'wppugmill_org_type', array(
		'sanitize_callback' => function( $value ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing
			if ( ! isset( $_POST['wppugmill_org_type'] ) ) {
				return get_option( 'wppugmill_org_type', 'Organization' );
			}
			$value = sanitize_text_field( $value );
			// Prevent empty string — fall back to Organization so schema @type is always valid.
			return $value ?: 'Organization';
		},
	) );
	register_setting( 'wppugmill_settings', 'wppugmill_author_same_as', array(
		'sanitize_callback' => function( $value ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing
			if ( ! isset( $_POST['wppugmill_author_same_as'] ) ) {
				return get_option( 'wppugmill_author_same_as', '' );
			}
			return sanitize_textarea_field( $value );
		},
	) );

	// AI provider selection (plain text, not sensitive)
	register_setting( 'wppugmill_settings', 'wppugmill_ai_provider', array(
		'sanitize_callback' => function( $value ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing
			if ( ! isset( $_POST['wppugmill_ai_provider'] ) ) {
				return get_option( 'wppugmill_ai_provider', 'anthropic' );
			}
			$allowed = array( 'anthropic', 'openai', 'gemini' );
			return in_array( $value, $allowed, true ) ? $value : 'anthropic';
		},
	) );

	// Hourly AI call limit — user-selectable to help control API spend
	register_setting( 'wppugmill_settings', 'wppugmill_ai_rate_limit', array(
		'sanitize_callback' => function( $value ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing
			if ( ! isset( $_POST['wppugmill_ai_rate_limit'] ) ) {
				return get_option( 'wppugmill_ai_rate_limit', 50 );
			}
			$allowed = array( 50, 100, 200 );
			$value   = (int) $value;
			return in_array( $value, $allowed, true ) ? $value : 50;
		},
		'default' => 50,
	) );

	// AI API key — encrypted at rest
	register_setting( 'wppugmill_settings', 'wppugmill_ai_api_key', array(
		'sanitize_callback' => function( $value ) {
			$value = sanitize_text_field( $value );
			if ( empty( $value ) ) {
				// Field not submitted (different tab's form) — preserve existing value.
				// If the field was intentionally cleared, $_POST will have the key present but empty.
				if ( ! isset( $_POST['wppugmill_ai_api_key'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
					return get_option( 'wppugmill_ai_api_key', '' );
				}
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
				// Field not submitted (different tab's form) — preserve existing value.
				if ( ! isset( $_POST['wppugmill_license_key'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
					return get_option( 'wppugmill_license_key', '' );
				}
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

	// Feature disable flags — set from Plugin Compatibility section.
	// Each checkbox has a companion <input type="hidden" value="0"> in the form, so
	// the key IS present in $_POST when the compatibility tab submits (value = 0 when
	// unchecked, 1 when checked). When a different tab submits, the key is absent —
	// preserve the existing value rather than resetting to 0.
	register_setting( 'wppugmill_settings', 'wppugmill_disable_json_ld', array(
		'sanitize_callback' => function( $value ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing
			if ( ! isset( $_POST['wppugmill_disable_json_ld'] ) ) {
				return get_option( 'wppugmill_disable_json_ld', 0 );
			}
			return ! empty( $value ) ? 1 : 0;
		},
		'default' => 0,
	) );
	register_setting( 'wppugmill_settings', 'wppugmill_disable_llms_txt', array(
		'sanitize_callback' => function( $value ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing
			if ( ! isset( $_POST['wppugmill_disable_llms_txt'] ) ) {
				return get_option( 'wppugmill_disable_llms_txt', 0 );
			}
			return ! empty( $value ) ? 1 : 0;
		},
		'default' => 0,
	) );
	register_setting( 'wppugmill_settings', 'wppugmill_disable_seo_meta', array(
		'sanitize_callback' => function( $value ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing
			if ( ! isset( $_POST['wppugmill_disable_seo_meta'] ) ) {
				return get_option( 'wppugmill_disable_seo_meta', 0 );
			}
			return ! empty( $value ) ? 1 : 0;
		},
		'default' => 0,
	) );

	// Robots.txt custom content
	register_setting( 'wppugmill_settings', 'wppugmill_robots_txt_custom', array(
		'sanitize_callback' => function( $value ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing
			if ( ! isset( $_POST['wppugmill_robots_txt_custom'] ) ) {
				return get_option( 'wppugmill_robots_txt_custom', '' );
			}
			return sanitize_textarea_field( $value );
		},
		'default' => '',
	) );

	// Registered under its own group so submitting other settings forms
	// (AI Connector, SEO, etc.) cannot inadvertently reset the opt-in state.
	register_setting( 'wppugmill_analytics', 'wppugmill_analytics_opted_in', array(
		'sanitize_callback' => 'absint',
		'default'           => 0,
	) );
}
add_action( 'admin_init', 'wppugmill_register_settings' );
