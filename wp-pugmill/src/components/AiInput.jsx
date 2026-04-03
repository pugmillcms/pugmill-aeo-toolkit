/**
 * WP Pugmill — AI action pill component.
 *
 * Compact inline button used for individual AI generate actions throughout
 * the sidebar panels. Matches the Pugmill CMS AiBtn visual language.
 *
 * @package WPPugmill
 */

/**
 * @param {{ label: string, onClick: Function, isActive: boolean, anyPending: boolean }} props
 */
export function AiPill( { label, onClick, isActive, anyPending } ) {
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
	const active = { ...idle, background: '#7c3aed', color: '#fff', borderColor: 'transparent', cursor: 'wait' };
	const faded  = { ...idle, opacity: 0.4, cursor: 'default' };

	return (
		<button
			type="button"
			onClick={ onClick }
			disabled={ anyPending }
			style={ isActive ? active : anyPending ? faded : idle }
		>
			<svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.5" strokeLinecap="round" strokeLinejoin="round" style={ { flexShrink: 0 } }>
				<path d="M13 10V3L4 14h7v7l9-11h-7z" />
			</svg>
			{ isActive ? 'Working…' : label }
		</button>
	);
}
