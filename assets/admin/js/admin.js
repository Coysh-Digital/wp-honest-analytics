/**
 * Honest Analytics - admin progressive enhancement.
 *
 * Everything on these screens works without JavaScript: the date picker is a
 * form, the comparison selector is a form, the settings groups are all present
 * in the markup. This file only makes them pleasanter.
 */
(function () {
	'use strict';

	/**
	 * Close the custom date picker when the focus leaves it.
	 */
	document.addEventListener( 'click', function ( event ) {
		var open = document.querySelector( '.ha-range-custom[open]' );

		if ( open && ! open.contains( event.target ) ) {
			open.removeAttribute( 'open' );
		}
	} );

	/**
	 * Keep the comparison button honest about how many rows are ticked.
	 */
	var compareForm = document.querySelector( '[data-ha-compare-form]' );

	if ( compareForm ) {
		var status = compareForm.querySelector( '[data-ha-compare-status]' );
		var limit = parseInt( compareForm.getAttribute( 'data-ha-compare-limit' ) || '4', 10 );
		var template = status ? status.getAttribute( 'data-template' ) : '';

		var update = function () {
			var boxes = compareForm.querySelectorAll( 'input[name="compare[]"]' );
			var checked = compareForm.querySelectorAll( 'input[name="compare[]"]:checked' );

			// Past the limit, the remaining boxes are disabled rather than the
			// submission being rejected afterwards.
			Array.prototype.forEach.call( boxes, function ( box ) {
				if ( ! box.checked ) {
					box.disabled = checked.length >= limit;
				}
			} );

			if ( status && template ) {
				status.textContent = template
					.replace( '%1$d', String( checked.length ) )
					.replace( '%2$d', String( limit ) );
			}
		};

		compareForm.addEventListener( 'change', function ( event ) {
			if ( event.target && 'compare[]' === event.target.name ) {
				update();
			}
		} );

		update();
	}

	/**
	 * Show and hide the settings that only apply when something else is on.
	 */
	Array.prototype.forEach.call( document.querySelectorAll( '[data-ha-toggle]' ), function ( control ) {
		var targets = document.querySelectorAll( control.getAttribute( 'data-ha-toggle' ) );

		var sync = function () {
			Array.prototype.forEach.call( targets, function ( target ) {
				target.hidden = ! control.checked;
			} );
		};

		control.addEventListener( 'change', sync );

		sync();
	} );

	/**
	 * Swap the hint under a goal's target field to match its type.
	 */
	var goalType = document.querySelector( '[data-ha-goal-type]' );

	if ( goalType ) {
		var hint = document.querySelector( '[data-ha-goal-hint]' );

		var hints = {};

		try {
			hints = JSON.parse( goalType.getAttribute( 'data-ha-goal-type' ) || '{}' );
		} catch ( error ) {}

		var syncHint = function () {
			if ( hint && hints[ goalType.value ] ) {
				hint.textContent = hints[ goalType.value ];
			}
		};

		goalType.addEventListener( 'change', syncHint );

		syncHint();
	}

	/**
	 * Confirm before deleting something that takes its measurements with it.
	 */
	Array.prototype.forEach.call( document.querySelectorAll( '[data-ha-confirm]' ), function ( link ) {
		link.addEventListener( 'click', function ( event ) {
			if ( ! window.confirm( link.getAttribute( 'data-ha-confirm' ) ) ) {
				event.preventDefault();
			}
		} );
	} );

	/**
	 * Make a table that scrolls sideways reachable with a keyboard.
	 *
	 * A container with overflow can be scrolled with a mouse or a finger and
	 * not at all with arrow keys, unless it can take focus. Applied only when
	 * the content genuinely overflows, so that a table which fits does not
	 * become a pointless tab stop - which is why this is done here rather than
	 * with a tabindex in the markup: the answer depends on the width of the
	 * window, and PHP does not know it.
	 */
	var SCROLLABLE = '.ha-scroll, .ha-code, .ha-heatmap';

	var syncScrollables = function () {
		Array.prototype.forEach.call( document.querySelectorAll( SCROLLABLE ), function ( region ) {
			var overflows =
				region.scrollWidth > region.clientWidth + 1 ||
				region.scrollHeight > region.clientHeight + 1;

			if ( ! overflows ) {
				region.removeAttribute( 'tabindex' );
				region.removeAttribute( 'role' );
				region.removeAttribute( 'aria-label' );

				return;
			}

			if ( region.hasAttribute( 'tabindex' ) ) {
				return;
			}

			var card = region.closest( '.ha-card' );
			var title = card ? card.querySelector( '.ha-card-title' ) : null;
			var caption = region.querySelector( 'caption' );
			var name = ( caption || title || {} ).textContent;

			region.setAttribute( 'tabindex', '0' );
			region.setAttribute( 'role', 'group' );
			region.setAttribute(
				'aria-label',
				name ? name.trim() + ' - scrollable' : 'Scrollable region'
			);
		} );
	};

	syncScrollables();

	if ( window.ResizeObserver ) {
		var observer = new window.ResizeObserver( syncScrollables );

		Array.prototype.forEach.call( document.querySelectorAll( SCROLLABLE ), function ( region ) {
			observer.observe( region );
		} );
	} else {
		window.addEventListener( 'resize', syncScrollables );
	}
}());
