/**
 * WP Pugmill — Schema type builder panel.
 *
 * Renders the "Schema" PanelBody including the AI "Suggest Schema" button
 * (AI Connector mode only) and form fields for each structured data type.
 *
 * @package WPPugmill
 */

import { PanelBody, Button, SelectControl, TextControl, TextareaControl } from '@wordpress/components';
import { useState } from '@wordpress/element';

import { useAeoMeta }    from '../hooks';
import { useSchemaData } from '../hooks';
import { Tick }          from './Tick';
import {
	IS_AI_MODE,
	BUTTON_STYLE,
	SCHEMA_TYPE_OPTIONS,
	SCHEMA_TYPE_DESCRIPTIONS,
	LOCAL_BUSINESS_TYPE_OPTIONS,
	PRODUCT_AVAILABILITY_OPTIONS,
	REVIEW_ITEM_TYPE_OPTIONS,
	ajaxUrl,
	howtoNonce,
	schemaAiNonce,
} from '../constants';

export function SchemaBuilder() {
	const { schema, updateSchema, updateSchemaType } = useSchemaData();
	const { postId } = useAeoMeta();

	const schemaType = schema.type || 'Article';

	// Checkmark rules per type:
	// - Article (default): always complete — schema is auto-generated.
	// - Custom types: complete when the key required field is filled.
	const schemaComplete = ( () => {
		switch ( schemaType ) {
			case 'Article':      return true;
			case 'HowTo':        return schema.howto.steps.length > 0;
			case 'Product':      return !! schema.product.price;
			case 'Event':        return !! schema.event.start_date;
			case 'LocalBusiness': return !! schema.local_business.address;
			case 'VideoObject':  return !! schema.video.upload_date;
			case 'Review':       return !! schema.review.item_name || !! schema.review.review_body;
			default:             return false;
		}
	} )();

	// Per-step field update helper for HowTo.
	const updateStep = ( stepIndex, field, value ) => {
		updateSchemaType( 'howto', {
			steps: schema.howto.steps.map( ( step, i ) =>
				i === stepIndex ? { ...step, [ field ]: value } : step
			),
		} );
	};

	// "Draft Steps from Content" button state.
	const [ howtoState, setHowtoState ] = useState( { loading: false, error: '' } );

	// "Suggest Schema from Content" button state.
	const [ suggestState, setSuggestState ] = useState( { loading: false, error: '', notice: '' } );

	return (
		<PanelBody title={ <span>Schema<Tick show={ schemaComplete } /></span> } initialOpen={ false }>
			{ /* ── AI: Suggest Schema ───────────────────────────────────────── */ }
			{ IS_AI_MODE && (
				<>
					{ suggestState.error && (
						<p style={ { fontSize: '11px', color: '#dc3232', margin: '0 0 8px' } }>
							{ suggestState.error }
						</p>
					) }
					{ suggestState.notice && (
						<p style={ {
							fontSize:     '11px',
							color:        '#555',
							background:   '#f6f7f7',
							border:       '1px solid #e0e0e0',
							borderRadius: '4px',
							padding:      '6px 8px',
							margin:       '0 0 8px',
							lineHeight:   '1.5',
						} }>
							{ suggestState.notice }
						</p>
					) }
					<Button
						variant="secondary"
						isBusy={ suggestState.loading }
						disabled={ suggestState.loading }
						onClick={ async () => {
							setSuggestState( { loading: true, error: '', notice: '' } );
							try {
								const res  = await fetch( ajaxUrl, {
									method:      'POST',
									credentials: 'same-origin',
									headers:     { 'Content-Type': 'application/x-www-form-urlencoded' },
									body:        new URLSearchParams( { action: 'wppugmill_suggest_schema', nonce: schemaAiNonce, post_id: postId } ),
								} );
								const data = await res.json();
								if ( ! data.success ) {
									setSuggestState( { loading: false, error: data.data?.message || 'Suggestion failed.', notice: '' } );
									return;
								}
								const suggestion = data.data;
								if ( ! suggestion.type ) {
									setSuggestState( { loading: false, error: '', notice: 'This post reads as a standard article — no additional schema type needed. Article + FAQ schema are already output automatically.' } );
									return;
								}
								const updates = { type: suggestion.type };
								if ( suggestion.howto )          updates.howto          = { ...schema.howto,          ...suggestion.howto          };
								if ( suggestion.product )        updates.product        = { ...schema.product,        ...suggestion.product        };
								if ( suggestion.event )          updates.event          = { ...schema.event,          ...suggestion.event          };
								if ( suggestion.local_business ) updates.local_business = { ...schema.local_business, ...suggestion.local_business };
								if ( suggestion.video )          updates.video          = { ...schema.video,          ...suggestion.video          };
								if ( suggestion.review )         updates.review         = { ...schema.review,         ...suggestion.review         };
								updateSchema( updates );
								setSuggestState( { loading: false, error: '', notice: '' } );
							} catch {
								setSuggestState( { loading: false, error: 'Network error. Please check your connection.', notice: '' } );
							}
						} }
						style={ { width: '100%', justifyContent: 'center', marginBottom: '12px', ...BUTTON_STYLE } }
					>
						{ suggestState.loading ? 'Analysing…' : '✨ Suggest Schema from Content' }
					</Button>
				</>
			) }

			{ /* ── Schema Type selector ─────────────────────────────────────── */ }
			<SelectControl
				label="Schema Type"
				value={ schemaType }
				options={ SCHEMA_TYPE_OPTIONS }
				onChange={ ( val ) => {
					updateSchema( { type: val } );
					setSuggestState( { loading: false, error: '', notice: '' } );
				} }
			/>
			<p style={ { fontSize: '11px', color: '#666', margin: '-8px 0 12px', lineHeight: '1.5' } }>
				{ SCHEMA_TYPE_DESCRIPTIONS[ schemaType ] }
			</p>

			{ /* ── HowTo fields ──────────────────────────────────────────────── */ }
			{ schemaType === 'HowTo' && (
				<>
					<TextareaControl
						label="Description"
						value={ schema.howto.description }
						onChange={ ( val ) => updateSchemaType( 'howto', { description: val } ) }
						rows={ 2 }
						help="Defaults to post excerpt if blank."
					/>
					<TextControl
						label="Total Time (ISO 8601)"
						placeholder="e.g. PT30M"
						value={ schema.howto.total_time }
						onChange={ ( val ) => updateSchemaType( 'howto', { total_time: val } ) }
						help="e.g. PT1H30M = 1 hour 30 minutes."
					/>
					<p style={ {
						fontSize:      '11px',
						fontWeight:    '600',
						color:         '#1e1e1e',
						margin:        '8px 0 4px',
						textTransform: 'uppercase',
						letterSpacing: '0.05em',
					} }>
						Steps ({ schema.howto.steps.length })
					</p>
					{ schema.howto.steps.map( ( step, i ) => (
						<div key={ i } style={ { borderLeft: '3px solid #7c3aed', paddingLeft: '10px', marginBottom: '10px' } }>
							<TextControl
								label={ `Step ${ i + 1 } Name` }
								placeholder="Optional short name"
								value={ step.name }
								onChange={ ( val ) => updateStep( i, 'name', val ) }
							/>
							<TextareaControl
								label="Instructions"
								value={ step.text }
								onChange={ ( val ) => updateStep( i, 'text', val ) }
								rows={ 2 }
							/>
							<Button
								isDestructive
								size="small"
								onClick={ () => updateSchemaType( 'howto', {
									steps: schema.howto.steps.filter( ( _, idx ) => idx !== i ),
								} ) }
							>
								Remove step
							</Button>
						</div>
					) ) }
					<Button
						variant="secondary"
						onClick={ () => updateSchemaType( 'howto', { steps: [ ...schema.howto.steps, { name: '', text: '' } ] } ) }
						style={ { marginBottom: '8px' } }
					>
						+ Add Step
					</Button>

					{ /* AI: Draft Steps from Content */ }
					{ IS_AI_MODE && (
						<>
							{ howtoState.error && (
								<p style={ { fontSize: '11px', color: '#dc3232', margin: '4px 0' } }>{ howtoState.error }</p>
							) }
							<Button
								variant="secondary"
								isBusy={ howtoState.loading }
								disabled={ howtoState.loading }
								onClick={ async () => {
									setHowtoState( { loading: true, error: '' } );
									try {
										const res  = await fetch( ajaxUrl, {
											method:  'POST',
											headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
											body:    new URLSearchParams( { action: 'wppugmill_generate_howto_steps', nonce: howtoNonce, post_id: postId } ),
										} );
										const data = await res.json();
										if ( data.success ) {
											updateSchemaType( 'howto', { description: data.data.description, steps: data.data.steps } );
											setHowtoState( { loading: false, error: '' } );
										} else {
											setHowtoState( { loading: false, error: data.data?.message || 'Generation failed. Please try again.' } );
										}
									} catch {
										setHowtoState( { loading: false, error: 'Network error. Please check your connection.' } );
									}
								} }
								style={ { width: '100%', justifyContent: 'center', marginTop: '4px', ...BUTTON_STYLE } }
							>
								{ howtoState.loading ? 'Drafting steps…' : '✨ Draft Steps from Content' }
							</Button>
						</>
					) }
				</>
			) }

			{ /* ── Product fields ───────────────────────────────────────────── */ }
			{ schemaType === 'Product' && (
				<>
					<TextControl
						label="Product Name"
						placeholder="Defaults to post title"
						value={ schema.product.name }
						onChange={ ( val ) => updateSchemaType( 'product', { name: val } ) }
					/>
					<TextareaControl
						label="Description"
						value={ schema.product.description }
						onChange={ ( val ) => updateSchemaType( 'product', { description: val } ) }
						rows={ 2 }
						help="Defaults to post excerpt if blank."
					/>
					<TextControl
						label="Brand"
						value={ schema.product.brand }
						onChange={ ( val ) => updateSchemaType( 'product', { brand: val } ) }
					/>
					<div style={ { display: 'flex', gap: '8px' } }>
						<div style={ { flex: 2 } }>
							<TextControl
								label="Price"
								placeholder="29.99"
								value={ schema.product.price }
								onChange={ ( val ) => updateSchemaType( 'product', { price: val } ) }
							/>
						</div>
						<div style={ { flex: 1 } }>
							<TextControl
								label="Currency"
								placeholder="USD"
								value={ schema.product.currency }
								onChange={ ( val ) => updateSchemaType( 'product', { currency: val.toUpperCase() } ) }
							/>
						</div>
					</div>
					<SelectControl
						label="Availability"
						value={ schema.product.availability }
						options={ PRODUCT_AVAILABILITY_OPTIONS }
						onChange={ ( val ) => updateSchemaType( 'product', { availability: val } ) }
					/>
				</>
			) }

			{ /* ── Event fields ─────────────────────────────────────────────── */ }
			{ schemaType === 'Event' && (
				<>
					<TextControl
						label="Event Name"
						placeholder="Defaults to post title"
						value={ schema.event.name }
						onChange={ ( val ) => updateSchemaType( 'event', { name: val } ) }
					/>
					<TextareaControl
						label="Description"
						value={ schema.event.description }
						onChange={ ( val ) => updateSchemaType( 'event', { description: val } ) }
						rows={ 2 }
						help="Defaults to post excerpt if blank."
					/>
					<div style={ { display: 'flex', gap: '8px' } }>
						<div style={ { flex: 1 } }>
							<TextControl
								label="Start Date & Time"
								type="datetime-local"
								value={ schema.event.start_date }
								onChange={ ( val ) => updateSchemaType( 'event', { start_date: val } ) }
							/>
						</div>
						<div style={ { flex: 1 } }>
							<TextControl
								label="End Date & Time"
								type="datetime-local"
								value={ schema.event.end_date }
								onChange={ ( val ) => updateSchemaType( 'event', { end_date: val } ) }
							/>
						</div>
					</div>
					<TextControl
						label="Venue / Location Name"
						value={ schema.event.location_name }
						onChange={ ( val ) => updateSchemaType( 'event', { location_name: val } ) }
					/>
					<TextControl
						label="Location Address"
						value={ schema.event.location_address }
						onChange={ ( val ) => updateSchemaType( 'event', { location_address: val } ) }
					/>
					<TextControl
						label="Organizer"
						value={ schema.event.organizer }
						onChange={ ( val ) => updateSchemaType( 'event', { organizer: val } ) }
					/>
				</>
			) }

			{ /* ── Local Business fields ────────────────────────────────────── */ }
			{ schemaType === 'LocalBusiness' && (
				<>
					<SelectControl
						label="Business Type"
						value={ schema.local_business.business_type }
						options={ LOCAL_BUSINESS_TYPE_OPTIONS }
						onChange={ ( val ) => updateSchemaType( 'local_business', { business_type: val } ) }
					/>
					<TextControl
						label="Business Name"
						placeholder="Defaults to site/org name"
						value={ schema.local_business.name }
						onChange={ ( val ) => updateSchemaType( 'local_business', { name: val } ) }
					/>
					<TextareaControl
						label="Description"
						value={ schema.local_business.description }
						onChange={ ( val ) => updateSchemaType( 'local_business', { description: val } ) }
						rows={ 2 }
					/>
					<TextControl
						label="Street Address"
						value={ schema.local_business.address }
						onChange={ ( val ) => updateSchemaType( 'local_business', { address: val } ) }
					/>
					<TextControl
						label="Phone"
						value={ schema.local_business.phone }
						onChange={ ( val ) => updateSchemaType( 'local_business', { phone: val } ) }
					/>
					<TextControl
						label="Opening Hours"
						placeholder="e.g. Mo-Fr 09:00-17:00"
						value={ schema.local_business.hours }
						onChange={ ( val ) => updateSchemaType( 'local_business', { hours: val } ) }
					/>
					<TextControl
						label="Price Range"
						placeholder="e.g. $$ or $10–$50"
						value={ schema.local_business.price_range }
						onChange={ ( val ) => updateSchemaType( 'local_business', { price_range: val } ) }
					/>
				</>
			) }

			{ /* ── VideoObject fields ───────────────────────────────────────── */ }
			{ schemaType === 'VideoObject' && (
				<>
					<TextControl
						label="Video Title"
						placeholder="Defaults to post title"
						value={ schema.video.name }
						onChange={ ( val ) => updateSchemaType( 'video', { name: val } ) }
					/>
					<TextareaControl
						label="Description"
						value={ schema.video.description }
						onChange={ ( val ) => updateSchemaType( 'video', { description: val } ) }
						rows={ 2 }
					/>
					<TextControl
						label="Upload Date"
						type="date"
						value={ schema.video.upload_date }
						onChange={ ( val ) => updateSchemaType( 'video', { upload_date: val } ) }
					/>
					<TextControl
						label="Duration (ISO 8601)"
						placeholder="e.g. PT5M30S"
						value={ schema.video.duration }
						onChange={ ( val ) => updateSchemaType( 'video', { duration: val } ) }
						help="PT5M30S = 5 minutes 30 seconds."
					/>
					<TextControl
						label="Thumbnail URL"
						placeholder="Defaults to featured image"
						value={ schema.video.thumbnail_url }
						onChange={ ( val ) => updateSchemaType( 'video', { thumbnail_url: val } ) }
					/>
					<TextControl
						label="Embed URL"
						placeholder="https://www.youtube.com/embed/…"
						value={ schema.video.embed_url }
						onChange={ ( val ) => updateSchemaType( 'video', { embed_url: val } ) }
					/>
				</>
			) }

			{ /* ── Review fields ────────────────────────────────────────────── */ }
			{ schemaType === 'Review' && (
				<>
					<SelectControl
						label="Item Type"
						value={ schema.review.item_type }
						options={ REVIEW_ITEM_TYPE_OPTIONS }
						onChange={ ( val ) => updateSchemaType( 'review', { item_type: val } ) }
					/>
					<TextControl
						label="Item Name"
						placeholder="Defaults to post title"
						value={ schema.review.item_name }
						onChange={ ( val ) => updateSchemaType( 'review', { item_name: val } ) }
					/>
					<TextControl
						label="Item Author / Creator"
						placeholder="e.g. Stephen King (for books/films)"
						value={ schema.review.item_author }
						onChange={ ( val ) => updateSchemaType( 'review', { item_author: val } ) }
					/>
					<div style={ { display: 'flex', gap: '8px' } }>
						<div style={ { flex: 1 } }>
							<TextControl
								label="Rating"
								placeholder="5"
								value={ schema.review.rating_value }
								onChange={ ( val ) => updateSchemaType( 'review', { rating_value: val } ) }
							/>
						</div>
						<div style={ { flex: 1 } }>
							<TextControl
								label="Best Rating"
								placeholder="5"
								value={ schema.review.best_rating }
								onChange={ ( val ) => updateSchemaType( 'review', { best_rating: val } ) }
							/>
						</div>
					</div>
					<TextareaControl
						label="Review Body"
						value={ schema.review.review_body }
						onChange={ ( val ) => updateSchemaType( 'review', { review_body: val } ) }
						rows={ 3 }
						help="Defaults to post excerpt if blank."
					/>
				</>
			) }
		</PanelBody>
	);
}
