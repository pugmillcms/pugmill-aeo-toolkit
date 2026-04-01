/**
 * Tests for src/constants.js
 *
 * Covers the static shape of exported constants — no WordPress runtime needed.
 */

import {
	SCHEMA_DEFAULTS,
	SCHEMA_TYPE_OPTIONS,
	SCHEMA_TYPE_DESCRIPTIONS,
	REVIEW_ITEM_TYPE_OPTIONS,
	ENTITY_TYPE_OPTIONS,
	LOCAL_BUSINESS_TYPE_OPTIONS,
	PRODUCT_AVAILABILITY_OPTIONS,
	IS_AI_MODE,
} from '../constants';

// ── SCHEMA_DEFAULTS ───────────────────────────────────────────────────────────

describe( 'SCHEMA_DEFAULTS', () => {
	it( 'has a top-level type key defaulting to empty string', () => {
		expect( SCHEMA_DEFAULTS.type ).toBe( '' );
	} );

	it( 'has all expected schema type sub-objects', () => {
		expect( SCHEMA_DEFAULTS ).toHaveProperty( 'howto' );
		expect( SCHEMA_DEFAULTS ).toHaveProperty( 'product' );
		expect( SCHEMA_DEFAULTS ).toHaveProperty( 'event' );
		expect( SCHEMA_DEFAULTS ).toHaveProperty( 'local_business' );
		expect( SCHEMA_DEFAULTS ).toHaveProperty( 'video' );
		expect( SCHEMA_DEFAULTS ).toHaveProperty( 'review' );
	} );

	it( 'has correct review defaults shape', () => {
		expect( SCHEMA_DEFAULTS.review ).toEqual( {
			item_name:    '',
			item_type:    'Book',
			item_author:  '',
			rating_value: '5',
			best_rating:  '5',
			review_body:  '',
		} );
	} );

	it( 'review default item_type is Book', () => {
		expect( SCHEMA_DEFAULTS.review.item_type ).toBe( 'Book' );
	} );

	it( 'review default rating_value is 5 (string)', () => {
		expect( SCHEMA_DEFAULTS.review.rating_value ).toBe( '5' );
		expect( SCHEMA_DEFAULTS.review.best_rating ).toBe( '5' );
	} );

	it( 'howto defaults have steps as empty array', () => {
		expect( Array.isArray( SCHEMA_DEFAULTS.howto.steps ) ).toBe( true );
		expect( SCHEMA_DEFAULTS.howto.steps ).toHaveLength( 0 );
	} );

	it( 'product defaults have USD currency and InStock availability', () => {
		expect( SCHEMA_DEFAULTS.product.currency ).toBe( 'USD' );
		expect( SCHEMA_DEFAULTS.product.availability ).toBe( 'InStock' );
	} );
} );

// ── SCHEMA_TYPE_OPTIONS ────────────────────────────────────────────────────────

describe( 'SCHEMA_TYPE_OPTIONS', () => {
	const values = SCHEMA_TYPE_OPTIONS.map( ( o ) => o.value );

	it( 'includes an empty-string "none" option', () => {
		expect( values ).toContain( '' );
	} );

	it( 'includes all original schema types', () => {
		expect( values ).toContain( 'HowTo' );
		expect( values ).toContain( 'Product' );
		expect( values ).toContain( 'Event' );
		expect( values ).toContain( 'LocalBusiness' );
		expect( values ).toContain( 'VideoObject' );
	} );

	it( 'includes Review', () => {
		expect( values ).toContain( 'Review' );
	} );

	it( 'every option has a non-empty label', () => {
		SCHEMA_TYPE_OPTIONS.forEach( ( { label } ) => {
			expect( typeof label ).toBe( 'string' );
			expect( label.length ).toBeGreaterThan( 0 );
		} );
	} );
} );

// ── SCHEMA_TYPE_DESCRIPTIONS ──────────────────────────────────────────────────

describe( 'SCHEMA_TYPE_DESCRIPTIONS', () => {
	it( 'has a description for every SCHEMA_TYPE_OPTIONS value', () => {
		SCHEMA_TYPE_OPTIONS.forEach( ( { value } ) => {
			expect( SCHEMA_TYPE_DESCRIPTIONS ).toHaveProperty( value );
			expect( typeof SCHEMA_TYPE_DESCRIPTIONS[ value ] ).toBe( 'string' );
			expect( SCHEMA_TYPE_DESCRIPTIONS[ value ].length ).toBeGreaterThan( 0 );
		} );
	} );

	it( 'Review description mentions star rating or rich snippet', () => {
		const desc = SCHEMA_TYPE_DESCRIPTIONS.Review.toLowerCase();
		expect( desc ).toMatch( /rating|review|snippet/i );
	} );
} );

// ── REVIEW_ITEM_TYPE_OPTIONS ──────────────────────────────────────────────────

describe( 'REVIEW_ITEM_TYPE_OPTIONS', () => {
	it( 'is a non-empty array', () => {
		expect( Array.isArray( REVIEW_ITEM_TYPE_OPTIONS ) ).toBe( true );
		expect( REVIEW_ITEM_TYPE_OPTIONS.length ).toBeGreaterThan( 0 );
	} );

	it( 'every option has label and value strings', () => {
		REVIEW_ITEM_TYPE_OPTIONS.forEach( ( { label, value } ) => {
			expect( typeof label ).toBe( 'string' );
			expect( typeof value ).toBe( 'string' );
			expect( label.length ).toBeGreaterThan( 0 );
			expect( value.length ).toBeGreaterThan( 0 );
		} );
	} );

	it( 'includes Book, Product, Movie, and Thing', () => {
		const values = REVIEW_ITEM_TYPE_OPTIONS.map( ( o ) => o.value );
		expect( values ).toContain( 'Book' );
		expect( values ).toContain( 'Product' );
		expect( values ).toContain( 'Movie' );
		expect( values ).toContain( 'Thing' );
	} );

	it( 'includes SoftwareApplication and Course', () => {
		const values = REVIEW_ITEM_TYPE_OPTIONS.map( ( o ) => o.value );
		expect( values ).toContain( 'SoftwareApplication' );
		expect( values ).toContain( 'Course' );
	} );
} );

// ── ENTITY_TYPE_OPTIONS ───────────────────────────────────────────────────────

describe( 'ENTITY_TYPE_OPTIONS', () => {
	it( 'includes Person, Organization, Place, and Thing', () => {
		const values = ENTITY_TYPE_OPTIONS.map( ( o ) => o.value );
		expect( values ).toContain( 'Person' );
		expect( values ).toContain( 'Organization' );
		expect( values ).toContain( 'Place' );
		expect( values ).toContain( 'Thing' );
	} );

	it( 'includes DefinedTerm', () => {
		const values = ENTITY_TYPE_OPTIONS.map( ( o ) => o.value );
		expect( values ).toContain( 'DefinedTerm' );
	} );
} );

// ── IS_AI_MODE ────────────────────────────────────────────────────────────────

describe( 'IS_AI_MODE', () => {
	it( 'is false when window.wppugmill is not set (defaults to free mode)', () => {
		// window.wppugmill is undefined in test env; mode defaults to 'free'
		expect( IS_AI_MODE ).toBe( false );
	} );
} );
