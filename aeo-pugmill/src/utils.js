/**
 * Pugmill AEO Toolkit — Shared utilities.
 *
 * @package WPPugmill
 */

/**
 * Saves the post if it has unsaved changes, then resolves.
 *
 * Sets the button into its loading state first so the UI doesn't appear
 * frozen. Rejects with a human-readable message if the save request fails.
 *
 * @return {Promise<void>}
 */
export async function saveIfDirty() {
	const { select, dispatch, subscribe } = window.wp.data;
	if ( ! select( 'core/editor' ).isEditedPostDirty() ) return;
	return new Promise( ( resolve, reject ) => {
		let saveStarted = false;
		const unsubscribe = subscribe( () => {
			const saving     = select( 'core/editor' ).isSavingPost();
			const autosaving = select( 'core/editor' ).isAutosavingPost();
			// isSavingPost() is true during autosaves too — ignore those transitions
			// so we only resolve against the explicit savePost() we dispatched below.
			if ( autosaving ) return;
			if ( ! saveStarted && saving  ) { saveStarted = true; return; }
			if (   saveStarted && ! saving ) {
				unsubscribe();
				select( 'core/editor' ).didPostSaveRequestFail()
					? reject( new Error( 'Save failed. Please save manually and try again.' ) )
					: resolve();
			}
		} );
		dispatch( 'core/editor' ).savePost();
	} );
}
