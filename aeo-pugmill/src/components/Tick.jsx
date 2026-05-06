/**
 * Pugmill AEO Toolkit — Tick checkmark component.
 *
 * Renders a green checkmark next to a panel title when the field is complete.
 *
 * @package WPPugmill
 */

/** Green checkmark rendered next to a panel title when the field is complete. */
export function Tick( { show } ) {
	if ( ! show ) return null;
	return <span style={ { color: '#46b450', fontWeight: '700', marginLeft: '3px' } }>✓</span>;
}
