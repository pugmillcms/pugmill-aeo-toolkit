/**
 * WP Pugmill — AEO Health panel component.
 *
 * Displays the live AEO score and a per-check audit list with inline
 * AI "Generate" fix buttons for failing checks.
 *
 * @package WPPugmill
 */

import { PanelBody } from '@wordpress/components';

import { ScoreDisplay }      from './ScoreDisplay';
import { AiPill }            from './AiInput';
import { computeScore }      from '../scoring';
import { getAuditFixActions } from '../constants';

/**
 * @param {{
 *   aeo:                 object,
 *   seo:                 object,
 *   draftContent:        string,
 *   featuredMediaAltText: string,
 *   healthFixStates:     object,
 *   onFix:               (checkId: string) => void,
 * }} props
 */
export function AeoHealthPanel( { aeo, seo, draftContent, featuredMediaAltText, healthFixStates, onFix } ) {
	const { score, grade, color, items } = computeScore(
		aeo,
		seo,
		{ postContent: draftContent, featuredImageAlt: featuredMediaAltText }
	);

	return (
		<PanelBody title="AEO Health" initialOpen={ true }>
			<ScoreDisplay score={ score } grade={ grade } color={ color } />
			<div style={ { marginTop: '6px' } }>
				{ items.map( ( item ) => {
					const fixState  = healthFixStates[ item.id ];
					const fixAction = getAuditFixActions()[ item.id ];
					return (
						<div key={ item.id } style={ {
							display:      'flex',
							alignItems:   'flex-start',
							gap:          '6px',
							padding:      '5px 0',
							borderBottom: '1px solid #f0f0f0',
						} }>
							<span style={ {
								fontSize:   '13px',
								color:      item.pass ? '#46b450' : '#999',
								flexShrink: 0,
								marginTop:  '1px',
							} }>
								{ item.pass ? '✓' : '○' }
							</span>
							<div style={ { flex: 1, minWidth: 0 } }>
								<span style={ {
									fontSize:   '12px',
									color:      item.pass ? '#1e1e1e' : '#555',
									fontWeight: '400',
									display:    'block',
									lineHeight: '1.4',
								} }>
									{ item.label }
									<span style={ { fontSize: '10px', color: '#bbb', marginLeft: '4px' } }>+{ item.points }</span>
								</span>
								{ ! item.pass && item.tip && (
									<span style={ { fontSize: '11px', color: '#999', display: 'block', lineHeight: '1.3', marginTop: '1px' } }>
										{ item.tip }
									</span>
								) }
								{ ! item.pass && item.id === 'has_headings' && (
									<span style={ { fontSize: '11px', color: '#999', display: 'block', marginTop: '2px' } }>
										Use the <strong>Audit</strong> panel for AI-suggested headings.
									</span>
								) }
								{ ! item.pass && fixAction && item.id !== 'has_headings' && (
									fixState === 'done' ? (
										<span style={ { fontSize: '11px', color: '#46b450', fontWeight: '600' } }>✓ Done</span>
									) : fixState && fixState !== 'loading' ? (
										<span style={ { fontSize: '11px', color: '#dc3232' } }>{ fixState }</span>
									) : (
										<div style={ { marginTop: '4px' } }>
											<AiPill
												label="Generate"
												isActive={ fixState === 'loading' }
												anyPending={ fixState === 'loading' }
												onClick={ () => onFix( item.id ) }
											/>
										</div>
									)
								) }
							</div>
						</div>
					);
				} ) }
			</div>
		</PanelBody>
	);
}
