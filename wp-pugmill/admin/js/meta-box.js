/**
 * WP Pugmill — Classic editor meta box script.
 *
 * Handles dynamic Q&A / entity row management, the Generate with AI button,
 * and the Write from Draft button on classic-editor post screens.
 *
 * Data is supplied by wp_localize_script() as window.wppugmillMetaBox.
 *
 * @package WPPugmill
 */

( function () {
	var data         = window.wppugmillMetaBox || {};
	var qaCount      = data.qaCount      || 0;
	var entityCount  = data.entityCount  || 0;
	var types        = data.types        || [];
	var ajaxUrl      = data.ajaxUrl      || '';
	var nonce        = data.nonce        || '';
	var rewriteNonce = data.rewriteNonce || '';
	var postId       = data.postId       || 0;

	// ── Add / remove Q&A rows ─────────────────────────────────────────────

	function makeQaRow( index, q, a ) {
		var row       = document.createElement( 'div' );
		row.className = 'wppugmill-qa-row';
		row.style     = 'display:flex; gap:8px; margin-bottom:8px;';
		row.innerHTML =
			'<input type="text" name="wppugmill_questions[' + index + '][q]" placeholder="Question" value="' + escAttr( q || '' ) + '" style="flex:1;">' +
			'<input type="text" name="wppugmill_questions[' + index + '][a]" placeholder="Answer" value="' + escAttr( a || '' ) + '" style="flex:2;">' +
			'<button type="button" class="button wppugmill-remove-qa">Remove</button>';
		return row;
	}

	function makeEntityRow( index, name, type, description ) {
		var row       = document.createElement( 'div' );
		row.className = 'wppugmill-entity-row';
		row.style     = 'display:flex; gap:8px; margin-bottom:8px;';
		var options   = types.map( function ( t ) {
			return '<option value="' + t + '"' + ( t === type ? ' selected' : '' ) + '>' + t + '</option>';
		} ).join( '' );
		row.innerHTML =
			'<input type="text" name="wppugmill_entities[' + index + '][name]" placeholder="Entity name" value="' + escAttr( name || '' ) + '" style="flex:2;">' +
			'<select name="wppugmill_entities[' + index + '][type]" style="flex:1;">' + options + '</select>' +
			'<input type="text" name="wppugmill_entities[' + index + '][description]" placeholder="Description (optional)" value="' + escAttr( description || '' ) + '" style="flex:2;">' +
			'<button type="button" class="button wppugmill-remove-entity">Remove</button>';
		return row;
	}

	function escAttr( str ) {
		return String( str )
			.replace( /&/g, '&amp;' )
			.replace( /"/g, '&quot;' )
			.replace( /</g, '&lt;' )
			.replace( />/g, '&gt;' );
	}

	document.getElementById( 'wppugmill-add-qa' ).addEventListener( 'click', function () {
		document.getElementById( 'wppugmill-questions' ).appendChild( makeQaRow( qaCount++ ) );
	} );

	document.getElementById( 'wppugmill-add-entity' ).addEventListener( 'click', function () {
		document.getElementById( 'wppugmill-entities' ).appendChild( makeEntityRow( entityCount++ ) );
	} );

	document.addEventListener( 'click', function ( e ) {
		if ( e.target.classList.contains( 'wppugmill-remove-qa' ) ) {
			e.target.closest( '.wppugmill-qa-row' ).remove();
		}
		if ( e.target.classList.contains( 'wppugmill-remove-entity' ) ) {
			e.target.closest( '.wppugmill-entity-row' ).remove();
		}
	} );

	// ── Generate with AI ──────────────────────────────────────────────────

	var generateBtn = document.getElementById( 'wppugmill-generate' );
	if ( generateBtn ) {
		generateBtn.addEventListener( 'click', function () {
			var label   = document.getElementById( 'wppugmill-generate-label' );
			var errorEl = document.getElementById( 'wppugmill-generate-error' );

			generateBtn.disabled = true;
			generateBtn.classList.add( 'wppugmill-loading' );
			label.textContent     = 'Generating\u2026';
			errorEl.style.display = 'none';

			var formData = new FormData();
			formData.append( 'action',  'wppugmill_generate_aeo' );
			formData.append( 'nonce',   nonce );
			formData.append( 'post_id', postId );

			fetch( ajaxUrl, { method: 'POST', body: formData } )
				.then( function ( res ) { return res.json(); } )
				.then( function ( json ) {
					if ( ! json.success ) {
						throw new Error( json.data.message || 'Unknown error.' );
					}
					populateFields( json.data );
				} )
				.catch( function ( err ) {
					errorEl.innerHTML    = 'Error: ' + err.message;
					errorEl.style.display = 'block';
				} )
				.finally( function () {
					generateBtn.disabled = false;
					generateBtn.classList.remove( 'wppugmill-loading' );
					label.textContent    = '\u2728 Generate with AI';
				} );
		} );
	}

	function populateFields( aeo ) {
		document.querySelector( 'textarea[name="wppugmill_summary"]' ).value = aeo.summary || '';

		var qaContainer = document.getElementById( 'wppugmill-questions' );
		qaContainer.innerHTML = '';
		qaCount = 0;
		( aeo.questions || [] ).forEach( function ( qa ) {
			qaContainer.appendChild( makeQaRow( qaCount++, qa.q, qa.a ) );
		} );

		var entityContainer = document.getElementById( 'wppugmill-entities' );
		entityContainer.innerHTML = '';
		entityCount = 0;
		( aeo.entities || [] ).forEach( function ( entity ) {
			entityContainer.appendChild( makeEntityRow( entityCount++, entity.name, entity.type, entity.description ) );
		} );

		document.querySelector( 'input[name="wppugmill_keywords"]' ).value = ( aeo.keywords || [] ).join( ', ' );
	}

	// ── Write from Draft ──────────────────────────────────────────────────

	var rewriteBtn = document.getElementById( 'wppugmill-rewrite' );
	if ( rewriteBtn ) {
		rewriteBtn.addEventListener( 'click', function () {
			var label     = document.getElementById( 'wppugmill-rewrite-label' );
			var errorEl   = document.getElementById( 'wppugmill-rewrite-error' );
			var contextEl = document.getElementById( 'wppugmill-rewrite-context' );
			var contextTa = document.getElementById( 'wppugmill-rewrite-context-text' );

			rewriteBtn.disabled     = true;
			rewriteBtn.classList.add( 'wppugmill-loading' );
			label.textContent       = 'Rewriting\u2026';
			errorEl.style.display   = 'none';
			contextEl.style.display = 'none';

			var formData = new FormData();
			formData.append( 'action',  'wppugmill_rewrite_draft' );
			formData.append( 'nonce',   rewriteNonce );
			formData.append( 'post_id', postId );

			fetch( ajaxUrl, { method: 'POST', body: formData } )
				.then( function ( res ) { return res.json(); } )
				.then( function ( json ) {
					if ( ! json.success ) {
						throw new Error( json.data.message || 'Rewrite failed. Please try again.' );
					}
					var d = json.data;

					document.querySelector( 'textarea[name="wppugmill_summary"]' ).value = d.summary || d.direct_answer || '';

					var qaContainer = document.getElementById( 'wppugmill-questions' );
					var firstRow    = makeQaRow( qaCount++, d.primary_question || '', d.direct_answer || '' );
					qaContainer.insertBefore( firstRow, qaContainer.firstChild );

					if ( d.keywords && d.keywords.length ) {
						document.querySelector( 'input[name="wppugmill_keywords"]' ).value = d.keywords.join( ', ' );
					}

					if ( d.context ) {
						var tmp         = document.createElement( 'div' );
						tmp.innerHTML   = d.context;
						contextTa.value = tmp.textContent || tmp.innerText || d.context;
						contextEl.style.display = 'block';
					}
				} )
				.catch( function ( err ) {
					errorEl.textContent   = 'Error: ' + err.message;
					errorEl.style.display = 'block';
				} )
				.finally( function () {
					rewriteBtn.disabled = false;
					rewriteBtn.classList.remove( 'wppugmill-loading' );
					label.textContent   = '\u270f Rewrite from Draft';
				} );
		} );
	}

} () );
