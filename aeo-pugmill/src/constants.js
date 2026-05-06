/**
 * Pugmill AEO Toolkit — Constants and plugin configuration.
 *
 * All values are injected via wp_localize_script in admin/editor-assets.php.
 *
 * @package WPPugmill
 */

const {
	mode       = 'free',
	hasApiKey  = false,
	ajaxUrl    = '',
	usageNonce = '',
	summaryNonce  = '',
	qaNonce       = '',
	entitiesNonce = '',
	keywordsNonce = '',
	seoNonce      = '',
	howtoNonce    = '',
	pricingUrl = 'https://aeopugmill.com/pricing',
} = window.aeopugmill || {};

export {
	mode,
	hasApiKey,
	ajaxUrl,
	usageNonce,
	summaryNonce,
	qaNonce,
	entitiesNonce,
	keywordsNonce,
	seoNonce,
	howtoNonce,
	pricingUrl,
};

/** True when the plugin is in Pugmill AEO Toolkit Pro or Pro mode. */
export const IS_AI_MODE = mode === 'ai' || mode === 'pro';

/** True when a BYOK API key is saved — unlocks basic AEO generation in free mode. */
export const HAS_API_KEY = !! hasApiKey;

/** Shared pill-button inline style (purple, rounded). */
export const BUTTON_STYLE = {
	background:   '#7c3aed',
	borderColor:  '#7c3aed',
	color:        '#fff',
	borderRadius: '9999px',
};

/** Entity type choices for the SelectControl in the Entities panel. */
export const ENTITY_TYPE_OPTIONS = [
	{ label: 'Thing',        value: 'Thing'       },
	{ label: 'Person',       value: 'Person'      },
	{ label: 'Organization', value: 'Organization'},
	{ label: 'Product',      value: 'Product'     },
	{ label: 'Place',        value: 'Place'       },
	{ label: 'Event',        value: 'Event'       },
	{ label: 'Technology',   value: 'Technology'  },
	{ label: 'Defined Term', value: 'DefinedTerm' },
];

/** Default (empty) schema data stored in _aeopugmill_schema. */
export const SCHEMA_DEFAULTS = {
	type: 'Article',
	howto: {
		description: '',
		total_time:  '',
		steps:       [],
	},
	product: {
		name:         '',
		description:  '',
		price:        '',
		currency:     'USD',
		availability: 'InStock',
		brand:        '',
	},
	event: {
		name:             '',
		description:      '',
		start_date:       '',
		end_date:         '',
		location_name:    '',
		location_address: '',
		organizer:        '',
	},
	local_business: {
		name:          '',
		description:   '',
		address:       '',
		phone:         '',
		hours:         '',
		price_range:   '',
		business_type: 'LocalBusiness',
	},
	video: {
		name:          '',
		description:   '',
		upload_date:   '',
		duration:      '',
		thumbnail_url: '',
		embed_url:     '',
	},
	review: {
		item_name:    '',
		item_type:    'Book',
		item_author:  '',
		rating_value: '5',
		best_rating:  '5',
		review_body:  '',
	},
};

/** Schema type choices for the top-level SelectControl. */
export const SCHEMA_TYPE_OPTIONS = [
	{ label: 'Article',                  value:   'Article' },
	{ label: 'HowTo',                   value:     'HowTo' },
	{ label: 'Product',                 value:   'Product' },
	{ label: 'Event',                   value:     'Event' },
	{ label: 'Local Business',          value: 'LocalBusiness' },
	{ label: 'Video',                   value: 'VideoObject' },
	{ label: 'Review',                  value:    'Review' },
];

/** LocalBusiness subtype options. */
export const LOCAL_BUSINESS_TYPE_OPTIONS = [
	'LocalBusiness', 'Restaurant', 'Store', 'MedicalBusiness', 'LegalService',
	'HomeAndConstructionBusiness', 'AutomotiveBusiness', 'HealthAndBeautyBusiness',
	'EntertainmentBusiness', 'FinancialService', 'FoodEstablishment', 'LodgingBusiness',
].map( ( v ) => ( { label: v, value: v } ) );

/** Product availability options. */
export const PRODUCT_AVAILABILITY_OPTIONS = [
	{ label: 'In Stock',    value: 'InStock'  },
	{ label: 'Out of Stock',value: 'OutOfStock'},
	{ label: 'Pre-Order',   value: 'PreOrder' },
];

/** Help text shown below the schema type selector. */
export const SCHEMA_TYPE_DESCRIPTIONS = {
	Article:       'Outputs Article (or BlogPosting), FAQPage (from your Q&A pairs), and Breadcrumb schema automatically — no setup needed.',
	HowTo:         'Enables step-by-step rich results in Google Search. Best for tutorials, recipes, and guides.',
	Product:       'Adds price, availability, and brand to search results. Best for product pages and reviews.',
	Event:         'Displays date, time, and location in search. Best for event announcements and listings.',
	LocalBusiness: 'Adds address, hours, and contact info. Best for location or business about-pages.',
	VideoObject:   'Marks up embedded video with duration and thumbnail for video-rich results.',
	Review:        'Adds a star rating and review body for a book, product, movie, or other item. Eligible for rich snippets in Google Search.',
};

/** Item types for Review schema. */
export const REVIEW_ITEM_TYPE_OPTIONS = [
	{ label: 'Book',                 value: 'Book'                },
	{ label: 'Movie',                value: 'Movie'               },
	{ label: 'Product',              value: 'Product'             },
	{ label: 'Software Application', value: 'SoftwareApplication' },
	{ label: 'Course',               value: 'Course'              },
	{ label: 'Game',                 value: 'Game'                },
	{ label: 'Music Recording',      value: 'MusicRecording'      },
	{ label: 'Restaurant',           value: 'Restaurant'          },
	{ label: 'Thing',                value: 'Thing'               },
];

export const SEO_TITLE_MAX = 60;
export const SEO_DESC_MAX  = 155;

/** Icon + color for each audit result status. */
export const AUDIT_STATUS_ICONS = {
	pass: { icon: '✓', color: '#46b450' },
	warn: { icon: '⚠', color: '#d97706' },
	fail: { icon: '✗', color: '#cc1818' },
};

/**
 * Lazy accessor for window.aeopugmill data.
 * Reading at call time (rather than module-load) makes tests easier to
 * set up and avoids capturing stale values if the global is written late.
 */
export function getWppugmillConfig() {
	return window.aeopugmill || {};
}

/**
 * Returns the map of audit check IDs → AJAX fix actions.
 * Nonces are read from window.aeopugmill at call time so test environments
 * can inject them without needing to mock the entire module.
 *
 * Special cases (keywords_in_content, has_headings) render their own result UI.
 */
export function getAuditFixActions() {
	const cfg = getWppugmillConfig();
	return {
		summary_present:     { ajaxAction: 'aeopugmill_generate_summary',     actionNonce: cfg.summaryNonce         || '', label: '✨ Generate Summary'    },
		summary_length:      { ajaxAction: 'aeopugmill_generate_summary',     actionNonce: cfg.summaryNonce         || '', label: '✨ Regenerate Summary'  },
		qa_present:          { ajaxAction: 'aeopugmill_generate_qa',          actionNonce: cfg.qaNonce              || '', label: '✨ Generate Q&A'        },
		qa_coverage:         { ajaxAction: 'aeopugmill_generate_qa',          actionNonce: cfg.qaNonce              || '', label: '✨ Regenerate Q&A'      },
		questions_natural:   { ajaxAction: 'aeopugmill_generate_qa',          actionNonce: cfg.qaNonce              || '', label: '✨ Regenerate Q&A'      },
		entities_present:    { ajaxAction: 'aeopugmill_generate_entities',    actionNonce: cfg.entitiesNonce        || '', label: '✨ Generate Entities'   },
		entity_specificity:  { ajaxAction: 'aeopugmill_generate_entities',    actionNonce: cfg.entitiesNonce        || '', label: '✨ Regenerate Entities' },
		keywords_present:    { ajaxAction: 'aeopugmill_generate_keywords',    actionNonce: cfg.keywordsNonce || '', label: '✨ Generate Keywords'   },
	};
}


