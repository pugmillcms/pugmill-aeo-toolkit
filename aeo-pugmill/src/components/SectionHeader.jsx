/**
 * AEO Pugmill — Section header divider component.
 *
 * Renders a thin purple-tinted band used to separate SEO / AEO / Content /
 * Distribute / Audit sections inside the main panel.
 *
 * @package WPPugmill
 */

/**
 * @param {{ label: string }} props
 */
export function SectionHeader( { label } ) {
	return (
		<div style={ {
			background: '#f5f0ff',
			margin:     '14px -16px 0',
			padding:    '5px 16px',
		} }>
			<span style={ {
				fontSize:       '9px',
				fontWeight:     '700',
				color:          '#a78bfa',
				textTransform:  'uppercase',
				letterSpacing:  '0.1em',
			} }>
				{ label }
			</span>
		</div>
	);
}
