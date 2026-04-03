/**
 * WP Pugmill — SEO panel component.
 *
 * Renders the "SEO" PanelBody with SERP preview, title, meta description,
 * canonical URL, robots flags, OG image, and the AI generate button.
 *
 * @package WPPugmill
 */

import { PanelBody, Button, TextControl, TextareaControl, CheckboxControl } from '@wordpress/components';
import { useSelect } from '@wordpress/data';
import { useState }  from '@wordpress/element';

import { useAeoMeta } from '../hooks';
import { useSeoMeta } from '../hooks';
import { GooglePreview } from './GooglePreview';
import {
	IS_AI_MODE,
	BUTTON_STYLE,
	SEO_TITLE_MAX,
	SEO_DESC_MAX,
	ajaxUrl,
	seoNonce,
} from '../constants';

/**
 * Returns a color based on how close a character count is to its limit.
 * Neutral at 0, warning near the limit, red over it.
 */
function countColor( count, max ) {
	if ( count === 0 )       return '#999';
	if ( count > max )       return '#dc3232';
	if ( count > max * 0.9 ) return '#ffb900';
	return '#46b450';
}

export function SeoPanel() {
	const { seo, updateSeo }  = useSeoMeta();
	const { aeo, postId }     = useAeoMeta();

	const postTitle   = useSelect( ( s ) => s( 'core/editor' ).getEditedPostAttribute( 'title' ),   [] );
	const postLink    = useSelect( ( s ) => s( 'core/editor' ).getEditedPostAttribute( 'link' ),    [] );
	const postExcerpt = useSelect( ( s ) => s( 'core/editor' ).getEditedPostAttribute( 'excerpt' ), [] );

	const featuredImageId     = useSelect( ( s ) => s( 'core/editor' ).getEditedPostAttribute( 'featured_media' ), [] );
	const featuredMediaRecord = useSelect( ( s ) => featuredImageId ? s( 'core' ).getMedia( featuredImageId ) : null, [ featuredImageId ] );
	const featuredImageUrl    = featuredMediaRecord?.source_url || '';

	const titleLen = ( seo.title    || '' ).length;
	const descLen  = ( seo.meta_desc || '' ).length;

	const [ generateState, setGenerateState ] = useState( { loading: false, error: '' } );

	// Panel title shows a green tick when both SEO fields are filled.
	const panelTitle = seo.title && seo.title.trim() && seo.meta_desc && seo.meta_desc.trim()
		? <span>SEO <span style={ { color: '#46b450', fontWeight: '700' } }>✓</span></span>
		: 'SEO';

	return (
		<PanelBody title={ panelTitle } initialOpen={ false }>
			<p style={ {
				fontSize:      '10px',
				fontWeight:    '600',
				color:         '#999',
				textTransform: 'uppercase',
				letterSpacing: '0.06em',
				margin:        '4px 0 6px',
			} }>
				Search Preview
			</p>

			<GooglePreview
				seo={ seo }
				aeo={ aeo }
				postTitle={ postTitle }
				postLink={ postLink }
				postExcerpt={ postExcerpt }
			/>

			{ /* SEO Title */ }
			<div style={ { marginBottom: '12px' } }>
				<TextControl
					label="SEO Title"
					placeholder="Leave blank to use post title"
					value={ seo.title }
					onChange={ ( val ) => updateSeo( { title: val } ) }
				/>
				<div style={ { display: 'flex', justifyContent: 'space-between', marginTop: '-4px' } }>
					<span style={ { fontSize: '11px', color: '#999' } }>
						Recommended: up to { SEO_TITLE_MAX } characters
					</span>
					<span style={ { fontSize: '11px', fontWeight: '600', color: countColor( titleLen, SEO_TITLE_MAX ) } }>
						{ titleLen }
					</span>
				</div>
			</div>

			{ /* Meta Description */ }
			<div style={ { marginBottom: '12px' } }>
				<TextareaControl
					label="Meta Description"
					placeholder="Leave blank to use AEO summary or excerpt"
					value={ seo.meta_desc }
					onChange={ ( val ) => updateSeo( { meta_desc: val } ) }
					rows={ 3 }
				/>
				<div style={ { display: 'flex', justifyContent: 'space-between', marginTop: '-4px' } }>
					<span style={ { fontSize: '11px', color: '#999' } }>
						Recommended: up to { SEO_DESC_MAX } characters
					</span>
					<span style={ { fontSize: '11px', fontWeight: '600', color: countColor( descLen, SEO_DESC_MAX ) } }>
						{ descLen }
					</span>
				</div>
			</div>

			<TextControl
				label="Canonical URL"
				placeholder="Leave blank to use default permalink"
				value={ seo.canonical }
				onChange={ ( val ) => updateSeo( { canonical: val } ) }
			/>

			{ /* Robots */ }
			<div style={ { marginTop: '4px', marginBottom: '8px' } }>
				<p style={ {
					fontSize:      '11px',
					fontWeight:    '600',
					color:         '#1e1e1e',
					margin:        '0 0 6px',
					textTransform: 'uppercase',
					letterSpacing: '0.05em',
				} }>
					Robots
				</p>
				<CheckboxControl
					label="noindex — exclude from search engines"
					checked={ !! seo.noindex }
					onChange={ ( val ) => updateSeo( { noindex: val } ) }
				/>
				<CheckboxControl
					label="nofollow — do not follow links on this page"
					checked={ !! seo.nofollow }
					onChange={ ( val ) => updateSeo( { nofollow: val } ) }
				/>
			</div>

			<TextControl
				label="Open Graph Image URL"
				help="Leave blank to use featured image."
				placeholder="https://…"
				value={ seo.og_image }
				onChange={ ( val ) => updateSeo( { og_image: val } ) }
			/>

			{ /* OG image thumbnail preview when URL is set */ }
			{ seo.og_image && (
				<div style={ { marginTop: '-4px', marginBottom: '8px' } }>
					<img
						src={ seo.og_image }
						alt=""
						style={ { width: '100%', maxHeight: '80px', objectFit: 'cover', borderRadius: '3px', border: '1px solid #ddd', display: 'block' } }
					/>
				</div>
			) }

			{ /* Offer featured image when OG field is blank */ }
			{ ! seo.og_image && featuredImageUrl && (
				<div style={ {
					display:      'flex',
					alignItems:   'center',
					gap:          '8px',
					marginTop:    '-4px',
					marginBottom: '8px',
					padding:      '6px 8px',
					background:   '#f0f6fc',
					borderRadius: '3px',
					border:       '1px solid #c3dafe',
				} }>
					<img src={ featuredImageUrl } alt="" style={ { width: '40px', height: '40px', objectFit: 'cover', borderRadius: '2px', flexShrink: 0 } } />
					<div style={ { flex: 1, minWidth: 0 } }>
						<p style={ { margin: '0 0 3px', fontSize: '11px', color: '#555' } }>Using featured image as fallback</p>
						<Button
							variant="link"
							onClick={ () => updateSeo( { og_image: featuredImageUrl } ) }
							style={ { fontSize: '11px', padding: 0, height: 'auto', minHeight: 0 } }
						>
							Set as OG image URL
						</Button>
					</div>
				</div>
			) }

			{ /* Nudge when no OG image and no featured image */ }
			{ ! seo.og_image && ! featuredImageUrl && (
				<p style={ {
					fontSize:     '11px',
					color:        '#d97706',
					margin:       '-4px 0 8px',
					padding:      '5px 8px',
					background:   '#fffbeb',
					borderRadius: '3px',
					border:       '1px solid #fde68a',
				} }>
					⚠ No featured image set — social shares may show no image. Add one in Document settings.
				</p>
			) }

			{ /* AI: Generate SEO Title & Description */ }
			{ IS_AI_MODE && (
				<>
					{ generateState.error && (
						<p style={ { fontSize: '11px', color: '#dc3232', margin: '8px 0 4px' } }>
							{ generateState.error }
						</p>
					) }
					<Button
						variant="secondary"
						isBusy={ generateState.loading }
						disabled={ generateState.loading }
						onClick={ async () => {
							setGenerateState( { loading: true, error: '' } );
							try {
								const res  = await fetch( ajaxUrl, {
									method:  'POST',
									headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
									body:    new URLSearchParams( {
										action:        'wppugmill_generate_seo',
										nonce:         seoNonce,
										post_id:       postId,
										draft_content: wp.data.select( 'core/editor' ).getEditedPostContent(),
									} ),
								} );
								const data = await res.json();
								if ( data.success ) {
									updateSeo( { title: data.data.title, meta_desc: data.data.meta_desc } );
									setGenerateState( { loading: false, error: '' } );
								} else {
									setGenerateState( { loading: false, error: data.data?.message || 'Generation failed. Please try again.' } );
								}
							} catch {
								setGenerateState( { loading: false, error: 'Network error. Please check your connection.' } );
							}
						} }
						style={ { width: '100%', justifyContent: 'center', marginTop: '8px', ...BUTTON_STYLE } }
					>
						{ generateState.loading ? 'Generating…' : '✨ Generate SEO Title & Description' }
					</Button>
				</>
			) }
		</PanelBody>
	);
}
