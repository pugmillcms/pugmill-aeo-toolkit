<?php
/**
 * Self-hosted plugin update checker.
 *
 * Hooks into WordPress's plugin update transient to check aeopugmill.com
 * for new versions. This file is included ONLY in the self-hosted distribution
 * (aeopugmill.com/aeo-pugmill.zip) and is removed from the wordpress.org
 * submission, where WP core handles updates natively.
 *
 * @package AEOPugmill
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Check aeopugmill.com for a newer plugin version.
 *
 * Fires on the `update_plugins` site transient filter. If the remote
 * version is newer than the installed version, WordPress will display
 * the update notice in the admin and allow one-click update.
 *
 * @param object $transient The update_plugins transient value.
 * @return object Modified transient with our plugin's update info (if available).
 */
function aeopugmill_check_for_updates( $transient ) {
	if ( empty( $transient->checked ) ) {
		return $transient;
	}

	$plugin_slug = 'aeo-pugmill/aeo-pugmill.php';
	$remote_url  = 'https://aeopugmill.com/api/plugin-version';

	$response = wp_remote_get( $remote_url, array(
		'timeout' => 10,
		'headers' => array( 'Accept' => 'application/json' ),
	) );

	if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) !== 200 ) {
		return $transient;
	}

	$body = json_decode( wp_remote_retrieve_body( $response ), true );

	if ( empty( $body['version'] ) || empty( $body['download_url'] ) ) {
		return $transient;
	}

	$installed_version = defined( 'AEOPUGMILL_VERSION' ) ? AEOPUGMILL_VERSION : '0.0.0';

	if ( version_compare( $body['version'], $installed_version, '>' ) ) {
		$transient->response[ $plugin_slug ] = (object) array(
			'slug'        => 'aeo-pugmill',
			'plugin'      => $plugin_slug,
			'new_version' => $body['version'],
			'url'         => 'https://aeopugmill.com/plugin',
			'package'     => $body['download_url'],
			'tested'      => $body['tested'] ?? '',
			'requires'    => $body['requires'] ?? '6.0',
			'requires_php'=> $body['requires_php'] ?? '7.4',
		);
	}

	return $transient;
}
add_filter( 'site_transient_update_plugins', 'aeopugmill_check_for_updates' );

/**
 * Inject plugin info into the "View details" popup.
 *
 * @param false|object|array $result The result object or array.
 * @param string             $action The API action being performed.
 * @param object             $args   Plugin API arguments.
 * @return false|object
 */
function aeopugmill_plugin_info( $result, $action, $args ) {
	if ( $action !== 'plugin_information' || ( $args->slug ?? '' ) !== 'aeo-pugmill' ) {
		return $result;
	}

	$response = wp_remote_get( 'https://aeopugmill.com/api/plugin-version', array(
		'timeout' => 10,
		'headers' => array( 'Accept' => 'application/json' ),
	) );

	if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) !== 200 ) {
		return $result;
	}

	$body = json_decode( wp_remote_retrieve_body( $response ), true );

	if ( empty( $body['version'] ) ) {
		return $result;
	}

	return (object) array(
		'name'          => 'AEO Pugmill',
		'slug'          => 'aeo-pugmill',
		'version'       => $body['version'],
		'author'        => '<a href="https://janzenworks.com">Janzen Works</a>',
		'homepage'      => 'https://aeopugmill.com/plugin',
		'download_link' => $body['download_url'] ?? '',
		'requires'      => $body['requires'] ?? '6.0',
		'requires_php'  => $body['requires_php'] ?? '7.4',
		'tested'        => $body['tested'] ?? '',
		'sections'      => array(
			'description' => 'The AEO plugin for WordPress. Structures your content for AI answer engines — FAQPage schema, entity graph, citations, bot analytics, and llms.txt.',
			'changelog'   => $body['changelog'] ?? 'See https://aeopugmill.com/plugin for the latest changes.',
		),
	);
}
add_filter( 'plugins_api', 'aeopugmill_plugin_info', 10, 3 );
