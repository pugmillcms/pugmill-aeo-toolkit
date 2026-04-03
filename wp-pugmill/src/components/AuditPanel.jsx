/**
 * WP Pugmill — AEO Audit panel component.
 *
 * Fetches results from the /wp-pugmill/v1/audit REST endpoint and renders
 * each check with an optional AI fix button. Special handling for
 * keywords_in_content (inline passage swap) and has_headings (insert block).
 *
 * @package WPPugmill
 */

import { PanelBody, Button } from '@wordpress/components';
import { useState, useCallback, useEffect, useRef } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';

import { useAeoMeta } from '../hooks';
import { saveIfDirty } from '../utils';
import {
	AUDIT_STATUS_ICONS,
	AUDIT_FIX_ACTIONS,
	BUTTON_STYLE,
	ajaxUrl,
	imageAltNonce,
} from '../constants';

/**
 * Build a regex that matches text in raw block HTML while tolerating
 * inline tags (bold, italic, links) between words.
 */
function buildTagTolerantRegex( text ) {
	const escapeRe = ( s ) => s.replace( /[.*+?^${}()|[\]\\]/g, '\\$&' );
	const parts    = text.trim().split( /\s+/ );
	return new RegExp( parts.map( escapeRe ).join( '(?:<[^>]*>)*\\s+(?:<[^>]*>)*' ) );
}


export function AuditPanel() {
	const { postId, aeo, updateAeo } = useAeoMeta();

	const [ panelOpen,  setPanelOpen  ] = useState( false );
	const [ results,    setResults    ] = useState( null  );
	const [ loading,    setLoading    ] = useState( false );
	const [ error,      setError      ] = useState( ''    );
	const [ fixStates,  setFixStates  ] = useState( {}    );

	// Keyword fix results
	const [ kwResults, setKwResults ] = useState( null );
	const [ kwError,   setKwError   ] = useState( ''   );

	// Heading suggestion results
	const [ hdgResults, setHdgResults ] = useState( null );
	const [ hdgError,   setHdgError   ] = useState( ''   );

	// Featured image alt text generation
	const [ altGenState, setAltGenState ] = useState( '' ); // '' | 'loading' | generated alt text
	const [ altGenError, setAltGenError ] = useState( '' );

	// Per-item applied tracking for inline suggestion panels
	const [ kwApplied,  setKwApplied  ] = useState( {} );
	const [ hdgApplied, setHdgApplied ] = useState( {} );

	const lastRunRef = useRef( 0 );

	const runAudit = useCallback( async ( force = false ) => {
		if ( ! postId ) return;
		if ( ! force && results && ( Date.now() - lastRunRef.current ) < 60000 ) return;
		setLoading( true );
		setError( '' );
		setResults( null );
		setFixStates( {} );
		setKwResults( null );
		setKwError( '' );
		setKwApplied( {} );
		setHdgResults( null );
		setHdgError( '' );
		setHdgApplied( {} );
		try {
			await saveIfDirty();
			const data = await apiFetch( { path: `/wp-pugmill/v1/audit/${ postId }` } );
			setResults( data );
			lastRunRef.current = Date.now();
		} catch ( err ) {
			setError( err.message || 'Audit failed.' );
		} finally {
			setLoading( false );
		}
	}, [ postId, results ] );

	// Audit runs when the user opens the panel, not on mount.

	const handleFix = useCallback( async ( checkId ) => {
		const action = AUDIT_FIX_ACTIONS[ checkId ];
		if ( ! action ) return;

		setFixStates( ( prev ) => ( { ...prev, [ checkId ]: 'loading' } ) );

		if ( checkId === 'keywords_in_content' ) {
			setKwResults( null );
			setKwError( '' );
			try {
				const res  = await fetch( ajaxUrl, {
					method:      'POST',
					credentials: 'same-origin',
					headers:     { 'Content-Type': 'application/x-www-form-urlencoded' },
					body:        new URLSearchParams( { action: action.ajaxAction, nonce: action.actionNonce, post_id: postId } ),
				} );
				const data = await res.json();
				if ( ! data.success ) throw new Error( data.data?.message || 'Fix failed.' );
				setKwResults( data.data.suggestions || [] );
				setFixStates( ( prev ) => ( { ...prev, [ checkId ]: null } ) );
			} catch ( err ) {
				setKwError( err.message || 'Fix failed.' );
				setFixStates( ( prev ) => ( { ...prev, [ checkId ]: null } ) );
			}
			return;
		}

		if ( checkId === 'has_headings' ) {
			setHdgResults( null );
			setHdgError( '' );
			try {
				const res  = await fetch( ajaxUrl, {
					method:      'POST',
					credentials: 'same-origin',
					headers:     { 'Content-Type': 'application/x-www-form-urlencoded' },
					body:        new URLSearchParams( { action: action.ajaxAction, nonce: action.actionNonce, post_id: postId } ),
				} );
				const data = await res.json();
				if ( ! data.success ) throw new Error( data.data?.message || 'Fix failed.' );
				setHdgResults( data.data.headings || [] );
				setFixStates( ( prev ) => ( { ...prev, [ checkId ]: null } ) );
			} catch ( err ) {
				setHdgError( err.message || 'Fix failed.' );
				setFixStates( ( prev ) => ( { ...prev, [ checkId ]: null } ) );
			}
			return;
		}

		// All other fix actions update AEO fields directly.
		try {
			const res  = await fetch( ajaxUrl, {
				method:      'POST',
				credentials: 'same-origin',
				headers:     { 'Content-Type': 'application/x-www-form-urlencoded' },
				body:        new URLSearchParams( { action: action.ajaxAction, nonce: action.actionNonce, post_id: postId } ),
			} );
			const data = await res.json();
			if ( ! data.success ) throw new Error( data.data?.message || 'Fix failed.' );
			const fields = data.data || {};
			const updated = { ...aeo };
			if ( fields.summary   !== undefined ) updated.summary   = fields.summary;
			if ( fields.questions !== undefined ) updated.questions = fields.questions.map( ( q ) => ( { q: q.q || q.question || '', a: q.a || q.answer || '' } ) );
			if ( fields.entities  !== undefined ) updated.entities  = fields.entities;
			if ( fields.keywords  !== undefined ) updated.keywords  = fields.keywords;
			updateAeo( updated );
			await window.wp.data.dispatch( 'core/editor' ).savePost();
			runAudit( true );
		} catch ( err ) {
			setFixStates( ( prev ) => ( { ...prev, [ checkId ]: err.message || 'Fix failed.' } ) );
		}
	}, [ postId, aeo, updateAeo, runAudit ] );

	// ── Helpers for applying inline suggestions ───────────────────────────────

	function applyKwFix( quote, suggestion, index ) {
		const { getBlocks }             = wp.data.select( 'core/block-editor' );
		const { updateBlockAttributes } = wp.data.dispatch( 'core/block-editor' );
		let applied = false;
		for ( const block of getBlocks() ) {
			const content = block.attributes.content;
			if ( ! content ) continue;
			if ( content.includes( quote ) ) {
				updateBlockAttributes( block.clientId, { content: content.replace( quote, suggestion ) } );
				applied = true; break;
			}
			const tagMatch = buildTagTolerantRegex( quote ).exec( content );
			if ( tagMatch ) {
				updateBlockAttributes( block.clientId, { content: content.replace( tagMatch[ 0 ], suggestion ) } );
				applied = true; break;
			}
		}
		if ( applied ) {
			setKwApplied( ( prev ) => ( { ...prev, [ index ]: true } ) );
			window.wp.data.dispatch( 'core/editor' ).savePost().then( () => runAudit( true ) );
		} else {
			setKwError( 'Could not locate that passage in the editor. The fix was not applied.' );
		}
	}

	function applyHeading( anchor, level, heading, index ) {
		const { getBlocks }   = wp.data.select( 'core/block-editor' );
		const { insertBlocks } = wp.data.dispatch( 'core/block-editor' );
		const blocks           = getBlocks();
		const anchorPattern = buildTagTolerantRegex( anchor );
		const targetIndex = blocks.findIndex(
			( b ) => b.attributes && b.attributes.content && (
				b.attributes.content.includes( anchor ) ||
				anchorPattern.test( b.attributes.content )
			)
		);
		if ( targetIndex === -1 ) {
			setHdgError( 'Could not locate the target paragraph. The heading was not inserted.' );
			return;
		}
		const headingBlock = wp.blocks.createBlock( 'core/heading', { level, content: heading } );
		insertBlocks( [ headingBlock ], targetIndex );
		setHdgApplied( ( prev ) => ( { ...prev, [ index ]: true } ) );
		window.wp.data.dispatch( 'core/editor' ).savePost().then( () => runAudit( true ) );
	}

	return (
		<PanelBody
			title="AEO Audit"
			opened={ panelOpen }
			onToggle={ () => {
				if ( ! panelOpen ) runAudit();
				setPanelOpen( ! panelOpen );
			} }
		>
			{ loading && (
				<p style={ { color: '#666', fontSize: '12px', margin: '4px 0 8px' } }>Running audit…</p>
			) }
			{ error && (
				<p style={ { color: '#cc1818', fontSize: '12px', margin: '4px 0 8px' } }>{ error }</p>
			) }
			{ results && ! loading && (
				<>
					{ /* Score summary bar */ }
					<div style={ { marginBottom: '14px' } }>
						<div style={ { display: 'flex', justifyContent: 'space-between', alignItems: 'baseline', marginBottom: '4px' } }>
							<span style={ { fontSize: '12px', color: '#555' } }>
								{ results.passed } / { results.total } checks passed
							</span>
							<span style={ {
								fontSize:   '18px',
								fontWeight: '700',
								color:      results.score >= 80 ? '#46b450' : results.score >= 50 ? '#d97706' : '#cc1818',
							} }>
								{ results.score }%
							</span>
						</div>
						<div style={ { background: '#f0f0f1', borderRadius: '4px', height: '6px', overflow: 'hidden' } }>
							<div style={ {
								height:       '100%',
								borderRadius: '4px',
								width:        `${ results.score }%`,
								background:   results.score >= 80 ? '#46b450' : results.score >= 50 ? '#d97706' : '#cc1818',
								transition:   'width 0.4s ease',
							} } />
						</div>
					</div>

					<p style={ { fontSize: '11px', color: '#888', fontStyle: 'italic', margin: '0 0 10px', lineHeight: '1.5' } }>
						These are signals, not requirements — your judgment about your content and audience always takes priority.
					</p>

					{ /* Check list */ }
					<div style={ { display: 'flex', flexDirection: 'column', gap: '8px', marginBottom: '12px' } }>
						{ results.checks.map( ( check ) => {
							const { icon, color } = AUDIT_STATUS_ICONS[ check.status ] || AUDIT_STATUS_ICONS.fail;
							const fixAction = check.status !== 'pass' ? AUDIT_FIX_ACTIONS[ check.id ] : null;
							const fixState  = fixStates[ check.id ];

							return (
								<div key={ check.id } style={ { borderLeft: `3px solid ${ color }`, paddingLeft: '8px' } }>
									<div style={ { display: 'flex', alignItems: 'flex-start', gap: '6px' } }>
										<span style={ { color, fontWeight: '700', fontSize: '12px', flexShrink: 0, marginTop: '1px' } }>
											{ icon }
										</span>
										<div style={ { flex: 1 } }>
											<div style={ { fontSize: '12px', fontWeight: '600', color: '#1d2327', lineHeight: '1.3' } }>
												{ check.label }
											</div>
											<div style={ { fontSize: '11px', color: '#666', lineHeight: '1.4', marginTop: '1px' } }>
												{ check.message }
											</div>
											{ check.status !== 'pass' && check.tip && (
												<div style={ { fontSize: '11px', color, lineHeight: '1.4', marginTop: '2px', fontStyle: 'italic' } }>
													{ check.tip }
													{ check.id === 'featured_image_alt' && (
														<div style={ { marginTop: '5px' } }>
															{ altGenState === 'loading' ? (
																<span style={ { fontSize: '11px', color: '#666' } }>Generating…</span>
															) : altGenState ? (
																<span style={ { fontSize: '11px', color: '#46b450', fontWeight: '600' } }>
																	✓ Alt text saved — re-run audit to verify
																</span>
															) : check.has_thumbnail ? (
																<Button
																	variant="secondary"
																	onClick={ async () => {
																		setAltGenState( 'loading' );
																		try {
																			const res  = await fetch( ajaxUrl, {
																				method:  'POST',
																				headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
																				body:    new URLSearchParams( { action: 'wppugmill_generate_image_alt', nonce: imageAltNonce, post_id: postId } ),
																			} );
																			const data = await res.json();
																			if ( ! data.success ) throw new Error( data.data?.message || 'Generation failed.' );
																			setAltGenState( data.data.alt_text );
																		} catch ( err ) {
																			setAltGenState( '' );
																			setAltGenError( err.message );
																		}
																	} }
																	style={ { fontSize: '11px', padding: '0 12px', ...BUTTON_STYLE } }
																>
																	✨ Generate Alt Text
																</Button>
															) : (
																<Button
																	variant="secondary"
																	onClick={ async () => {
																		// Walk all blocks (including nested) to find the first image.
																		// Prefers media-library images (have an attachment ID);
																		// falls back to any image with a URL (external images).
																		const findFirstImage = ( blocks ) => {
																			for ( const block of blocks ) {
																				if ( block.name === 'core/image' ) {
																					if ( block.attributes?.id ) {
																						return { id: block.attributes.id, url: null, clientId: null };
																					}
																					if ( block.attributes?.url ) {
																						return { id: null, url: block.attributes.url, clientId: block.clientId };
																					}
																				}
																				if ( block.innerBlocks?.length ) {
																					const found = findFirstImage( block.innerBlocks );
																					if ( found ) return found;
																				}
																			}
																			return null;
																		};
																		const allBlocks = window.wp?.data?.select( 'core/block-editor' )?.getBlocks() || [];
																		const found     = findFirstImage( allBlocks );
																		if ( ! found ) {
																			setAltGenError( 'No image blocks found. Add an Image block to the post first.' );
																			return;
																		}
																		setAltGenError( '' );
																		setAltGenState( 'loading' );
																		// For media-library images, promote to featured image in editor state.
																		if ( found.id ) {
																			window.wp.data.dispatch( 'core/editor' ).editPost( { featured_media: found.id } );
																		}
																		try {
																			const params = { action: 'wppugmill_generate_image_alt', nonce: imageAltNonce, post_id: postId };
																			if ( found.id  ) params.attachment_id = found.id;
																			if ( found.url ) params.image_url     = found.url;
																			const res  = await fetch( ajaxUrl, {
																				method:  'POST',
																				headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
																				body:    new URLSearchParams( params ),
																			} );
																			const data = await res.json();
																			if ( ! data.success ) throw new Error( data.data?.message || 'Generation failed.' );
																			// For external images (no attachment), update the block's alt attribute directly.
																			if ( found.clientId ) {
																				window.wp.data.dispatch( 'core/block-editor' ).updateBlockAttributes( found.clientId, { alt: data.data.alt_text } );
																			}
																			setAltGenState( data.data.alt_text );
																		} catch ( err ) {
																			setAltGenState( '' );
																			setAltGenError( err.message );
																		}
																	} }
																	style={ { fontSize: '11px', padding: '0 12px', ...BUTTON_STYLE } }
																>
																	✨ Generate Alt Text
																</Button>
															) }
															{ altGenError && (
																<span style={ { fontSize: '11px', color: '#cc1818', display: 'block', marginTop: '4px' } }>
																	{ altGenError }
																</span>
															) }
														</div>
													) }
																			</div>
											) }
											{ fixAction && (
												<div style={ { marginTop: '5px' } }>
													{ fixState === 'done' ? (
														<span style={ { fontSize: '11px', color: '#46b450', fontWeight: '600' } }>
															✓ Generated — save & re-run to verify
														</span>
													) : fixState && fixState !== 'loading' ? (
														<span style={ { fontSize: '11px', color: '#cc1818' } }>{ fixState }</span>
													) : (
														<Button
															variant="secondary"
															isBusy={ fixState === 'loading' }
															disabled={ fixState === 'loading' }
															onClick={ () => handleFix( check.id ) }
															style={ { fontSize: '11px', padding: '0 12px', ...BUTTON_STYLE } }
														>
															{ fixState === 'loading' ? 'Generating…' : fixAction.label }
														</Button>
													) }
												</div>
											) }
										</div>
									</div>
								</div>
							);
						} ) }
					</div>
				</>
			) }

			{ /* ── Keyword fix results ───────────────────────────────────────── */ }
			{ kwResults !== null && (
				<>
					{ kwError && (
						<p style={ { fontSize: '11px', color: '#cc1818', margin: '0 0 6px' } }>{ kwError }</p>
					) }
					{ kwResults.length === 0 && (
						<p style={ { fontSize: '11px', color: '#46b450', margin: '0 0 8px' } }>
							Content looks good — no obvious keyword gaps found.
						</p>
					) }
					{ kwResults.length > 0 && (
						<div style={ { marginBottom: '8px' } }>
							<p style={ { fontSize: '11px', fontWeight: '600', color: '#555', margin: '0 0 6px' } }>
								Suggested keyword fixes — review and apply:
							</p>
							{ kwResults.map( ( item, i ) => (
								<div key={ i } style={ {
									border:       '1px solid #e0e0e0',
									borderRadius: '4px',
									padding:      '10px',
									marginBottom: '8px',
									background:   '#fff',
								} }>
									<p style={ { fontSize: '11px', color: '#555', fontWeight: '600', margin: '0 0 4px' } }>
										Keyword: { item.keyword }
									</p>
									<p style={ {
										fontSize:     '11px',
										color:        '#666',
										fontStyle:    'italic',
										margin:       '0 0 4px',
										overflow:     'hidden',
										textOverflow: 'ellipsis',
										whiteSpace:   'nowrap',
									} }>
										"{ item.quote }"
									</p>
									<p style={ { fontSize: '11px', color: '#333', margin: '0 0 6px' } }>
										{ item.suggestion }
									</p>
									{ kwApplied[ i ] ? (
										<span style={ { fontSize: '11px', color: '#46b450', fontWeight: '600' } }>✓ Applied</span>
									) : (
										<Button
											variant="secondary"
											onClick={ () => applyKwFix( item.quote, item.suggestion, i ) }
											style={ { fontSize: '11px', padding: '0 12px', ...BUTTON_STYLE } }
										>
											Apply fix →
										</Button>
									) }
								</div>
							) ) }
							<button
								type="button"
								onClick={ () => setKwResults( null ) }
								style={ { fontSize: '11px', color: '#999', background: 'none', border: 'none', padding: 0, cursor: 'pointer' } }
							>
								Dismiss all
							</button>
						</div>
					) }
				</>
			) }

			{ /* ── Heading suggestion results ────────────────────────────────── */ }
			{ hdgResults !== null && (
				<>
					{ hdgError && (
						<p style={ { fontSize: '11px', color: '#cc1818', margin: '0 0 6px' } }>{ hdgError }</p>
					) }
					{ hdgResults.length === 0 && (
						<p style={ { fontSize: '11px', color: '#46b450', margin: '0 0 8px' } }>
							Content structure looks good — no headings suggested.
						</p>
					) }
					{ hdgResults.length > 0 && (
						<div style={ { marginBottom: '8px' } }>
							<p style={ { fontSize: '11px', fontWeight: '600', color: '#555', margin: '0 0 6px' } }>
								Suggested headings — insert each or dismiss:
							</p>
							{ hdgResults.map( ( item, i ) => (
								<div key={ i } style={ {
									border:       '1px solid #e0e0e0',
									borderRadius: '4px',
									padding:      '10px',
									marginBottom: '8px',
									background:   '#fff',
								} }>
									<p style={ { fontSize: '11px', color: '#555', fontWeight: '600', margin: '0 0 4px' } }>
										H{ item.level }: { item.heading }
									</p>
									<p style={ {
										fontSize:     '11px',
										color:        '#666',
										fontStyle:    'italic',
										margin:       '0 0 6px',
										overflow:     'hidden',
										textOverflow: 'ellipsis',
										whiteSpace:   'nowrap',
									} }>
										Before: "{ item.anchor }"
									</p>
									{ hdgApplied[ i ] ? (
										<span style={ { fontSize: '11px', color: '#46b450', fontWeight: '600' } }>✓ Applied</span>
									) : (
										<Button
											variant="secondary"
											onClick={ () => applyHeading( item.anchor, item.level, item.heading, i ) }
											style={ { fontSize: '11px', padding: '0 12px', ...BUTTON_STYLE } }
										>
											Insert heading →
										</Button>
									) }
								</div>
							) ) }
							<button
								type="button"
								onClick={ () => setHdgResults( null ) }
								style={ { fontSize: '11px', color: '#999', background: 'none', border: 'none', padding: 0, cursor: 'pointer' } }
							>
								Dismiss all
							</button>
						</div>
					) }
				</>
			) }

			<Button
				variant="secondary"
				isBusy={ loading }
				onClick={ () => runAudit( true ) }
				disabled={ loading }
				style={ { width: '100%', justifyContent: 'center', ...BUTTON_STYLE } }
			>
				{ loading ? 'Running…' : 'Re-run Audit' }
			</Button>
		</PanelBody>
	);
}
