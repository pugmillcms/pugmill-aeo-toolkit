/**
 * WP Pugmill — Main sidebar panel component.
 *
 * Registers as a PluginDocumentSettingPanel (shown in the block editor's
 * Document sidebar). Contains all AEO and SEO editing controls, the score
 * bar, all AI generation buttons, and the Audit panel.
 *
 * @package WPPugmill
 */

import { PluginDocumentSettingPanel } from '@wordpress/editor';
import { PanelBody, Button, Notice, TextControl, TextareaControl, SelectControl } from '@wordpress/components';
import { useSelect, useDispatch } from '@wordpress/data';
import { useState, useCallback, useEffect } from '@wordpress/element';

import { useAeoMeta }    from '../hooks';
import { useSeoMeta }    from '../hooks';
import { useSchemaData } from '../hooks';
import { PugmillLogo }     from './Logo';
import { SectionHeader }   from './SectionHeader';
import { SeoPanel }        from './SeoPanel';
import { SchemaBuilder }   from './SchemaBuilder';
import { AiPill } from './AiInput';
import { Tick }            from './Tick';
import { UsageMeter }      from './UsageMeter';
import { AeoHealthPanel }  from './AeoHealthPanel';
import { ToneCheckPanel }  from './ToneCheckPanel';
import { SocialDraftPanel } from './SocialDraftPanel';
import { saveIfDirty }     from '../utils';
import {
	IS_AI_MODE,
	HAS_API_KEY,
	BUTTON_STYLE,
	ENTITY_TYPE_OPTIONS,
	getAuditFixActions,
	ajaxUrl,
	nonce,
	toneNonce,
	readingLevelNonce,
	headlinesNonce,
	topicFocusNonce,
	refineFocusNonce,
	swapFocusNonce,
	excerptNonce,
	internalLinksNonce,
	socialDraftNonce,
	usageNonce,
	summaryNonce,
	qaNonce,
	entitiesNonce,
	keywordsNonce,
	seoNonce,
	schemaAiNonce,
} from '../constants';

// ── Utility ───────────────────────────────────────────────────────────────────

/**
 * Build a regex that matches `text` in raw block HTML while tolerating
 * inline tags (bold, italic, links, etc.) between words.
 *
 * E.g. passage "great post about widgets" will match
 * "great <strong>post</strong> about widgets" in raw block content.
 */
function buildTagTolerantRegex( text ) {
	const escapeRe = ( s ) => s.replace( /[.*+?^${}()|[\]\\]/g, '\\$&' );
	const parts    = text.trim().split( /\s+/ );
	// Between each word: allow optional inline tags and whitespace (at least one space).
	return new RegExp( parts.map( escapeRe ).join( '(?:<[^>]*>)*\\s+(?:<[^>]*>)*' ) );
}

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

	const { resetEditorBlocks, editPost } = useDispatch( 'core/editor' );
	const draftContent      = useSelect( ( s ) => s( 'core/editor' ).getEditedPostContent(), [] );
	const postExcerpt       = useSelect( ( s ) => s( 'core/editor' ).getEditedPostAttribute( 'excerpt' ), [] );
	const featuredImageId   = useSelect( ( s ) => s( 'core/editor' ).getEditedPostAttribute( 'featured_media' ), [] );
	const featuredMediaRecord = useSelect( ( s ) => featuredImageId ? s( 'core' ).getMedia( featuredImageId ) : null, [ featuredImageId ] );
	const featuredMediaAltText = featuredMediaRecord?.alt_text || '';

	// ── State ─────────────────────────────────────────────────────────────────
	const [ generateAllLoading, setGenerateAllLoading ] = useState( false );
	const [ generateAllError,   setGenerateAllError   ] = useState( '' );
	const [ generateAllSuccess, setGenerateAllSuccess ] = useState( false );


	// Health score inline Fix buttons
	const [ healthFixStates, setHealthFixStates ] = useState( {} );

	// Local AEO override for immediate score feedback after a health fix.
	// The WP data store update from updateAeo() is async, so the score ring
	// would stay stale until the store propagates. We hold the just-fixed aeo
	// here and use it for score computation; clear it once the store catches up.
	const [ aeoOverride, setAeoOverride ] = useState( null );
	useEffect( () => { setAeoOverride( null ); }, [ aeo ] );
	const displayAeo = aeoOverride ?? aeo;

	// Tone Check
	const [ toneLoading,  setToneLoading  ] = useState( false );
	const [ toneError,    setToneError    ] = useState( '' );
	const [ toneResults,  setToneResults  ] = useState( null );
	const [ toneSwapErrs, setToneSwapErrs ] = useState( {} );
	const [ toneApplied,  setToneApplied  ] = useState( {} );

	// Reading Level (analysis only; rewriting via Rewrite panel)
	const [ readingState, setReadingState ] = useState( { loading: false, error: '', result: null } );

	// Suggest Titles
	const [ headlineState,   setHeadlineState   ] = useState( { loading: false, error: '', result: null } );
	const [ headlineApplied, setHeadlineApplied ] = useState( {} );

	// Topic Focus
	const [ topicState,  setTopicState  ] = useState( { loading: false, error: '', result: null } );
	const [ refineState, setRefineState ] = useState( { loading: false, error: '', result: null } );
	const [ swapStates,  setSwapStates  ] = useState( {} );

	// Excerpt Generator
	const [ excerptState,   setExcerptState   ] = useState( { loading: false, error: '', result: null } );
	const [ excerptApplied, setExcerptApplied ] = useState( false );

	// Internal Links
	const [ linksState,    setLinksState   ] = useState( { loading: false, error: '', result: null } );
	const [ linkInserted,  setLinkInserted ] = useState( {} );

	// Social Media Draft
	const [ socialState, setSocialState ] = useState( { loading: false, error: '', platform: null, draft: '' } );

	// Panel open state (controlled — allows AI Ask bar to open panels programmatically)
	const [ toneOpen,    setToneOpen    ] = useState( false );
	const [ readingOpen, setReadingOpen ] = useState( false );
	const [ topicOpen,   setTopicOpen   ] = useState( false );
	const [ linksOpen,   setLinksOpen   ] = useState( false );
	const [ titlesOpen,  setTitlesOpen  ] = useState( false );
	const [ excerptOpen, setExcerptOpen ] = useState( false );
	const [ socialOpen,  setSocialOpen  ] = useState( false );

	// AI usage meter
	const [ usage, setUsage ] = useState( { count: 0, limit: 50 } );

	// Per-field generate states
	const [ summaryState,   setSummaryState   ] = useState( { loading: false, error: '' } );
	const [ qaState,        setQaState        ] = useState( { loading: false, error: '' } );
	const [ entitiesState,  setEntitiesState  ] = useState( { loading: false, error: '' } );
	const [ keywordsState,  setKeywordsState  ] = useState( { loading: false, error: '' } );

	// ── Usage meter ───────────────────────────────────────────────────────────
	const fetchUsage = useCallback( async () => {
		if ( ! usageNonce ) return;
		try {
			const res  = await fetch( ajaxUrl, {
				method:  'POST',
				headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
				body:    new URLSearchParams( { action: 'wppugmill_get_usage', nonce: usageNonce } ),
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
			fetchUsage();
			setHealthFixStates( ( prev ) => ( { ...prev, [ checkId ]: 'done' } ) );
		} catch ( err ) {
			setHealthFixStates( ( prev ) => ( { ...prev, [ checkId ]: err.message || 'Fix failed.' } ) );
		}
	}, [ postId, draftContent, aeo, updateAeo, fetchUsage ] );

	// ── Shared AJAX helpers ───────────────────────────────────────────────────

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

	/** POST to AJAX with current draft_content — no save required. */
	const ajaxFetch = useCallback( async ( ajaxAction, actionNonce, setState ) => {
		setState( { loading: true, error: '', result: null } );
		try {
			const res  = await fetch( ajaxUrl, {
				method:  'POST',
				headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
				body:    new URLSearchParams( { action: ajaxAction, nonce: actionNonce, post_id: postId, draft_content: draftContent } ),
			} );
			const data = await res.json();
			if ( ! data.success ) throw new Error( data.data?.message || data.data || 'Request failed. Please try again.' );
			setState( { loading: false, error: '', result: data.data } );
		} catch ( err ) {
			setState( { loading: false, error: err.message, result: null } );
		}
		fetchUsage();
	}, [ postId, draftContent, fetchUsage ] );

	// ── Q&A / Entities field updaters ─────────────────────────────────────────
	const updateQuestion = ( index, field, value ) =>
		updateAeo( { questions: aeo.questions.map( ( q, i ) => i === index ? { ...q, [ field ]: value } : q ) } );

	const updateEntity = ( index, field, value ) =>
		updateAeo( { entities: aeo.entities.map( ( e, i ) => i === index ? { ...e, [ field ]: value } : e ) } );

	const keywordsString = ( aeo.keywords || [] ).join( ', ' );

	// ── Score ─────────────────────────────────────────────────────────────────

	const summaryOk   = !! ( aeo.summary  && aeo.summary.trim() ) && aeo.summary.length >= 50;
	const qaOk        = ( aeo.questions  || [] ).filter( ( q ) => q.q && q.a ).length >= 3;
	const entitiesOk  = ( aeo.entities   || [] ).filter( ( e ) => e.name ).length >= 1;
	const keywordsOk  = ( aeo.keywords   || [] ).filter( ( k ) => k.length > 0 ).length >= 5;

	// ── Tone Check: apply a fix inline ────────────────────────────────────────
	function applyToneFix( quote, suggestion, index ) {
		const { getBlocks }             = wp.data.select( 'core/block-editor' );
		const { updateBlockAttributes } = wp.data.dispatch( 'core/block-editor' );
		const normalize = ( s ) => s.replace( /\s+/g, ' ' ).trim().toLowerCase();
		const stripTags = ( s ) => s.replace( /<[^>]*>/g, '' );
		const normQuote = normalize( quote );
		const escapeRe  = ( s ) => s.replace( /[.*+?^${}()|[\]\\]/g, '\\$&' );
		let applied = false;

		for ( const block of getBlocks() ) {
			if ( block.name !== 'core/paragraph' ) continue;
			const raw = block.attributes.content;
			if ( ! raw ) continue;

			// 1. Exact match — fast path for unformatted text.
			if ( raw.includes( quote ) ) {
				updateBlockAttributes( block.clientId, { content: raw.replace( quote, suggestion ) } );
				applied = true;
				break;
			}

			// 2. Tag-tolerant match — quote words present but separated by inline tags
			//    (bold, italic, links). Replaces the matched span (tags included) with suggestion.
			const tagMatch = buildTagTolerantRegex( quote ).exec( raw );
			if ( tagMatch ) {
				updateBlockAttributes( block.clientId, { content: raw.replace( tagMatch[ 0 ], suggestion ) } );
				applied = true;
				break;
			}

			// 3. Case-insensitive fallback — handles minor case/punctuation differences.
			//    Tries to replace in raw HTML first (preserving inline formatting);
			//    only falls back to stripped content as a last resort.
			const plain = stripTags( raw );
			if ( normalize( plain ).includes( normQuote ) ) {
				const caseRe = new RegExp( escapeRe( quote ), 'i' );
				updateBlockAttributes( block.clientId, {
					content: caseRe.test( raw ) ? raw.replace( caseRe, suggestion ) : plain.replace( caseRe, suggestion ),
				} );
				applied = true;
				break;
			}
		}

		if ( applied ) {
			setToneApplied( ( prev ) => ( { ...prev, [ index ]: true } ) );
			window.wp.data.dispatch( 'core/editor' ).savePost();
		} else {
			setToneSwapErrs( ( prev ) => ( { ...prev, [ index ]: 'Looks like this passage changed after the check ran — the anchor no longer matches. Re-run for fresh results.' } ) );
		}
	}

	// ── Topic Focus: swap a passage ────────────────────────────────────────────
	async function swapFocusPassage( issue, index ) {
		setSwapStates( ( prev ) => ( { ...prev, [ index ]: 'loading' } ) );
		try {
			const res  = await fetch( ajaxUrl, {
				method:  'POST',
				headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
				body:    new URLSearchParams( { action: 'wppugmill_swap_focus_passage', nonce: swapFocusNonce, post_id: postId, passage: issue.passage, recommendation: issue.recommendation } ),
			} );
			const data = await res.json();
			if ( ! data.success ) throw new Error( data.data?.message || 'Swap failed.' );
			const rewritten = data.data.rewritten;
			const { getBlocks }           = wp.data.select( 'core/block-editor' );
			const { updateBlockAttributes } = wp.data.dispatch( 'core/block-editor' );
			const normalize   = ( s ) => s.replace( /\s+/g, ' ' ).trim().toLowerCase();
			const strip       = ( s ) => s.replace( /<[^>]*>/g, '' );
			const normPassage = normalize( issue.passage );
			let applied = false;
			for ( const block of getBlocks() ) {
				if ( block.name !== 'core/paragraph' ) continue;
				const raw   = block.attributes.content;
				const plain = strip( raw );
				// 1. Exact match — fast path for unformatted paragraphs.
				if ( raw.includes( issue.passage ) ) {
					updateBlockAttributes( block.clientId, { content: raw.replace( issue.passage, rewritten ) } ); applied = true; break;
				}
				// 2. Tag-tolerant match — passage words present but separated by inline tags
				//    (bold, italic, links). Replaces the entire matched span (tags included)
				//    with the rewritten text, preserving surrounding formatting.
				const tagMatch = buildTagTolerantRegex( issue.passage ).exec( raw );
				if ( tagMatch ) {
					updateBlockAttributes( block.clientId, { content: raw.replace( tagMatch[ 0 ], rewritten ) } ); applied = true; break;
				}
				// 3. Normalised fallback — handles whitespace/case differences.
				if ( normalize( plain ).includes( normPassage ) ) {
					updateBlockAttributes( block.clientId, { content: rewritten } ); applied = true; break;
				}
			}
			if ( ! applied ) throw new Error( 'Looks like this passage changed after the check ran — the anchor no longer matches. Re-run for fresh results.' );
			window.wp.data.dispatch( 'core/editor' ).savePost();
			fetchUsage();
			setSwapStates( ( prev ) => ( { ...prev, [ index ]: 'done' } ) );
		} catch ( err ) {
			setSwapStates( ( prev ) => ( { ...prev, [ index ]: err.message } ) );
		}
	}

	// ── Internal Links: insert anchor ─────────────────────────────────────────
	function insertLink( link, index ) {
		try {
			const { getBlocks }           = wp.data.select( 'core/block-editor' );
			const { updateBlockAttributes } = wp.data.dispatch( 'core/block-editor' );
			const strip   = ( s ) => s.replace( /<[^>]*>/g, '' );
			const normalize = ( s ) => s.replace( /\s+/g, ' ' ).trim().toLowerCase();
			const anchor  = `<a href="${ link.url }">${ link.anchorText }</a>`;
			for ( const block of getBlocks() ) {
				if ( block.name !== 'core/paragraph' ) continue;
				const raw   = block.attributes.content;
				const plain = strip( raw );
				if ( raw.includes( `">${ link.anchorText }</a>` ) ) continue; // already linked
				// 1. Exact match.
				if ( raw.includes( link.anchorText ) ) {
					updateBlockAttributes( block.clientId, { content: raw.replace( link.anchorText, anchor ) } );
					setLinkInserted( ( prev ) => ( { ...prev, [ index ]: 'done' } ) );
					window.wp.data.dispatch( 'core/editor' ).savePost();
					return;
				}
				// 2. Tag-tolerant match — anchor text words split by inline formatting.
				const tagMatch = buildTagTolerantRegex( link.anchorText ).exec( raw );
				if ( tagMatch ) {
					updateBlockAttributes( block.clientId, { content: raw.replace( tagMatch[ 0 ], anchor ) } );
					setLinkInserted( ( prev ) => ( { ...prev, [ index ]: 'done' } ) );
					window.wp.data.dispatch( 'core/editor' ).savePost();
					return;
				}
				// 3. Normalised anchor fallback — anchor text is present in stripped content
				//    but capitalisation differs from the raw HTML (e.g. sentence-start).
				//    Only replaces in raw HTML to preserve inline formatting.
				if ( normalize( plain ).includes( normalize( link.anchorText ) ) ) {
					const caseRe = new RegExp( link.anchorText.replace( /[.*+?^${}()|[\]\\]/g, '\\$&' ), 'i' );
					if ( caseRe.test( raw ) ) {
						updateBlockAttributes( block.clientId, { content: raw.replace( caseRe, anchor ) } );
						setLinkInserted( ( prev ) => ( { ...prev, [ index ]: 'done' } ) );
						window.wp.data.dispatch( 'core/editor' ).savePost();
						return;
					}
				}
			}
			setLinkInserted( ( prev ) => ( { ...prev, [ index ]: 'Looks like this passage changed after the check ran — the anchor no longer matches. Re-run for fresh results.' } ) );
		} catch ( err ) {
			setLinkInserted( ( prev ) => ( { ...prev, [ index ]: err.message } ) );
		}
	}

	// ── Named action runners (called by both buttons and AI Ask bar) ─────────

	const runToneCheck = useCallback( async () => {
		setToneOpen( true );
		setToneLoading( true );
		setToneError( '' );
		setToneResults( null );
		setToneApplied( {} );
		setToneSwapErrs( {} );
		try {
			const res  = await fetch( ajaxUrl, {
				method:  'POST',
				headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
				body:    new URLSearchParams( { action: 'wppugmill_tone_check', nonce: toneNonce, post_id: postId, draft_content: draftContent } ),
			} );
			const data = await res.json();
			if ( ! data.success ) throw new Error( data.data?.message || data.data || 'Tone check failed. Please try again.' );
			setToneResults( data.data.items || [] );
		} catch ( err ) {
			setToneError( err.message );
		} finally {
			setToneLoading( false );
		}
	}, [ postId, draftContent ] );

	const runReadingLevel = useCallback( () => {
		setReadingOpen( true );
		ajaxFetch( 'wppugmill_reading_level', readingLevelNonce, setReadingState );
	}, [ ajaxFetch ] );

	const runTopicFocus = useCallback( () => {
		setTopicOpen( true );
		setRefineState( { loading: false, error: '', result: null } );
		ajaxFetch( 'wppugmill_topic_focus', topicFocusNonce, setTopicState );
	}, [ ajaxFetch ] );

	const runInternalLinks = useCallback( () => {
		setLinksOpen( true );
		setLinkInserted( {} );
		ajaxFetch( 'wppugmill_internal_links', internalLinksNonce, setLinksState );
	}, [ ajaxFetch ] );

	const runSuggestTitles = useCallback( () => {
		setTitlesOpen( true );
		setHeadlineApplied( {} );
		ajaxFetch( 'wppugmill_headline_variants', headlinesNonce, setHeadlineState );
	}, [ ajaxFetch ] );

	const runSuggestExcerpt = useCallback( () => {
		setExcerptOpen( true );
		setExcerptApplied( false );
		ajaxFetch( 'wppugmill_generate_excerpt', excerptNonce, setExcerptState );
	}, [ ajaxFetch ] );

	const runSocialDraft = useCallback( async ( platform = 'twitter' ) => {
		setSocialOpen( true );
		setSocialState( { loading: true, error: '', platform, draft: '' } );
		try {
			await saveIfDirty();
			const res  = await fetch( ajaxUrl, {
				method:  'POST',
				headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
				body:    new URLSearchParams( { action: 'wppugmill_social_draft', nonce: socialDraftNonce, post_id: postId, platform } ),
			} );
			const data = await res.json();
			if ( ! data.success ) throw new Error( data.data?.message || 'Draft failed. Please try again.' );
			setSocialState( { loading: false, error: '', platform, draft: data.data.draft } );
			fetchUsage();
		} catch ( err ) {
			setSocialState( { loading: false, error: err.message, platform, draft: '' } );
		}
	}, [ postId, fetchUsage ] );

	// ── Render ────────────────────────────────────────────────────────────────
	return (
		<PluginDocumentSettingPanel
			name="wppugmill-panel"
			title="SEO+AEO — WP Pugmill"
			className="wppugmill-panel"
		>
			{ /* Header */ }
			<div style={ {
				display:        'flex',
				flexDirection:  'column',
				alignItems:     'center',
				gap:            '4px',
				margin:         '0 -16px 0',
				padding:        '12px 16px',
				background:     '#f5f0ff',
			} }>
				<PugmillLogo />
				<span style={ { fontSize: '15px', fontWeight: '700', letterSpacing: '0.06em', textTransform: 'uppercase', color: '#7c3aed' } }>
					WP Pugmill
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

			{ /* ── Generate All ───────────────────────────────────────────── */ }
			{ IS_AI_MODE && generateAllError && (
				<Notice status="error" isDismissible={ false } style={ { marginTop: '8px' } }>
					{ generateAllError }
				</Notice>
			) }
			{ IS_AI_MODE && generateAllSuccess && (
				<Notice status="success" isDismissible={ false } style={ { marginTop: '8px' } }>
					Fields generated — review and save.
				</Notice>
			) }
			<Button
				variant="primary"
				isBusy={ generateAllLoading }
				disabled={ ! IS_AI_MODE || generateAllLoading }
				onClick={ async () => {
					setGenerateAllLoading( true );
					setGenerateAllError( '' );
					setGenerateAllSuccess( false );
					try {
						// Step 1 — AEO
						const aeoRes  = await fetch( ajaxUrl, {
							method:  'POST',
							headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
							body:    new URLSearchParams( { action: 'wppugmill_generate_aeo', nonce, post_id: postId, draft_content: draftContent } ),
						} );
						const aeoData = await aeoRes.json();
						if ( ! aeoData.success ) {
							setGenerateAllError( aeoData.data?.message || aeoData.data || 'AEO generation failed. Please try again.' );
							return;
						}

						// Step 2 — SEO
						const seoRes  = await fetch( ajaxUrl, {
							method:  'POST',
							headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
							body:    new URLSearchParams( { action: 'wppugmill_generate_seo', nonce: seoNonce, post_id: postId, draft_content: draftContent } ),
						} );
						const seoData = await seoRes.json();

						// Apply AEO + SEO to post meta.
						const newAeo = JSON.stringify( { ...aeo, ...aeoData.data } );
						const newSeo = seoData.success
							? JSON.stringify( { ...seo, title: seoData.data.title, meta_desc: seoData.data.meta_desc } )
							: JSON.stringify( seo );
						setMeta( { ...meta, _wppugmill_aeo: newAeo, _wppugmill_seo: newSeo } );

						// Step 3 — Save so post-content-dependent steps read the latest content.
						await saveIfDirty();

						// Step 4 — Excerpt (auto-apply)
						const excerptRes  = await fetch( ajaxUrl, {
							method:  'POST',
							headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
							body:    new URLSearchParams( { action: 'wppugmill_generate_excerpt', nonce: excerptNonce, post_id: postId } ),
						} );
						const excerptData = await excerptRes.json();
						if ( excerptData.success && excerptData.data?.excerpt ) {
							editPost( { excerpt: excerptData.data.excerpt } );
							setExcerptApplied( true );
							setExcerptState( { loading: false, error: '', result: excerptData.data } );
						}

						// Step 5 — Topic Focus (populate panel)
						const topicRes  = await fetch( ajaxUrl, {
							method:  'POST',
							headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
							body:    new URLSearchParams( { action: 'wppugmill_topic_focus', nonce: topicFocusNonce, post_id: postId } ),
						} );
						const topicData = await topicRes.json();
						if ( topicData.success ) {
							setTopicState( { loading: false, error: '', result: topicData.data } );
							setRefineState( { loading: false, error: '', result: null } );
							setSwapStates( {} );
						}

						// Step 6 — Internal Links (populate panel)
						const linksRes  = await fetch( ajaxUrl, {
							method:  'POST',
							headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
							body:    new URLSearchParams( { action: 'wppugmill_internal_links', nonce: internalLinksNonce, post_id: postId, draft_content: draftContent } ),
						} );
						const linksData = await linksRes.json();
						if ( linksData.success ) {
							setLinksState( { loading: false, error: '', result: linksData.data } );
							setLinkInserted( {} );
						}

						// Step 7 — Schema suggestion (apply only if AI returns a type)
						const schemaRes  = await fetch( ajaxUrl, {
							method:      'POST',
							credentials: 'same-origin',
							headers:     { 'Content-Type': 'application/x-www-form-urlencoded' },
							body:        new URLSearchParams( { action: 'wppugmill_suggest_schema', nonce: schemaAiNonce, post_id: postId } ),
						} );
						const schemaData = await schemaRes.json();
						if ( schemaData.success && schemaData.data?.type ) {
							const s = schemaData.data;
							const updates = { type: s.type };
							if ( s.howto )          updates.howto          = { ...schema.howto,          ...s.howto          };
							if ( s.product )        updates.product        = { ...schema.product,        ...s.product        };
							if ( s.event )          updates.event          = { ...schema.event,          ...s.event          };
							if ( s.local_business ) updates.local_business = { ...schema.local_business, ...s.local_business };
							if ( s.video )          updates.video          = { ...schema.video,          ...s.video          };
							if ( s.review )         updates.review         = { ...schema.review,         ...s.review         };
							// Use setMeta directly with explicit newAeo/newSeo rather than
							// updateSchema(), which closes over stale meta and would clobber
							// the AEO and SEO fields written earlier in this flow.
							setMeta( { ...meta, _wppugmill_aeo: newAeo, _wppugmill_seo: newSeo, _wppugmill_schema: JSON.stringify( { ...schema, ...updates } ) } );
						}

						fetchUsage();
						setGenerateAllSuccess( true );
						setTimeout( () => setGenerateAllSuccess( false ), 5000 );
					} catch ( err ) {
						setGenerateAllError( err?.message || 'Network error. Please check your connection and try again.' );
					} finally {
						setGenerateAllLoading( false );
					}
				} }
				style={ { width: '100%', justifyContent: 'center', marginTop: '12px', ...BUTTON_STYLE, ...( ! IS_AI_MODE ? { opacity: 0.4 } : {} ) } }
			>
				{ generateAllLoading ? 'Generating…' : '✨ Generate All' }
			</Button>
			{ IS_AI_MODE && <UsageMeter usage={ usage } /> }
			{ IS_AI_MODE && (
				<p style={ { margin: '6px 0 0', fontSize: '11px', color: '#9ca3af', lineHeight: '1.5', textAlign: 'center' } }>
					Finish editing your content before generating — the AI reads your current draft.
				</p>
			) }

			{ /* ── AEO section ───────────────────────────────────────────────── */ }
			<SectionHeader label="AEO" />

			{ /* AI Summary */ }
			<PanelBody title={ <span>AI Summary<Tick show={ summaryOk } /></span> } initialOpen={ false }>
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
						onClick={ () => ajaxGenerate( 'wppugmill_generate_summary', summaryNonce, setSummaryState, ( d ) => updateAeo( { summary: d.summary } ) ) }
					/>
				</div>
			</PanelBody>

			{ /* Q&A Pairs */ }
			<PanelBody title={ <span>Q&A Pairs ({ aeo.questions.length })<Tick show={ qaOk } /></span> } initialOpen={ false }>
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
						onClick={ () => ajaxGenerate( 'wppugmill_generate_qa', qaNonce, setQaState, ( d ) => updateAeo( { questions: d.questions } ) ) }
					/>
				</div>
			</PanelBody>

			{ /* Named Entities */ }
			<PanelBody title={ <span>Named Entities ({ aeo.entities.length })<Tick show={ entitiesOk } /></span> } initialOpen={ false }>
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
						onClick={ () => ajaxGenerate( 'wppugmill_generate_entities', entitiesNonce, setEntitiesState, ( d ) => updateAeo( { entities: d.entities } ) ) }
					/>
				</div>
			</PanelBody>

			{ /* Keywords */ }
			<PanelBody title={ <span>Keywords { keywordsOk ? <Tick show={ true } /> : <span style={ { fontSize: '11px', fontWeight: '400', color: '#999' } }>({ aeo.keywords.length }/10)</span> }</span> } initialOpen={ false }>
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
						onClick={ () => ajaxGenerate( 'wppugmill_generate_keywords', keywordsNonce, setKeywordsState, ( d ) => updateAeo( { keywords: d.keywords } ) ) }
					/>
				</div>
			</PanelBody>

			<SchemaBuilder />

			{ /* ── SEO section ───────────────────────────────────────────────── */ }
			<SectionHeader label="SEO" />
			<SeoPanel />

			{ /* Featured Image Alt Text */ }
			{ featuredImageId ? <FeaturedImageAlt featuredImageId={ featuredImageId } initialAlt={ featuredMediaAltText } /> : null }

			{ /* ── Refine Content section ────────────────────────────────── */ }
			<SectionHeader label="Refine Content" />

			{ /* Tone Check */ }
			<ToneCheckPanel
				open={ toneOpen }
				onToggle={ () => setToneOpen( ! toneOpen ) }
				loading={ toneLoading }
				error={ toneError }
				results={ toneResults }
				applied={ toneApplied }
				swapErrs={ toneSwapErrs }
				onCheck={ runToneCheck }
				onApplyFix={ applyToneFix }
				onDismissError={ () => setToneError( '' ) }
				onDismissAll={ () => { setToneResults( null ); setToneApplied( {} ); setToneSwapErrs( {} ); } }
				locked={ ! IS_AI_MODE }
			/>

			{ /* Topic Focus */ }
			<PanelBody title="Topic Focus" opened={ topicOpen } onToggle={ () => setTopicOpen( ! topicOpen ) }>
					{ topicState.error && (
						<Notice status="error" isDismissible={ false } style={ { marginBottom: '8px' } }>{ topicState.error }</Notice>
					) }
					{ topicState.result && ( () => {
						const s = topicState.result.score;
						const c = s >= 4 ? '#46b450' : s >= 3 ? '#ffb900' : '#dc3232';
						return (
							<div style={ { marginBottom: '8px' } }>
								<div style={ { display: 'flex', justifyContent: 'space-between', alignItems: 'baseline', marginBottom: '6px' } }>
									<span style={ { fontSize: '12px', color: '#555' } }>{ topicState.result.topic }</span>
									<span style={ { fontSize: '13px', fontWeight: '700', color: c } }>
										{ s }<span style={ { fontSize: '11px', fontWeight: '400', color: '#666' } }>/5</span>
									</span>
								</div>
								<div style={ { background: '#e0e0e0', borderRadius: '3px', height: '6px', overflow: 'hidden', marginBottom: '6px' } }>
									<div style={ { width: `${ 20 * s }%`, height: '100%', background: c, borderRadius: '3px' } } />
								</div>
								<p style={ { fontSize: '12px', color: '#555', margin: '0 0 8px' } }>{ topicState.result.note }</p>
								{ s < 5 && (
									<Button
										variant="secondary"
										isBusy={ refineState.loading }
										disabled={ ! IS_AI_MODE || refineState.loading }
										onClick={ () => {
											setSwapStates( {} );
											ajaxFetch( 'wppugmill_refine_focus', refineFocusNonce, setRefineState );
										} }
										style={ { width: '100%', justifyContent: 'center', ...BUTTON_STYLE, ...( ! IS_AI_MODE ? { opacity: 0.4 } : {} ) } }
									>
										{ refineState.loading ? 'Working…' : '🎯 Refine Focus' }
									</Button>
								) }
							</div>
						);
					} )() }
					{ refineState.error && (
						<Notice status="error" isDismissible={ false } style={ { marginBottom: '8px' } }>{ refineState.error }</Notice>
					) }
					{ refineState.result && (
						<div style={ { marginBottom: '8px' } }>
							{ ( refineState.result.issues || [] ).map( ( issue, i ) => {
								const state = swapStates[ i ];
								return (
									<div key={ i } style={ {
										padding:      '8px',
										background:   '#fff8e1',
										borderLeft:   '3px solid ' + ( state === 'done' ? '#46b450' : '#ffb900' ),
										borderRadius: '0 3px 3px 0',
										marginBottom: '6px',
									} }>
										<p style={ { fontSize: '12px', fontWeight: '600', color: '#1e1e1e', margin: '0 0 4px' } }>{ issue.label }</p>
										{ issue.passage && (
											<div style={ { display: 'flex', alignItems: 'flex-start', gap: '6px', margin: '0 0 4px' } }>
												<p style={ { fontSize: '11px', color: '#555', fontStyle: 'italic', margin: 0, lineHeight: '1.4', flex: 1 } }>
													"{ issue.passage }"
												</p>
												<button
													onClick={ () => window.find( issue.passage, false, false, true ) }
													style={ { fontSize: '10px', padding: '1px 6px', background: '#f0f0f0', border: '1px solid #ccc', borderRadius: '3px', cursor: 'pointer', whiteSpace: 'nowrap', flexShrink: 0 } }
												>
													Find
												</button>
											</div>
										) }
										<p style={ { fontSize: '12px', color: '#1e1e1e', margin: '0 0 6px', lineHeight: '1.4' } }>
											{ issue.recommendation }
										</p>
										{ state === 'done' ? (
											<span style={ { fontSize: '11px', color: '#46b450', fontWeight: '600' } }>✓ Applied</span>
										) : (
											<Button
												variant="secondary"
												isBusy={ state === 'loading' }
												disabled={ state === 'loading' }
												onClick={ () => swapFocusPassage( issue, i ) }
												style={ { fontSize: '11px', padding: '0 12px', ...BUTTON_STYLE } }
											>
												{ state === 'loading' ? 'Rewriting…' : '✏ Rewrite' }
											</Button>
										) }
										{ state && state !== 'done' && state !== 'loading' && (
											<p style={ { fontSize: '11px', color: '#dc3232', margin: '4px 0 0' } }>{ state }</p>
										) }
									</div>
								);
							} ) }
						</div>
					) }
					<Button
						variant="secondary"
						isBusy={ topicState.loading }
						disabled={ ! IS_AI_MODE || topicState.loading }
						onClick={ runTopicFocus }
						style={ { width: '100%', justifyContent: 'center', ...BUTTON_STYLE, ...( ! IS_AI_MODE ? { opacity: 0.4 } : {} ) } }
					>
						{ topicState.loading ? 'Analyzing…' : '🎯 Analyze Topic Focus' }
					</Button>
			</PanelBody>

			{ /* Internal Links */ }
			<PanelBody title="Internal Links" opened={ linksOpen } onToggle={ () => setLinksOpen( ! linksOpen ) }>
					<p style={ { fontSize: '12px', color: '#555', margin: '0 0 10px' } }>
						Suggests internal linking opportunities based on your published posts.
					</p>
					{ linksState.error && (
						<Notice status="error" isDismissible={ false } style={ { marginBottom: '8px' } }>{ linksState.error }</Notice>
					) }
					{ linksState.result && linksState.result.links.length === 0 && (
						<p style={ { fontSize: '12px', color: '#666', marginBottom: '8px' } }>
							No strong internal linking opportunities found for this post.
						</p>
					) }
					{ linksState.result?.links.length > 0 && (
						<div style={ { marginBottom: '8px' } }>
							{ linksState.result.links.map( ( link, i ) => {
								const inserted = linkInserted[ i ];
								return (
									<div key={ i } style={ {
										padding:      '8px',
										background:   '#fff8e1',
										borderLeft:   '3px solid ' + ( inserted === 'done' ? '#46b450' : '#ffb900' ),
										borderRadius: '0 3px 3px 0',
										marginBottom: '6px',
									} }>
										<p style={ { fontSize: '12px', fontWeight: '600', color: '#1e1e1e', margin: '0 0 4px' } }>
											{ link.title } <span style={ { fontWeight: '400', color: '#555' } }>→ <em>{ link.anchorText }</em></span>
										</p>
										<div style={ { display: 'flex', alignItems: 'flex-start', gap: '6px', margin: '0 0 6px' } }>
											<p style={ { fontSize: '11px', color: '#555', fontStyle: 'italic', margin: 0, lineHeight: '1.4', flex: 1 } }>
												"{ link.context }"
											</p>
											<button
												onClick={ () => window.find( link.context, false, false, true ) }
												style={ { fontSize: '10px', padding: '1px 6px', background: '#f0f0f0', border: '1px solid #ccc', borderRadius: '3px', cursor: 'pointer', whiteSpace: 'nowrap', flexShrink: 0 } }
											>
												Find
											</button>
										</div>
										{ inserted === 'done' ? (
											<span style={ { fontSize: '11px', color: '#46b450', fontWeight: '600' } }>✓ Inserted</span>
										) : (
											<div style={ { display: 'flex', gap: '10px', alignItems: 'center' } }>
												<Button
													variant="secondary"
													onClick={ () => insertLink( link, i ) }
													style={ { fontSize: '11px', padding: '0 12px', ...BUTTON_STYLE } }
												>
													⇄ Insert Link
												</Button>
												<button type="button" onClick={ () => navigator.clipboard?.writeText( `<a href="${ link.url }">${ link.anchorText }</a>` ) }
													style={ { fontSize: '11px', color: '#555', background: 'none', border: 'none', padding: 0, cursor: 'pointer', textDecoration: 'underline' } }>
													Copy HTML
												</button>
											</div>
										) }
										{ inserted && inserted !== 'done' && (
											<p style={ { fontSize: '11px', color: '#dc3232', margin: '4px 0 0' } }>{ inserted }</p>
										) }
									</div>
								);
							} ) }
						</div>
					) }
					<Button
						variant="secondary"
						isBusy={ linksState.loading }
						disabled={ ! IS_AI_MODE || linksState.loading }
						onClick={ runInternalLinks }
						style={ { width: '100%', justifyContent: 'center', ...BUTTON_STYLE, ...( ! IS_AI_MODE ? { opacity: 0.4 } : {} ) } }
					>
						{ linksState.loading ? 'Finding links…' : '🔗 Find Internal Links' }
					</Button>
			</PanelBody>

			{ /* Reading Level */ }
			<PanelBody title="Reading Level" opened={ readingOpen } onToggle={ () => setReadingOpen( ! readingOpen ) }>
					{ readingState.error && (
						<Notice status="error" isDismissible={ false } style={ { marginBottom: '8px' } }>{ readingState.error }</Notice>
					) }
					{ readingState.result && (
						<div style={ { marginBottom: '8px' } }>
							<p style={ { margin: '0 0 4px', fontSize: '13px' } }>
								<strong>{ readingState.result.level }</strong>
								<span style={ { color: '#888', fontSize: '11px', marginLeft: '6px' } }>
									Grade { readingState.result.gradeLevel }
								</span>
							</p>
							<p style={ { margin: '0 0 4px', fontSize: '12px', color: '#555' } }>{ readingState.result.note }</p>
							{ readingState.result.fit && (
								<p style={ { margin: '0 0 4px', fontSize: '11px', color: '#777' } }>
									{ readingState.result.fit }
								</p>
							) }
							<p style={ { margin: '0', fontSize: '11px', color: '#999' } }>
								To adjust the reading level, use the Tone Check or Topic Focus panels to refine specific passages.
							</p>
						</div>
					) }
					<Button
						variant="secondary"
						isBusy={ readingState.loading }
						disabled={ ! IS_AI_MODE || readingState.loading }
						onClick={ runReadingLevel }
						style={ { width: '100%', justifyContent: 'center', ...BUTTON_STYLE, ...( ! IS_AI_MODE ? { opacity: 0.4 } : {} ) } }
					>
						{ readingState.loading ? 'Analyzing…' : '📖 Analyze Reading Level' }
					</Button>
			</PanelBody>

			{ /* Suggest Titles */ }
			<PanelBody title="Suggest Titles" opened={ titlesOpen } onToggle={ () => setTitlesOpen( ! titlesOpen ) }>
					<p style={ { fontSize: '12px', color: '#555', margin: '0 0 10px' } }>
						Generate a curiosity-driven and a utility-driven alternative to your current title.
					</p>
					{ headlineState.error && (
						<Notice status="error" isDismissible={ false } style={ { marginBottom: '8px' } }>{ headlineState.error }</Notice>
					) }
					{ headlineState.result && (
						<div style={ { marginBottom: '8px' } }>
							{ [ { key: 'curiosity', label: 'Curiosity' }, { key: 'utility', label: 'Utility' } ].map( ( { key, label } ) => (
								<div key={ key } style={ { border: '1px solid #e0e0e0', borderRadius: '4px', padding: '8px 10px', marginBottom: '8px', background: '#fff' } }>
									<p style={ { fontSize: '11px', color: '#999', margin: '0 0 3px', textTransform: 'uppercase', letterSpacing: '0.05em' } }>{ label }</p>
									<p style={ { fontSize: '12px', color: '#333', margin: '0 0 6px' } }>{ headlineState.result[ key ] }</p>
									{ headlineApplied[ key ] ? (
										<span style={ { fontSize: '11px', color: '#46b450', fontWeight: '600' } }>✓ Applied</span>
									) : (
										<Button
											variant="secondary"
											onClick={ () => {
												editPost( { title: headlineState.result[ key ] } );
												window.wp.data.dispatch( 'core/editor' ).savePost();
												setHeadlineApplied( ( prev ) => ( { ...prev, [ key ]: true } ) );
											} }
											style={ { fontSize: '11px', padding: '0 12px', ...BUTTON_STYLE } }
										>
											Use this title →
										</Button>
									) }
								</div>
							) ) }
						</div>
					) }
					<div style={ { marginTop: '8px' } }>
						<AiPill
							label="Generate"
							isActive={ headlineState.loading }
							anyPending={ headlineState.loading }
							locked={ ! IS_AI_MODE }
							onClick={ runSuggestTitles }
						/>
					</div>
			</PanelBody>

			{ /* Excerpt Generator */ }
			<PanelBody
					title={
						postExcerpt && postExcerpt.trim()
							? <span>Excerpt Generator <span style={ { color: '#46b450', fontWeight: '700' } }>✓</span></span>
							: 'Excerpt Generator'
					}
					opened={ excerptOpen }
					onToggle={ () => setExcerptOpen( ! excerptOpen ) }
				>
					{ excerptState.error && (
						<Notice status="error" isDismissible={ false } style={ { marginBottom: '8px' } }>{ excerptState.error }</Notice>
					) }
					{ excerptState.result && (
						<div style={ { marginBottom: '8px' } }>
							<p style={ {
								fontSize:     '12px',
								color:        '#333',
								background:   '#f6f7f7',
								border:       '1px solid #e0e0e0',
								borderRadius: '4px',
								padding:      '8px',
								margin:       '0 0 6px',
							} }>
								{ excerptState.result.excerpt }
							</p>
							<p style={ { fontSize: '11px', color: '#999', margin: '0 0 6px' } }>
								{ excerptState.result.excerpt.length } chars
							</p>
							{ excerptApplied ? (
								<span style={ { fontSize: '11px', color: '#46b450', fontWeight: '600' } }>✓ Applied</span>
							) : (
								<Button
									variant="secondary"
									onClick={ () => {
										editPost( { excerpt: excerptState.result.excerpt } );
										window.wp.data.dispatch( 'core/editor' ).savePost();
										setExcerptApplied( true );
									} }
									style={ { fontSize: '11px', padding: '0 12px', ...BUTTON_STYLE } }
								>
									Apply to excerpt →
								</Button>
							) }
						</div>
					) }
					<div style={ { marginTop: '8px' } }>
						<AiPill
							label="Generate"
							isActive={ excerptState.loading }
							anyPending={ excerptState.loading }
							locked={ ! IS_AI_MODE }
							onClick={ runSuggestExcerpt }
						/>
					</div>
			</PanelBody>

			{ /* ── Distribute section ───────────────────────────────────── */ }
			<SectionHeader label="Distribute" />

			{ /* Social Media Draft */ }
			<SocialDraftPanel
				open={ socialOpen }
				onToggle={ () => setSocialOpen( ! socialOpen ) }
				state={ socialState }
				onStateChange={ setSocialState }
				onGenerate={ runSocialDraft }
				locked={ ! IS_AI_MODE }
			/>

		</PluginDocumentSettingPanel>
	);
}
