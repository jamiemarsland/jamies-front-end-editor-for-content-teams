/**
 * Frontend Inline Editor — Interactivity API Store
 *
 * Click any text block to edit it inline. Changes are saved per-block so block
 * markup is preserved. Editing takes WordPress's native post lock so two people
 * (front end or back end) can't clobber each other; a content fingerprint is
 * also sent on save as a backstop against concurrent edits.
 */
import { store, getContext, getElement } from '@wordpress/interactivity';

let fie_refreshTimer = null;

const { state } = store( 'jamies-front-end-editor-for-content-teams', {
	state: {
		// Server-provided: postId, restNonce, endpoint, lockEndpoint,
		// unlockEndpoint, lockedByName, isEditing, isSaving, message
		activeBlockIndex: null,
		originalHTML: '',
		baseHash: '',
	},

	actions: {
		async editBlock() {
			const ctx = getContext();
			const el = getElement();

			// Someone else already holds the lock — stay read-only.
			if ( state.lockedByName ) {
				state.message = state.lockedByName + ' is currently editing this page.';
				return;
			}

			const inner = el.ref.querySelector( 'p, h1, h2, h3, h4, h5, h6, li, blockquote, pre' );
			if ( ! inner ) return;

			// Take the edit lock before allowing changes.
			try {
				const resp = await fetch( state.lockEndpoint, {
					method: 'POST',
					headers: {
						'Content-Type': 'application/json',
						'X-WP-Nonce': state.restNonce,
					},
					body: JSON.stringify( { postId: state.postId } ),
				} );

				if ( ! resp.ok ) {
					const err = await resp.json().catch( () => ( {} ) );
					state.lockedByName = err.message || 'Someone';
					state.message = state.lockedByName + ' is currently editing this page.';
					return;
				}
			} catch ( e ) {
				state.message = 'Could not start editing. Please try again.';
				return;
			}

			state.activeBlockIndex = ctx.blockIndex;
			state.originalHTML = inner.innerHTML;
			state.baseHash = ctx.base || '';
			state.isEditing = true;
			state.message = '';

			ctx.active = true;
			inner.contentEditable = 'true';
			inner.focus();

			fie_startRefresh();
		},

		onKeydown( event ) {
			if ( ! state.isEditing ) return;
			// Plain Enter in contentEditable creates <div>/<p> wrappers, which are
			// invalid inside a paragraph/heading. Insert a line break instead.
			if ( event.key === 'Enter' && ! event.shiftKey ) {
				event.preventDefault();
				document.execCommand( 'insertLineBreak' );
			}
		},

		cancel() {
			const activeEl = document.querySelector( '.fie-active' );
			if ( activeEl ) {
				const inner = activeEl.querySelector( '[contenteditable="true"]' );
				if ( inner ) {
					inner.innerHTML = state.originalHTML;
					inner.contentEditable = 'false';
				}
			}

			fie_releaseLock();
			fie_resetState();
		},

		async save() {
			const activeEl = document.querySelector( '.fie-active' );
			if ( ! activeEl ) return;

			const inner = activeEl.querySelector( '[contenteditable="true"]' );
			if ( ! inner ) return;

			state.isSaving = true;
			state.message = '';

			try {
				const resp = await fetch( state.endpoint, {
					method: 'POST',
					headers: {
						'Content-Type': 'application/json',
						'X-WP-Nonce': state.restNonce,
					},
					body: JSON.stringify( {
						postId: state.postId,
						blockIndex: state.activeBlockIndex,
						newContent: fie_cleanInline( inner.innerHTML ),
						baseHash: state.baseHash,
					} ),
				} );

				if ( ! resp.ok ) {
					const err = await resp.json().catch( () => ( {} ) );
					// 409 conflict or 423 locked: keep the user's text, tell them clearly.
					state.message = err.message || 'Save failed';
					state.isSaving = false;
					return;
				}

				inner.contentEditable = 'false';
				state.message = 'Saved!';
				setTimeout( () => { state.message = ''; }, 2000 );

				fie_releaseLock();
				fie_resetState();
			} catch ( err ) {
				state.message = 'Error: ' + err.message;
				state.isSaving = false;
			}
		},
	},

	callbacks: {
		syncEditable() {
			const ctx = getContext();
			if ( state.activeBlockIndex !== ctx.blockIndex ) {
				ctx.active = false;
			}
		},
	},
} );

// Release the lock if the tab is closed mid-edit.
window.addEventListener( 'beforeunload', () => {
	if ( state.isEditing ) {
		fie_releaseLock();
	}
} );

function fie_startRefresh() {
	fie_stopRefresh();
	// Post locks expire (~150s); refresh well inside that window while editing.
	fie_refreshTimer = setInterval( () => {
		fetch( state.lockEndpoint, {
			method: 'POST',
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': state.restNonce,
			},
			body: JSON.stringify( { postId: state.postId } ),
		} ).catch( () => {} );
	}, 60000 );
}

function fie_stopRefresh() {
	if ( fie_refreshTimer ) {
		clearInterval( fie_refreshTimer );
		fie_refreshTimer = null;
	}
}

function fie_releaseLock() {
	fie_stopRefresh();
	fetch( state.unlockEndpoint, {
		method: 'POST',
		keepalive: true,
		headers: {
			'Content-Type': 'application/json',
			'X-WP-Nonce': state.restNonce,
		},
		body: JSON.stringify( { postId: state.postId } ),
	} ).catch( () => {} );
}

function fie_resetState() {
	fie_stopRefresh();
	state.isEditing = false;
	state.isSaving = false;
	state.activeBlockIndex = null;
	state.originalHTML = '';
	state.baseHash = '';
}

/**
 * Flatten block-level line breaks that contentEditable inserts (<div>, <p>) into
 * <br>, so the saved paragraph/heading markup stays valid.
 */
function fie_cleanInline( html ) {
	return html
		.replace( /<div>\s*<br\s*\/?>\s*<\/div>/gi, '<br>' )
		.replace( /<p>\s*<br\s*\/?>\s*<\/p>/gi, '<br>' )
		.replace( /<div[^>]*>/gi, '<br>' )
		.replace( /<\/div>/gi, '' )
		.replace( /<p[^>]*>/gi, '<br>' )
		.replace( /<\/p>/gi, '' )
		.replace( /^(?:\s*<br\s*\/?>\s*)+/gi, '' )
		.replace( /(?:\s*<br\s*\/?>\s*)+$/gi, '' )
		.trim();
}
