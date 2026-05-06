/**
 * Pugmill AEO Toolkit — Client-side AEO Health score.
 *
 * Returns a 0–100 score reflecting how completely the AEO meta fields have
 * been filled in, combined with basic content structure checks. Used for the
 * sidebar AEO Health panel and the pre-publish panel.
 *
 * Metrics are kept in sync with Pugmill CMS (calcAeoHealth in PostForm.tsx).
 *
 * This is a field-completeness + structure check, NOT the same as the
 * server-side audit (aeopugmill_run_audit) which performs deeper quality checks.
 *
 * @package WPPugmill
 */

/**
 * Compute the AEO Health score for a post.
 *
 * @param {Object} aeo     AEO meta: { summary, questions, entities, keywords }
 * @param {Object} seo     SEO meta (unused in scoring, kept for API compatibility)
 * @param {Object} options { postContent: string, featuredImageAlt: string|null }
 * @return {{ score: number, grade: string, color: string, items: Array }}
 */
export function computeScore( aeo, seo = {}, options = {} ) {
	const postContent      = options.postContent      ?? '';
	const featuredImageAlt = options.featuredImageAlt ?? null;

	let score = 0;
	const items = [];

	// ── Content helpers ───────────────────────────────────────────────────────

	// Strip block serialisation comments and HTML tags to get plain text.
	const plainText = postContent
		.replace( /<!--[\s\S]*?-->/g, ' ' )
		.replace( /<[^>]*>/g, ' ' )
		.replace( /\s+/g, ' ' )
		.trim();
	const wordCount = plainText ? plainText.split( /\s+/ ).filter( Boolean ).length : 0;

	// Opening paragraph — innerText of the first <p> element.
	const firstParaMatch   = postContent.match( /<p[^>]*>([\s\S]*?)<\/p>/i );
	const firstParaText    = firstParaMatch
		? firstParaMatch[ 1 ].replace( /<[^>]*>/g, '' ).trim()
		: '';
	const openingWordCount = firstParaText
		? firstParaText.split( /\s+/ ).filter( Boolean ).length
		: 0;

	// ── Content length ────────────────────────────────────────────────────────
	const hasEnoughWords = wordCount >= 400;
	items.push( {
		id:       'content_length',
		label:    '400+ words',
		pass:     hasEnoughWords,
		points:   15,
		tip:      `Post has ${ wordCount } word${ wordCount === 1 ? '' : 's' } — aim for 400+ for meaningful depth.`,
		category: 'content',
	} );
	if ( hasEnoughWords ) score += 15;

	// ── Subheadings ───────────────────────────────────────────────────────────
	const hasHeadings = /<h[23]/i.test( postContent );
	items.push( {
		id:       'has_headings',
		label:    'H2/H3 subheadings present',
		pass:     hasHeadings,
		points:   10,
		tip:      'Break up your content with H2 or H3 subheadings.',
		category: 'content',
	} );
	if ( hasHeadings ) score += 10;

	// ── No H1 in body ─────────────────────────────────────────────────────────
	const noH1 = ! /<h1/i.test( postContent );
	items.push( {
		id:       'no_h1',
		label:    'No H1 in body',
		pass:     noH1,
		points:   5,
		tip:      'Remove any H1 headings — the post title already provides one.',
		category: 'content',
	} );
	if ( noH1 ) score += 5;

	// ── Opening paragraph conciseness ─────────────────────────────────────────
	const openingConcise = openingWordCount > 0 && openingWordCount <= 80;
	items.push( {
		id:       'opening_concise',
		label:    'Opening paragraph ≤ 80 words',
		pass:     openingConcise,
		points:   5,
		tip:      'Keep your opening paragraph under 80 words for scannability.',
		category: 'content',
	} );
	if ( openingConcise ) score += 5;

	// ── Summary present ───────────────────────────────────────────────────────
	const hasSummary = !! ( aeo.summary && aeo.summary.trim() );
	items.push( {
		id:       'summary_present',
		label:    'Summary written',
		pass:     hasSummary,
		points:   10,
		tip:      'Add a 2–3 sentence summary for AI crawlers.',
		category: 'aeo',
	} );
	if ( hasSummary ) score += 10;

	// ── Summary length ────────────────────────────────────────────────────────
	const summaryLong = hasSummary && aeo.summary.length >= 80;
	items.push( {
		id:       'summary_length',
		label:    'Summary 80+ chars',
		pass:     summaryLong,
		points:   5,
		tip:      'Expand your summary to at least 80 characters.',
		category: 'aeo',
	} );
	if ( summaryLong ) score += 5;

	// ── Q&A pairs ─────────────────────────────────────────────────────────────
	const qaCount = ( aeo.questions || [] ).filter( ( q ) => q.q && q.a ).length;

	const hasQa = qaCount >= 1;
	items.push( {
		id:       'qa_present',
		label:    'At least 1 Q&A pair',
		pass:     hasQa,
		points:   10,
		tip:      'Add a Q&A pair — generates FAQPage schema.',
		category: 'aeo',
	} );
	if ( hasQa ) score += 10;

	const hasThreeQa = qaCount >= 3;
	items.push( {
		id:       'qa_coverage',
		label:    '3+ Q&A pairs',
		pass:     hasThreeQa,
		points:   5,
		tip:      'Add at least 3 Q&A pairs for better FAQ coverage.',
		category: 'aeo',
	} );
	if ( hasThreeQa ) score += 5;

	// ── Entities ──────────────────────────────────────────────────────────────
	const hasEntities = ( aeo.entities || [] ).filter( ( e ) => e.name ).length >= 1;
	items.push( {
		id:       'entities_present',
		label:    'Named entity tagged',
		pass:     hasEntities,
		points:   10,
		tip:      'Tag key people, orgs, products, or concepts.',
		category: 'aeo',
	} );
	if ( hasEntities ) score += 10;

	// ── Keywords ──────────────────────────────────────────────────────────────
	const keywords = ( aeo.keywords || [] ).filter( ( k ) => k.length > 0 );
	const hasKeywords = keywords.length >= 5;
	items.push( {
		id:       'keywords_present',
		label:    '5+ keywords',
		pass:     hasKeywords,
		points:   10,
		tip:      'Add at least 5 search-focused keywords.',
		category: 'aeo',
	} );
	if ( hasKeywords ) score += 10;

	// ── Keywords in content ───────────────────────────────────────────────────
	const plainLower = plainText.toLowerCase();
	const hasKeywordsInContent = keywords.length > 0 &&
		keywords.some( ( k ) => plainLower.includes( k.toLowerCase() ) );
	items.push( {
		id:       'keywords_in_content',
		label:    'Keywords found in content',
		pass:     hasKeywordsInContent,
		points:   10,
		tip:      'Ensure your AEO keywords appear naturally in the post body.',
		category: 'aeo',
	} );
	if ( hasKeywordsInContent ) score += 10;

	// ── Featured image alt text ───────────────────────────────────────────────
	const hasImageAlt = typeof featuredImageAlt === 'string' && featuredImageAlt.trim().length > 0;
	items.push( {
		id:       'featured_image_alt',
		label:    'Featured image has alt text',
		pass:     hasImageAlt,
		points:   5,
		tip:      'Add alt text to your featured image for accessibility and SEO.',
		category: 'seo',
	} );
	if ( hasImageAlt ) score += 5;

	// ── Grade / color ─────────────────────────────────────────────────────────
	let grade, color;
	if ( score >= 90 )      { grade = 'Excellent'; color = '#46b450'; }
	else if ( score >= 70 ) { grade = 'Good';      color = '#00a0d2'; }
	else if ( score >= 40 ) { grade = 'Fair';      color = '#ffb900'; }
	else                    { grade = 'Poor';       color = '#dc3232'; }

	return { score, grade, color, items };
}
