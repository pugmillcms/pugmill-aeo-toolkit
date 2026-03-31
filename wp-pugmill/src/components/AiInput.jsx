/**
 * WP Pugmill — Natural language AI input component.
 *
 * Single-shot intent routing: type what you want, get it done.
 * Routes to /wp-pugmill/v1/chat which classifies the intent and dispatches
 * the appropriate plugin action. No conversation history or thread UI.
 *
 * @package WPPugmill
 */

import { Button } from '@wordpress/components';
import { useSelect, useDispatch } from '@wordpress/data';
import { useState, useCallback } from '@wordpress/element';
import { rawHandler } from '@wordpress/blocks';
import apiFetch from '@wordpress/api-fetch';

import { useAeoMeta } from '../hooks';
import { useSeoMeta } from '../hooks';
import { saveIfDirty } from '../utils';
import {
	IS_AI_MODE,
	rewriteNonce,
	ajaxUrl,
	nonce,
	seoNonce,
	summaryNonce,
	qaNonce,
	entitiesNonce,
	keywordsNonce,
} from '../constants';

// AJAX actions for individual field generators.
const FIELD_ACTIONS = {
	generate_summary:  { ajaxAction: 'wppugmill_generate_summary',  actionNonce: summaryNonce  },
	generate_qa:       { ajaxAction: 'wppugmill_generate_qa',       actionNonce: qaNonce       },
	generate_entities: { ajaxAction: 'wppugmill_generate_entities', actionNonce: entitiesNonce },
	generate_keywords: { ajaxAction: 'wppugmill_generate_keywords', actionNonce: keywordsNonce },
};

/**
 * Compact AI action pill — matches Pugmill CMS AiBtn visual language.
 * Use for small inline actions; full-width <Button isBusy> remains for primary generates.
 *
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

export function AiInput( { onUsageChange, onAction } ) {
	const postId       = useSelect( ( s ) => s( 'core/editor' ).getCurrentPostId(),     [] );
	const draftContent = useSelect( ( s ) => s( 'core/editor' ).getEditedPostContent(), [] );

	const { aeo, updateAeo } = useAeoMeta();
	const { updateSeo }      = useSeoMeta();
	const { resetEditorBlocks } = useDispatch( 'core/editor' );

	const [ input,   setInput   ] = useState( '' );
	const [ status,  setStatus  ] = useState( null ); // null | { text, ok }
	const [ sending, setSending ] = useState( false );

	// ── Action executor (same dispatch logic as former AiAgent) ───────────────

	const executeAction = useCallback( async ( action ) => {
		const saveReminder = ' Save (Ctrl+S / ⌘S) before running the audit.';

		if ( action.id === 'run_audit' ) {
			const result = await apiFetch( { path: `/wp-pugmill/v1/audit/${ postId }` } );
			const failed = result.checks.filter( ( c ) => c.status === 'fail' ).length;
			const warned = result.checks.filter( ( c ) => c.status === 'warn' ).length;
			setStatus( {
				ok:   true,
				text: `Audit complete — ${ result.score }% (${ result.passed }/${ result.total } passed${ failed ? `, ${ failed } failed` : '' }${ warned ? `, ${ warned } warnings` : '' }). See the Audit panel for details.`,
			} );
			return;
		}

		if ( action.id === 'write_from_draft' ) {
			const prompt = action.params?.prompt || '';
			if ( ! prompt ) { setStatus( { ok: false, text: 'Please provide a topic or brief.' } ); return; }
			const res  = await fetch( ajaxUrl, {
				method: 'POST', credentials: 'same-origin',
				headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
				body: new URLSearchParams( { action: 'wppugmill_rewrite_draft', nonce: rewriteNonce, post_id: postId, draft_content: prompt } ),
			} );
			const data = await res.json();
			if ( ! data.success ) throw new Error( data.data?.message || 'Write failed.' );
			const d = data.data || {};
			if ( d.summary || d.primary_question ) {
				updateAeo( {
					summary:   d.summary || d.direct_answer || aeo.summary,
					questions: d.primary_question ? [ { q: d.primary_question, a: d.direct_answer || '' }, ...( aeo.questions || [] ) ] : aeo.questions,
					keywords:  d.keywords?.length ? d.keywords : aeo.keywords,
				} );
			}
			if ( d.context ) resetEditorBlocks( rawHandler( { HTML: d.context } ) );
			setStatus( { ok: true, text: '✓ Content written and loaded. Save (Ctrl+S / ⌘S) to preserve.' } );
			return;
		}

		if ( action.id === 'generate_seo' ) {
			const res  = await fetch( ajaxUrl, {
				method: 'POST', credentials: 'same-origin',
				headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
				body: new URLSearchParams( { action: 'wppugmill_generate_seo', nonce: seoNonce, post_id: postId, draft_content: draftContent } ),
			} );
			const data = await res.json();
			if ( ! data.success ) throw new Error( data.data?.message || 'SEO generation failed.' );
			updateSeo( { title: data.data.title, meta_desc: data.data.meta_desc } );
			setStatus( { ok: true, text: '✓ SEO title and meta description generated.' + saveReminder } );
			return;
		}

		if ( action.id === 'generate_all' ) {
			const aeoRes  = await fetch( ajaxUrl, {
				method: 'POST', credentials: 'same-origin',
				headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
				body: new URLSearchParams( { action: 'wppugmill_generate_aeo', nonce, post_id: postId, draft_content: draftContent } ),
			} );
			const aeoData = await aeoRes.json();
			if ( ! aeoData.success ) throw new Error( aeoData.data?.message || 'AEO generation failed.' );
			const a = aeoData.data || {};
			const updatedAeo = { ...aeo };
			if ( a.summary   !== undefined ) updatedAeo.summary   = a.summary;
			if ( a.questions !== undefined ) updatedAeo.questions = a.questions.map( ( q ) => ( { q: q.q || q.question || '', a: q.a || q.answer || '' } ) );
			if ( a.entities  !== undefined ) updatedAeo.entities  = a.entities;
			if ( a.keywords  !== undefined ) updatedAeo.keywords  = a.keywords;
			updateAeo( updatedAeo );

			const seoRes  = await fetch( ajaxUrl, {
				method: 'POST', credentials: 'same-origin',
				headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
				body: new URLSearchParams( { action: 'wppugmill_generate_seo', nonce: seoNonce, post_id: postId, draft_content: draftContent } ),
			} );
			const seoData = await seoRes.json();
			if ( ! seoData.success ) throw new Error( seoData.data?.message || 'SEO generation failed.' );
			updateSeo( { title: seoData.data.title, meta_desc: seoData.data.meta_desc } );
			setStatus( { ok: true, text: '✓ All SEO + AEO fields generated.' + saveReminder } );
			if ( onUsageChange ) onUsageChange();
			return;
		}

		// Delegate to parent-supplied panel triggers (analyze / write / distribute actions)
		if ( onAction?.[ action.id ] ) {
			await onAction[ action.id ]( action );
			return;
		}

		// Individual field generators
		const fieldAction = FIELD_ACTIONS[ action.id ];
		if ( ! fieldAction ) { setStatus( { ok: false, text: `Unknown action: ${ action.id }` } ); return; }
		const res  = await fetch( ajaxUrl, {
			method: 'POST', credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
			body: new URLSearchParams( { action: fieldAction.ajaxAction, post_id: postId, nonce: fieldAction.actionNonce, draft_content: draftContent } ),
		} );
		const data = await res.json();
		if ( ! data.success ) throw new Error( data.data?.message || 'Generation failed.' );
		const f = data.data || {};
		const updatedAeo = { ...aeo };
		if ( f.summary   !== undefined ) updatedAeo.summary   = f.summary;
		if ( f.questions !== undefined ) updatedAeo.questions = f.questions.map( ( q ) => ( { q: q.q || q.question || '', a: q.a || q.answer || '' } ) );
		if ( f.entities  !== undefined ) updatedAeo.entities  = f.entities;
		if ( f.keywords  !== undefined ) updatedAeo.keywords  = f.keywords;
		updateAeo( updatedAeo );
		const fieldLabel = action.id.replace( 'generate_', '' );
		setStatus( { ok: true, text: `✓ ${ fieldLabel.charAt( 0 ).toUpperCase() + fieldLabel.slice( 1 ) } generated.` + saveReminder } );
		if ( onUsageChange ) onUsageChange();
	}, [ postId, draftContent, aeo, updateAeo, updateSeo, resetEditorBlocks, onUsageChange, onAction ] );

	// ── Submit ─────────────────────────────────────────────────────────────────

	const submit = useCallback( async () => {
		const text = input.trim();
		if ( ! text || sending ) return;

		setSending( true );
		setStatus( { ok: true, text: '…' } );

		try {
			const result = await apiFetch( {
				path:   '/wp-pugmill/v1/chat',
				method: 'POST',
				data:   { post_id: postId, messages: [ { role: 'user', content: text } ] },
			} );
			setStatus( { ok: true, text: result.message } );
			if ( result.actions?.length ) {
				for ( const action of result.actions ) {
					await executeAction( action );
				}
			}
		} catch ( err ) {
			setStatus( { ok: false, text: err.message || 'Something went wrong. Please try again.' } );
		} finally {
			setSending( false );
			setInput( '' );
		}
	}, [ input, sending, postId, executeAction ] );

	if ( ! IS_AI_MODE ) return null;

	return (
		<div style={ {
			padding:      '10px 16px',
			borderBottom: '1px solid #e8e0f7',
			background:   '#faf7ff',
		} }>
			<div style={ { display: 'flex', gap: '6px', alignItems: 'center' } }>
				<input
					type="text"
					value={ input }
					onChange={ ( e ) => setInput( e.target.value ) }
					onKeyDown={ ( e ) => { if ( e.key === 'Enter' ) { e.preventDefault(); submit(); } } }
					placeholder="Ask the AI — rewrite, suggest excerpt, check tone, simplify…"
					disabled={ sending }
					style={ {
						flex:         1,
						fontSize:     '12px',
						padding:      '6px 8px',
						border:       '1px solid #d4c8f0',
						borderRadius: '4px',
						fontFamily:   'inherit',
						lineHeight:   '1.4',
						boxSizing:    'border-box',
						background:   '#fff',
					} }
				/>
				<Button
					variant="primary"
					onClick={ submit }
					disabled={ sending || ! input.trim() }
					style={ {
						fontSize:   '12px',
						padding:    '6px 14px',
						background: '#7c3aed',
						color:      '#fff',
						border:     'none',
						flexShrink: 0,
						height:     '30px',
					} }
				>
					{ sending ? '…' : 'Ask' }
				</Button>
			</div>


			{ status && (
				<p style={ {
					fontSize:   '11px',
					color:      status.ok ? ( status.text.startsWith( '✓' ) ? '#46b450' : '#555' ) : '#dc3232',
					margin:     '6px 0 0',
					lineHeight: '1.4',
				} }>
					{ status.text }
				</p>
			) }
		</div>
	);
}
