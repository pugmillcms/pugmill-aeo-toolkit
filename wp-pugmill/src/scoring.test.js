/**
 * WP Pugmill — unit tests for computeScore().
 *
 * Metrics are kept in sync with Pugmill CMS (calcAeoHealth in PostForm.tsx).
 * Pure function, no WordPress globals required.
 * Run via: npm test
 *
 * @package WPPugmill
 */

import { computeScore } from './scoring';

// ── Helpers ────────────────────────────────────────────────────────────────

const emptyAeo = { summary: '', questions: [], entities: [], keywords: [] };
const emptySeo = {};

function aeoWith( overrides ) {
	return { ...emptyAeo, ...overrides };
}

function optsWith( overrides ) {
	return { postContent: '', featuredImageAlt: null, ...overrides };
}

/**
 * Minimal postContent that passes all four content checks:
 *   - 400+ words
 *   - H2 subheading present
 *   - No H1
 *   - Opening paragraph ≤ 80 words
 *
 * Accepts optional extraWords injected into the body paragraph — use to
 * ensure specific keywords appear in plain text for keywords_in_content.
 */
function makeRichContent( openingWords = 10, totalWords = 420, extraWords = '' ) {
	const opening = Array.from( { length: openingWords }, ( _, i ) => `word${ i }` ).join( ' ' );
	const filler  = Array.from( { length: totalWords },  ( _, i ) => `filler${ i }` ).join( ' ' );
	return `<p>${ opening }</p><h2>A Heading</h2><p>${ filler }${ extraWords ? ' ' + extraWords : '' }</p>`;
}

// Keywords that will be injected into fullOpts content.
const FULL_KEYWORDS = [ 'pugmill', 'seo', 'aeo', 'content', 'optimization' ];

// Fully-populated inputs that yield score 100.
const fullAeo = aeoWith( {
	summary:   'A'.repeat( 80 ),
	questions: [ { q: 'Q1', a: 'A1' }, { q: 'Q2', a: 'A2' }, { q: 'Q3', a: 'A3' } ],
	entities:  [ { name: 'WP Pugmill', type: 'Product' } ],
	keywords:  FULL_KEYWORDS,
} );
const fullOpts = optsWith( {
	postContent:      makeRichContent( 10, 420, FULL_KEYWORDS.join( ' ' ) ),
	featuredImageAlt: 'A descriptive alt text',
} );

// ── Structural invariants ─────────────────────────────────────────────────

describe( 'computeScore — structure', () => {
	test( 'returns score, grade, color, items', () => {
		const result = computeScore( emptyAeo, emptySeo );
		expect( result ).toHaveProperty( 'score' );
		expect( result ).toHaveProperty( 'grade' );
		expect( result ).toHaveProperty( 'color' );
		expect( result ).toHaveProperty( 'items' );
		expect( Array.isArray( result.items ) ).toBe( true );
	} );

	test( 'returns exactly 12 scoring items', () => {
		const { items } = computeScore( emptyAeo, emptySeo );
		expect( items ).toHaveLength( 12 );
	} );

	test( 'all item points sum to 100', () => {
		const { items } = computeScore( emptyAeo, emptySeo );
		const total = items.reduce( ( sum, item ) => sum + item.points, 0 );
		expect( total ).toBe( 100 );
	} );

	test( 'score equals sum of points for passing items', () => {
		const { score, items } = computeScore( fullAeo, emptySeo, fullOpts );
		const sumPassing = items.filter( ( i ) => i.pass ).reduce( ( s, i ) => s + i.points, 0 );
		expect( score ).toBe( sumPassing );
	} );

	test( 'seo and options params default gracefully when omitted', () => {
		const { score } = computeScore( emptyAeo );
		expect( typeof score ).toBe( 'number' );
	} );
} );

// ── Score bounds ──────────────────────────────────────────────────────────

describe( 'computeScore — bounds', () => {
	test( 'empty inputs → score 5 (no_h1 passes by default)', () => {
		// no_h1 awards 5 pts even with empty content — no H1 tag present = pass.
		expect( computeScore( emptyAeo, emptySeo ).score ).toBe( 5 );
	} );

	test( 'fully populated → score 100', () => {
		expect( computeScore( fullAeo, emptySeo, fullOpts ).score ).toBe( 100 );
	} );

	test( 'score is never negative', () => {
		expect( computeScore( emptyAeo, emptySeo ).score ).toBeGreaterThanOrEqual( 0 );
	} );

	test( 'score is never greater than 100', () => {
		const aeo  = aeoWith( {
			summary:   'A'.repeat( 80 ),
			questions: Array.from( { length: 10 }, ( _, i ) => ( { q: `Q${ i }`, a: `A${ i }` } ) ),
			entities:  [ { name: 'X', type: 'Thing' } ],
			keywords:  [ 'a', 'b', 'c', 'd', 'e', 'f', 'g' ],
		} );
		const opts = optsWith( { postContent: makeRichContent(), featuredImageAlt: 'alt' } );
		expect( computeScore( aeo, emptySeo, opts ).score ).toBeLessThanOrEqual( 100 );
	} );
} );

// ── Content structure checks ──────────────────────────────────────────────

describe( 'computeScore — content length (15 pts)', () => {
	test( 'pass with 400+ words', () => {
		const content = `<p>${ Array( 401 ).fill( 'word' ).join( ' ' ) }</p>`;
		const { items } = computeScore( emptyAeo, emptySeo, optsWith( { postContent: content } ) );
		expect( items.find( ( i ) => i.id === 'content_length' ).pass ).toBe( true );
	} );

	test( 'pass at exactly 400 words', () => {
		const content = `<p>${ Array( 400 ).fill( 'word' ).join( ' ' ) }</p>`;
		const { items } = computeScore( emptyAeo, emptySeo, optsWith( { postContent: content } ) );
		expect( items.find( ( i ) => i.id === 'content_length' ).pass ).toBe( true );
	} );

	test( 'fail with 399 words', () => {
		const content = `<p>${ Array( 399 ).fill( 'word' ).join( ' ' ) }</p>`;
		const { items } = computeScore( emptyAeo, emptySeo, optsWith( { postContent: content } ) );
		expect( items.find( ( i ) => i.id === 'content_length' ).pass ).toBe( false );
	} );

	test( 'fail with empty postContent', () => {
		const { items } = computeScore( emptyAeo, emptySeo );
		expect( items.find( ( i ) => i.id === 'content_length' ).pass ).toBe( false );
	} );

	test( 'strips HTML tags and block comments before counting', () => {
		const words   = Array( 400 ).fill( 'word' ).join( ' ' );
		const content = `<!-- wp:paragraph --><p><strong>${ words }</strong></p><!-- /wp:paragraph -->`;
		const { items } = computeScore( emptyAeo, emptySeo, optsWith( { postContent: content } ) );
		expect( items.find( ( i ) => i.id === 'content_length' ).pass ).toBe( true );
	} );

	test( 'worth 15 points', () => {
		expect( computeScore( emptyAeo, emptySeo ).items.find( ( i ) => i.id === 'content_length' ).points ).toBe( 15 );
	} );
} );

describe( 'computeScore — H2/H3 subheadings (10 pts)', () => {
	test( 'pass when H2 present', () => {
		const { items } = computeScore( emptyAeo, emptySeo, optsWith( { postContent: '<h2>Heading</h2>' } ) );
		expect( items.find( ( i ) => i.id === 'has_headings' ).pass ).toBe( true );
	} );

	test( 'pass when H3 present', () => {
		const { items } = computeScore( emptyAeo, emptySeo, optsWith( { postContent: '<h3>Heading</h3>' } ) );
		expect( items.find( ( i ) => i.id === 'has_headings' ).pass ).toBe( true );
	} );

	test( 'fail when only H1 present', () => {
		const { items } = computeScore( emptyAeo, emptySeo, optsWith( { postContent: '<h1>Title</h1>' } ) );
		expect( items.find( ( i ) => i.id === 'has_headings' ).pass ).toBe( false );
	} );

	test( 'fail with no headings', () => {
		const { items } = computeScore( emptyAeo, emptySeo, optsWith( { postContent: '<p>Just a paragraph.</p>' } ) );
		expect( items.find( ( i ) => i.id === 'has_headings' ).pass ).toBe( false );
	} );

	test( 'fail with empty postContent', () => {
		const { items } = computeScore( emptyAeo, emptySeo );
		expect( items.find( ( i ) => i.id === 'has_headings' ).pass ).toBe( false );
	} );

	test( 'worth 10 points', () => {
		expect( computeScore( emptyAeo, emptySeo ).items.find( ( i ) => i.id === 'has_headings' ).points ).toBe( 10 );
	} );
} );

describe( 'computeScore — No H1 in body (5 pts)', () => {
	test( 'pass when content has no H1', () => {
		const { items } = computeScore( emptyAeo, emptySeo, optsWith( { postContent: '<h2>Fine</h2><p>Content here.</p>' } ) );
		expect( items.find( ( i ) => i.id === 'no_h1' ).pass ).toBe( true );
	} );

	test( 'pass with empty content', () => {
		const { items } = computeScore( emptyAeo, emptySeo );
		expect( items.find( ( i ) => i.id === 'no_h1' ).pass ).toBe( true );
	} );

	test( 'fail when an H1 is present', () => {
		const { items } = computeScore( emptyAeo, emptySeo, optsWith( { postContent: '<h1>Should not be here</h1><p>Body.</p>' } ) );
		expect( items.find( ( i ) => i.id === 'no_h1' ).pass ).toBe( false );
	} );

	test( 'fail when H1 appears alongside H2', () => {
		const { items } = computeScore( emptyAeo, emptySeo, optsWith( { postContent: '<h1>Bad</h1><h2>Good</h2>' } ) );
		expect( items.find( ( i ) => i.id === 'no_h1' ).pass ).toBe( false );
	} );

	test( 'worth 5 points', () => {
		expect( computeScore( emptyAeo, emptySeo ).items.find( ( i ) => i.id === 'no_h1' ).points ).toBe( 5 );
	} );
} );

describe( 'computeScore — opening paragraph ≤ 80 words (5 pts)', () => {
	test( 'pass when opening paragraph has exactly 80 words', () => {
		const para = Array( 80 ).fill( 'word' ).join( ' ' );
		const { items } = computeScore( emptyAeo, emptySeo, optsWith( { postContent: `<p>${ para }</p>` } ) );
		expect( items.find( ( i ) => i.id === 'opening_concise' ).pass ).toBe( true );
	} );

	test( 'pass when opening paragraph is short', () => {
		const { items } = computeScore( emptyAeo, emptySeo, optsWith( { postContent: '<p>Short opener.</p>' } ) );
		expect( items.find( ( i ) => i.id === 'opening_concise' ).pass ).toBe( true );
	} );

	test( 'fail when opening paragraph exceeds 80 words', () => {
		const para = Array( 81 ).fill( 'word' ).join( ' ' );
		const { items } = computeScore( emptyAeo, emptySeo, optsWith( { postContent: `<p>${ para }</p>` } ) );
		expect( items.find( ( i ) => i.id === 'opening_concise' ).pass ).toBe( false );
	} );

	test( 'fail when no paragraph found', () => {
		const { items } = computeScore( emptyAeo, emptySeo, optsWith( { postContent: '<h2>Only a heading</h2>' } ) );
		expect( items.find( ( i ) => i.id === 'opening_concise' ).pass ).toBe( false );
	} );

	test( 'fail with empty postContent', () => {
		const { items } = computeScore( emptyAeo, emptySeo );
		expect( items.find( ( i ) => i.id === 'opening_concise' ).pass ).toBe( false );
	} );
} );

// ── AEO metadata checks ───────────────────────────────────────────────────

describe( 'computeScore — Summary (10 + 5 pts)', () => {
	test( 'summary present: passes when non-empty', () => {
		const { items } = computeScore( aeoWith( { summary: 'Short.' } ), emptySeo );
		expect( items.find( ( i ) => i.id === 'summary_present' ).pass ).toBe( true );
	} );

	test( 'summary present: fails when empty', () => {
		const { items } = computeScore( emptyAeo, emptySeo );
		expect( items.find( ( i ) => i.id === 'summary_present' ).pass ).toBe( false );
	} );

	test( 'summary 80+ chars: passes at exactly 80 chars', () => {
		const { items } = computeScore( aeoWith( { summary: 'A'.repeat( 80 ) } ), emptySeo );
		expect( items.find( ( i ) => i.id === 'summary_length' ).pass ).toBe( true );
	} );

	test( 'summary 80+ chars: fails at 79 chars', () => {
		const { items } = computeScore( aeoWith( { summary: 'A'.repeat( 79 ) } ), emptySeo );
		expect( items.find( ( i ) => i.id === 'summary_length' ).pass ).toBe( false );
	} );

	test( 'summary 80+ chars: fails when summary absent', () => {
		const { items } = computeScore( emptyAeo, emptySeo );
		expect( items.find( ( i ) => i.id === 'summary_length' ).pass ).toBe( false );
	} );

	test( 'summary present worth 10 points', () => {
		expect( computeScore( emptyAeo, emptySeo ).items.find( ( i ) => i.id === 'summary_present' ).points ).toBe( 10 );
	} );

	test( 'summary length worth 5 points', () => {
		expect( computeScore( emptyAeo, emptySeo ).items.find( ( i ) => i.id === 'summary_length' ).points ).toBe( 5 );
	} );
} );

describe( 'computeScore — Q&A pairs (10 + 5 pts)', () => {
	test( '1+ Q&A: passes with one complete pair', () => {
		const { items } = computeScore( aeoWith( { questions: [ { q: 'Q?', a: 'A.' } ] } ), emptySeo );
		expect( items.find( ( i ) => i.id === 'qa_present' ).pass ).toBe( true );
	} );

	test( '1+ Q&A: fails with incomplete pair (no answer)', () => {
		const { items } = computeScore( aeoWith( { questions: [ { q: 'Q?', a: '' } ] } ), emptySeo );
		expect( items.find( ( i ) => i.id === 'qa_present' ).pass ).toBe( false );
	} );

	test( '3+ Q&A: passes with exactly 3 complete pairs', () => {
		const questions = [ { q: 'Q1', a: 'A1' }, { q: 'Q2', a: 'A2' }, { q: 'Q3', a: 'A3' } ];
		const { items } = computeScore( aeoWith( { questions } ), emptySeo );
		expect( items.find( ( i ) => i.id === 'qa_coverage' ).pass ).toBe( true );
	} );

	test( '3+ Q&A: fails with only 2 complete pairs', () => {
		const questions = [ { q: 'Q1', a: 'A1' }, { q: 'Q2', a: 'A2' } ];
		const { items } = computeScore( aeoWith( { questions } ), emptySeo );
		expect( items.find( ( i ) => i.id === 'qa_coverage' ).pass ).toBe( false );
	} );

	test( '3+ Q&A: counts only pairs with both q and a filled', () => {
		const questions = [
			{ q: 'Q1', a: 'A1' },
			{ q: 'Q2', a: '' },   // incomplete
			{ q: 'Q3', a: 'A3' },
			{ q: '',   a: 'A4' }, // incomplete
		];
		const { items } = computeScore( aeoWith( { questions } ), emptySeo );
		expect( items.find( ( i ) => i.id === 'qa_coverage' ).pass ).toBe( false );
	} );

	test( '1+ Q&A worth 10 points', () => {
		expect( computeScore( emptyAeo, emptySeo ).items.find( ( i ) => i.id === 'qa_present' ).points ).toBe( 10 );
	} );

	test( '3+ Q&A worth 5 points', () => {
		expect( computeScore( emptyAeo, emptySeo ).items.find( ( i ) => i.id === 'qa_coverage' ).points ).toBe( 5 );
	} );
} );

describe( 'computeScore — Named entities (10 pts)', () => {
	test( 'passes with one entity that has a name', () => {
		const { items } = computeScore( aeoWith( { entities: [ { name: 'WP Pugmill', type: 'Product' } ] } ), emptySeo );
		expect( items.find( ( i ) => i.id === 'entities_present' ).pass ).toBe( true );
	} );

	test( 'fails when entities array is empty', () => {
		const { items } = computeScore( emptyAeo, emptySeo );
		expect( items.find( ( i ) => i.id === 'entities_present' ).pass ).toBe( false );
	} );

	test( 'fails when entity has empty name', () => {
		const { items } = computeScore( aeoWith( { entities: [ { name: '', type: 'Thing' } ] } ), emptySeo );
		expect( items.find( ( i ) => i.id === 'entities_present' ).pass ).toBe( false );
	} );
} );

describe( 'computeScore — Keywords (10 pts)', () => {
	test( 'passes with exactly 5 non-empty keywords', () => {
		const { items } = computeScore( aeoWith( { keywords: [ 'a', 'b', 'c', 'd', 'e' ] } ), emptySeo );
		expect( items.find( ( i ) => i.id === 'keywords_present' ).pass ).toBe( true );
	} );

	test( 'fails with only 4 keywords', () => {
		const { items } = computeScore( aeoWith( { keywords: [ 'a', 'b', 'c', 'd' ] } ), emptySeo );
		expect( items.find( ( i ) => i.id === 'keywords_present' ).pass ).toBe( false );
	} );

	test( 'empty strings within the array do not count', () => {
		const { items } = computeScore( aeoWith( { keywords: [ 'a', 'b', 'c', 'd', '' ] } ), emptySeo );
		expect( items.find( ( i ) => i.id === 'keywords_present' ).pass ).toBe( false );
	} );
} );

describe( 'computeScore — Keywords found in content (10 pts)', () => {
	test( 'passes when at least one keyword appears in the post body', () => {
		const content = '<p>This post is about pugmill optimization.</p>';
		const aeo     = aeoWith( { keywords: [ 'pugmill', 'seo', 'aeo', 'content', 'strategy' ] } );
		const { items } = computeScore( aeo, emptySeo, optsWith( { postContent: content } ) );
		expect( items.find( ( i ) => i.id === 'keywords_in_content' ).pass ).toBe( true );
	} );

	test( 'is case-insensitive', () => {
		const content = '<p>WordPress SEO Plugin guide.</p>';
		const aeo     = aeoWith( { keywords: [ 'seo', 'plugin', 'guide', 'wordpress', 'tips' ] } );
		const { items } = computeScore( aeo, emptySeo, optsWith( { postContent: content } ) );
		expect( items.find( ( i ) => i.id === 'keywords_in_content' ).pass ).toBe( true );
	} );

	test( 'fails when no keywords appear in the content', () => {
		const content = '<p>This post is about cooking and recipes.</p>';
		const aeo     = aeoWith( { keywords: [ 'pugmill', 'seo', 'aeo', 'schema', 'structured' ] } );
		const { items } = computeScore( aeo, emptySeo, optsWith( { postContent: content } ) );
		expect( items.find( ( i ) => i.id === 'keywords_in_content' ).pass ).toBe( false );
	} );

	test( 'fails when keywords array is empty', () => {
		const content = '<p>Some content here.</p>';
		const { items } = computeScore( emptyAeo, emptySeo, optsWith( { postContent: content } ) );
		expect( items.find( ( i ) => i.id === 'keywords_in_content' ).pass ).toBe( false );
	} );

	test( 'fails with empty content', () => {
		const aeo = aeoWith( { keywords: [ 'a', 'b', 'c', 'd', 'e' ] } );
		const { items } = computeScore( aeo, emptySeo );
		expect( items.find( ( i ) => i.id === 'keywords_in_content' ).pass ).toBe( false );
	} );

	test( 'strips HTML before checking', () => {
		const content = '<p><strong>pugmill</strong> is great.</p>';
		const aeo     = aeoWith( { keywords: [ 'pugmill', 'seo', 'aeo', 'plugin', 'schema' ] } );
		const { items } = computeScore( aeo, emptySeo, optsWith( { postContent: content } ) );
		expect( items.find( ( i ) => i.id === 'keywords_in_content' ).pass ).toBe( true );
	} );

	test( 'worth 10 points', () => {
		expect( computeScore( emptyAeo, emptySeo ).items.find( ( i ) => i.id === 'keywords_in_content' ).points ).toBe( 10 );
	} );
} );

// ── Featured image alt check ──────────────────────────────────────────────

describe( 'computeScore — featured image alt (5 pts)', () => {
	test( 'passes when alt text is a non-empty string', () => {
		const { items } = computeScore( emptyAeo, emptySeo, optsWith( { featuredImageAlt: 'A dog sitting on a chair' } ) );
		expect( items.find( ( i ) => i.id === 'featured_image_alt' ).pass ).toBe( true );
	} );

	test( 'fails when alt text is null (no featured image)', () => {
		const { items } = computeScore( emptyAeo, emptySeo, optsWith( { featuredImageAlt: null } ) );
		expect( items.find( ( i ) => i.id === 'featured_image_alt' ).pass ).toBe( false );
	} );

	test( 'fails when alt text is empty string', () => {
		const { items } = computeScore( emptyAeo, emptySeo, optsWith( { featuredImageAlt: '' } ) );
		expect( items.find( ( i ) => i.id === 'featured_image_alt' ).pass ).toBe( false );
	} );

	test( 'fails when alt text is whitespace only', () => {
		const { items } = computeScore( emptyAeo, emptySeo, optsWith( { featuredImageAlt: '   ' } ) );
		expect( items.find( ( i ) => i.id === 'featured_image_alt' ).pass ).toBe( false );
	} );

	test( 'worth 5 points', () => {
		expect( computeScore( emptyAeo, emptySeo ).items.find( ( i ) => i.id === 'featured_image_alt' ).points ).toBe( 5 );
	} );
} );

// ── Item IDs ──────────────────────────────────────────────────────────────

describe( 'computeScore — item IDs', () => {
	const EXPECTED_IDS = [
		'content_length',
		'has_headings',
		'no_h1',
		'opening_concise',
		'summary_present',
		'summary_length',
		'qa_present',
		'qa_coverage',
		'entities_present',
		'keywords_present',
		'keywords_in_content',
		'featured_image_alt',
	];

	test( 'every item has an id property', () => {
		const { items } = computeScore( emptyAeo, emptySeo );
		for ( const item of items ) {
			expect( item ).toHaveProperty( 'id' );
			expect( typeof item.id ).toBe( 'string' );
			expect( item.id.length ).toBeGreaterThan( 0 );
		}
	} );

	test( 'item IDs match expected set in order', () => {
		const { items } = computeScore( emptyAeo, emptySeo );
		expect( items.map( ( i ) => i.id ) ).toEqual( EXPECTED_IDS );
	} );

	test( 'all IDs are unique', () => {
		const { items } = computeScore( emptyAeo, emptySeo );
		const ids = items.map( ( i ) => i.id );
		expect( new Set( ids ).size ).toBe( ids.length );
	} );

	test( 'AEO-fixable IDs are stable across different inputs', () => {
		const { items } = computeScore( aeoWith( { summary: 'Short' } ), emptySeo );
		for ( const id of [ 'summary_present', 'summary_length', 'qa_present', 'qa_coverage', 'entities_present', 'keywords_present' ] ) {
			expect( items.find( ( i ) => i.id === id ) ).toBeTruthy();
		}
	} );
} );

// ── Grade thresholds ──────────────────────────────────────────────────────
// Point map: content_length(15) has_headings(10) no_h1(5) opening_concise(5)
//            summary_present(10) summary_length(5) qa_present(10) qa_coverage(5)
//            entities_present(10) keywords_present(10) keywords_in_content(10)
//            featured_image_alt(5)

describe( 'computeScore — grade thresholds', () => {
	test( 'score 0 → Poor', () => {
		expect( computeScore( emptyAeo, emptySeo ).grade ).toBe( 'Poor' );
	} );

	test( 'score 40 → Fair', () => {
		// content_length(15) + has_headings(10) + no_h1(5) + summary_present(10) = 40
		// opening_concise fails (long opener), everything else empty/absent.
		const longOpening = Array( 90 ).fill( 'word' ).join( ' ' );
		const filler      = Array( 350 ).fill( 'filler' ).join( ' ' );
		const content     = `<p>${ longOpening }</p><h2>Heading</h2><p>${ filler }</p>`;
		const aeo         = aeoWith( { summary: 'Short.' } ); // present but < 80 chars
		const result      = computeScore( aeo, emptySeo, optsWith( { postContent: content } ) );
		expect( result.score ).toBe( 40 );
		expect( result.grade ).toBe( 'Fair' );
	} );

	test( 'score 70 → Good', () => {
		// All 4 content checks(35) + summary_present(10) + summary_length(5)
		// + qa_present(10) + entities_present(10) = 70
		// qa_coverage, keywords_present, keywords_in_content, featured_image_alt all fail.
		const aeo  = aeoWith( {
			summary:   'A'.repeat( 80 ),
			questions: [ { q: 'Q1', a: 'A1' } ], // only 1 — qa_coverage fails
			entities:  [ { name: 'X', type: 'Thing' } ],
			keywords:  [], // none — keywords_present and keywords_in_content fail
		} );
		const opts = optsWith( { postContent: makeRichContent() } ); // no featured alt
		const result = computeScore( aeo, emptySeo, opts );
		expect( result.score ).toBe( 70 );
		expect( result.grade ).toBe( 'Good' );
	} );

	test( 'score 90 → Excellent', () => {
		// All checks pass except qa_coverage(5) and featured_image_alt(5) = −10 → 90.
		const aeo  = aeoWith( {
			summary:   'A'.repeat( 80 ),
			questions: [ { q: 'Q1', a: 'A1' }, { q: 'Q2', a: 'A2' } ], // 2 — qa_coverage fails
			entities:  [ { name: 'X', type: 'Thing' } ],
			keywords:  FULL_KEYWORDS,
		} );
		const opts = optsWith( {
			postContent:      makeRichContent( 10, 420, FULL_KEYWORDS.join( ' ' ) ),
			featuredImageAlt: null, // featured_image_alt fails
		} );
		const result = computeScore( aeo, emptySeo, opts );
		expect( result.score ).toBe( 90 );
		expect( result.grade ).toBe( 'Excellent' );
	} );

	test( 'score 100 → Excellent', () => {
		expect( computeScore( fullAeo, emptySeo, fullOpts ).score ).toBe( 100 );
		expect( computeScore( fullAeo, emptySeo, fullOpts ).grade ).toBe( 'Excellent' );
	} );
} );
