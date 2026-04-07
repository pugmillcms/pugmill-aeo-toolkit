/**
 * Sidebar resize handle for the Gutenberg block editor.
 *
 * Injects a drag handle on the left edge of the editor sidebar so users can
 * resize it. Width is persisted in localStorage and restored on load.
 *
 * Namespaced entirely under wppugmill- to avoid conflicts with other plugins
 * that may also manipulate the sidebar.
 */
( function () {
	'use strict';

	var STORAGE_KEY  = 'wppugmill_sidebar_width';
	var MIN_WIDTH    = 220;
	var MAX_WIDTH    = 600;
	var HANDLE_ID    = 'wppugmill-resize-handle';

	var sidebar  = null;
	var handle   = null;
	var dragging = false;
	var startX   = 0;
	var startW   = 0;

	/**
	 * Find the sidebar element. Gutenberg uses different class names depending
	 * on WP version; try both and fall back gracefully.
	 *
	 * @return {Element|null}
	 */
	function getSidebar() {
		return (
			document.querySelector( '.interface-complementary-area__fill' ) ||
			document.querySelector( '.interface-interface-skeleton__sidebar' ) ||
			document.querySelector( '.edit-post-sidebar' ) ||
			null
		);
	}

	/**
	 * Apply a pixel width to the sidebar via an injected <style> tag.
	 *
	 * Gutenberg sets sidebar width in its own stylesheet, so inline styles
	 * lose the specificity battle. Injecting !important rules into <head>
	 * wins cleanly without touching Gutenberg's own DOM attributes.
	 *
	 * @param {number} width
	 */
	function applyWidth( width ) {
		width = Math.min( Math.max( width, MIN_WIDTH ), MAX_WIDTH );

		var styleEl = document.getElementById( 'wppugmill-resize-style' );
		if ( ! styleEl ) {
			styleEl    = document.createElement( 'style' );
			styleEl.id = 'wppugmill-resize-style';
			document.head.appendChild( styleEl );
		}

		styleEl.textContent =
			'.interface-complementary-area__fill,' +
			'.interface-complementary-area.editor-sidebar,' +
			'.interface-interface-skeleton__sidebar,' +
			'.edit-post-sidebar {' +
			'  width: '     + width + 'px !important;' +
			'  min-width: ' + width + 'px !important;' +
			'  max-width: ' + width + 'px !important;' +
			'}';
	}

	/**
	 * Restore a previously saved width from localStorage.
	 */
	function restoreWidth() {
		var saved = localStorage.getItem( STORAGE_KEY );
		if ( saved ) {
			applyWidth( parseInt( saved, 10 ) );
		}
	}

	/**
	 * Create and attach the drag handle to the sidebar.
	 */
	function attachHandle() {
		// Don't double-attach.
		if ( document.getElementById( HANDLE_ID ) ) {
			return;
		}

		handle = document.createElement( 'div' );
		handle.id = HANDLE_ID;
		handle.setAttribute( 'role', 'separator' );
		handle.setAttribute( 'aria-label', 'Resize sidebar' );
		handle.setAttribute( 'tabindex', '0' );

		handle.addEventListener( 'mousedown', onMouseDown );

		// Keyboard support: left/right arrows nudge the width.
		handle.addEventListener( 'keydown', function ( e ) {
			var current = sidebar.offsetWidth;
			if ( e.key === 'ArrowLeft' ) {
				applyWidth( current + 20 ); // left arrow = wider (handle is on left edge)
				localStorage.setItem( STORAGE_KEY, sidebar.offsetWidth );
			} else if ( e.key === 'ArrowRight' ) {
				applyWidth( current - 20 );
				localStorage.setItem( STORAGE_KEY, sidebar.offsetWidth );
			}
		} );

		sidebar.appendChild( handle );
	}

	function onMouseDown( e ) {
		dragging = true;
		startX   = e.clientX;
		startW   = sidebar.offsetWidth;

		document.body.style.cursor        = 'ew-resize';
		document.body.style.userSelect    = 'none';

		document.addEventListener( 'mousemove',  onMouseMove );
		document.addEventListener( 'mouseup',    onMouseUp );
		document.addEventListener( 'mouseleave', onMouseUp );

		e.preventDefault();
	}

	function onMouseMove( e ) {
		if ( ! dragging ) {
			return;
		}
		// Handle is on the LEFT edge — moving mouse left increases width.
		var delta = startX - e.clientX;
		applyWidth( startW + delta );
	}

	function onMouseUp() {
		if ( ! dragging ) {
			return;
		}
		dragging = false;
		document.body.style.cursor     = '';
		document.body.style.userSelect = '';

		localStorage.setItem( STORAGE_KEY, sidebar.offsetWidth );

		document.removeEventListener( 'mousemove',  onMouseMove );
		document.removeEventListener( 'mouseup',    onMouseUp );
		document.removeEventListener( 'mouseleave', onMouseUp );
	}

	/**
	 * Gutenberg renders asynchronously. Poll until the sidebar exists, then
	 * attach once and stop polling.
	 */
	function init() {
		var attempts = 0;
		var interval = setInterval( function () {
			attempts++;
			sidebar = getSidebar();

			if ( sidebar ) {
				clearInterval( interval );
				restoreWidth();
				attachHandle();
			}

			// Give up after ~10 seconds to avoid running forever on screens
			// that don't have a sidebar (e.g. full-screen mode).
			if ( attempts > 100 ) {
				clearInterval( interval );
			}
		}, 100 );
	}

	// Re-attach if the user opens a different panel (sidebar can be remounted).
	document.addEventListener( 'click', function () {
		if ( sidebar && ! document.getElementById( HANDLE_ID ) ) {
			attachHandle();
		}
	} );

	init();
} () );
