/**
 * WP Pugmill — Bulk AEO queue runner.
 *
 * Reads window.wppugmillBulk (localized by PHP) and drives the
 * generate-all-posts flow on the Bulk AEO settings tab.
 *
 * @package WPPugmill
 */
( function( cfg ) {
	'use strict';

	var queue     = [];
	var paused    = false;
	var stopped   = false;
	var running   = false;
	var results   = { success: 0, failed: 0, skipped: 0 };
	var runStart  = 0;   // Date.now() when run begins — used for rate calc
	var els       = {};

	// ── Boot ─────────────────────────────────────────────────────────────────

	document.addEventListener( 'DOMContentLoaded', function() {
		els = {
			statsText:    document.getElementById( 'wppugmill-bulk-stats' ),
			postTypeEls:  document.querySelectorAll( 'input[name="wppugmill_bulk_post_types"]' ),
			skipExisting: document.getElementById( 'wppugmill-bulk-skip-existing' ),
			speedSelect:  document.getElementById( 'wppugmill-bulk-speed' ),
			startBtn:     document.getElementById( 'wppugmill-bulk-start' ),
			progressWrap: document.getElementById( 'wppugmill-bulk-progress' ),
			barFill:      document.getElementById( 'wppugmill-bulk-bar-fill' ),
			counter:      document.getElementById( 'wppugmill-bulk-counter' ),
			rateEl:       document.getElementById( 'wppugmill-bulk-rate' ),
			currentPost:  document.getElementById( 'wppugmill-bulk-current' ),
			successCount: document.getElementById( 'wppugmill-bulk-success' ),
			failedCount:  document.getElementById( 'wppugmill-bulk-failed' ),
			skippedCount: document.getElementById( 'wppugmill-bulk-skipped' ),
			pauseBtn:     document.getElementById( 'wppugmill-bulk-pause' ),
			cancelBtn:    document.getElementById( 'wppugmill-bulk-cancel' ),
			completeMsg:  document.getElementById( 'wppugmill-bulk-complete' ),
		};

		if ( ! els.startBtn ) return;

		fetchStats();

		// Re-fetch stats when options change.
		els.postTypeEls.forEach( function( el ) {
			el.addEventListener( 'change', fetchStats );
		} );
		if ( els.skipExisting ) {
			els.skipExisting.addEventListener( 'change', fetchStats );
		}

		els.startBtn.addEventListener( 'click', startRun );

		if ( els.pauseBtn ) {
			els.pauseBtn.addEventListener( 'click', function() {
				if ( paused ) {
					paused = false;
					els.pauseBtn.textContent = 'Pause';
					processAt( queue._index );
				} else {
					paused = true;
					els.pauseBtn.textContent = 'Resume';
				}
			} );
		}

		if ( els.cancelBtn ) {
			els.cancelBtn.addEventListener( 'click', function() {
				stopped = true;
				paused  = false;
			} );
		}

		// Warn if user tries to leave while a run is active.
		window.addEventListener( 'beforeunload', function( e ) {
			if ( running ) {
				e.preventDefault();
				e.returnValue = '';
			}
		} );
	} );

	// ── Options ───────────────────────────────────────────────────────────────

	function getOptions() {
		var postTypes = 'all';
		els.postTypeEls.forEach( function( el ) {
			if ( el.checked ) postTypes = el.value;
		} );
		var skipExisting = ( els.skipExisting && els.skipExisting.checked ) ? '1' : '0';
		return { post_types: postTypes, skip_existing: skipExisting };
	}

	// ── Stats ─────────────────────────────────────────────────────────────────

	function fetchStats() {
		if ( ! els.statsText ) return;
		els.statsText.textContent = 'Loading…';

		var opts = getOptions();
		var data = new FormData();
		data.append( 'action',        'wppugmill_bulk_aeo_get_queue' );
		data.append( 'nonce',         cfg.nonce );
		data.append( 'post_types',    opts.post_types );
		data.append( 'skip_existing', opts.skip_existing );

		fetch( cfg.ajaxUrl, { method: 'POST', body: data } )
			.then( function( r ) { return r.json(); } )
			.then( function( json ) {
				if ( ! json.success ) {
					els.statsText.textContent = 'Could not load stats.';
					return;
				}
				var s = json.data.stats;
				els.statsText.textContent =
					s.total + ' total  \u2022  ' +
					s.missing_aeo + ' missing AEO  \u2022  ' +
					s.have_aeo + ' complete';

				// Store IDs for the upcoming run.
				queue       = json.data.ids;
				queue._index = 0;

				if ( els.startBtn ) {
					els.startBtn.disabled = ! cfg.isProMode || queue.length === 0;
				}
			} )
			.catch( function() {
				els.statsText.textContent = 'Could not load stats.';
			} );
	}

	// ── Run ───────────────────────────────────────────────────────────────────

	function startRun() {
		if ( ! cfg.isProMode || running ) return;

		// Refresh queue then start immediately.
		var opts = getOptions();
		var data = new FormData();
		data.append( 'action',        'wppugmill_bulk_aeo_get_queue' );
		data.append( 'nonce',         cfg.nonce );
		data.append( 'post_types',    opts.post_types );
		data.append( 'skip_existing', opts.skip_existing );

		if ( els.startBtn ) {
			els.startBtn.disabled     = true;
			els.startBtn.textContent  = 'Starting…';
		}

		fetch( cfg.ajaxUrl, { method: 'POST', body: data } )
			.then( function( r ) { return r.json(); } )
			.then( function( json ) {
				if ( ! json.success || ! json.data.ids.length ) {
					if ( els.startBtn ) {
						els.startBtn.disabled    = false;
						els.startBtn.textContent = 'Generate AEO for All Content';
					}
					if ( els.statsText ) els.statsText.textContent = 'Nothing to generate.';
					return;
				}

				queue        = json.data.ids;
				queue._index = 0;
				paused       = false;
				stopped      = false;
				running      = true;
				runStart     = Date.now();
				results      = { success: 0, failed: 0, skipped: 0 };

				if ( els.progressWrap ) els.progressWrap.style.display = 'block';
				if ( els.completeMsg  ) els.completeMsg.style.display  = 'none';
				if ( els.rateEl       ) els.rateEl.textContent         = '';
				if ( els.startBtn ) {
					els.startBtn.style.display = 'none';
					els.startBtn.classList.add( 'wppugmill-loading' );
				}

				updateCounters();
				processAt( 0 );
			} )
			.catch( function() {
				if ( els.startBtn ) {
					els.startBtn.disabled    = false;
					els.startBtn.textContent = 'Generate AEO for All Content';
				}
			} );
	}

	function getDelay() {
		var sel = els.speedSelect;
		var val = sel ? parseInt( sel.value, 10 ) : 3000;
		return isNaN( val ) ? 3000 : val;
	}

	function updateRate() {
		if ( ! els.rateEl ) return;
		var completed = results.success + results.failed + results.skipped;
		if ( completed < 2 || ! runStart ) { els.rateEl.textContent = ''; return; }
		var elapsedHrs = ( Date.now() - runStart ) / 3600000;
		var rate = Math.round( completed / elapsedHrs );
		els.rateEl.textContent = '\u2248' + rate + '/hr';
	}

	function processAt( index ) {
		queue._index = index;

		if ( stopped ) { showComplete(); return; }
		if ( paused  ) { return; }
		if ( index >= queue.length ) { showComplete(); return; }

		var postId = queue[ index ];
		updateProgress( index, queue.length );

		var data = new FormData();
		data.append( 'action',  'wppugmill_bulk_aeo_process' );
		data.append( 'nonce',   cfg.nonce );
		data.append( 'post_id', postId );

		fetch( cfg.ajaxUrl, { method: 'POST', body: data } )
			.then( function( r ) { return r.json(); } )
			.then( function( json ) {
				if ( json.success ) {
					if ( json.data && json.data.skipped ) {
						results.skipped++;
					} else {
						results.success++;
						if ( els.currentPost && json.data && json.data.post_title ) {
							els.currentPost.textContent = '\u2713 ' + json.data.post_title;
						}
					}
				} else {
					results.failed++;
				}
				updateCounters();
				updateRate();
				setTimeout( function() { processAt( index + 1 ); }, getDelay() );
			} )
			.catch( function() {
				results.failed++;
				updateCounters();
				updateRate();
				setTimeout( function() { processAt( index + 1 ); }, getDelay() );
			} );
	}

	// ── UI helpers ────────────────────────────────────────────────────────────

	function updateProgress( index, total ) {
		var pct = total > 0 ? Math.round( ( index / total ) * 100 ) : 0;
		if ( els.barFill ) els.barFill.style.width = pct + '%';
		if ( els.counter ) els.counter.textContent = ( index + 1 ) + ' / ' + total;
	}

	function updateCounters() {
		if ( els.successCount ) els.successCount.textContent = results.success;
		if ( els.failedCount  ) els.failedCount.textContent  = results.failed;
		if ( els.skippedCount ) els.skippedCount.textContent = results.skipped;
	}

	function showComplete() {
		running = false;
		if ( els.progressWrap ) els.progressWrap.style.display = 'none';

		if ( els.completeMsg ) {
			var label = stopped ? 'Cancelled.' : 'Done.';
			var parts = [];
			if ( results.success ) parts.push( results.success + ' generated' );
			if ( results.failed  ) parts.push( results.failed  + ' failed'    );
			if ( results.skipped ) parts.push( results.skipped + ' skipped'   );
			els.completeMsg.textContent = label + ( parts.length ? ' ' + parts.join( ', ' ) + '.' : '' );
			els.completeMsg.style.display = 'block';
		}

		if ( els.startBtn ) {
			els.startBtn.classList.remove( 'wppugmill-loading' );
			els.startBtn.style.display   = '';
			els.startBtn.disabled        = ! cfg.isProMode;
			els.startBtn.textContent     = 'Generate AEO for All Content';
		}

		// Refresh stats to reflect the newly generated AEO.
		fetchStats();
	}

}( window.wppugmillBulk || {} ) );
