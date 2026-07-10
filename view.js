/**
 * Frontend Inline Editor — Interactivity API Store
 *
 * Double-click any text block to edit it inline.
 * Changes are saved per-block so block markup is preserved.
 */
import { store, getContext, getElement } from '@wordpress/interactivity';

store( 'jamies-front-end-editor-for-content-teams', {
	state: {
		// Server-provided: postId, restNonce, endpoint, isEditing, isSaving, message
		activeBlockIndex: null,
		originalHTML: '',
	},

	actions: {
		editBlock() {
			const ctx = getContext();
			const { state } = store( 'jamies-front-end-editor-for-content-teams' );
			const el = getElement();

			// Find the actual content element inside the wrapper (p, h1-h6, li, etc.)
			const inner = el.ref.querySelector( 'p, h1, h2, h3, h4, h5, h6, li, blockquote, pre' );
			if ( ! inner ) return;

			state.activeBlockIndex = ctx.blockIndex;
			state.originalHTML = inner.innerHTML;
			state.isEditing = true;
			state.message = '';

			ctx.active = true;
			inner.contentEditable = 'true';
			inner.focus();
		},

		cancel() {
			const { state } = store( 'jamies-front-end-editor-for-content-teams' );

			// Restore original content in the active block.
			const activeEl = document.querySelector( '.fie-active' );
			if ( activeEl ) {
				const inner = activeEl.querySelector( '[contenteditable="true"]' );
				if ( inner ) {
					inner.innerHTML = state.originalHTML;
					inner.contentEditable = 'false';
				}
			}

			fie_resetState( state );
		},

		async save() {
			const { state } = store( 'jamies-front-end-editor-for-content-teams' );

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
						newContent: inner.innerHTML,
					} ),
				} );

				if ( ! resp.ok ) {
					const err = await resp.json().catch( () => ( {} ) );
					throw new Error( err.message || 'Save failed' );
				}

				inner.contentEditable = 'false';
				state.message = 'Saved!';
				setTimeout( () => { state.message = ''; }, 2000 );

				fie_resetState( state );
			} catch ( err ) {
				state.message = 'Error: ' + err.message;
				state.isSaving = false;
			}
		},
	},

	callbacks: {
		syncEditable() {
			// When state resets, ensure this block's active flag is cleared.
			const ctx = getContext();
			const { state } = store( 'jamies-front-end-editor-for-content-teams' );
			if ( state.activeBlockIndex !== ctx.blockIndex ) {
				ctx.active = false;
			}
		},
	},
} );

function fie_resetState( state ) {
	state.isEditing = false;
	state.isSaving = false;
	state.activeBlockIndex = null;
	state.originalHTML = '';
}

/**
 * "Edit with Frontend" admin bar link — jumps to the first editable block
 * on the page and activates it, reusing the existing click-to-edit directive
 * so the Interactivity API's context/element tracking stays correct.
 */
document.addEventListener( 'click', ( event ) => {
	const trigger = event.target.closest( '.jfect-edit-frontend-toolbar-item' );
	if ( ! trigger ) return;

	event.preventDefault();

	const target = document.querySelector( '.fie-block' );
	if ( ! target ) return;

	target.scrollIntoView( { behavior: 'smooth', block: 'center' } );
	target.click();
} );
