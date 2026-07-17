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

// Link editing: the selection to restore, and the anchor being edited (if any).
let fie_savedRange = null;
let fie_editAnchor = null;

// The floating link bubble: 'add' (text selected) or 'edit' (caret in a link).
let fie_bubble = null;
let fie_bubbleAnchor = null;
let fie_bubbleMode = null;
let fie_bubbleRaf = null;

// Image editing: the clicked image block, its <img>, index and content hash.
let fie_imgBubble = null;
let fie_imgEl = null;
let fie_imgImg = null;
let fie_imgIndex = null;
let fie_imgBase = '';
let fie_toastTimer = null;

// Button editing: the clicked button block, its <a>, and index.
let fie_btnEl = null;
let fie_btnAnchor = null;
let fie_btnIndex = null;

// The reactive context of the block currently being text-edited, so its
// fingerprint can be refreshed after a save (keeps editing without a reload).
let fie_activeCtx = null;

// Same idea for the image block currently under the image controls.
let fie_imgCtx = null;

const { state } = store( 'jamies-front-end-editor-for-content-teams', {
	state: {
		// Server-provided: postId, restNonce, endpoint, lockEndpoint,
		// unlockEndpoint, lockedByName, isEditing, isSaving, message
		activeBlockIndex: null,
		originalHTML: '',
		baseHash: '',
		linkOpen: false,
		linkTitle: 'Add link',
		linkEditing: false,
		buttonOpen: false,
	},

	actions: {
		async editBlock( event ) {
			const ctx = getContext();
			const el = getElement();

			// Starting/continuing text editing dismisses any open image controls.
			fie_hideImageBubble();

			// Already editing something.
			if ( state.isEditing ) {
				const target = event && event.target;
				// Stop links navigating away mid-edit (the caret is still placed).
				if ( target && target.closest && target.closest( 'a' ) ) {
					event.preventDefault();
				}
				// Clicking inside the block being edited: just let the caret move.
				if ( ctx.blockIndex === state.activeBlockIndex ) {
					return;
				}
				// Clicking a different block: switch instantly. We already hold the
				// post lock (the refresh timer keeps it alive), so there is no network
				// wait — make the new block editable and focus it right away.
				const nextInner = el.ref.querySelector( 'p, h1, h2, h3, h4, h5, h6, li, blockquote, pre' );
				if ( ! nextInner ) return;
				const prev = document.querySelector( '.fie-active [contenteditable="true"]' );
				if ( prev && prev !== nextInner ) {
					prev.contentEditable = 'false';
				}
				fie_hideBubble();
				state.activeBlockIndex = ctx.blockIndex;
				state.originalHTML = nextInner.innerHTML; fie_activeCtx = ctx;
				state.baseHash = ctx.base || '';
				state.message = '';
				ctx.active = true;
				nextInner.contentEditable = 'true';
				nextInner.focus();
				fie_placeCaret( event, nextInner );
				return;
			}

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
			state.originalHTML = inner.innerHTML; fie_activeCtx = ctx;
			state.baseHash = ctx.base || '';
			state.isEditing = true;
			state.message = '';

			ctx.active = true;
			inner.contentEditable = 'true';
			inner.focus();
			fie_placeCaret( event, inner );

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

		// Click an image block: show its controls (replace / alt / remove).
		editImage( event ) {
			if ( event ) {
				event.preventDefault();
			}
			if ( state.lockedByName ) {
				fie_toast( state.lockedByName + ' is currently editing this page.', true );
				return;
			}
			const ctx = getContext();
			const el = getElement();
			const img = el.ref.querySelector( 'img' );
			if ( ! img ) return;

			fie_hideBubble();
			fie_imgEl = el.ref;
			fie_imgImg = img;
			fie_imgIndex = ctx.blockIndex;
			fie_imgBase = ctx.base || '';
			fie_imgCtx = ctx;
			fie_showImageBubble();
		},

		// Click a button block: open a dialog to edit its text and link.
		editButton( event ) {
			if ( event ) {
				event.preventDefault();
			}
			if ( state.lockedByName ) {
				fie_toast( state.lockedByName + ' is currently editing this page.', true );
				return;
			}
			const ctx = getContext();
			const el = getElement();
			const a = el.ref.querySelector( 'a' );

			fie_hideBubble();
			fie_hideImageBubble();
			fie_btnEl = el.ref;
			fie_btnAnchor = a;
			fie_btnIndex = ctx.blockIndex;

			const textInput = fie_btnTextInput();
			const urlInput = fie_btnUrlInput();
			const newtab = fie_btnNewtabInput();
			if ( textInput ) textInput.value = a ? ( a.textContent || '' ).trim() : '';
			if ( urlInput ) urlInput.value = a ? ( a.getAttribute( 'href' ) || '' ) : '';
			if ( newtab ) newtab.checked = a ? ( a.getAttribute( 'target' ) === '_blank' ) : false;

			state.buttonOpen = true;
			if ( textInput ) setTimeout( () => { textInput.focus(); textInput.select(); }, 0 );
		},

		applyButton() {
			fie_doApplyButton();
		},

		closeButton() {
			fie_closeButton();
		},

		buttonBackdrop( event ) {
			if ( event && event.target && event.target.classList
				&& event.target.classList.contains( 'fie-modal-overlay' ) ) {
				fie_closeButton();
			}
		},

		buttonKeydown( event ) {
			if ( event.key === 'Enter' ) {
				event.preventDefault();
				fie_doApplyButton();
			} else if ( event.key === 'Escape' ) {
				event.preventDefault();
				fie_closeButton();
			}
		},

		// Open the link modal. Bound to mousedown with preventDefault so the
		// contentEditable keeps its selection (clicking a button would blur it).
		openLink( event ) {
			if ( event ) {
				event.preventDefault();
			}
			if ( ! state.isEditing ) return;
			fie_hideBubble();

			const sel = window.getSelection();
			if ( ! sel || ! sel.rangeCount ) return;
			fie_savedRange = sel.getRangeAt( 0 ).cloneRange();

			// Is the caret inside an existing link within the block being edited?
			const inner = fie_activeInner();
			const node = sel.anchorNode;
			const el = node ? ( node.nodeType === 1 ? node : node.parentElement ) : null;
			const anchor = el && el.closest ? el.closest( 'a' ) : null;
			fie_editAnchor = ( anchor && inner && inner.contains( anchor ) ) ? anchor : null;

			state.linkEditing = !! fie_editAnchor;
			state.linkTitle = fie_editAnchor ? 'Edit link' : 'Add link';

			const input = fie_linkInput();
			if ( input ) {
				input.value = fie_editAnchor ? ( fie_editAnchor.getAttribute( 'href' ) || '' ) : '';
			}
			const newtab = fie_newtabInput();
			if ( newtab ) {
				newtab.checked = fie_editAnchor ? ( fie_editAnchor.getAttribute( 'target' ) === '_blank' ) : false;
			}

			state.linkOpen = true;
			if ( input ) {
				setTimeout( () => { input.focus(); input.select(); }, 0 );
			}
		},

		applyLink() {
			if ( fie_doApplyLink() ) {
				fie_closeLink();
			}
		},

		removeLink() {
			fie_doRemoveLink();
			fie_closeLink();
		},

		closeLink() {
			fie_closeLink();
		},

		// Close only when the click is on the backdrop itself, not the dialog.
		modalBackdrop( event ) {
			if ( event && event.target && event.target.classList
				&& event.target.classList.contains( 'fie-modal-overlay' ) ) {
				fie_closeLink();
			}
		},

		linkKeydown( event ) {
			if ( event.key === 'Enter' ) {
				event.preventDefault();
				if ( fie_doApplyLink() ) {
					fie_closeLink();
				}
			} else if ( event.key === 'Escape' ) {
				event.preventDefault();
				fie_closeLink();
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
				const fieData = await resp.json().catch( () => ( {} ) ); if ( fie_activeCtx && fieData.newBase ) fie_activeCtx.base = fieData.newBase;
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
	fie_activeCtx = null;
	state.linkOpen = false;
	state.linkEditing = false;
	fie_savedRange = null;
	fie_editAnchor = null;
	fie_hideBubble();
}

/** The contentEditable element of the block currently being edited. */
function fie_activeInner() {
	const activeEl = document.querySelector( '.fie-active' );
	return activeEl ? activeEl.querySelector( '[contenteditable="true"]' ) : null;
}

/** Put the caret where the user actually clicked, so focus feels natural. */
function fie_placeCaret( event, inner ) {
	if ( ! event || typeof event.clientX !== 'number' ) return;
	let range = null;
	if ( document.caretRangeFromPoint ) {
		range = document.caretRangeFromPoint( event.clientX, event.clientY );
	} else if ( document.caretPositionFromPoint ) {
		const pos = document.caretPositionFromPoint( event.clientX, event.clientY );
		if ( pos ) {
			range = document.createRange();
			range.setStart( pos.offsetNode, pos.offset );
			range.collapse( true );
		}
	}
	if ( range && inner.contains( range.startContainer ) ) {
		const sel = window.getSelection();
		sel.removeAllRanges();
		sel.addRange( range );
	}
}

/** The link URL field in the modal. */
function fie_linkInput() {
	return document.querySelector( '.fie-link-input' );
}

/** The "open in new tab" checkbox in the modal. */
function fie_newtabInput() {
	return document.querySelector( '.fie-link-newtab' );
}

/** Close the modal and clear the pending selection/anchor. */
function fie_closeLink() {
	state.linkOpen = false;
	fie_savedRange = null;
	fie_editAnchor = null;
}

/** Restore the saved selection back into the editor before running a command. */
function fie_restoreSelection( inner ) {
	inner.focus();
	if ( fie_savedRange ) {
		const sel = window.getSelection();
		sel.removeAllRanges();
		sel.addRange( fie_savedRange );
	}
}

/** Unwrap an anchor, keeping its text where it sits. */
function fie_unwrap( a ) {
	const parent = a.parentNode;
	if ( ! parent ) return;
	while ( a.firstChild ) {
		parent.insertBefore( a.firstChild, a );
	}
	parent.removeChild( a );
}

/** Set or clear the new-tab attributes on an anchor. */
function fie_setNewTab( anchor, newtab ) {
	if ( ! anchor ) return;
	if ( newtab ) {
		anchor.setAttribute( 'target', '_blank' );
		anchor.setAttribute( 'rel', 'noopener' );
	} else {
		anchor.removeAttribute( 'target' );
		anchor.removeAttribute( 'rel' );
	}
}

/**
 * Apply the URL: update the link being edited, wrap the selected text, or (if
 * nothing is selected) insert a new link showing the URL as its text.
 * Returns true when something was applied, false when there was nothing to do.
 */
function fie_doApplyLink() {
	const inner = fie_activeInner();
	const input = fie_linkInput();
	if ( ! inner || ! input ) return false;

	const url = input.value.trim();
	const newtab = fie_newtabInput() ? fie_newtabInput().checked : false;

	// Nothing to do: no URL and not editing an existing link.
	if ( ! url && ! fie_editAnchor ) return false;

	fie_restoreSelection( inner );

	let anchor = null;

	if ( fie_editAnchor ) {
		if ( url ) {
			fie_editAnchor.setAttribute( 'href', url );
			anchor = fie_editAnchor;
		} else {
			fie_unwrap( fie_editAnchor );
		}
	} else {
		const sel = window.getSelection();
		const range = sel && sel.rangeCount ? sel.getRangeAt( 0 ) : null;
		if ( range && ! range.collapsed ) {
			document.execCommand( 'createLink', false, url );
			const node = window.getSelection().anchorNode;
			const el = node ? ( node.nodeType === 1 ? node : node.parentElement ) : null;
			anchor = el && el.closest ? el.closest( 'a' ) : null;
		} else if ( range ) {
			// No selection: drop a new link at the caret, using the URL as its text.
			anchor = document.createElement( 'a' );
			anchor.href = url;
			anchor.textContent = url;
			range.insertNode( anchor );
		}
	}

	fie_setNewTab( anchor, newtab );

	fie_editAnchor = null;
	fie_savedRange = null;
	return true;
}

/** Remove the link at the selection (or the anchor being edited). */
function fie_doRemoveLink() {
	const inner = fie_activeInner();
	if ( ! inner ) return;

	fie_restoreSelection( inner );

	if ( fie_editAnchor ) {
		fie_unwrap( fie_editAnchor );
	} else {
		document.execCommand( 'unlink' );
	}

	fie_editAnchor = null;
	fie_savedRange = null;
}

/* ---- Floating link bubble (add + edit) --------------------------------- */

/** Start adding a link to the current text selection (from the 'add' bubble). */
function fie_startLinkFromSelection() {
	const sel = window.getSelection();
	if ( ! sel || ! sel.rangeCount ) return;
	fie_savedRange = sel.getRangeAt( 0 ).cloneRange();
	fie_editAnchor = null;
	state.linkEditing = false;
	state.linkTitle = 'Add link';

	const input = fie_linkInput();
	if ( input ) input.value = '';
	const newtab = fie_newtabInput();
	if ( newtab ) newtab.checked = false;

	fie_hideBubble();
	state.linkOpen = true;
	if ( input ) setTimeout( () => input.focus(), 0 );
}

/** Open the modal preloaded with a specific anchor (from the 'edit' bubble). */
function fie_openLinkForAnchor( anchor ) {
	fie_editAnchor = anchor;

	const range = document.createRange();
	range.selectNodeContents( anchor );
	fie_savedRange = range.cloneRange();

	state.linkEditing = true;
	state.linkTitle = 'Edit link';

	const input = fie_linkInput();
	if ( input ) input.value = anchor.getAttribute( 'href' ) || '';
	const newtab = fie_newtabInput();
	if ( newtab ) newtab.checked = anchor.getAttribute( 'target' ) === '_blank';

	fie_hideBubble();
	state.linkOpen = true;
	if ( input ) setTimeout( () => { input.focus(); input.select(); }, 0 );
}

/** Position the bubble by its target: above a selection, below a link. */
function fie_positionBubble() {
	if ( ! fie_bubble ) return;
	let rect = null;
	if ( 'edit' === fie_bubbleMode && fie_bubbleAnchor ) {
		rect = fie_bubbleAnchor.getBoundingClientRect();
	} else {
		const sel = window.getSelection();
		if ( sel && sel.rangeCount ) rect = sel.getRangeAt( 0 ).getBoundingClientRect();
	}
	if ( ! rect ) return;

	const bw = fie_bubble.offsetWidth;
	const bh = fie_bubble.offsetHeight;
	let left = rect.left + ( rect.width / 2 ) - ( bw / 2 );
	const maxLeft = window.innerWidth - bw - 12;
	if ( left > maxLeft ) left = Math.max( 12, maxLeft );
	if ( left < 12 ) left = 12;

	let top;
	if ( 'add' === fie_bubbleMode ) {
		top = rect.top - bh - 8;
		if ( top < 48 ) top = rect.bottom + 8; // flip below if it would hit the toolbar
	} else {
		top = rect.bottom + 8;
	}
	fie_bubble.style.left = left + 'px';
	fie_bubble.style.top = top + 'px';
}

/** A bubble button that keeps the editor's selection when pressed. */
function fie_bubbleButton( label, extra ) {
	const b = document.createElement( 'button' );
	b.type = 'button';
	b.className = 'fie-bubble-btn' + ( extra ? ' ' + extra : '' );
	b.textContent = label;
	b.addEventListener( 'mousedown', ( e ) => e.preventDefault() );
	return b;
}

/** Remove the bubble. */
function fie_hideBubble() {
	if ( ! fie_bubble ) return;
	fie_bubble.remove();
	fie_bubble = null;
	fie_bubbleAnchor = null;
	fie_bubbleMode = null;
}

/** Show (or reposition) the bubble in 'add' or 'edit' mode. */
function fie_showBubble( mode, target ) {
	// Same bubble already shown — just keep it positioned (no flicker).
	if ( fie_bubble && fie_bubbleMode === mode && ( 'edit' !== mode || fie_bubbleAnchor === target ) ) {
		fie_positionBubble();
		return;
	}
	fie_hideBubble();
	fie_bubbleMode = mode;
	fie_bubbleAnchor = 'edit' === mode ? target : null;

	const bubble = document.createElement( 'div' );
	bubble.className = 'fie-link-bubble';

	if ( 'edit' === mode ) {
		const anchor = target;
		const href = anchor.getAttribute( 'href' ) || '';
		const short = href.length > 40 ? href.slice( 0, 40 ) + '…' : href;

		const url = document.createElement( 'a' );
		url.className = 'fie-bubble-url';
		url.href = href;
		url.target = '_blank';
		url.rel = 'noopener';
		url.textContent = short || '(no URL)';

		const edit = fie_bubbleButton( 'Edit' );
		const remove = fie_bubbleButton( 'Remove', 'fie-bubble-remove' );
		edit.addEventListener( 'click', ( e ) => { e.preventDefault(); fie_openLinkForAnchor( anchor ); } );
		remove.addEventListener( 'click', ( e ) => {
			e.preventDefault();
			const a = fie_bubbleAnchor;
			fie_hideBubble();
			if ( a ) fie_unwrap( a );
		} );

		bubble.appendChild( url );
		bubble.appendChild( edit );
		bubble.appendChild( remove );
	} else {
		const add = fie_bubbleButton( '🔗 Link' );
		add.addEventListener( 'click', ( e ) => { e.preventDefault(); fie_startLinkFromSelection(); } );
		bubble.appendChild( add );
	}

	document.body.appendChild( bubble );
	fie_bubble = bubble;
	fie_positionBubble();
}

/** Decide what the bubble should show based on the current selection. */
function fie_syncBubble() {
	if ( ! state.isEditing || state.linkOpen ) { fie_hideBubble(); return; }

	const inner = fie_activeInner();
	const sel = window.getSelection();
	if ( ! inner || ! sel || ! sel.rangeCount ) { fie_hideBubble(); return; }

	const range = sel.getRangeAt( 0 );
	if ( ! inner.contains( range.commonAncestorContainer ) ) { fie_hideBubble(); return; }

	// Caret inside an existing link → edit bubble.
	const node = sel.anchorNode;
	const el = node ? ( node.nodeType === 1 ? node : node.parentElement ) : null;
	const anchor = el && el.closest ? el.closest( 'a' ) : null;
	if ( anchor && inner.contains( anchor ) ) {
		fie_showBubble( 'edit', anchor );
		return;
	}

	// Text selected (and not a link) → add bubble.
	if ( ! range.collapsed ) {
		fie_showBubble( 'add', range );
		return;
	}

	fie_hideBubble();
}

/** Coalesce the frequent selectionchange events into one update per frame. */
function fie_scheduleBubbleSync() {
	if ( fie_bubbleRaf ) return;
	fie_bubbleRaf = requestAnimationFrame( () => {
		fie_bubbleRaf = null;
		fie_syncBubble();
	} );
}

document.addEventListener( 'selectionchange', fie_scheduleBubbleSync );
window.addEventListener( 'scroll', () => fie_positionBubble(), true );
window.addEventListener( 'resize', () => fie_positionBubble() );

/* ---- Image editing: replace / alt / remove ----------------------------- */

/** Position the image controls near the top of the image. */
function fie_positionImageBubble() {
	if ( ! fie_imgBubble || ! fie_imgEl ) return;
	const r = fie_imgEl.getBoundingClientRect();
	const bw = fie_imgBubble.offsetWidth;
	let left = r.left + ( r.width / 2 ) - ( bw / 2 );
	const maxLeft = window.innerWidth - bw - 12;
	if ( left > maxLeft ) left = Math.max( 12, maxLeft );
	if ( left < 12 ) left = 12;
	let top = r.top + 10;
	if ( top < 48 ) top = 48; // clear the fixed toolbar
	fie_imgBubble.style.left = left + 'px';
	fie_imgBubble.style.top = top + 'px';
}

/** Hide the outside-click / scroll listeners are dropped here too. */
function fie_hideImageBubble() {
	if ( fie_imgEl ) fie_imgEl.classList.remove( 'fie-img-selected' );
	if ( ! fie_imgBubble ) return;
	fie_imgBubble.remove();
	fie_imgBubble = null;
	document.removeEventListener( 'mousedown', fie_imgDocClick, true );
	window.removeEventListener( 'scroll', fie_positionImageBubble, true );
	window.removeEventListener( 'resize', fie_positionImageBubble );
}

/** Dismiss the image controls when clicking away from them and the image. */
function fie_imgDocClick( e ) {
	if ( fie_imgBubble && fie_imgBubble.contains( e.target ) ) return;
	if ( fie_imgEl && fie_imgEl.contains( e.target ) ) return;
	fie_hideImageBubble();
}

/** Show the image controls bubble over the clicked image. */
function fie_showImageBubble() {
	fie_hideImageBubble();

	const bubble = document.createElement( 'div' );
	bubble.className = 'fie-link-bubble fie-img-bubble';

	const replace = fie_bubbleButton( 'Replace' );
	const alt = fie_bubbleButton( 'Alt text' );
	const remove = fie_bubbleButton( 'Remove', 'fie-bubble-remove' );
	replace.addEventListener( 'click', ( e ) => { e.preventDefault(); fie_openMedia(); } );
	alt.addEventListener( 'click', ( e ) => { e.preventDefault(); fie_showAltInput(); } );
	remove.addEventListener( 'click', ( e ) => { e.preventDefault(); fie_removeImage(); } );

	bubble.appendChild( replace );
	bubble.appendChild( alt );
	bubble.appendChild( remove );
	document.body.appendChild( bubble );

	fie_imgBubble = bubble;
	if ( fie_imgEl ) fie_imgEl.classList.add( 'fie-img-selected' );
	fie_positionImageBubble();

	setTimeout( () => document.addEventListener( 'mousedown', fie_imgDocClick, true ), 0 );
	window.addEventListener( 'scroll', fie_positionImageBubble, true );
	window.addEventListener( 'resize', fie_positionImageBubble );
}

/** Swap the bubble contents for an inline alt-text field. */
function fie_showAltInput() {
	if ( ! fie_imgBubble ) return;
	fie_imgBubble.innerHTML = '';

	const input = document.createElement( 'input' );
	input.type = 'text';
	input.className = 'fie-alt-input';
	input.placeholder = 'Describe this image (alt text)';
	input.value = fie_imgImg ? ( fie_imgImg.getAttribute( 'alt' ) || '' ) : '';

	const save = fie_bubbleButton( 'Save' );
	const commit = () => { fie_sendImageOp( 'alt', { alt: input.value } ); fie_hideImageBubble(); };
	save.addEventListener( 'click', ( e ) => { e.preventDefault(); commit(); } );
	input.addEventListener( 'keydown', ( e ) => {
		if ( e.key === 'Enter' ) { e.preventDefault(); commit(); }
		else if ( e.key === 'Escape' ) { e.preventDefault(); fie_hideImageBubble(); }
	} );

	fie_imgBubble.appendChild( input );
	fie_imgBubble.appendChild( save );
	fie_positionImageBubble();
	setTimeout( () => input.focus(), 0 );
}

/** Open the WordPress media library to pick a replacement image. */
function fie_openMedia() {
	const wp = window.wp;
	if ( ! wp || ! wp.media ) {
		fie_toast( 'The media library is unavailable.', true );
		return;
	}
	const frame = wp.media( {
		title: 'Replace image',
		button: { text: 'Use this image' },
		multiple: false,
		library: { type: 'image' },
	} );
	frame.on( 'select', function () {
		const att = frame.state().get( 'selection' ).first().toJSON();
		let url = att.url;
		if ( att.sizes && att.sizes.large && att.sizes.large.url ) {
			url = att.sizes.large.url;
		}
		fie_sendImageOp( 'replace', { id: att.id, url: url, alt: att.alt || '' } );
	} );
	frame.open();
}

/** Confirm and remove the image block. */
function fie_removeImage() {
	if ( ! window.confirm( 'Remove this image?' ) ) return;
	fie_sendImageOp( 'remove' );
}

/** Send an image operation to the server and reflect it in the page. */
async function fie_sendImageOp( op, extra ) {
	if ( fie_imgIndex === null ) return;
	const body = Object.assign(
		{ postId: state.postId, blockIndex: fie_imgIndex, baseHash: fie_imgBase, op: op },
		extra || {}
	);
	try {
		const resp = await fetch( state.imageEndpoint, {
			method: 'POST',
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': state.restNonce },
			body: JSON.stringify( body ),
		} );
		const data = await resp.json().catch( () => ( {} ) );
		if ( ! resp.ok ) {
			fie_toast( data.message || 'Update failed', true );
			return;
		}

		if ( op === 'remove' ) {
			if ( fie_imgEl ) fie_imgEl.remove();
			fie_hideImageBubble();
			fie_imgEl = null;
			fie_imgImg = null;
			fie_imgIndex = null;
			fie_imgBase = '';
			fie_toast( 'Image removed' );
			return;
		}

		if ( op === 'replace' && fie_imgImg ) {
			fie_imgImg.setAttribute( 'src', extra.url );
			fie_imgImg.setAttribute( 'alt', extra.alt || '' );
			fie_imgImg.removeAttribute( 'srcset' );
			fie_imgImg.removeAttribute( 'sizes' );
			if ( extra.id ) {
				fie_imgImg.className = ( fie_imgImg.className.replace( /\bwp-image-\d+\b/, '' ).trim() + ' wp-image-' + extra.id ).trim();
			}
		} else if ( op === 'alt' && fie_imgImg ) {
			fie_imgImg.setAttribute( 'alt', extra.alt || '' );
		}

		if ( data.newBase ) { fie_imgBase = data.newBase; if ( fie_imgCtx ) fie_imgCtx.base = data.newBase; }
		fie_toast( op === 'alt' ? 'Alt text saved' : 'Image replaced' );
		fie_positionImageBubble();
	} catch ( e ) {
		fie_toast( 'Error: ' + e.message, true );
	}
}

/** A small transient toast, used for image actions. */
function fie_toast( msg, isError ) {
	let t = document.querySelector( '.fie-toast' );
	if ( ! t ) {
		t = document.createElement( 'div' );
		t.className = 'fie-toast';
		document.body.appendChild( t );
	}
	t.textContent = msg;
	t.classList.toggle( 'fie-toast-error', !! isError );
	// Force reflow so re-triggering the animation works.
	void t.offsetWidth;
	t.classList.add( 'fie-toast-show' );
	if ( fie_toastTimer ) clearTimeout( fie_toastTimer );
	fie_toastTimer = setTimeout( () => t.classList.remove( 'fie-toast-show' ), 2600 );
}

/* ---- Button editing: text + link --------------------------------------- */

function fie_btnTextInput() { return document.querySelector( '.fie-btn-text-input' ); }
function fie_btnUrlInput() { return document.querySelector( '.fie-btn-url-input' ); }
function fie_btnNewtabInput() { return document.querySelector( '.fie-btn-newtab' ); }

function fie_closeButton() {
	state.buttonOpen = false;
	fie_btnEl = null;
	fie_btnAnchor = null;
	fie_btnIndex = null;
}

/** Save the button's new text and link, then reflect it in the page. */
async function fie_doApplyButton() {
	if ( fie_btnIndex === null ) return;
	const text = fie_btnTextInput() ? fie_btnTextInput().value : '';
	const url = fie_btnUrlInput() ? fie_btnUrlInput().value.trim() : '';
	const newtab = fie_btnNewtabInput() ? fie_btnNewtabInput().checked : false;

	try {
		const resp = await fetch( state.buttonEndpoint, {
			method: 'POST',
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': state.restNonce },
			body: JSON.stringify( { postId: state.postId, blockIndex: fie_btnIndex, text: text, url: url, newtab: newtab } ),
		} );
		const data = await resp.json().catch( () => ( {} ) );
		if ( ! resp.ok ) {
			fie_toast( data.message || 'Update failed', true );
			return;
		}

		if ( fie_btnAnchor ) {
			fie_btnAnchor.textContent = text;
			if ( url ) fie_btnAnchor.setAttribute( 'href', url );
			else fie_btnAnchor.removeAttribute( 'href' );
			if ( newtab ) {
				fie_btnAnchor.setAttribute( 'target', '_blank' );
				fie_btnAnchor.setAttribute( 'rel', 'noreferrer noopener' );
			} else {
				fie_btnAnchor.removeAttribute( 'target' );
				fie_btnAnchor.removeAttribute( 'rel' );
			}
		}

		fie_toast( 'Button updated' );
		fie_closeButton();
	} catch ( e ) {
		fie_toast( 'Error: ' + e.message, true );
	}
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
