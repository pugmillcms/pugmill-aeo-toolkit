/**
 * WP Pugmill — Google SERP preview component.
 *
 * @package WPPugmill
 */

import { SEO_TITLE_MAX, SEO_DESC_MAX } from '../constants';

/**
 * Truncate a string to a maximum length, appending "…" if needed.
 *
 * @param {string}  str
 * @param {number}  max
 * @return {string}
 */
function truncate( str, max ) {
	if ( ! str || str.length <= max ) return str;
	return str.slice( 0, max - 1 ) + '…';
}

/**
 * Render a simulated Google SERP snippet preview.
 *
 * @param {{ seo: Object, aeo: Object, postTitle: string, postLink: string, postExcerpt: string }} props
 */
export function GooglePreview( { seo, aeo, postTitle, postLink, postExcerpt } ) {
	const displayTitle = truncate(
		seo.title && seo.title.trim() ? seo.title : postTitle,
		SEO_TITLE_MAX
	) || '';

	const displayDesc = truncate(
		seo.meta_desc && seo.meta_desc.trim()
			? seo.meta_desc
			: ( aeo.summary && aeo.summary.trim() ? aeo.summary : postExcerpt ),
		SEO_DESC_MAX
	) || '';

	const displayUrl = postLink
		? postLink.replace( /^https?:\/\//, '' ).replace( /\/$/, '' ).replace( /\//g, ' › ' )
		: window.location.hostname;

	const titleColor = displayTitle ? '#1a0dab' : '#bbb';

	return (
		<div style={ {
			border:       '1px solid #dadce0',
			borderRadius: '8px',
			padding:      '12px 14px',
			background:   '#fff',
			marginBottom: '16px',
			fontFamily:   'arial, sans-serif',
		} }>
			{ /* Site icon + domain row */ }
			<div style={ {
				display:     'flex',
				alignItems:  'center',
				gap:         '8px',
				marginBottom:'6px',
			} }>
				<div style={ {
					width:        '26px',
					height:       '26px',
					borderRadius: '50%',
					background:   '#f1f3f4',
					border:       '1px solid #e0e0e0',
					flexShrink:   0,
				} } />
				<div>
					<div style={ { fontSize: '13px', color: '#202124', lineHeight: '1.2', fontWeight: '500' } }>
						{ window.location.hostname }
					</div>
					<div style={ {
						fontSize:     '11px',
						color:        '#4d5156',
						lineHeight:   '1.2',
						maxWidth:     '220px',
						overflow:     'hidden',
						textOverflow: 'ellipsis',
						whiteSpace:   'nowrap',
					} }>
						{ displayUrl }
					</div>
				</div>
			</div>

			{ /* Title */ }
			<div style={ {
				fontSize:   '19px',
				color:      titleColor,
				lineHeight: '1.3',
				marginBottom: '4px',
				fontStyle:  displayTitle ? 'normal' : 'italic',
			} }>
				{ displayTitle || 'Post title will appear here' }
			</div>

			{ /* Description */ }
			<div style={ {
				fontSize:   '13px',
				color:      displayDesc ? '#4d5156' : '#bbb',
				lineHeight: '1.55',
				fontStyle:  displayDesc ? 'normal' : 'italic',
			} }>
				{ displayDesc || 'Meta description will appear here. Add one in the field below, or it will be pulled from your AEO summary or excerpt.' }
			</div>
		</div>
	);
}
