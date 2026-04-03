/**
 * WP Pugmill — Pre-publish AEO health check panel.
 *
 * Shows a compact version of the completeness score in the Gutenberg
 * pre-publish flow. Expands automatically when the score is < 100.
 *
 * @package WPPugmill
 */

import { PluginPrePublishPanel } from '@wordpress/edit-post';
import { useSelect } from '@wordpress/data';

import { useAeoMeta } from '../hooks';
import { useSeoMeta } from '../hooks';
import { CheckList }  from './CheckList';
import { computeScore } from '../scoring';
import { IS_AI_MODE, pricingUrl } from '../constants';

export function PrePublishPanel() {
	const { aeo } = useAeoMeta();
	const { seo } = useSeoMeta();

	const draftContent       = useSelect( ( s ) => s( 'core/editor' ).getEditedPostContent(), [] );
	const featuredImageId    = useSelect( ( s ) => s( 'core/editor' ).getEditedPostAttribute( 'featured_media' ), [] );
	const featuredMediaAlt   = useSelect( ( s ) => featuredImageId ? s( 'core' ).getMedia( featuredImageId )?.alt_text || '' : '', [ featuredImageId ] );

	const { score, color, grade, items } = computeScore( aeo, seo, { postContent: draftContent, featuredImageAlt: featuredMediaAlt } );
	const failing = items.filter( ( item ) => ! item.pass );

	return (
		<PluginPrePublishPanel title="AEO Health Check" initialOpen={ score < 100 }>
			{ score === 100 ? (
				<p style={ { display: 'flex', alignItems: 'center', gap: '6px', fontSize: '13px', color: '#46b450', margin: 0 } }>
					<strong>✓ 100/100 — Your AEO is complete.</strong>
				</p>
			) : (
				<>
					<div style={ { display: 'flex', alignItems: 'center', gap: '10px', marginBottom: '8px' } }>
						<span style={ { fontSize: '28px', fontWeight: '700', color, lineHeight: 1 } }>
							{ score }
						</span>
						<span style={ { fontSize: '13px', color: '#666', lineHeight: 1 } }>
							/100<br />
							<strong style={ { color } }>{ grade }</strong>
						</span>
					</div>

					<CheckList items={ failing } failingOnly={ true } />

					<p style={ { fontSize: '11px', color: '#666', marginTop: '10px', marginBottom: 0 } }>
						{ IS_AI_MODE
							? 'Go back and use Generate with AI to complete missing fields.'
							: <>
								Complete these in the <strong>SEO+AEO — WP Pugmill</strong> panel, or{ ' ' }
								<a href={ pricingUrl } target="_blank" rel="noreferrer">get AI Connector</a>
								{ ' ' }to auto-fill them.
							</>
						}
					</p>
				</>
			) }
		</PluginPrePublishPanel>
	);
}
