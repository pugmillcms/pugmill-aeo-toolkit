<?php
/**
 * Pugmill AEO Toolkit — License compatibility shim.
 *
 * License validation has moved to the Pugmill AEO Toolkit Pro add-on. These stub
 * functions preserve backward compatibility for any code that calls them.
 * When the Pro add-on is active it defines AEOPUGMILL_PRO_ACTIVE and the
 * "licensed" state is inferred from that constant, not from a remote API call.
 *
 * @package WPPugmill
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Whether a valid Pro license is active on this site.
 *
 * @return bool
 */
function aeopugmill_is_licensed() {
	return defined( 'AEOPUGMILL_PRO_ACTIVE' ) && AEOPUGMILL_PRO_ACTIVE;
}

/**
 * Return a license status array compatible with existing callers.
 *
 * @return array{status: string, error: string, customer_email: string, expires_at: string}
 */
function aeopugmill_license_status() {
	if ( aeopugmill_is_licensed() ) {
		return array(
			'status'         => 'active',
			'error'          => '',
			'customer_email' => '',
			'expires_at'     => '',
		);
	}
	return array(
		'status'         => 'inactive',
		'error'          => '',
		'customer_email' => '',
		'expires_at'     => '',
	);
}
