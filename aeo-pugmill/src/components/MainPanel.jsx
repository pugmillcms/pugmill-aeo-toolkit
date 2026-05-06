/**
 * Pugmill AEO Toolkit — Main sidebar panel component.
 *
 * Registers as a PluginDocumentSettingPanel (shown in the block editor's
 * Document sidebar). Contains all AEO and SEO editing controls, the score
 * bar, and AI generation buttons.
 *
 * Pro features (Generate AEO, Refine Content, Distribute) are handled by
 * the Pugmill AEO Toolkit Pro add-on, which registers its own sidebar panels.
 *
 * @package WPPugmill
 */

import { PluginDocumentSettingPanel } from '@wordpress/editor';
import { PanelBody, Button, TextControl, TextareaControl, SelectControl } from '@wordpress/components';
import { useSelect } from '@wordpress/data';
import { useState, useCallback, useEffect } from '@wordpress/element';

import { useAeoMeta }    from '../hooks';
import { useSeoMeta }    from '../hooks';
import { useSchemaData } from '../hooks';
import { PugmillLogo }   from './Logo';
import { SectionHeader } from './SectionHeader';
import { SchemaBuilder } from './SchemaBuilder';
import { AiPill }        from './AiInput';
import { Tick }          from './Tick';
import { UsageMeter }    from './UsageMeter';
import { AeoHealthPanel } from './AeoHealthPanel';
import {
	IS_AI_MODE,
	HAS_API_KEY,
	BUTTON_STYLE,
	ENTITY_TYPE_OPTIONS,
	getAuditFixActions,
	ajaxUrl,
	usageNonce,
	summaryNonce,
	qaNonce,
	entitiesNonce,
	keywordsNonce,
	pricingUrl,
} from '../constants';

/**
 * Inline alt text editor for the featured image.
 * Saves directly to the media attachment via WP REST API.
 */
function FeaturedImageAlt( { featuredImageId, initialAlt } ) {
	const [ altText,   setAltText   ] = useState( initialAlt );
	const [ saveState, setSaveState ] = useState( '' ); // '' | 'saving' | 'saved' | error msg

	// Keep in sync when the featured image or its alt changes.
	useEffect( () => { setAltText( initialAlt ); }, [ initialAlt ] );

	const save = useCallback( async () => {
		if ( altText === initialAlt ) return;
		setSaveState( 'saving' );
		try {
			await wp.apiFetch( {
				path:   `/wp/v2/media/${ featuredImageId }`,
				method: 'POST',
				data:   { alt_text: altText.trim() },
			} );
			// Bust the core data cache so audit / health panels see the update.
			wp.data.dispatch( 'core' ).invalidateResolution( 'getMedia', [ featuredImageId ] );
			setSaveState( 'saved' );
			setTimeout( () => setSaveState( '' ), 3000 );
		} catch ( err ) {
			setSaveState( err.message || 'Save failed.' );
		}
	}, [ featuredImageId, altText, initialAlt ] );

	return (
		<PanelBody title={ <span>Featured Image Alt Text<Tick show={ !! altText.trim() } /></span> } initialOpen={ false }>
			<p style={ { fontSize: '11px', color: '#777', margin: '0 0 6px' } }>
				Alt text is read by screen readers and used by AI crawlers. Set it here or in the Media Library.
			</p>
			<input
				type="text"
				value={ altText }
				onChange={ ( e ) => { setAltText( e.target.value ); setSaveState( '' ); } }
				onBlur={ save }
				placeholder="Describe this image…"
				style={ {
					width:        '100%',
					fontSize:     '12px',
					padding:      '6px 8px',
					border:       '1px solid #ddd',
					borderRadius: '4px',
					boxSizing:    'border-box',
					fontFamily:   'inherit',
					lineHeight:   '1.4',
					marginBottom: '6px',
				} }
			/>
			<div style={ { display: 'flex', justifyContent: 'space-between', alignItems: 'center' } }>
				<Button
					variant="secondary"
					onClick={ save }
					disabled={ saveState === 'saving' || altText === initialAlt }
					style={ { fontSize: '11px', padding: '2px 12px', ...BUTTON_STYLE } }
				>
					{ saveState === 'saving' ? 'Saving…' : 'Save' }
				</Button>
				{ saveState === 'saved' && (
					<span style={ { fontSize: '11px', color: '#46b450', fontWeight: '600' } }>✓ Saved</span>
				) }
				{ saveState && saveState !== 'saving' && saveState !== 'saved' && (
					<span style={ { fontSize: '11px', color: '#dc3232' } }>{ saveState }</span>
				) }
			</div>
		</PanelBody>
	);
}

// ── Main Panel ────────────────────────────────────────────────────────────────

export function MainPanel() {
	const { aeo, updateAeo, postId, meta, setMeta } = useAeoMeta();
	const { seo, updateSeo }                        = useSeoMeta();
	const { schema, updateSchema }                  = useSchemaData();

	const draftContent        = useSelect( ( s ) => s( 'core/editor' ).getEditedPostContent(), [] );
	const featuredImageId     = useSelect( ( s ) => s( 'core/editor' ).getEditedPostAttribute( 'featured_media' ), [] );
	const featuredMediaRecord = useSelect( ( s ) => featuredImageId ? s( 'core' ).getMedia( featuredImageId ) : null, [ featuredImageId ] );
	const featuredMediaAltText = featuredMediaRecord?.alt_text || '';

	// ── State ─────────────────────────────────────────────────────────────────

	// Health score inline Fix buttons
	const [ healthFixStates, setHealthFixStates ] = useState( {} );

	// Controlled open state for AEO field panels — auto-opened after health fixes
	// so the user can see and save the generated content.
	const [ summaryPanelOpen,  setSummaryPanelOpen  ] = useState( false );
	const [ qaPanelOpen,       setQaPanelOpen       ] = useState( false );
	const [ entitiesPanelOpen, setEntitiesPanelOpen ] = useState( false );
	const [ keywordsPanelOpen, setKeywordsPanelOpen ] = useState( false );

	// Local AEO override for immediate score feedback after a health fix.
	// The WP data store update from updateAeo() is async, so the score ring
	// would stay stale until the store propagates. We hold the just-fixed aeo
	// here and use it for score computation; clear it once the store catches up.
	const [ aeoOverride, setAeoOverride ] = useState( null );
	useEffect( () => { setAeoOverride( null ); }, [ aeo ] );
	const displayAeo = aeoOverride ?? aeo;

	// AI usage meter
	const [ usage, setUsage ] = useState( { count: 0, limit: 50 } );

	// Per-field generate states
	const [ summaryState,  setSummaryState  ] = useState( { loading: false, error: '' } );
	const [ qaState,       setQaState       ] = useState( { loading: false, error: '' } );
	const [ entitiesState, setEntitiesState ] = useState( { loading: false, error: '' } );
	const [ keywordsState, setKeywordsState ] = useState( { loading: false, error: '' } );

	// ── Usage meter ───────────────────────────────────────────────────────────
	const fetchUsage = useCallback( async () => {
		if ( ! usageNonce ) return;
		try {
			const res  = await fetch( ajaxUrl, {
				method:  'POST',
				headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
				body:    new URLSearchParams( { action: 'aeopugmill_get_usage', nonce: usageNonce } ),
			} );
			const data = await res.json();
			if ( data.success ) setUsage( data.data );
		} catch {}
	}, [] );

	useEffect( () => { if ( IS_AI_MODE ) fetchUsage(); }, [ IS_AI_MODE ] );

	// ── Health score inline Fix handler ───────────────────────────────────────

	const handleHealthFix = useCallback( async ( checkId ) => {
		const action = getAuditFixActions()[ checkId ];
		if ( ! action ) return;
		setHealthFixStates( ( prev ) => ( { ...prev, [ checkId ]: 'loading' } ) );
		try {
			const res  = await fetch( ajaxUrl, {
				method:      'POST',
				credentials: 'same-origin',
				headers:     { 'Content-Type': 'application/x-www-form-urlencoded' },
				body:        new URLSearchParams( { action: action.ajaxAction, nonce: action.actionNonce, post_id: postId, draft_content: draftContent } ),
			} );
			const data = await res.json();
			if ( ! data.success ) throw new Error( data.data?.message || 'Fix failed.' );
			const f = data.data || {};
			const updatedAeo = { ...aeo };
			if ( f.summary   !== undefined ) updatedAeo.summary   = f.summary;
			if ( f.questions !== undefined ) updatedAeo.questions = f.questions.map( ( q ) => ( { q: q.q || q.question || '', a: q.a || q.answer || '' } ) );
			if ( f.entities  !== undefined ) updatedAeo.entities  = f.entities;
			if ( f.keywords  !== undefined ) updatedAeo.keywords  = f.keywords;
			updateAeo( updatedAeo );
			setAeoOverride( updatedAeo ); // score updates immediately, before WP store propagates

			// Auto-open the lower panel so the user can see and save the result.
			( {
				summary_present:    setSummaryPanelOpen,
				summary_length:     setSummaryPanelOpen,
				qa_present:         setQaPanelOpen,
				qa_coverage:        setQaPanelOpen,
				questions_natural:  setQaPanelOpen,
				entities_present:   setEntitiesPanelOpen,
				entity_specificity: setEntitiesPanelOpen,
				keywords_present:   setKeywordsPanelOpen,
			} )[ checkId ]?.( true );

			fetchUsage();
			setHealthFixStates( ( prev ) => ( { ...prev, [ checkId ]: 'done' } ) );
		} catch ( err ) {
			setHealthFixStates( ( prev ) => ( { ...prev, [ checkId ]: err.message || 'Fix failed.' } ) );
		}
	}, [ postId, draftContent, aeo, updateAeo, fetchUsage ] );

	// ── Shared AJAX helper ────────────────────────────────────────────────────

	/** POST to AJAX, pass draft_content + post_id. Updates state, calls onSuccess with data. */
	const ajaxGenerate = useCallback( async ( ajaxAction, actionNonce, setState, onSuccess ) => {
		setState( { loading: true, error: '' } );
		try {
			const res  = await fetch( ajaxUrl, {
				method:  'POST',
				headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
				body:    new URLSearchParams( { action: ajaxAction, nonce: actionNonce, post_id: postId, draft_content: draftContent } ),
			} );
			const data = await res.json();
			if ( data.success ) {
				onSuccess( data.data );
				fetchUsage();
				setState( { loading: false, error: '' } );
			} else {
				setState( { loading: false, error: data.data?.message || 'Generation failed. Please try again.' } );
			}
		} catch {
			setState( { loading: false, error: 'Network error. Please check your connection.' } );
		}
	}, [ postId, draftContent, fetchUsage ] );

	// ── Q&A / Entities field updaters ─────────────────────────────────────────
	const updateQuestion = ( index, field, value ) =>
		updateAeo( { questions: aeo.questions.map( ( q, i ) => i === index ? { ...q, [ field ]: value } : q ) } );

	const updateEntity = ( index, field, value ) =>
		updateAeo( { entities: aeo.entities.map( ( e, i ) => i === index ? { ...e, [ field ]: value } : e ) } );

	const keywordsString = ( aeo.keywords || [] ).join( ', ' );

	// ── Score ─────────────────────────────────────────────────────────────────

	const summaryOk  = !! ( aeo.summary && aeo.summary.trim() ) && aeo.summary.length >= 50;
	const qaOk       = ( aeo.questions || [] ).filter( ( q ) => q.q && q.a ).length >= 3;
	const entitiesOk = ( aeo.entities  || [] ).filter( ( e ) => e.name ).length >= 1;
	const keywordsOk = ( aeo.keywords  || [] ).filter( ( k ) => k.length > 0 ).length >= 5;

	// ── Render ────────────────────────────────────────────────────────────────
	return (
		<PluginDocumentSettingPanel
			name="aeopugmill-panel"
			title="Pugmill AEO Toolkit"
			className="aeopugmill-panel"
		>
			{ /* Header */ }
			<div style={ {
				display:       'flex',
				flexDirection: 'column',
				alignItems:    'center',
				gap:           '4px',
				margin:        '0 -16px 0',
				padding:       '12px 16px',
				background:    '#f5f0ff',
			} }>
				<PugmillLogo />
				<span style={ { fontSize: '15px', fontWeight: '700', letterSpacing: '0.06em', textTransform: 'uppercase', color: '#7c3aed' } }>
					Pugmill AEO Toolkit
				</span>
				<span style={ { fontSize: '10px', color: '#a78bfa', letterSpacing: '0.04em', textTransform: 'uppercase', marginTop: '-2px' } }>
					Search Engine + Answer Engine Optimization
				</span>
			</div>

			{ /* ── AEO Health ─────────────────────────────────────────────── */ }
			<AeoHealthPanel
				aeo={ displayAeo }
				seo={ seo }
				draftContent={ draftContent }
				featuredMediaAltText={ featuredMediaAltText }
				healthFixStates={ healthFixStates }
				onFix={ handleHealthFix }
			/>

			{ /* ── Generate AEO (Pro CTA) ─────────────────────────────────── */ }
			<div style={ { margin: '12px 0 0', padding: '10px 12px', background: '#faf5ff', border: '1px solid #e9d5ff', borderRadius: '4px', textAlign: 'center' } }>
				<p style={ { margin: '0 0 6px', fontSize: '11px', color: '#6b21a8', lineHeight: '1.5' } }>
					✨ Auto-generate Summary, Q&amp;A, Entities &amp; Keywords in one click.
				</p>
				<a
					href={ pricingUrl }
					target="_blank"
					rel="noopener noreferrer"
					style={ { display: 'inline-block', fontSize: '12px', fontWeight: 600, color: '#fff', background: '#7c3aed', borderRadius: '4px', padding: '5px 16px', textDecoration: 'none' } }
				>
					Get Pugmill AEO Toolkit Pro →
				</a>
			</div>

			{ /* ── AEO section ───────────────────────────────────────────────── */ }
			<SectionHeader label="AEO" />

			{ /* AI Summary */ }
			<PanelBody title={ <span>AI Summary<Tick show={ summaryOk } /></span> } opened={ summaryPanelOpen } onToggle={ () => setSummaryPanelOpen( ( o ) => ! o ) }>
				<TextareaControl
					help="2–3 sentences describing this content for AI crawlers."
					value={ aeo.summary }
					onChange={ ( val ) => updateAeo( { summary: val } ) }
					rows={ 4 }
				/>
				<p style={ { fontSize: '11px', color: '#999', margin: '0 0 6px' } }>
					{ aeo.summary.length } chars
					{ aeo.summary.length > 0 && aeo.summary.length < 80 ? ' — aim for 80+ characters' : '' }
				</p>
				{ summaryState.error && (
					<p style={ { fontSize: '11px', color: '#dc3232', margin: '0 0 6px' } }>{ summaryState.error }</p>
				) }
				<div style={ { marginTop: '8px' } }>
					<AiPill
						label="Generate"
						isActive={ summaryState.loading }
						anyPending={ summaryState.loading }
						locked={ ! IS_AI_MODE && ! HAS_API_KEY }
						onClick={ () => ajaxGenerate( 'aeopugmill_generate_summary', summaryNonce, setSummaryState, ( d ) => updateAeo( { summary: d.summary } ) ) }
					/>
				</div>
			</PanelBody>

			{ /* Q&A Pairs */ }
			<PanelBody title={ <span>Q&A Pairs ({ aeo.questions.length })<Tick show={ qaOk } /></span> } opened={ qaPanelOpen } onToggle={ () => setQaPanelOpen( ( o ) => ! o ) }>
				{ aeo.questions.map( ( qa, i ) => (
					<div key={ i } style={ { borderBottom: '1px solid #e0e0e0', paddingBottom: '12px', marginBottom: '12px' } }>
						<TextControl
							label={ `Q${ i + 1 }` }
							placeholder="Question readers might ask…"
							value={ qa.q }
							onChange={ ( val ) => updateQuestion( i, 'q', val ) }
						/>
						<TextareaControl
							label="Answer"
							placeholder="Clear, direct answer…"
							value={ qa.a }
							onChange={ ( val ) => updateQuestion( i, 'a', val ) }
							rows={ 3 }
						/>
						<Button
							isDestructive
							size="small"
							onClick={ () => updateAeo( { questions: aeo.questions.filter( ( _, idx ) => idx !== i ) } ) }
						>
							Remove
						</Button>
					</div>
				) ) }
				<Button variant="secondary" onClick={ () => updateAeo( { questions: [ ...aeo.questions, { q: '', a: '' } ] } ) }>
					+ Add Q&A Pair
				</Button>
				{ qaState.error && (
					<p style={ { fontSize: '11px', color: '#dc3232', margin: '6px 0 0' } }>{ qaState.error }</p>
				) }
				<div style={ { marginTop: '8px' } }>
					<AiPill
						label="Generate"
						isActive={ qaState.loading }
						anyPending={ qaState.loading }
						locked={ ! IS_AI_MODE && ! HAS_API_KEY }
						onClick={ () => ajaxGenerate( 'aeopugmill_generate_qa', qaNonce, setQaState, ( d ) => updateAeo( { questions: d.questions } ) ) }
					/>
				</div>
			</PanelBody>

			{ /* Named Entities */ }
			<PanelBody title={ <span>Named Entities ({ aeo.entities.length })<Tick show={ entitiesOk } /></span> } opened={ entitiesPanelOpen } onToggle={ () => setEntitiesPanelOpen( ( o ) => ! o ) }>
				{ aeo.entities.map( ( entity, i ) => (
					<div key={ i } style={ { borderBottom: '1px solid #e0e0e0', paddingBottom: '12px', marginBottom: '12px' } }>
						<TextControl
							label="Name"
							placeholder="e.g. Anthropic, Claude, OpenAI"
							value={ entity.name }
							onChange={ ( val ) => updateEntity( i, 'name', val ) }
						/>
						<SelectControl
							label="Type"
							value={ entity.type || 'Thing' }
							options={ ENTITY_TYPE_OPTIONS }
							onChange={ ( val ) => updateEntity( i, 'type', val ) }
						/>
						<TextControl
							label="Description / Definition"
							placeholder={ entity.type === 'DefinedTerm' ? 'Define this term…' : 'Brief description (optional)' }
							value={ entity.description || '' }
							onChange={ ( val ) => updateEntity( i, 'description', val ) }
						/>
						<TextControl
							label="sameAs URL"
							placeholder="e.g. https://www.wikidata.org/wiki/Q…"
							value={ entity.same_as || '' }
							onChange={ ( val ) => updateEntity( i, 'same_as', val ) }
							help="Canonical knowledge-graph URL (Wikipedia, Wikidata, etc.)"
						/>
						<Button
							isDestructive
							size="small"
							onClick={ () => updateAeo( { entities: aeo.entities.filter( ( _, idx ) => idx !== i ) } ) }
						>
							Remove
						</Button>
					</div>
				) ) }
				<Button variant="secondary" onClick={ () => updateAeo( { entities: [ ...aeo.entities, { name: '', type: 'Thing', same_as: '' } ] } ) }>
					+ Add Entity
				</Button>
				{ entitiesState.error && (
					<p style={ { fontSize: '11px', color: '#dc3232', margin: '6px 0 0' } }>{ entitiesState.error }</p>
				) }
				<div style={ { marginTop: '8px' } }>
					<AiPill
						label="Generate"
						isActive={ entitiesState.loading }
						anyPending={ entitiesState.loading }
						locked={ ! IS_AI_MODE && ! HAS_API_KEY }
						onClick={ () => ajaxGenerate( 'aeopugmill_generate_entities', entitiesNonce, setEntitiesState, ( d ) => updateAeo( { entities: d.entities } ) ) }
					/>
				</div>
			</PanelBody>

			{ /* Keywords */ }
			<PanelBody title={ <span>Keywords { keywordsOk ? <Tick show={ true } /> : <span style={ { fontSize: '11px', fontWeight: '400', color: '#999' } }>({ aeo.keywords.length }/10)</span> }</span> } opened={ keywordsPanelOpen } onToggle={ () => setKeywordsPanelOpen( ( o ) => ! o ) }>
				<TextareaControl
					help="Comma-separated. 5–15 specific, search-focused terms."
					value={ keywordsString }
					onChange={ ( val ) => updateAeo( { keywords: val.split( ',' ).map( ( k ) => k.trim() ).filter( Boolean ) } ) }
					rows={ 3 }
				/>
				<p style={ { fontSize: '11px', color: '#999', margin: '0 0 6px' } }>
					{ aeo.keywords.length } keyword{ aeo.keywords.length !== 1 ? 's' : '' }
					{ aeo.keywords.length > 0 && aeo.keywords.length < 5 ? ' — aim for 5+' : '' }
				</p>
				{ keywordsState.error && (
					<p style={ { fontSize: '11px', color: '#dc3232', margin: '0 0 6px' } }>{ keywordsState.error }</p>
				) }
				<div style={ { marginTop: '8px' } }>
					<AiPill
						label="Generate"
						isActive={ keywordsState.loading }
						anyPending={ keywordsState.loading }
						locked={ ! IS_AI_MODE && ! HAS_API_KEY }
						onClick={ () => ajaxGenerate( 'aeopugmill_generate_keywords', keywordsNonce, setKeywordsState, ( d ) => updateAeo( { keywords: d.keywords } ) ) }
					/>
				</div>
			</PanelBody>

			<SchemaBuilder />

			{ /* Featured Image Alt Text */ }
			{ featuredImageId ? <FeaturedImageAlt featuredImageId={ featuredImageId } initialAlt={ featuredMediaAltText } /> : null }

			{ /* ── Refine Content & Distribute (Pro) ──────────────────────── */ }
			<SectionHeader label="Refine Content" />
			<div style={ { padding: '12px 0 16px', textAlign: 'center' } }>
				<p style={ { fontSize: '12px', color: '#555', margin: '0 0 10px', lineHeight: '1.5' } }>
					Tone Check, Topic Focus, Reading Level, Suggest Titles, Excerpt Generator, Internal Links, and Social Media Drafts.
				</p>
				<a
					href={ pricingUrl }
					target="_blank"
					rel="noopener noreferrer"
					style={ { display: 'inline-block', padding: '6px 18px', background: '#7c3aed', color: '#fff', borderRadius: '4px', textDecoration: 'none', fontSize: '13px', fontWeight: 600 } }
				>
					Get Pugmill AEO Toolkit Pro →
				</a>
			</div>

		</PluginDocumentSettingPanel>
	);
}
