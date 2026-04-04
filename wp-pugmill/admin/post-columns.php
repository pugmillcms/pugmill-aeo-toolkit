<?php
/**
 * Posts/Pages list table column — SEO + AEO health badge.
 *
 * Adds a "Pugmill" column to all public post type list tables showing a
 * color-coded score badge. Clicking the badge opens the post editor.
 *
 * @package WPPugmill
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Register the column for all public post types.
 *
 * @param  array  $columns Existing columns.
 * @return array
 */
function wppugmill_add_list_column( $columns ) {
	// Insert after the title column for natural placement.
	$new = array();
	foreach ( $columns as $key => $label ) {
		$new[ $key ] = $label;
		if ( 'title' === $key ) {
			$new['wppugmill_score'] = '<span title="WP Pugmill SEO + AEO Score" style="display:inline-flex;align-items:center;gap:4px;"><svg viewBox="0 0 500 500" xmlns="http://www.w3.org/2000/svg" fill="none" stroke="currentColor" stroke-miterlimit="10" width="12" height="12" style="opacity:0.7"><polygon points="47.33 254.69 116.33 300.93 116.33 301.39 116.53 394.54 116.05 393.87 8.76 254.66 8.74 254.63 116.03 115.41 116.51 114.75 116.31 207.89 116.31 208.36 47.09 254.53 47.33 254.69"/><polygon points="288.69 254.69 219.69 300.93 219.69 301.39 219.49 394.54 219.97 393.87 327.26 254.66 327.28 254.63 219.99 115.41 219.52 114.75 219.71 207.89 219.71 208.36 288.93 254.53 288.69 254.69"/><polygon points="369.43 254.69 300.43 300.93 300.43 301.39 300.23 394.54 300.71 393.87 408 254.66 408.02 254.63 300.73 115.41 300.25 114.75 300.45 207.89 300.45 208.36 369.67 254.53 369.43 254.69"/><polygon points="451.21 254.69 382.22 300.93 382.22 301.39 382.02 394.54 382.49 393.87 489.79 254.66 489.81 254.63 382.52 115.41 382.04 114.75 382.24 207.89 382.24 208.36 451.46 254.53 451.21 254.69"/><polygon points="110.13 487.08 154.03 487.18 154.1 486.67 227.28 11.14 226.9 11.14 182.99 11.14 182.92 11.65 109.61 487.08 110.13 487.08"/></svg> AEO Score</span>';
		}
	}
	return $new;
}

/**
 * Render the column cell for a given post.
 *
 * @param string $column  Column key.
 * @param int    $post_id Post ID.
 */
function wppugmill_render_list_column( $column, $post_id ) {
	if ( 'wppugmill_score' !== $column ) {
		return;
	}

	$health = wppugmill_health_score( $post_id );
	$score  = $health['score'];
	$grade  = $health['grade'];
	$color  = $health['color'];

	// Compact dot + number badge
	printf(
		'<span title="%s" style="display:inline-flex;align-items:center;gap:5px;font-size:12px;font-weight:600;color:%s;">
			<span style="width:8px;height:8px;border-radius:50%%;background:%s;flex-shrink:0;"></span>
			%d
		</span>',
		esc_attr( $grade . ' — ' . $score . '/100' ),
		esc_attr( $color ),
		esc_attr( $color ),
		absint( $score )
	);
}

/**
 * Set a fixed narrow width for the score column via inline CSS.
 */
function wppugmill_list_column_css() {
	$screen = get_current_screen();
	if ( ! $screen ) {
		return;
	}
	// Only inject on list table screens for public post types.
	$post_types = get_post_types( array( 'public' => true ), 'names' );
	$screens    = array_map( function( $pt ) { return 'edit-' . $pt; }, $post_types );
	if ( ! in_array( $screen->id, $screens, true ) ) {
		return;
	}
	echo '<style>.column-wppugmill_score { width: 60px; text-align: center; }</style>' . "\n";
}
add_action( 'admin_head', 'wppugmill_list_column_css' );

/**
 * Hook the column into every public post type's list table.
 */
function wppugmill_register_list_columns() {
	$post_types = get_post_types( array( 'public' => true ), 'names' );
	foreach ( $post_types as $post_type ) {
		add_filter( "manage_{$post_type}_posts_columns",       'wppugmill_add_list_column' );
		add_action( "manage_{$post_type}_posts_custom_column", 'wppugmill_render_list_column', 10, 2 );
	}
}
add_action( 'admin_init', 'wppugmill_register_list_columns' );

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
