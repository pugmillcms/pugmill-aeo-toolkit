<?php
/**
 * Settings — register plugin options.
 *
 * Sensitive values (API key, license key) are encrypted at rest via
 * includes/encryption.php. The registered sanitize callbacks handle
 * encrypt-on-save. Retrieval always goes through aeopugmill_get_encrypted_option().
 *
 * @package WPPugmill
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function aeopugmill_register_settings() {
	// Site-level AEO (plain text, not sensitive)
	register_setting( 'aeopugmill_settings', 'aeopugmill_site_summary', array(
		'sanitize_callback' => function( $value ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing
			if ( ! isset( $_POST['aeopugmill_site_summary'] ) ) {
				return get_option( 'aeopugmill_site_summary', '' );
			}
			return sanitize_textarea_field( $value );
		},
	) );
	register_setting( 'aeopugmill_settings', 'aeopugmill_author_voice', array(
		'sanitize_callback' => function( $value ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing
			if ( ! isset( $_POST['aeopugmill_author_voice'] ) ) {
				return get_option( 'aeopugmill_author_voice', '' );
			}
			return sanitize_textarea_field( $value );
		},
	) );
	register_setting( 'aeopugmill_settings', 'aeopugmill_org_name', array(
		'sanitize_callback' => function( $value ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing
			if ( ! isset( $_POST['aeopugmill_org_name'] ) ) {
				return get_option( 'aeopugmill_org_name', '' );
			}
			return sanitize_text_field( $value );
		},
	) );
	register_setting( 'aeopugmill_settings', 'aeopugmill_org_type', array(
		'sanitize_callback' => function( $value ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing
			if ( ! isset( $_POST['aeopugmill_org_type'] ) ) {
				return get_option( 'aeopugmill_org_type', 'Organization' );
			}
			$value = sanitize_text_field( $value );
			// Prevent empty string — fall back to Organization so schema @type is always valid.
			return $value ?: 'Organization';
		},
	) );
	register_setting( 'aeopugmill_settings', 'aeopugmill_author_same_as', array(
		'sanitize_callback' => function( $value ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing
			if ( ! isset( $_POST['aeopugmill_author_same_as'] ) ) {
				return get_option( 'aeopugmill_author_same_as', '' );
			}
			return sanitize_textarea_field( $value );
		},
	) );

	// AI provider selection (plain text, not sensitive)
	register_setting( 'aeopugmill_settings', 'aeopugmill_ai_provider', array(
		'sanitize_callback' => function( $value ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing
			if ( ! isset( $_POST['aeopugmill_ai_provider'] ) ) {
				return get_option( 'aeopugmill_ai_provider', '' );
			}
			$allowed = array( 'anthropic', 'openai', 'gemini' );
			// If empty ("— Select —") or invalid, keep existing rather than silently defaulting.
			if ( ! in_array( $value, $allowed, true ) ) {
				return get_option( 'aeopugmill_ai_provider', '' );
			}
			return $value;
		},
	) );

	// Hourly AI call limit — user-selectable to help control API spend
	register_setting( 'aeopugmill_settings', 'aeopugmill_ai_rate_limit', array(
		'sanitize_callback' => function( $value ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing
			if ( ! isset( $_POST['aeopugmill_ai_rate_limit'] ) ) {
				return get_option( 'aeopugmill_ai_rate_limit', 50 );
			}
			$allowed = array( 50, 100, 200 );
			$value   = (int) $value;
			return in_array( $value, $allowed, true ) ? $value : 50;
		},
		'default' => 50,
	) );

	// AI API key — encrypted at rest
	//
	// IMPORTANT — double-sanitize guard:
	// WordPress's update_option() calls sanitize_option() and then, when the
	// option does not yet exist (fresh install), falls through to add_option()
	// which calls sanitize_option() a *second* time. Without the static guard
	// below the key would be encrypted twice on first save, producing a value
	// that decrypts to ciphertext instead of plaintext — the root cause of the
	// "key looks saved but returns 401" bug on fresh installs.
	register_setting( 'aeopugmill_settings', 'aeopugmill_ai_api_key', array(
		'sanitize_callback' => function( $value ) {
			static $already_sanitized = false;
			if ( $already_sanitized ) {
				return $value; // prevent double-encryption on fresh installs
			}
			$already_sanitized = true;

			// If the hidden flag isn't set to '1' the user did not type a new key —
			// the field contains the masked display value. Keep the existing encrypted
			// value without touching it. This is deterministic and requires no
			// bullet-character detection or encoding assumptions.
			// phpcs:ignore WordPress.Security.NonceVerification.Missing
			$key_changed = isset( $_POST['aeopugmill_api_key_changed'] ) && '1' === $_POST['aeopugmill_api_key_changed'];
			if ( ! $key_changed ) {
				return get_option( 'aeopugmill_ai_api_key', '' );
			}

			$value = sanitize_text_field( $value );
			if ( empty( $value ) ) {
				// Intentionally cleared — wipe the stored key.
				return '';
			}
			// Validate minimum length.
			if ( strlen( $value ) < 20 ) {
				add_settings_error( 'aeopugmill_ai_api_key', 'invalid_key', __( 'API key appears too short. Please check and try again.', 'aeo-pugmill' ) );
				return get_option( 'aeopugmill_ai_api_key', '' ); // keep existing
			}
			return aeopugmill_encrypt( $value );
		},
	) );

	// License key — encrypted at rest (same double-sanitize guard as API key above)
	register_setting( 'aeopugmill_settings', 'aeopugmill_license_key', array(
		'sanitize_callback' => function( $value ) {
			static $already_sanitized = false;
			if ( $already_sanitized ) {
				return $value;
			}
			$already_sanitized = true;

			$value = sanitize_text_field( $value );
			if ( empty( $value ) ) {
				// Field not submitted (different tab's form) — preserve existing value.
				if ( ! isset( $_POST['aeopugmill_license_key'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
					return get_option( 'aeopugmill_license_key', '' );
				}
				return '';
			}
			if ( strpos( $value, '•' ) !== false ) {
				return get_option( 'aeopugmill_license_key', '' );
			}
			// Basic format validation (20-100 chars)
			if ( strlen( $value ) < 20 || strlen( $value ) > 100 ) {
				add_settings_error( 'aeopugmill_license_key', 'invalid_key', __( 'License key format is invalid. Please check your key.', 'aeo-pugmill' ) );
				return get_option( 'aeopugmill_license_key', '' );
			}
			return aeopugmill_encrypt( $value );
		},
	) );

	// Feature disable flags — set from Plugin Compatibility section.
	// Each checkbox has a companion <input type="hidden" value="0"> in the form, so
	// the key IS present in $_POST when the compatibility tab submits (value = 0 when
	// unchecked, 1 when checked). When a different tab submits, the key is absent —
	// preserve the existing value rather than resetting to 0.
	register_setting( 'aeopugmill_settings', 'aeopugmill_disable_json_ld', array(
		'sanitize_callback' => function( $value ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing
			if ( ! isset( $_POST['aeopugmill_disable_json_ld'] ) ) {
				return get_option( 'aeopugmill_disable_json_ld', 0 );
			}
			return ! empty( $value ) ? 1 : 0;
		},
		'default' => 0,
	) );
	register_setting( 'aeopugmill_settings', 'aeopugmill_disable_llms_txt', array(
		'sanitize_callback' => function( $value ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing
			if ( ! isset( $_POST['aeopugmill_disable_llms_txt'] ) ) {
				return get_option( 'aeopugmill_disable_llms_txt', 0 );
			}
			return ! empty( $value ) ? 1 : 0;
		},
		'default' => 0,
	) );
	register_setting( 'aeopugmill_settings', 'aeopugmill_disable_seo_meta', array(
		'sanitize_callback' => function( $value ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing
			if ( ! isset( $_POST['aeopugmill_disable_seo_meta'] ) ) {
				return get_option( 'aeopugmill_disable_seo_meta', 0 );
			}
			return ! empty( $value ) ? 1 : 0;
		},
		'default' => 0,
	) );
	register_setting( 'aeopugmill_settings', 'aeopugmill_disable_sitemap', array(
		'sanitize_callback' => function( $value ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing
			if ( ! isset( $_POST['aeopugmill_disable_sitemap'] ) ) {
				return get_option( 'aeopugmill_disable_sitemap', 0 );
			}
			return ! empty( $value ) ? 1 : 0;
		},
		'default' => 0,
	) );
	// Breadcrumb JSON-LD — suppress independently of the full JSON-LD kill switch.
	// Major SEO plugins (Yoast, RankMath) output their own BreadcrumbList.
	// Checking this prevents duplicate breadcrumb schema without suppressing
	// Pugmill's AEO-exclusive additions (FAQPage, mentions, citations).
	register_setting( 'aeopugmill_settings', 'aeopugmill_disable_breadcrumbs', array(
		'sanitize_callback' => function( $value ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing
			if ( ! isset( $_POST['aeopugmill_disable_breadcrumbs'] ) ) {
				return get_option( 'aeopugmill_disable_breadcrumbs', 0 );
			}
			return ! empty( $value ) ? 1 : 0;
		},
		'default' => 0,
	) );
	register_setting( 'aeopugmill_settings', 'aeopugmill_disable_robots_append', array(
		'sanitize_callback' => function( $value ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing
			if ( ! isset( $_POST['aeopugmill_disable_robots_append'] ) ) {
				return get_option( 'aeopugmill_disable_robots_append', 0 );
			}
			return ! empty( $value ) ? 1 : 0;
		},
		'default' => 0,
	) );

	// Robots.txt custom content
	register_setting( 'aeopugmill_settings', 'aeopugmill_robots_txt_custom', array(
		'sanitize_callback' => function( $value ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing
			if ( ! isset( $_POST['aeopugmill_robots_txt_custom'] ) ) {
				return get_option( 'aeopugmill_robots_txt_custom', '' );
			}
			return sanitize_textarea_field( $value );
		},
		'default' => '',
	) );

	// Registered under its own group so submitting other settings forms
	// (AI Connector, SEO, etc.) cannot inadvertently reset the opt-in state.
	register_setting( 'aeopugmill_analytics', 'aeopugmill_analytics_opted_in', array(
		'sanitize_callback' => 'aeopugmill_sanitize_analytics_opt_in',
		'default'           => 0,
	) );
}
add_action( 'admin_init', 'aeopugmill_register_settings' );

/**
 * Sanitize the analytics opt-in value and set a transient when first activated.
 *
 * @param  mixed $value Incoming value.
 * @return int
 */
function aeopugmill_sanitize_analytics_opt_in( $value ) {
	$new = absint( $value );
	$old = (int) get_option( 'aeopugmill_analytics_opted_in', 0 );
	if ( 1 === $new && 0 === $old ) {
		set_transient( 'aeopugmill_analytics_just_activated', 1, 5 * MINUTE_IN_SECONDS );
	}
	return $new;
}
