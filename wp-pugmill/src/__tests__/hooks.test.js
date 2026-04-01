/**
 * Tests for the schema/AEO merge logic used by the hooks in src/hooks.js.
 *
 * The hooks themselves depend on @wordpress/data and @wordpress/core-data,
 * which are WordPress runtime externals not installed in node_modules. Rather
 * than fighting the missing dependencies, we test the pure merge logic that
 * the hooks delegate to — extracted here as the same inline expressions.
 *
 * If the merge logic in hooks.js changes, update these tests to match.
 */

import { SCHEMA_DEFAULTS } from '../constants';

// ── Schema merge logic (mirrors useSchemaData in hooks.js) ────────────────────

/**
 * Replicate the merge performed inside useSchemaData's useMemo.
 */
function mergeSchema( rawJson ) {
	if ( ! rawJson ) return { ...SCHEMA_DEFAULTS };
	let parsed;
	try {
		parsed = JSON.parse( rawJson );
	} catch {
		return { ...SCHEMA_DEFAULTS };
	}
	return {
		...SCHEMA_DEFAULTS,
		...parsed,
		howto:          { ...SCHEMA_DEFAULTS.howto,          ...( parsed.howto          || {} ) },
		product:        { ...SCHEMA_DEFAULTS.product,        ...( parsed.product        || {} ) },
		event:          { ...SCHEMA_DEFAULTS.event,          ...( parsed.event          || {} ) },
		local_business: { ...SCHEMA_DEFAULTS.local_business, ...( parsed.local_business || {} ) },
		video:          { ...SCHEMA_DEFAULTS.video,          ...( parsed.video          || {} ) },
		review:         { ...SCHEMA_DEFAULTS.review,         ...( parsed.review         || {} ) },
	};
}

// ── AEO merge logic (mirrors useAeoMeta in hooks.js) ─────────────────────────

const AEO_DEFAULTS = { summary: '', questions: [], entities: [], keywords: [] };

function mergeAeo( rawJson ) {
	if ( ! rawJson ) return { ...AEO_DEFAULTS };
	try {
		return { ...AEO_DEFAULTS, ...JSON.parse( rawJson ) };
	} catch {
		return { ...AEO_DEFAULTS };
	}
}

// ── SEO merge logic (mirrors useSeoMeta in hooks.js) ─────────────────────────

const SEO_DEFAULTS = {
	title: '', meta_desc: '', canonical: '', noindex: false, nofollow: false,
	og_title: '', og_desc: '', og_image: '',
};

function mergeSeo( rawJson ) {
	if ( ! rawJson ) return { ...SEO_DEFAULTS };
	try {
		return { ...SEO_DEFAULTS, ...JSON.parse( rawJson ) };
	} catch {
		return { ...SEO_DEFAULTS };
	}
}

// ── mergeSchema tests ─────────────────────────────────────────────────────────

describe( 'mergeSchema (schema meta merge logic)', () => {
	it( 'returns SCHEMA_DEFAULTS when raw is null/undefined', () => {
		expect( mergeSchema( null ) ).toEqual( SCHEMA_DEFAULTS );
		expect( mergeSchema( undefined ) ).toEqual( SCHEMA_DEFAULTS );
		expect( mergeSchema( '' ) ).toEqual( SCHEMA_DEFAULTS );
	} );

	it( 'returns SCHEMA_DEFAULTS on invalid JSON', () => {
		expect( mergeSchema( '{{broken' ) ).toEqual( SCHEMA_DEFAULTS );
	} );

	it( 'sets type when stored', () => {
		const result = mergeSchema( JSON.stringify( { type: 'Review' } ) );
		expect( result.type ).toBe( 'Review' );
	} );

	// ── Review sub-object ──

	it( 'fills review defaults when stored review is empty object', () => {
		const result = mergeSchema( JSON.stringify( { type: 'Review', review: {} } ) );
		expect( result.review ).toEqual( SCHEMA_DEFAULTS.review );
	} );

	it( 'merges partial review data with review defaults', () => {
		const result = mergeSchema( JSON.stringify( {
			type:   'Review',
			review: { item_name: 'Dune', rating_value: '4' },
		} ) );
		expect( result.review.item_name ).toBe( 'Dune' );
		expect( result.review.rating_value ).toBe( '4' );
		// Defaults fill missing keys.
		expect( result.review.item_type ).toBe( 'Book' );
		expect( result.review.best_rating ).toBe( '5' );
		expect( result.review.review_body ).toBe( '' );
		expect( result.review.item_author ).toBe( '' );
	} );

	it( 'stores all review fields when fully populated', () => {
		const full = {
			item_name:    'Foundation',
			item_type:    'Book',
			item_author:  'Isaac Asimov',
			rating_value: '5',
			best_rating:  '5',
			review_body:  'A masterpiece of science fiction.',
		};
		const result = mergeSchema( JSON.stringify( { type: 'Review', review: full } ) );
		expect( result.review ).toEqual( full );
	} );

	it( 'preserves all other sub-types when type is Review', () => {
		const result = mergeSchema( JSON.stringify( {
			type:    'Review',
			product: { name: 'Widget', price: '9.99' },
		} ) );
		expect( result.product.name ).toBe( 'Widget' );
		expect( result.product.price ).toBe( '9.99' );
		expect( result.product.currency ).toBe( 'USD' ); // default filled
	} );

	it( 'keeps review defaults intact when a different schema type is selected', () => {
		const result = mergeSchema( JSON.stringify( { type: 'HowTo' } ) );
		expect( result.review ).toEqual( SCHEMA_DEFAULTS.review );
	} );

	// ── Regression: existing sub-types ──

	it( 'fills howto defaults for missing keys', () => {
		const result = mergeSchema( JSON.stringify( { howto: { description: 'desc' } } ) );
		expect( result.howto.description ).toBe( 'desc' );
		expect( Array.isArray( result.howto.steps ) ).toBe( true );
	} );

	it( 'fills product defaults for missing keys', () => {
		const result = mergeSchema( JSON.stringify( { product: { name: 'Gadget' } } ) );
		expect( result.product.name ).toBe( 'Gadget' );
		expect( result.product.currency ).toBe( 'USD' );
		expect( result.product.availability ).toBe( 'InStock' );
	} );

	it( 'fills local_business defaults for missing keys', () => {
		const result = mergeSchema( JSON.stringify( { local_business: { name: 'Cafe Mio' } } ) );
		expect( result.local_business.name ).toBe( 'Cafe Mio' );
		expect( result.local_business.business_type ).toBe( 'LocalBusiness' );
	} );
} );

// ── mergeAeo tests ────────────────────────────────────────────────────────────

describe( 'mergeAeo (AEO meta merge logic)', () => {
	it( 'returns AEO defaults on null/undefined/empty', () => {
		expect( mergeAeo( null ) ).toEqual( AEO_DEFAULTS );
		expect( mergeAeo( '' ) ).toEqual( AEO_DEFAULTS );
	} );

	it( 'returns AEO defaults on invalid JSON', () => {
		expect( mergeAeo( '{{broken' ) ).toEqual( AEO_DEFAULTS );
	} );

	it( 'parses a standard AEO object', () => {
		const stored = { summary: 'A summary', questions: [ { q: 'Q?', a: 'A.' } ], entities: [], keywords: [ 'seo', 'aeo' ] };
		const result = mergeAeo( JSON.stringify( stored ) );
		expect( result.summary ).toBe( 'A summary' );
		expect( result.questions ).toHaveLength( 1 );
		expect( result.keywords ).toContain( 'aeo' );
	} );

	it( 'entity with same_as field passes through unchanged', () => {
		const entity = { name: 'Anthropic', type: 'Organization', description: 'AI company', same_as: 'https://www.wikidata.org/wiki/Q107269070' };
		const result = mergeAeo( JSON.stringify( { entities: [ entity ] } ) );
		expect( result.entities[ 0 ].same_as ).toBe( 'https://www.wikidata.org/wiki/Q107269070' );
	} );

	it( 'entity without same_as has no same_as key', () => {
		const entity = { name: 'WordPress', type: 'Technology' };
		const result = mergeAeo( JSON.stringify( { entities: [ entity ] } ) );
		expect( result.entities[ 0 ].same_as ).toBeUndefined();
	} );

	it( 'preserves entity type, description, and name alongside same_as', () => {
		const entity = { name: 'OpenAI', type: 'Organization', description: 'AI lab', same_as: 'https://en.wikipedia.org/wiki/OpenAI' };
		const result = mergeAeo( JSON.stringify( { entities: [ entity ] } ) );
		const e = result.entities[ 0 ];
		expect( e.name ).toBe( 'OpenAI' );
		expect( e.type ).toBe( 'Organization' );
		expect( e.description ).toBe( 'AI lab' );
		expect( e.same_as ).toBe( 'https://en.wikipedia.org/wiki/OpenAI' );
	} );

	it( 'fills missing AEO keys with defaults', () => {
		const result = mergeAeo( JSON.stringify( { summary: 'Just a summary' } ) );
		expect( result.questions ).toEqual( [] );
		expect( result.entities ).toEqual( [] );
		expect( result.keywords ).toEqual( [] );
	} );
} );

// ── mergeSeo tests ────────────────────────────────────────────────────────────

describe( 'mergeSeo (SEO meta merge logic)', () => {
	it( 'returns SEO defaults on null/undefined/empty', () => {
		expect( mergeSeo( null ) ).toEqual( SEO_DEFAULTS );
		expect( mergeSeo( '' ) ).toEqual( SEO_DEFAULTS );
	} );

	it( 'returns SEO defaults on invalid JSON', () => {
		expect( mergeSeo( '{{broken' ) ).toEqual( SEO_DEFAULTS );
	} );

	it( 'parses title and meta_desc', () => {
		const result = mergeSeo( JSON.stringify( { title: 'My Title', meta_desc: 'A description' } ) );
		expect( result.title ).toBe( 'My Title' );
		expect( result.meta_desc ).toBe( 'A description' );
	} );

	it( 'noindex and nofollow default to false', () => {
		const result = mergeSeo( JSON.stringify( { title: 'Title' } ) );
		expect( result.noindex ).toBe( false );
		expect( result.nofollow ).toBe( false );
	} );

	it( 'noindex true is preserved', () => {
		const result = mergeSeo( JSON.stringify( { noindex: true } ) );
		expect( result.noindex ).toBe( true );
	} );

	it( 'fills missing SEO keys with defaults', () => {
		const result = mergeSeo( JSON.stringify( { title: 'Only Title' } ) );
		expect( result.canonical ).toBe( '' );
		expect( result.og_title ).toBe( '' );
		expect( result.og_desc ).toBe( '' );
		expect( result.og_image ).toBe( '' );
	} );
} );
