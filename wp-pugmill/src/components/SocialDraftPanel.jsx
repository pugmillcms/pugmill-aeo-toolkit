/**
 * WP Pugmill — Social Media Draft panel component.
 *
 * Platform picker, draft textarea with character counter, trim-to-fit,
 * and copy button.
 *
 * @package WPPugmill
 */

import { PanelBody, Notice } from '@wordpress/components';

import { SOCIAL_PLATFORMS } from '../constants';

/**
 * @param {{
 *   open:          boolean,
 *   onToggle:      () => void,
 *   state:         { loading: boolean, error: string, platform: string|null, draft: string },
 *   onStateChange: (updater: (prev: object) => object) => void,
 *   onGenerate:    (platform: string) => void,
 * }} props
 */
export function SocialDraftPanel( { open, onToggle, state, onStateChange, onGenerate, locked } ) {
	const platform = SOCIAL_PLATFORMS.find( ( p ) => p.key === state.platform );
	const len      = state.draft.length;
	const over     = platform && len > platform.limit;

	return (
		<PanelBody title="Social Media Draft" opened={ open } onToggle={ onToggle }>
			<p style={ { fontSize: '12px', color: '#555', margin: '0 0 10px' } }>
				Pick a platform — a draft is generated instantly using your AEO metadata.
			</p>
			<div style={ { display: 'flex', gap: '4px', marginBottom: '10px', flexWrap: 'wrap' } }>
				{ SOCIAL_PLATFORMS.map( ( { key, label } ) => (
					<button
						key={ key }
						type="button"
						disabled={ locked || state.loading }
						onClick={ locked ? undefined : () => onGenerate( key ) }
						style={ {
							fontSize:     '12px',
							padding:      '4px 12px',
							borderRadius: '9999px',
							cursor:       ( locked || state.loading ) ? 'not-allowed' : 'pointer',
							border:       '1px solid',
							borderColor:  state.platform === key ? '#7c3aed' : '#ccc',
							background:   state.platform === key ? '#7c3aed' : '#fff',
							color:        state.platform === key ? '#fff' : '#1e1e1e',
							fontWeight:   state.platform === key ? '600' : '400',
							opacity:      locked ? 0.4 : ( state.loading && state.platform !== key ? 0.5 : 1 ),
						} }
					>
						{ state.loading && state.platform === key ? '…' : label }
					</button>
				) ) }
			</div>
			{ state.loading && (
				<div style={ { height: '6px', borderRadius: '3px', overflow: 'hidden', marginBottom: '10px' } }>
					<div className="wppugmill-loading" style={ { height: '100%', borderRadius: '3px' } } />
				</div>
			) }
			{ state.error && (
				<Notice status="error" isDismissible={ false } style={ { marginBottom: '8px' } }>{ state.error }</Notice>
			) }
			{ state.draft && (
				<div>
					<textarea
						value={ state.draft }
						onChange={ ( e ) => onStateChange( ( prev ) => ( { ...prev, draft: e.target.value } ) ) }
						rows={ 5 }
						style={ {
							width:        '100%',
							fontSize:     '12px',
							padding:      '8px',
							boxSizing:    'border-box',
							borderRadius: '3px',
							border:       `1px solid ${ over ? '#dc3232' : '#ccc' }`,
							resize:       'vertical',
							fontFamily:   'inherit',
							lineHeight:   '1.5',
						} }
					/>
					<div style={ { display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginTop: '6px' } }>
						<span style={ { fontSize: '11px', color: over ? '#dc3232' : '#888', fontWeight: over ? '600' : '400' } }>
							{ len }{ platform ? ` / ${ platform.limit }` : '' } chars
						</span>
						<div style={ { display: 'flex', gap: '8px' } }>
							{ over && (
								<button
									type="button"
									onClick={ () => {
										const limit     = platform.limit;
										let trimmed     = state.draft.slice( 0, limit );
										const lastSpace = trimmed.lastIndexOf( ' ' );
										if ( lastSpace > 0 ) trimmed = trimmed.slice( 0, lastSpace );
										trimmed = trimmed.replace( /[,.\s;:]+$/, '' ) + '…';
										onStateChange( ( prev ) => ( { ...prev, draft: trimmed } ) );
									} }
									style={ { fontSize: '11px', color: '#dc3232', background: 'none', border: 'none', padding: 0, cursor: 'pointer', textDecoration: 'underline' } }
								>
									Trim to fit
								</button>
							) }
							<button
								type="button"
								onClick={ () => navigator.clipboard.writeText( state.draft ) }
								style={ { fontSize: '11px', color: '#007cba', background: 'none', border: 'none', padding: 0, cursor: 'pointer', textDecoration: 'underline' } }
							>
								Copy
							</button>
						</div>
					</div>
				</div>
			) }
		</PanelBody>
	);
}
