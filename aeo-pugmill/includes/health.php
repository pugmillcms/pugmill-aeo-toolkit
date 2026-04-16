<?php
/**
 * AEO Health — per-post score and checklist.
 *
 * Scores content structure and AEO completeness on a 0–100 scale.
 * This is the server-side mirror of src/scoring.js — both must produce
 * identical results for the same post. scoring.js is the source of truth;
 * any change to checks or point values must be applied to both files.
 *
 * Scoring breakdown (total 100pts):
 *   400+ words                    15pts  content
 *   H2/H3 subheadings present     10pts  content
 *   No H1 in body                  5pts  content
 *   Opening paragraph ≤ 80 words   5pts  content
 *   Summary written               10pts  aeo
 *   Summary 80+ chars              5pts  aeo
 *   At least 1 Q&A pair           10pts  aeo
 *   3+ Q&A pairs                   5pts  aeo
 *   Named entity tagged           10pts  aeo
 *   5+ keywords                   10pts  aeo
 *   Keywords found in content     10pts  aeo
 *   Featured image has alt text    5pts  aeo
 *                                ------
 *   Max                          100pts
 *
 * @package WPPugmill
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Calculate the content-structure sub-score for a post (0–35).
 *
 * These are the four HITL checks that depend on post content alone:
 *   400+ words (15), H2/H3 present (10), no H1 in body (5), opening ≤80 words (5).
 *
 * Stored separately as _aeopugmill_content_score so the Audit AEO tab can
 * sort by content-readiness without re-running the full health check.
 *
 * @param  int    $post_id
 * @param  string $content  Optional pre-fetched post_content (avoids a DB call).
 * @return int
 */
function aeopugmill_content_score( $post_id, $content = null ) {
	if ( null === $content ) {
		$content = get_post_field( 'post_content', $post_id );
	}

	$plain = preg_replace( '/<!--[\s\S]*?-->/', ' ', $content );
	$plain = wp_strip_all_tags( $plain );
	$plain = trim( preg_replace( '/\s+/', ' ', $plain ) );
	$words = $plain ? count( preg_split( '/\s+/', $plain, -1, PREG_SPLIT_NO_EMPTY ) ) : 0;

	preg_match( '/<p[^>]*>([\s\S]*?)<\/p>/i', $content, $m );
	$opening       = isset( $m[1] ) ? wp_strip_all_tags( $m[1] ) : '';
	$opening_words = $opening ? count( preg_split( '/\s+/', $opening, -1, PREG_SPLIT_NO_EMPTY ) ) : 0;

	$score  = 0;
	if ( $words >= 400 )                                           $score += 15;
	if ( preg_match( '/<h[23]/i', $content ) )                    $score += 10;
	if ( ! preg_match( '/<h1/i', $content ) )                     $score += 5;
	if ( $opening_words > 0 && $opening_words <= 80 )             $score += 5;

	return $score;
}

/**
 * Calculate the AEO health score and checklist for a post.
 *
 * @param  int   $post_id
 * @return array{score: int, grade: string, color: string, items: array}
 */
function aeopugmill_health_score( $post_id ) {
	$aeo     = aeopugmill_get_aeo( $post_id );
	$content = get_post_field( 'post_content', $post_id );
	$items   = array();
	$score   = 0;

	// ── Content helpers ───────────────────────────────────────────────────────
	// Strip block serialisation comments then HTML to get plain text —
	// mirrors the JS plainText calculation in scoring.js exactly.
	$plain_text = preg_replace( '/<!--[\s\S]*?-->/', ' ', $content );
	$plain_text = wp_strip_all_tags( $plain_text );
	$plain_text = trim( preg_replace( '/\s+/', ' ', $plain_text ) );
	// Use preg_split instead of str_word_count() to match the JS
	// .split(/\s+/).filter(Boolean).length — str_word_count() is
	// locale-dependent and can produce different results.
	$word_count = $plain_text ? count( preg_split( '/\s+/', $plain_text, -1, PREG_SPLIT_NO_EMPTY ) ) : 0;

	// Opening paragraph — first <p>…</p> in the raw content.
	preg_match( '/<p[^>]*>([\s\S]*?)<\/p>/i', $content, $first_para );
	$opening_text       = ! empty( $first_para[1] ) ? wp_strip_all_tags( $first_para[1] ) : '';
	$opening_word_count = $opening_text ? count( preg_split( '/\s+/', $opening_text, -1, PREG_SPLIT_NO_EMPTY ) ) : 0;

	// ── Content length ────────────────────────────────────────────────────────
	$has_enough_words = $word_count >= 400;
	$items[] = array(
		'id'       => 'content_length',
		'label'    => __( '400+ words', 'aeo-pugmill' ),
		'pass'     => $has_enough_words,
		'points'   => 15,
		/* translators: %d: current word count */
		'tip'      => sprintf( __( 'Post has %d word(s) — aim for 400+ for meaningful depth.', 'aeo-pugmill' ), $word_count ),
		'category' => 'content',
	);
	if ( $has_enough_words ) $score += 15;

	// ── Subheadings ───────────────────────────────────────────────────────────
	$has_headings = (bool) preg_match( '/<h[23]/i', $content );
	$items[] = array(
		'id'       => 'has_headings',
		'label'    => __( 'H2/H3 subheadings present', 'aeo-pugmill' ),
		'pass'     => $has_headings,
		'points'   => 10,
		'tip'      => __( 'Break up your content with H2 or H3 subheadings.', 'aeo-pugmill' ),
		'category' => 'content',
	);
	if ( $has_headings ) $score += 10;

	// ── No H1 in body ─────────────────────────────────────────────────────────
	$no_h1 = ! preg_match( '/<h1/i', $content );
	$items[] = array(
		'id'       => 'no_h1',
		'label'    => __( 'No H1 in body', 'aeo-pugmill' ),
		'pass'     => $no_h1,
		'points'   => 5,
		'tip'      => __( 'Remove any H1 headings — the post title already provides one.', 'aeo-pugmill' ),
		'category' => 'content',
	);
	if ( $no_h1 ) $score += 5;

	// ── Opening paragraph conciseness ─────────────────────────────────────────
	$opening_concise = $opening_word_count > 0 && $opening_word_count <= 80;
	$items[] = array(
		'id'       => 'opening_concise',
		'label'    => __( 'Opening paragraph ≤ 80 words', 'aeo-pugmill' ),
		'pass'     => $opening_concise,
		'points'   => 5,
		'tip'      => __( 'Keep your opening paragraph under 80 words for scannability.', 'aeo-pugmill' ),
		'category' => 'content',
	);
	if ( $opening_concise ) $score += 5;

	// ── Summary present ───────────────────────────────────────────────────────
	$has_summary = ! empty( trim( $aeo['summary'] ) );
	$items[] = array(
		'id'       => 'summary_present',
		'label'    => __( 'Summary written', 'aeo-pugmill' ),
		'pass'     => $has_summary,
		'points'   => 10,
		'tip'      => __( 'Add a 2–3 sentence summary for AI crawlers.', 'aeo-pugmill' ),
		'category' => 'aeo',
	);
	if ( $has_summary ) $score += 10;

	// ── Summary length ────────────────────────────────────────────────────────
	$summary_long = $has_summary && strlen( $aeo['summary'] ) >= 80;
	$items[] = array(
		'id'       => 'summary_length',
		'label'    => __( 'Summary 80+ chars', 'aeo-pugmill' ),
		'pass'     => $summary_long,
		'points'   => 5,
		'tip'      => __( 'Expand your summary to at least 80 characters.', 'aeo-pugmill' ),
		'category' => 'aeo',
	);
	if ( $summary_long ) $score += 5;

	// ── Q&A pairs ─────────────────────────────────────────────────────────────
	$qa_count = count( array_filter( $aeo['questions'], function( $q ) {
		return ! empty( $q['q'] ) && ! empty( $q['a'] );
	} ) );

	$has_qa = $qa_count >= 1;
	$items[] = array(
		'id'       => 'qa_present',
		'label'    => __( 'At least 1 Q&A pair', 'aeo-pugmill' ),
		'pass'     => $has_qa,
		'points'   => 10,
		'tip'      => __( 'Add a Q&A pair — generates FAQPage schema.', 'aeo-pugmill' ),
		'category' => 'aeo',
	);
	if ( $has_qa ) $score += 10;

	$has_three_qa = $qa_count >= 3;
	$items[] = array(
		'id'       => 'qa_coverage',
		'label'    => __( '3+ Q&A pairs', 'aeo-pugmill' ),
		'pass'     => $has_three_qa,
		'points'   => 5,
		'tip'      => __( 'Add at least 3 Q&A pairs for better FAQ coverage.', 'aeo-pugmill' ),
		'category' => 'aeo',
	);
	if ( $has_three_qa ) $score += 5;

	// ── Entities ──────────────────────────────────────────────────────────────
	$has_entities = count( array_filter( $aeo['entities'], function( $e ) {
		return ! empty( $e['name'] );
	} ) ) >= 1;
	$items[] = array(
		'id'       => 'entities_present',
		'label'    => __( 'Named entity tagged', 'aeo-pugmill' ),
		'pass'     => $has_entities,
		'points'   => 10,
		'tip'      => __( 'Tag key people, orgs, products, or concepts.', 'aeo-pugmill' ),
		'category' => 'aeo',
	);
	if ( $has_entities ) $score += 10;

	// ── Keywords ──────────────────────────────────────────────────────────────
	$keywords     = array_values( array_filter( $aeo['keywords'], 'strlen' ) );
	$has_keywords = count( $keywords ) >= 5;
	$items[] = array(
		'id'       => 'keywords_present',
		'label'    => __( '5+ keywords', 'aeo-pugmill' ),
		'pass'     => $has_keywords,
		'points'   => 10,
		'tip'      => __( 'Add at least 5 search-focused keywords.', 'aeo-pugmill' ),
		'category' => 'aeo',
	);
	if ( $has_keywords ) $score += 10;

	// ── Keywords in content ───────────────────────────────────────────────────
	$plain_lower          = mb_strtolower( $plain_text );
	$keywords_in_content  = ! empty( $keywords ) && count( array_filter( $keywords, function( $k ) use ( $plain_lower ) {
		return false !== mb_strpos( $plain_lower, mb_strtolower( $k ) );
	} ) ) > 0;
	$items[] = array(
		'id'       => 'keywords_in_content',
		'label'    => __( 'Keywords found in content', 'aeo-pugmill' ),
		'pass'     => $keywords_in_content,
		'points'   => 10,
		'tip'      => __( 'Ensure your AEO keywords appear naturally in the post body.', 'aeo-pugmill' ),
		'category' => 'aeo',
	);
	if ( $keywords_in_content ) $score += 10;

	// ── Featured image alt text ───────────────────────────────────────────────
	$thumb_id      = get_post_thumbnail_id( $post_id );
	$has_image_alt = false;
	if ( $thumb_id ) {
		$alt           = get_post_meta( (int) $thumb_id, '_wp_attachment_image_alt', true );
		$has_image_alt = ! empty( trim( (string) $alt ) );
	}
	$items[] = array(
		'id'       => 'featured_image_alt',
		'label'    => __( 'Featured image has alt text', 'aeo-pugmill' ),
		'pass'     => $has_image_alt,
		'points'   => 5,
		'tip'      => __( 'Add alt text to your featured image for accessibility and AEO.', 'aeo-pugmill' ),
		'category' => 'aeo',
	);
	if ( $has_image_alt ) $score += 5;

	// ── Grade / colour ────────────────────────────────────────────────────────
	if ( $score >= 90 )     { $grade = __( 'Excellent', 'aeo-pugmill' ); $color = '#46b450'; }
	elseif ( $score >= 70 ) { $grade = __( 'Good', 'aeo-pugmill' );      $color = '#00a0d2'; }
	elseif ( $score >= 40 ) { $grade = __( 'Fair', 'aeo-pugmill' );      $color = '#ffb900'; }
	else                    { $grade = __( 'Poor', 'aeo-pugmill' );      $color = '#dc3232'; }

	return array(
		'score' => $score,
		'grade' => $grade,
		'color' => $color,
		'items' => $items,
	);
}

/**
 * AJAX — calculate, store, and return scores for a batch of post IDs.
 *
 * Used by the Audit AEO tab for two purposes:
 *   1. Live update of unscored rows on the current page after load.
 *   2. Site-wide backfill batches triggered by the "Calculate All" button.
 *
 * Returns a map of post_id → { score, grade, color, missing[] }.
 */
function aeopugmill_ajax_calculate_scores() {
	check_ajax_referer( 'aeopugmill_calculate_scores', 'nonce' );
	if ( ! current_user_can( 'edit_posts' ) ) {
		wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'aeo-pugmill' ) ), 403 );
	}

	$post_ids = isset( $_POST['post_ids'] ) ? array_map( 'absint', (array) wp_unslash( $_POST['post_ids'] ) ) : array();
	$post_ids = array_values( array_filter( $post_ids ) );

	if ( empty( $post_ids ) ) {
		wp_send_json_success( array() );
	}

	// Map health-check item IDs to the field labels shown in the Audit table.
	// Deriving "missing" directly from the score items guarantees the
	// displayed score and the missing-field tags can never disagree.
	$item_to_field = array(
		'summary_present' => 'Summary',
		'qa_present'      => 'Q&A',
		'entities_present' => 'Entities',
		'keywords_present' => 'Keywords',
	);

	$results = array();
	foreach ( $post_ids as $post_id ) {
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			continue;
		}
		$health        = aeopugmill_health_score( $post_id );
		$content_score = aeopugmill_content_score( $post_id );
		update_post_meta( $post_id, '_aeopugmill_score',         (int) $health['score'] );
		update_post_meta( $post_id, '_aeopugmill_content_score', (int) $content_score );

		// Derive missing fields from the score's own item results — single
		// source of truth, no second aeopugmill_get_aeo() call needed.
		$missing = array();
		foreach ( $health['items'] as $item ) {
			if ( ! $item['pass'] && isset( $item_to_field[ $item['id'] ] ) ) {
				$missing[] = $item_to_field[ $item['id'] ];
			}
		}

		$results[ $post_id ] = array(
			'score'   => $health['score'],
			'grade'   => $health['grade'],
			'color'   => $health['color'],
			'missing' => $missing,
		);
	}

	wp_send_json_success( $results );
}
add_action( 'wp_ajax_aeopugmill_calculate_scores', 'aeopugmill_ajax_calculate_scores' );

/**
 * Register the AEO Health meta box (classic editor only).
 *
 * Suppressed in the block editor — the Gutenberg sidebar shows the score.
 */
function aeopugmill_add_health_meta_box() {
	if ( aeopugmill_is_block_editor() ) {
		return;
	}
	$post_types = get_post_types( array( 'public' => true ) );
	add_meta_box(
		'aeopugmill_health',
		__( 'AEO Health Score', 'aeo-pugmill' ),
		'aeopugmill_render_health_meta_box',
		array_values( $post_types ),
		'side',
		'high'
	);
}
add_action( 'add_meta_boxes', 'aeopugmill_add_health_meta_box' );

/**
 * Render the AEO Health meta box (classic editor).
 */
function aeopugmill_render_health_meta_box( $post ) {
	$health = aeopugmill_health_score( $post->ID );
	$score  = $health['score'];
	$grade  = $health['grade'];
	$color  = $health['color'];
	$items  = $health['items'];
	$mode   = aeopugmill_mode();
	?>
	<div id="aeopugmill-health" style="font-family:-apple-system,sans-serif;">

		<!-- Score ring -->
		<div style="text-align:center; padding:12px 0 8px;">
			<svg width="80" height="80" viewBox="0 0 80 80" style="transform:rotate(-90deg);">
				<circle cx="40" cy="40" r="34" fill="none" stroke="#e0e0e0" stroke-width="8"/>
				<circle cx="40" cy="40" r="34" fill="none"
					stroke="<?php echo esc_attr( $color ); ?>"
					stroke-width="8"
					stroke-dasharray="<?php echo (float) round( 213.6 * $score / 100, 1 ); ?> 213.6"
					stroke-linecap="round"/>
			</svg>
			<div style="margin-top:-58px; margin-bottom:34px;">
				<span style="font-size:22px; font-weight:700; color:<?php echo esc_attr( $color ); ?>;"><?php echo absint( $score ); ?></span>
				<span style="font-size:11px; color:#666;">/100</span>
			</div>
			<div style="font-size:13px; font-weight:600; color:<?php echo esc_attr( $color ); ?>;">
				<?php echo esc_html( $grade ); ?>
			</div>
		</div>

		<!-- Checklist -->
		<ul style="margin:12px 0 0; padding:0; list-style:none;">
			<?php foreach ( $items as $item ) : ?>
			<li style="display:flex; align-items:flex-start; gap:6px; margin-bottom:6px; font-size:12px;">
				<span style="color:<?php echo esc_attr( $item['pass'] ? '#46b450' : '#dc3232' ); ?>; flex-shrink:0; margin-top:1px;">
					<?php echo esc_html( $item['pass'] ? '✓' : '✗' ); ?>
				</span>
				<span style="color:<?php echo esc_attr( $item['pass'] ? '#333' : '#666' ); ?>;">
					<?php echo esc_html( $item['label'] ); ?>
					<?php if ( ! $item['pass'] ) : ?>
						<span style="color:#999; font-size:11px; display:block; margin-top:1px;">
							<?php echo esc_html( $item['tip'] ); ?>
						</span>
					<?php endif; ?>
				</span>
				<span style="margin-left:auto; flex-shrink:0; color:<?php echo esc_attr( $item['pass'] ? '#46b450' : '#bbb' ); ?>; font-size:11px;">
					+<?php echo absint( $item['points'] ); ?>
				</span>
			</li>
			<?php endforeach; ?>
		</ul>

		<?php if ( $score < 100 && ( 'ai' === $mode || 'pro' === $mode ) ) : ?>
			<p style="margin:12px 0 0; font-size:11px; color:#666; text-align:center;">
				<?php echo wp_kses( __( 'Click <strong>Generate with AI</strong> to auto-complete missing fields.', 'aeo-pugmill' ), array( 'strong' => array() ) ); ?>
			</p>
		<?php elseif ( $score < 100 && 'free' === $mode ) : ?>
			<p style="margin:12px 0 0; font-size:11px; color:#666; text-align:center;">
				<?php printf(
					'<a href="%1$s" target="_blank">%2$s</a> %3$s',
					esc_url( 'https://aeopugmill.com/pricing' ),
					esc_html__( 'Get AEO Pugmill Pro', 'aeo-pugmill' ),
					esc_html__( 'to auto-complete these fields.', 'aeo-pugmill' )
				); ?>
			</p>
		<?php endif; ?>

	</div>
	<?php
}
