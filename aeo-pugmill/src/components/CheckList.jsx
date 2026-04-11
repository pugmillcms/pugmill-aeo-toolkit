/**
 * AEO Pugmill — Score checklist component.
 *
 * @package WPPugmill
 */

/**
 * Render a list of score items with pass/fail icons and tips.
 *
 * @param {{ items: Array, failingOnly?: boolean }} props
 */
export function CheckList( { items, failingOnly = false } ) {
	const visible = failingOnly ? items.filter( ( item ) => ! item.pass ) : items;

	if ( ! visible.length ) return null;

	return (
		<ul style={ { margin: '8px 0 0', padding: 0, listStyle: 'none' } }>
			{ visible.map( ( item, index ) => (
				<li
					key={ index }
					style={ {
						display:      'flex',
						alignItems:   'flex-start',
						gap:          '6px',
						marginBottom: '6px',
						fontSize:     '12px',
					} }
				>
					<span style={ {
						color:      item.pass ? '#46b450' : '#dc3232',
						flexShrink: 0,
						marginTop:  '1px',
					} }>
						{ item.pass ? '✓' : '✗' }
					</span>
					<span style={ { color: item.pass ? '#333' : '#666', flex: 1 } }>
						{ item.label }
						{ ! item.pass && (
							<span style={ {
								color:      '#999',
								fontSize:   '11px',
								display:    'block',
								marginTop:  '1px',
							} }>
								{ item.tip }
							</span>
						) }
					</span>
					<span style={ {
						marginLeft: 'auto',
						flexShrink: 0,
						color:      item.pass ? '#46b450' : '#bbb',
						fontSize:   '11px',
					} }>
						+{ item.points }
					</span>
				</li>
			) ) }
		</ul>
	);
}
