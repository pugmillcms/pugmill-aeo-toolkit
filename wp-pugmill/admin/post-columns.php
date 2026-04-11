<?php
/**
 * Post list table — AEO score pill + delete warning.
 *
 * The score is injected inline after each post title via JavaScript so we
 * don't add a new column (which can break the list table layout when other
 * plugins like Yoast or RankMath also add columns). Score data is output as
 * a JSON map in admin_head and the script appends a lavender pill to each
 * title cell.
 *
 * @package WPPugmill
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Output AEO score pills inline after each post title.
 *
 * Scores are collected server-side (updating the cached meta as a side-effect),
 * serialised into a JS map, and rendered client-side so we don't touch any
 * existing column structure.
 */
function wppugmill_inline_score_pills() {
	$screen = get_current_screen();
	if ( ! $screen ) {
		return;
	}
	$post_types = get_post_types( array( 'public' => true ), 'names' );
	$screens    = array_map( function( $pt ) { return 'edit-' . $pt; }, $post_types );
	if ( ! in_array( $screen->id, $screens, true ) ) {
		return;
	}

	// Collect scores for every post currently visible in the list table.
	// WP has already run the main query, so $wp_query->posts is available.
	global $wp_query;
	$scores = array();
	if ( ! empty( $wp_query->posts ) ) {
		foreach ( $wp_query->posts as $post ) {
			$health = wppugmill_health_score( $post->ID );
			$score  = $health['score'];
			$grade  = $health['grade'];
			$color  = $health['color'];

			// Keep the cached meta in sync (no extra DB query — WP object cache).
			$cached = get_post_meta( $post->ID, '_wppugmill_score', true );
			if ( '' === $cached || (int) $cached !== $score ) {
				update_post_meta( $post->ID, '_wppugmill_score', $score );
			}

			$scores[ $post->ID ] = array(
				'score' => $score,
				'grade' => $grade,
				'color' => $color,
			);
		}
	}

	if ( empty( $scores ) ) {
		return;
	}
	?>
	<script>
	( function() {
		var scores = <?php echo wp_json_encode( $scores ); ?>;

		function inject() {
			Object.keys( scores ).forEach( function( id ) {
				var row = document.getElementById( 'post-' + id );
				if ( ! row ) { return; }
				// Avoid double-injection on re-renders.
				if ( row.querySelector( '.wppugmill-score-pill' ) ) { return; }

				var titleLink = row.querySelector( '.row-title' );
				if ( ! titleLink ) { return; }

				var s    = scores[ id ];
				var pill = document.createElement( 'span' );
				pill.className   = 'wppugmill-score-pill';
				pill.title       = 'AEO Score: ' + s.grade + ' — ' + s.score + '/100';
				pill.textContent = 'AEO ' + s.score;
				pill.style.cssText = [
					'display:inline-flex',
					'align-items:center',
					'margin-left:7px',
					'padding:1px 7px',
					'font-size:10px',
					'font-weight:700',
					'border-radius:999px',
					'background:#ede9fe',
					'color:#5b21b6',
					'vertical-align:middle',
					'white-space:nowrap',
					'letter-spacing:0.02em',
					'cursor:default',
				].join( ';' );
				titleLink.parentNode.insertBefore( pill, titleLink.nextSibling );
			} );
		}

		if ( document.readyState === 'loading' ) {
			document.addEventListener( 'DOMContentLoaded', inject );
		} else {
			inject();
		}
	}() );
	</script>
	<?php
}
add_action( 'admin_head', 'wppugmill_inline_score_pills' );

/**
 * Warn before deleting the plugin — all post meta is wiped on full deletion.
 * Fires only on the plugins list page so the script is never loaded elsewhere.
 */
function wppugmill_delete_warning_js() {
	?>
	<script>
	( function() {
		var row  = document.querySelector( '[data-plugin="wp-pugmill/wp-pugmill.php"]' );
		if ( ! row ) return;
		var link = row.querySelector( '.delete a' );
		if ( ! link ) return;
		link.addEventListener( 'click', function( e ) {
			var ok = window.confirm(
				'Heads up: deleting WP Pugmill will permanently erase all your AEO content ' +
				'— summaries, Q&As, entities, and SEO fields — from every post on this site. ' +
				'This cannot be undone.\n\n' +
				'To keep your content safe, choose Deactivate instead. ' +
				'You can reactivate at any time and everything will still be there.\n\n' +
				'Delete the plugin and erase all content?'
			);
			if ( ! ok ) {
				e.preventDefault();
			}
		} );
	}() );
	</script>
	<?php
}
add_action( 'admin_footer-plugins.php', 'wppugmill_delete_warning_js' );
