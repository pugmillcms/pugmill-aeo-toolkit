/**
 * WP Pugmill — AI usage meter component.
 *
 * Displays a colour-coded progress bar showing how many AI calls have been
 * made this hour against the configured rate-limit cap.
 *
 * @package WPPugmill
 */

/**
 * @param {{ usage: { count: number, limit: number } }} props
 */
export function UsageMeter( { usage } ) {
	const { count, limit } = usage;
	const pct        = Math.min( ( count / limit ) * 100, 100 );
	const meterColor = count >= 40 ? '#dc3232' : count >= 30 ? '#e65c00' : count >= 20 ? '#ffb900' : '#46b450';
	const label      = count >= limit
		? 'Limit reached — resets in under 1 hour'
		: `${ count } / ${ limit } AI calls this hour`;

	return (
		<div style={ { marginTop: '10px' } }>
			<div style={ { display: 'flex', justifyContent: 'space-between', alignItems: 'baseline', marginBottom: '4px' } }>
				<span style={ { fontSize: '11px', color: '#666' } }>AI usage</span>
				<span style={ { fontSize: '11px', fontWeight: '600', color: meterColor } }>{ label }</span>
			</div>
			<div style={ { background: '#e0e0e0', borderRadius: '3px', height: '5px', overflow: 'hidden' } }>
				<div style={ { width: `${ pct }%`, height: '100%', background: meterColor, borderRadius: '3px', transition: 'width 0.3s ease, background 0.3s ease' } } />
			</div>
		</div>
	);
}
