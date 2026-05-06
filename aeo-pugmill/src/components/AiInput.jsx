/**
 * Pugmill AEO Toolkit — AI action pill component.
 *
 * Compact inline button used for individual AI generate actions throughout
 * the sidebar panels. Matches the Pugmill CMS AiBtn visual language.
 *
 * @package WPPugmill
 */

/**
 * @param {{ label: string, onClick: Function, isActive: boolean, anyPending: boolean, locked: boolean, pillLabel: string }} props
 */
export function AiPill( { label, onClick, isActive, anyPending, locked, pillLabel } ) {
	const idle = {
		display:      'inline-flex',
		alignItems:   'center',
		gap:          '4px',
		fontSize:     '11px',
		fontWeight:   '500',
		padding:      '2px 8px',
		borderRadius: '9999px',
		border:       '1px solid #d4c8f0',
		background:   '#f0eafa',
		color:        '#6d28d9',
		cursor:       'pointer',
		fontFamily:   'inherit',
		lineHeight:   '1.6',
		transition:   'background 0.15s, color 0.15s',
	};
	const active     = { ...idle, background: '#7c3aed', color: '#fff', borderColor: 'transparent', cursor: 'wait' };
	const faded      = { ...idle, opacity: 0.4, cursor: 'default' };
	const lockedStyle = { ...idle, opacity: 0.35, cursor: 'not-allowed' };

	const style = locked ? lockedStyle : isActive ? active : anyPending ? faded : idle;

	return (
		<button
			type="button"
			onClick={ locked ? undefined : onClick }
			disabled={ locked || anyPending }
			className={ isActive ? 'aeopugmill-loading' : '' }
			style={ style }
		>
			<svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.5" strokeLinecap="round" strokeLinejoin="round" style={ { flexShrink: 0 } }>
				<path d="M13 10V3L4 14h7v7l9-11h-7z" />
			</svg>
			{ isActive ? 'Working…' : label }
			{ locked && (
				<span style={ { fontSize: '8px', fontWeight: 700, letterSpacing: '.04em', textTransform: 'uppercase', background: '#f3e8ff', color: '#7c3aed', padding: '1px 5px', borderRadius: '3px', lineHeight: '1.4', opacity: 1 } }>{ pillLabel || 'AI' }</span>
			) }
		</button>
	);
}
