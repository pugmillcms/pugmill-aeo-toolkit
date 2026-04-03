/**
 * WP Pugmill — Main sidebar panel component.
 *
 * Registers as a PluginDocumentSettingPanel (shown in the block editor's
 * Document sidebar). Contains all AEO and SEO editing controls, the score
 * bar, all AI generation buttons, and the Audit panel.
 *
 * @package WPPugmill
 */

import { PluginDocumentSettingPanel } from '@wordpress/edit-post';
import { PanelBody, Button, Notice, TextControl, TextareaControl, SelectControl } from '@wordpress/components';
import { useSelect, useDispatch } from '@wordpress/data';
import { useState, useCallback, useEffect } from '@wordpress/element';
import { rawHandler } from '@wordpress/blocks';

import { useAeoMeta } from '../hooks';
import { useSeoMeta } from '../hooks';
import { PugmillLogo }  from './Logo';
import { SectionHeader } from './SectionHeader';
import { SeoPanel }     from './SeoPanel';
import { SchemaBuilder } from './SchemaBuilder';
import { AiInput, AiPill } from './AiInput';
import { ScoreDisplay } from './ScoreDisplay';
import { saveIfDirty }  from '../utils';
import { computeScore } from '../scoring';
import {
	IS_AI_MODE,
	BUTTON_STYLE,
	ENTITY_TYPE_OPTIONS,
	SOCIAL_PLATFORMS,
	AUDIT_FIX_ACTIONS,
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
	pricingUrl,
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

/** Green checkmark rendered next to a panel title when the field is complete. */
function Tick( { show } ) {
	if ( ! show ) return null;
	return <span style={ { color: '#46b450', fontWeight: '700', marginLeft: '3px' } }>✓</span>;
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
		const action = AUDIT_FIX_ACTIONS[ checkId ];
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

	/** POST to AJAX (no draft_content), saves first if dirty, then puts result in state. */
	const ajaxFetch = useCallback( async ( ajaxAction, actionNonce, setState ) => {
		setState( { loading: true, error: '', result: null } );
		try {
			await saveIfDirty();
			const res  = await fetch( ajaxUrl, {
				method:  'POST',
				headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
				body:    new URLSearchParams( { action: ajaxAction, nonce: actionNonce, post_id: postId } ),
			} );
			const data = await res.json();
			if ( ! data.success ) throw new Error( data.data?.message || data.data || 'Request failed. Please try again.' );
			setState( { loading: false, error: '', result: data.data } );
		} catch ( err ) {
			setState( { loading: false, error: err.message, result: null } );
		}
		fetchUsage();
	}, [ postId, fetchUsage ] );

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
			setToneSwapErrs( ( prev ) => ( { ...prev, [ index ]: 'Could not locate that passage in the editor — the post may have been edited since the check ran. The fix was not applied.' } ) );
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
			if ( ! applied ) throw new Error( 'Original passage not found in post content. Edit manually.' );
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
				// 3. Case-insensitive fallback — context found but anchor text may differ in case.
				//    Replaces in raw HTML first to preserve inline formatting.
				if ( normalize( plain ).includes( normalize( link.context ) ) ) {
					const caseRe = new RegExp( link.anchorText.replace( /[.*+?^${}()|[\]\\]/g, '\\$&' ), 'i' );
					updateBlockAttributes( block.clientId, {
						content: caseRe.test( raw ) ? raw.replace( caseRe, anchor ) : plain.replace( link.anchorText, anchor ),
					} );
					setLinkInserted( ( prev ) => ( { ...prev, [ index ]: 'done' } ) );
					window.wp.data.dispatch( 'core/editor' ).savePost();
					return;
				}
			}
			setLinkInserted( ( prev ) => ( { ...prev, [ index ]: 'Anchor text not found in content. Copy and insert manually.' } ) );
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
			await saveIfDirty();
			const res  = await fetch( ajaxUrl, {
				method:  'POST',
				headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
				body:    new URLSearchParams( { action: 'wppugmill_tone_check', nonce: toneNonce, post_id: postId } ),
			} );
			const data = await res.json();
			if ( ! data.success ) throw new Error( data.data?.message || data.data || 'Tone check failed. Please try again.' );
			setToneResults( data.data.items || [] );
		} catch ( err ) {
			setToneError( err.message );
		} finally {
			setToneLoading( false );
		}
	}, [ postId ] );

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

	// Map of action IDs → trigger functions, passed to AiInput so the Ask bar
	// can open and run any panel action.
	const aiPanelActions = {
		tone_check:     ( _action )  => runToneCheck(),
		reading_level:  ( _action )  => runReadingLevel(),
		topic_focus:    ( _action )  => runTopicFocus(),
		internal_links: ( _action )  => runInternalLinks(),
		suggest_titles: ( _action )  => runSuggestTitles(),
		suggest_excerpt: ( _action ) => runSuggestExcerpt(),
		social_draft:   ( action )   => runSocialDraft( action.params?.platform || 'twitter' ),
	};

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

			<AiInput onUsageChange={ fetchUsage } onAction={ aiPanelActions } />

			{ /* ── Content section (AI mode only) ──────────────────────────── */ }
			{ IS_AI_MODE && <SectionHeader label="Content" /> }

			{ /* Tone Check */ }
			{ IS_AI_MODE && (
				<PanelBody title="Tone Check" opened={ toneOpen } onToggle={ () => setToneOpen( ! toneOpen ) }>
					<p style={ { fontSize: '12px', color: '#555', margin: '0 0 10px' } }>
						Checks the post against your <strong>Author Voice guide</strong> and flags passages that deviate.
						Set your voice guide in <strong>Settings → WP Pugmill</strong>.
					</p>
					{ toneError && (
						<Notice status="error" isDismissible onDismiss={ () => setToneError( '' ) } style={ { marginBottom: '8px' } }>
							{ toneError }
						</Notice>
					) }
					{ toneResults !== null && toneResults.length === 0 && (
						<Notice status="success" isDismissible onDismiss={ () => setToneResults( null ) } style={ { marginBottom: '8px' } }>
							Content matches your voice guide well.
						</Notice>
					) }
					{ toneResults && toneResults.length > 0 && (
						<div style={ { marginBottom: '8px' } }>
							{ toneResults.map( ( item, i ) => (
								<div key={ i } style={ {
									padding:      '8px',
									background:   '#fff8e1',
									borderLeft:   '3px solid ' + ( toneApplied[ i ] ? '#46b450' : '#d97706' ),
									borderRadius: '0 3px 3px 0',
									marginBottom: '6px',
								} }>
									<p style={ { fontSize: '12px', fontWeight: '600', color: '#1e1e1e', margin: '0 0 4px' } }>{ item.issue }</p>
									<div style={ { display: 'flex', alignItems: 'flex-start', gap: '6px', margin: '0 0 4px' } }>
										<p style={ { fontSize: '11px', color: '#555', fontStyle: 'italic', margin: 0, lineHeight: '1.4', flex: 1 } }>
											"{ item.quote }"
										</p>
										<button
											onClick={ () => window.find( item.quote, false, false, true ) }
											style={ { fontSize: '10px', padding: '1px 6px', background: '#f0f0f0', border: '1px solid #ccc', borderRadius: '3px', cursor: 'pointer', whiteSpace: 'nowrap', flexShrink: 0 } }
										>
											Find
										</button>
									</div>
									<p style={ { fontSize: '12px', color: '#1e1e1e', margin: '0 0 6px', lineHeight: '1.4' } }>{ item.suggestion }</p>
									{ toneApplied[ i ] ? (
										<span style={ { fontSize: '11px', color: '#46b450', fontWeight: '600' } }>✓ Applied</span>
									) : (
										<Button
											variant="secondary"
											onClick={ () => applyToneFix( item.quote, item.suggestion, i ) }
											style={ { fontSize: '11px', padding: '0 12px', ...BUTTON_STYLE } }
										>
											⇄ Apply Fix
										</Button>
									) }
									{ toneSwapErrs[ i ] && (
										<p style={ { fontSize: '11px', color: '#dc3232', margin: '4px 0 0' } }>{ toneSwapErrs[ i ] }</p>
									) }
								</div>
							) ) }
							<button type="button" onClick={ () => { setToneResults( null ); setToneApplied( {} ); setToneSwapErrs( {} ); } } style={ { fontSize: '11px', color: '#999', background: 'none', border: 'none', padding: 0, cursor: 'pointer' } }>
								Dismiss all
							</button>
						</div>
					) }
					<Button
						variant="secondary"
						isBusy={ toneLoading }
						disabled={ toneLoading }
						onClick={ runToneCheck }
						style={ { width: '100%', justifyContent: 'center', ...BUTTON_STYLE } }
					>
						{ toneLoading ? 'Checking…' : '🎨 Check Tone' }
					</Button>
				</PanelBody>
			) }

			{ /* Topic Focus */ }
			{ IS_AI_MODE && (
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
										disabled={ refineState.loading }
										onClick={ () => {
											setSwapStates( {} );
											ajaxFetch( 'wppugmill_refine_focus', refineFocusNonce, setRefineState );
										} }
										className={ refineState.loading ? 'wppugmill-loading' : '' }
										style={ { width: '100%', justifyContent: 'center', ...BUTTON_STYLE } }
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
												disabled={ state === 'loading' }
												onClick={ () => swapFocusPassage( issue, i ) }
												style={ { fontSize: '11px', padding: '0 12px', ...BUTTON_STYLE } }
											>
												{ state === 'loading' ? 'Rewriting…' : '⇄ Swap Content' }
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
						disabled={ topicState.loading }
						onClick={ runTopicFocus }
						style={ { width: '100%', justifyContent: 'center', ...BUTTON_STYLE } }
					>
						{ topicState.loading ? 'Analyzing…' : '🎯 Analyze Topic Focus' }
					</Button>
				</PanelBody>
			) }

			{ /* Internal Links */ }
			{ IS_AI_MODE && (
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
						disabled={ linksState.loading }
						onClick={ runInternalLinks }
						style={ { width: '100%', justifyContent: 'center', ...BUTTON_STYLE } }
					>
						{ linksState.loading ? 'Finding links…' : '🔗 Find Internal Links' }
					</Button>
				</PanelBody>
			) }


			{ /* Reading Level */ }
			{ IS_AI_MODE && (
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
								To adjust the reading level, use the <strong>Ask</strong> bar with instructions like "simplify for a general audience".
							</p>
						</div>
					) }
					<Button
						variant="secondary"
						isBusy={ readingState.loading }
						disabled={ readingState.loading }
						onClick={ runReadingLevel }
						style={ { width: '100%', justifyContent: 'center', ...BUTTON_STYLE } }
					>
						{ readingState.loading ? 'Analyzing…' : '📖 Analyze Reading Level' }
					</Button>
				</PanelBody>
			) }

			{ /* ── AEO Health ─────────────────────────────────────────────── */ }
			{ ( () => {
				const { score, grade, color, items } = computeScore( aeo, seo, { postContent: draftContent, featuredImageAlt: featuredMediaAltText } );
				return (
					<PanelBody title="AEO Health" initialOpen={ true }>
						<ScoreDisplay score={ score } grade={ grade } color={ color } />
						<div style={ { marginTop: '6px' } }>
							{ items.map( ( item ) => {
								const fixState = healthFixStates[ item.id ];
								const fixAction = AUDIT_FIX_ACTIONS[ item.id ];
								return (
									<div key={ item.id } style={ {
										display:       'flex',
										alignItems:    'flex-start',
										gap:           '6px',
										padding:       '5px 0',
										borderBottom:  '1px solid #f0f0f0',
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
												fontSize:       '12px',
												color:          item.pass ? '#1e1e1e' : '#555',
												fontWeight:     item.pass ? '400' : '400',
												display:        'block',
												lineHeight:     '1.4',
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
															onClick={ () => handleHealthFix( item.id ) }
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
			} )() }

			{ /* ── Generate All (AI mode) ───────────────────────────────────── */ }
			{ IS_AI_MODE ? (
				<>
					{ generateAllError && (
						<Notice status="error" isDismissible={ false } style={ { marginTop: '8px' } }>
							{ generateAllError }
						</Notice>
					) }
					{ generateAllSuccess && (
						<Notice status="success" isDismissible={ false } style={ { marginTop: '8px' } }>
							Fields generated — review and save.
						</Notice>
					) }
					<Button
						variant="primary"
						isBusy={ generateAllLoading }
						disabled={ generateAllLoading }
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
									body:    new URLSearchParams( { action: 'wppugmill_internal_links', nonce: internalLinksNonce, post_id: postId } ),
								} );
								const linksData = await linksRes.json();
								if ( linksData.success ) {
									setLinksState( { loading: false, error: '', result: linksData.data } );
									setLinkInserted( {} );
								}

								fetchUsage();
								setGenerateAllSuccess( true );
								setTimeout( () => setGenerateAllSuccess( false ), 5000 );
							} catch {
								setGenerateAllError( 'Network error. Please check your connection and try again.' );
							} finally {
								setGenerateAllLoading( false );
							}
						} }
						style={ { width: '100%', justifyContent: 'center', marginTop: '12px', ...BUTTON_STYLE } }
					>
						{ generateAllLoading ? 'Generating…' : '✨ Generate All' }
					</Button>

					{ /* Usage meter */ }
					{ ( () => {
						const { count, limit } = usage;
						const pct    = Math.min( ( count / limit ) * 100, 100 );
						const meterColor = count >= 40 ? '#dc3232' : count >= 30 ? '#e65c00' : count >= 20 ? '#ffb900' : '#46b450';
						const label  = count >= limit
							? 'Limit reached — resets in under 1 hour'
							: `${ count } / ${ limit } AI calls this hour`;
						return (
							<div style={ { marginTop: '10px' } }>
								<div style={ { display: 'flex', justifyContent: 'space-between', alignItems: 'baseline', marginBottom: '4px' } }>
									<span style={ { fontSize: '11px', color: '#666' } }>AI usage</span>
									<span style={ { fontSize: '11px', fontWeight: '600', color: meterColor } }>{ label }</span>
								</div>
								<div style={ { background: '#e0e0e0', borderRadius: '3px', height: '5px', overflow: 'hidden' } }>
									<div style={ { width: `${ pct }%`, height: '100%', background: meterColor, borderRadius: '3px', transition: 'width 0.3s ease, background 0.3s ease' } } />
								</div>
							</div>
						);
					} )() }
				</>
			) : (
				<p style={ { fontSize: '11px', color: '#666', textAlign: 'center', marginTop: '10px' } }>
					Use the <strong>Generate</strong> buttons in each panel below, or{ ' ' }
					<a href={ pricingUrl } target="_blank" rel="noreferrer">upgrade to AI Connector</a>
					{ ' ' }to generate all fields at once.
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

			{ /* ── Write section (AI mode only) ────────────────────────────── */ }
			{ IS_AI_MODE && <SectionHeader label="Write" /> }

			{ /* Suggest Titles */ }
			{ IS_AI_MODE && (
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
							onClick={ runSuggestTitles }
						/>
					</div>
				</PanelBody>
			) }

			{ /* Excerpt Generator */ }
			{ IS_AI_MODE && (
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
							onClick={ runSuggestExcerpt }
						/>
					</div>
				</PanelBody>
			) }

			{ /* ── Distribute section (AI mode only) ──────────────────────── */ }
			{ IS_AI_MODE && <SectionHeader label="Distribute" /> }

			{ /* Social Media Draft */ }
			{ IS_AI_MODE && (
				<PanelBody title="Social Media Draft" opened={ socialOpen } onToggle={ () => setSocialOpen( ! socialOpen ) }>
					<p style={ { fontSize: '12px', color: '#555', margin: '0 0 10px' } }>
						Pick a platform — a draft is generated instantly using your AEO metadata.
					</p>
					<div style={ { display: 'flex', gap: '4px', marginBottom: '10px', flexWrap: 'wrap' } }>
						{ SOCIAL_PLATFORMS.map( ( { key, label } ) => (
							<button
								key={ key }
								type="button"
								disabled={ socialState.loading }
								onClick={ () => runSocialDraft( key ) }
								style={ {
									fontSize:    '12px',
									padding:     '4px 12px',
									borderRadius:'9999px',
									cursor:      socialState.loading ? 'not-allowed' : 'pointer',
									border:      '1px solid',
									borderColor: socialState.platform === key ? '#7c3aed' : '#ccc',
									background:  socialState.platform === key ? '#7c3aed' : '#fff',
									color:       socialState.platform === key ? '#fff' : '#1e1e1e',
									fontWeight:  socialState.platform === key ? '600' : '400',
									opacity:     socialState.loading && socialState.platform !== key ? 0.5 : 1,
								} }
							>
								{ socialState.loading && socialState.platform === key ? '…' : label }
							</button>
						) ) }
					</div>
					{ socialState.loading && (
						<div style={ { height: '6px', borderRadius: '3px', overflow: 'hidden', marginBottom: '10px' } }>
							<div className="wppugmill-loading" style={ { height: '100%', borderRadius: '3px' } } />
						</div>
					) }
					{ socialState.error && (
						<Notice status="error" isDismissible={ false } style={ { marginBottom: '8px' } }>{ socialState.error }</Notice>
					) }
					{ socialState.draft && ( () => {
						const platform = SOCIAL_PLATFORMS.find( ( p ) => p.key === socialState.platform );
						const len = socialState.draft.length;
						const over = platform && len > platform.limit;
						return (
							<div>
								<textarea
									value={ socialState.draft }
									onChange={ ( e ) => setSocialState( ( prev ) => ( { ...prev, draft: e.target.value } ) ) }
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
													const limit = platform.limit;
													let trimmed = socialState.draft.slice( 0, limit );
													const lastSpace = trimmed.lastIndexOf( ' ' );
													if ( lastSpace > 0 ) trimmed = trimmed.slice( 0, lastSpace );
													trimmed = trimmed.replace( /[,.\s;:]+$/, '' ) + '…';
													setSocialState( ( prev ) => ( { ...prev, draft: trimmed } ) );
												} }
												style={ { fontSize: '11px', color: '#dc3232', background: 'none', border: 'none', padding: 0, cursor: 'pointer', textDecoration: 'underline' } }
											>
												Trim to fit
											</button>
										) }
										<button
											type="button"
											onClick={ () => navigator.clipboard.writeText( socialState.draft ) }
											style={ { fontSize: '11px', color: '#007cba', background: 'none', border: 'none', padding: 0, cursor: 'pointer', textDecoration: 'underline' } }
										>
											Copy
										</button>
									</div>
								</div>
							</div>
						);
					} )() }
				</PanelBody>
			) }

		</PluginDocumentSettingPanel>
	);
}
