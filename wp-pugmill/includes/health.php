<?php
/**
 * SEO + AEO Health — per-post combined score and checklist.
 *
 * Scores SEO basics and AEO completeness on a 0–100 scale.
 * Displayed as a meta box on the post edit screen (Gutenberg sidebar mirrors this).
 *
 * Scoring breakdown:
 *   SEO title set          10pts
 *   Meta description set   10pts
 *   Summary present        15pts
 *   Summary length ≥ 50    10pts
 *   Q&A pairs ≥ 1          15pts
 *   Q&A pairs ≥ 3          10pts
 *   Entities ≥ 1           15pts
 *   Keywords ≥ 5           15pts
 *                         ------
 *   Max                   100pts
 *
 * @package WPPugmill
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Calculate AEO health score and checklist for a post.
 *
 * @param  int   $post_id
 * @return array{score: int, grade: string, color: string, items: array}
 */
function wppugmill_health_score( $post_id ) {
	$aeo   = wppugmill_get_aeo( $post_id );
	$seo   = wppugmill_get_seo( $post_id );
	$items = array();
	$score = 0;

	// ── SEO basics ────────────────────────────────────────────────────────

	// SEO title set (10pts)
	$has_seo_title = ! empty( trim( $seo['title'] ) );
	$items[] = array(
		'label'    => __( 'SEO title set', 'wp-pugmill' ),
		'pass'     => $has_seo_title,
		'points'   => 10,
		'tip'      => __( 'Add a custom SEO title in the SEO panel to control how this page appears in search results.', 'wp-pugmill' ),
		'category' => 'seo',
	);
	if ( $has_seo_title ) $score += 10;

	// Meta description set (10pts)
	$has_meta_desc = ! empty( trim( $seo['meta_desc'] ) );
	$items[] = array(
		'label'    => __( 'Meta description written', 'wp-pugmill' ),
		'pass'     => $has_meta_desc,
		'points'   => 10,
		'tip'      => __( 'Add a meta description (up to 155 characters) that summarises the page for search results.', 'wp-pugmill' ),
		'category' => 'seo',
	);
	if ( $has_meta_desc ) $score += 10;

	// ── AEO fields ────────────────────────────────────────────────────────

	// Summary present (15pts)
	$has_summary = ! empty( trim( $aeo['summary'] ) );
	$items[] = array(
		'label'    => __( 'AI summary written', 'wp-pugmill' ),
		'pass'     => $has_summary,
		'points'   => 15,
		'tip'      => __( 'Add a 2–3 sentence summary that describes this content for AI crawlers.', 'wp-pugmill' ),
		'category' => 'aeo',
	);
	if ( $has_summary ) $score += 15;

	// Summary length (10pts)
	$summary_long = $has_summary && strlen( $aeo['summary'] ) >= 50;
	$items[] = array(
		'label'    => __( 'Summary is descriptive (50+ characters)', 'wp-pugmill' ),
		'pass'     => $summary_long,
		'points'   => 10,
		'tip'      => __( 'Expand your summary to at least 50 characters for better AI discoverability.', 'wp-pugmill' ),
		'category' => 'aeo',
	);
	if ( $summary_long ) $score += 10;

	// At least 1 Q&A pair (15pts)
	$qa_count = count( array_filter( $aeo['questions'], function( $q ) {
		return ! empty( $q['q'] ) && ! empty( $q['a'] );
	} ) );
	$has_qa = $qa_count >= 1;
	$items[] = array(
		'label'    => __( 'At least 1 Q&A pair', 'wp-pugmill' ),
		'pass'     => $has_qa,
		'points'   => 15,
		'tip'      => __( 'Add a question and answer pair — generates FAQPage schema and helps AI engines cite your content.', 'wp-pugmill' ),
		'category' => 'aeo',
	);
	if ( $has_qa ) $score += 15;

	// At least 3 Q&A pairs (10pts)
	$has_qa3 = $qa_count >= 3;
	$items[] = array(
		'label'    => __( '3 or more Q&A pairs', 'wp-pugmill' ),
		'pass'     => $has_qa3,
		'points'   => 10,
		'tip'      => __( 'Add at least 3 Q&A pairs to cover the main questions readers might ask.', 'wp-pugmill' ),
		'category' => 'aeo',
	);
	if ( $has_qa3 ) $score += 10;

	// At least 1 entity (15pts)
	$entity_count = count( array_filter( $aeo['entities'], function( $e ) {
		return ! empty( $e['name'] );
	} ) );
	$has_entity = $entity_count >= 1;
	$items[] = array(
		'label'    => __( 'Named entities tagged', 'wp-pugmill' ),
		'pass'     => $has_entity,
		'points'   => 15,
		'tip'      => __( 'Tag the key people, organizations, products, or concepts mentioned in this post.', 'wp-pugmill' ),
		'category' => 'aeo',
	);
	if ( $has_entity ) $score += 15;

	// At least 5 keywords (15pts)
	$keyword_count = count( array_filter( $aeo['keywords'], 'strlen' ) );
	$has_keywords  = $keyword_count >= 5;
	$items[] = array(
		'label'    => __( '5 or more keywords', 'wp-pugmill' ),
		'pass'     => $has_keywords,
		'points'   => 15,
		'tip'      => __( 'Add at least 5 specific, search-focused keywords.', 'wp-pugmill' ),
		'category' => 'aeo',
	);
	if ( $has_keywords ) $score += 15;

	// Grade
	if ( $score >= 90 )      { $grade = __( 'Excellent', 'wp-pugmill' ); $color = '#46b450'; }
	elseif ( $score >= 70 )  { $grade = __( 'Good', 'wp-pugmill' );      $color = '#00a0d2'; }
	elseif ( $score >= 40 )  { $grade = __( 'Fair', 'wp-pugmill' );      $color = '#ffb900'; }
	else                     { $grade = __( 'Poor', 'wp-pugmill' );      $color = '#dc3232'; }

	return array(
		'score' => $score,
		'grade' => $grade,
		'color' => $color,
		'items' => $items,
	);
}

/**
 * Register the AEO Health meta box.
 *
 * Suppressed in the block editor — the Gutenberg sidebar shows the score there.
 */
function wppugmill_add_health_meta_box() {
	// Gutenberg sidebar includes the health score — skip classic meta box.
	if ( wppugmill_is_block_editor() ) {
		return;
	}

	$post_types = get_post_types( array( 'public' => true ) );
	add_meta_box(
		'wppugmill_health',
		__( 'AEO Health Score', 'wp-pugmill' ),
		'wppugmill_render_health_meta_box',
		array_values( $post_types ),
		'side',
		'high'
	);
}
add_action( 'add_meta_boxes', 'wppugmill_add_health_meta_box' );

/**
 * Render the AEO Health meta box.
 */
function wppugmill_render_health_meta_box( $post ) {
	$health = wppugmill_health_score( $post->ID );
	$score  = $health['score'];
	$grade  = $health['grade'];
	$color  = $health['color'];
	$items  = $health['items'];
	$mode   = wppugmill_mode();
	?>
	<div id="wppugmill-health" style="font-family:-apple-system,sans-serif;">

		<!-- Score ring -->
		<div style="text-align:center; padding:12px 0 8px;">
			<svg width="80" height="80" viewBox="0 0 80 80" style="transform:rotate(-90deg);">
				<circle cx="40" cy="40" r="34" fill="none" stroke="#e0e0e0" stroke-width="8"/>
				<circle cx="40" cy="40" r="34" fill="none"
					stroke="<?php echo esc_attr( $color ); ?>"
					stroke-width="8"
					stroke-dasharray="<?php echo round( 213.6 * $score / 100, 1 ); ?> 213.6"
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
				<?php echo wp_kses( __( 'Click <strong>Generate with AI</strong> to auto-complete missing fields.', 'wp-pugmill' ), array( 'strong' => array() ) ); ?>
			</p>
		<?php elseif ( $score < 100 && 'free' === $mode ) : ?>
			<p style="margin:12px 0 0; font-size:11px; color:#666; text-align:center;">
				<?php
				printf(
					'<a href="%1$s" target="_blank">%2$s</a> %3$s',
					esc_url( 'https://wppugmill.com/pricing' ),
					esc_html__( 'Get AI Connector', 'wp-pugmill' ),
					esc_html__( 'to auto-complete these fields.', 'wp-pugmill' )
				);
				?>
			</p>
		<?php endif; ?>

	</div>
	<?php
}
