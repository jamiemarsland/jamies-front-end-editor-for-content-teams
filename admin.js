/**
 * Block picker for the settings page.
 *
 * Turns the "Other blocks (advanced)" setting into a search-and-add field:
 * type to find a block, click to add it as a chip. Each chip carries a hidden
 * input so the selection submits with the normal settings form.
 */
( function () {
	var data = window.jfectPicker || { blocks: [] };
	var search = document.getElementById( 'jfect-block-search' );
	var suggestions = document.getElementById( 'jfect-suggestions' );
	var chips = document.getElementById( 'jfect-chips' );

	if ( ! search || ! suggestions || ! chips ) {
		return;
	}

	function enabledNames() {
		var inputs = chips.querySelectorAll( 'input[type="hidden"]' );
		return Array.prototype.map.call( inputs, function ( i ) {
			return i.value;
		} );
	}

	function closeSuggestions() {
		suggestions.innerHTML = '';
		suggestions.hidden = true;
	}

	function render() {
		var q = search.value.trim().toLowerCase();
		suggestions.innerHTML = '';

		if ( ! q ) {
			closeSuggestions();
			return;
		}

		var enabled = enabledNames();
		var matches = data.blocks.filter( function ( b ) {
			if ( enabled.indexOf( b.name ) !== -1 ) {
				return false;
			}
			return (
				b.title.toLowerCase().indexOf( q ) !== -1 ||
				b.name.toLowerCase().indexOf( q ) !== -1
			);
		} ).slice( 0, 10 );

		if ( ! matches.length ) {
			var none = document.createElement( 'li' );
			none.className = 'jfect-suggestion jfect-suggestion-empty';
			none.textContent = data.noneFound || 'No matching blocks';
			suggestions.appendChild( none );
			suggestions.hidden = false;
			return;
		}

		matches.forEach( function ( b ) {
			var li = document.createElement( 'li' );
			li.className = 'jfect-suggestion';

			var label = document.createElement( 'strong' );
			label.textContent = b.title;

			var code = document.createElement( 'code' );
			code.textContent = b.name;

			li.appendChild( label );
			li.appendChild( code );

			// mousedown fires before the input blur, so the click still registers.
			li.addEventListener( 'mousedown', function ( e ) {
				e.preventDefault();
				addChip( b.name, b.title );
			} );

			suggestions.appendChild( li );
		} );

		suggestions.hidden = false;
	}

	function addChip( name, title ) {
		var span = document.createElement( 'span' );
		span.className = 'jfect-chip';

		var label = document.createElement( 'span' );
		label.className = 'jfect-chip-label';
		label.textContent = title;

		var code = document.createElement( 'code' );
		code.textContent = name;

		var btn = document.createElement( 'button' );
		btn.type = 'button';
		btn.className = 'jfect-chip-remove';
		btn.setAttribute( 'aria-label', ( data.removeText || 'Remove' ) + ' ' + title );
		btn.innerHTML = '&times;';

		var input = document.createElement( 'input' );
		input.type = 'hidden';
		input.name = 'jfect_extra_blocks[]';
		input.value = name;

		span.appendChild( label );
		span.appendChild( code );
		span.appendChild( btn );
		span.appendChild( input );
		chips.appendChild( span );

		search.value = '';
		closeSuggestions();
		search.focus();
	}

	chips.addEventListener( 'click', function ( e ) {
		var btn = e.target.closest( '.jfect-chip-remove' );
		if ( btn ) {
			var chip = btn.closest( '.jfect-chip' );
			if ( chip ) {
				chip.remove();
			}
		}
	} );

	search.addEventListener( 'input', render );
	search.addEventListener( 'focus', render );

	document.addEventListener( 'click', function ( e ) {
		if ( ! e.target.closest( '.jfect-block-picker' ) ) {
			closeSuggestions();
		}
	} );
}() );
