/**
 * WP Pugmill — Score progress bar component.
 *
 * @package WPPugmill
 */

/**
 * Render a labelled progress bar showing the completeness score.
 *
 * @param {{ score: number, color: string, grade: string, size?: number }} props
 */
export function ScoreDisplay( { score, color, grade } ) {
	return (
		<div style={ { padding: '12px 0 8px' } }>
			<div style={ {
				display:     'flex',
				alignItems:  'baseline',
				gap:         '4px',
				marginBottom:'6px',
			} }>
				<span style={ { fontSize: '30px', fontWeight: '700', lineHeight: 1, color } }>
					{ score }
				</span>
				<span style={ { fontSize: '13px', color: '#bbb' } }>/100</span>
				<span style={ { fontSize: '12px', fontWeight: '600', color, marginLeft: 'auto' } }>
					{ grade }
				</span>
			</div>
			<div style={ {
				background:   '#e0e0e0',
				borderRadius: '3px',
				height:       '6px',
				overflow:     'hidden',
			} }>
				<div style={ {
					width:        `${ score }%`,
					height:       '100%',
					background:   color,
					borderRadius: '3px',
					transition:   'width 0.5s ease',
				} } />
			</div>
		</div>
	);
}
