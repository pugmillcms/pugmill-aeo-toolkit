<?php
/**
 * Post list table — delete warning.
 *
 * @package WPPugmill
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Warn before deleting the plugin — all post meta is wiped on full deletion.
 * Fires only on the plugins list page so the script is never loaded elsewhere.
 */
function aeopugmill_delete_warning_js() {
	?>
	<script>
	( function() {
		var row  = document.querySelector( '[data-plugin="aeo-pugmill/aeo-pugmill.php"]' );
		if ( ! row ) return;
		var link = row.querySelector( '.delete a' );
		if ( ! link ) return;
		link.addEventListener( 'click', function( e ) {
			var ok = window.confirm(
				'Heads up: deleting AEO Pugmill will permanently erase all your AEO content ' +
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
add_action( 'admin_footer-plugins.php', 'aeopugmill_delete_warning_js' );
