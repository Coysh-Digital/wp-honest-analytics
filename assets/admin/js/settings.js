/**
 * Honest Analytics - the Settings screen.
 *
 * Separate from admin.js because none of this is wanted on a report screen, and
 * the settings page is the one place where getting the enhancement wrong has a
 * cost: somebody changes what is collected, navigates away, and never finds out
 * the change was not saved.
 *
 * Everything here is an enhancement. The tabs are anchors to sections that are
 * all present in the markup, the consequences are already rendered beside their
 * controls, and the form submits the same way with JavaScript switched off.
 */
(function () {
	'use strict';

	var form = document.querySelector( '[data-ha-settings-form]' );

	if ( ! form ) {
		return;
	}

	var strings = window.honestAnalyticsSettings || {};

	/* ------------------------------------------------------------------ tabs */

	var tabs = form.querySelectorAll( '[data-ha-settings-tab]' );

	if ( tabs.length ) {
		var panels = {};

		Array.prototype.forEach.call( tabs, function ( tab ) {
			var name = tab.getAttribute( 'data-ha-settings-tab' );
			var panel = form.querySelector( '[data-ha-settings-panel="' + name + '"]' );

			if ( panel ) {
				panels[ name ] = panel;
			}
		} );

		var select = function ( name, push ) {
			if ( ! panels[ name ] ) {
				return;
			}

			Array.prototype.forEach.call( tabs, function ( tab ) {
				var selected = tab.getAttribute( 'data-ha-settings-tab' ) === name;

				tab.classList.toggle( 'is-selected', selected );
				tab.setAttribute( 'aria-selected', selected ? 'true' : 'false' );
			} );

			for ( var key in panels ) {
				if ( Object.prototype.hasOwnProperty.call( panels, key ) ) {
					panels[ key ].hidden = key !== name;
				}
			}

			// Written to the address bar rather than the fragment, so a reload
			// or a save round trip comes back to the group being edited without
			// the browser also scrolling to it.
			if ( push && window.history && history.replaceState ) {
				history.replaceState( null, '', '#' + name );
			}
		};

		Array.prototype.forEach.call( tabs, function ( tab ) {
			tab.addEventListener( 'click', function ( event ) {
				event.preventDefault();
				select( tab.getAttribute( 'data-ha-settings-tab' ), true );
			} );
		} );

		select( location.hash.replace( '#', '' ) || tabs[0].getAttribute( 'data-ha-settings-tab' ), false );

		// A validation error names the field, not the group it lives in.
		form.addEventListener( 'invalid', function ( event ) {
			var panel = event.target && event.target.closest ? event.target.closest( '[data-ha-settings-panel]' ) : null;

			if ( panel && panel.hidden ) {
				select( panel.getAttribute( 'data-ha-settings-panel' ), true );
			}
		}, true );
	}

	/* ---------------------------------------------------------- consequences */

	/**
	 * Reveal the consequence beside a control that has moved off its default.
	 *
	 * The text is server-rendered and always present; only its visibility is
	 * decided here, so the warning survives with JavaScript switched off.
	 */
	Array.prototype.forEach.call( form.querySelectorAll( '[data-ha-consequence-for]' ), function ( note ) {
		var control = form.querySelector( '[name="' + note.getAttribute( 'data-ha-consequence-for' ) + '"]' );

		if ( ! control ) {
			return;
		}

		var trigger = note.getAttribute( 'data-ha-consequence-when' );

		var sync = function () {
			var value = 'checkbox' === control.type ? ( control.checked ? '1' : '0' ) : control.value;

			note.hidden = null !== trigger && value !== trigger;
		};

		control.addEventListener( 'change', sync );

		sync();
	} );

	/* -------------------------------------------------------------- posture */

	// The banner at the top of the screen states what the current combination
	// of settings means for a visitor. Left to a page load it would describe
	// the settings as they were saved, which is the opposite of useful while
	// somebody is deciding whether to change them.
	var posture = document.querySelector( '[data-ha-posture]' );

	if ( posture ) {
		var claims = form.querySelectorAll( '[data-ha-posture-claim]' );

		var syncPosture = function () {
			var broken = [];

			Array.prototype.forEach.call( claims, function ( control ) {
				var breaksIt = control.getAttribute( 'data-ha-posture-claim' );
				var value = 'checkbox' === control.type ? ( control.checked ? '1' : '0' ) : control.value;

				if ( value === breaksIt ) {
					broken.push( control.getAttribute( 'data-ha-posture-label' ) || control.name );
				}
			} );

			posture.classList.toggle( 'is-clean', 0 === broken.length );
			posture.classList.toggle( 'is-consent', broken.length > 0 );

			var body = posture.querySelector( '[data-ha-posture-body]' );

			if ( body ) {
				body.textContent = broken.length
					? ( strings.postureChanged || '' ).replace( '%s', broken.join( ', ' ) )
					: ( strings.postureClean || body.textContent );
			}

			var unsaved = posture.querySelector( '[data-ha-posture-unsaved]' );

			if ( unsaved ) {
				unsaved.hidden = ! dirty;
			}
		};

		Array.prototype.forEach.call( claims, function ( control ) {
			control.addEventListener( 'change', syncPosture );
		} );

		syncPosture();
	}

	/* ----------------------------------------------------- unsaved changes */

	var dirty = false;

	form.addEventListener( 'change', function () {
		dirty = true;
	} );

	form.addEventListener( 'input', function () {
		dirty = true;
	} );

	form.addEventListener( 'submit', function () {
		dirty = false;
	} );

	addEventListener( 'beforeunload', function ( event ) {
		if ( ! dirty ) {
			return;
		}

		// Browsers ignore the text and show their own, but returning a value is
		// still what asks for the prompt at all.
		event.preventDefault();
		event.returnValue = '';

		return '';
	} );

	/* ------------------------------------------------------------ clipboard */

	Array.prototype.forEach.call( document.querySelectorAll( '[data-ha-copy]' ), function ( button ) {
		var source = document.querySelector( button.getAttribute( 'data-ha-copy' ) );

		if ( ! source || ! navigator.clipboard ) {
			return;
		}

		button.hidden = false;

		button.addEventListener( 'click', function ( event ) {
			event.preventDefault();

			navigator.clipboard.writeText( source.textContent.trim() ).then( function () {
				var was = button.textContent;

				button.textContent = strings.copied || 'Copied';

				window.setTimeout( function () {
					button.textContent = was;
				}, 2000 );
			} ).catch( function () {} );
		} );
	} );

	/* ------------------------------------------------------------ retention */

	// Retention is the one number on this screen that destroys data, so the
	// screen says what the chosen figure means in dates rather than in days.
	var retention = form.querySelector( '[data-ha-retention]' );

	if ( retention ) {
		var summary = document.querySelector( '[data-ha-retention-summary]' );

		var syncRetention = function () {
			if ( ! summary ) {
				return;
			}

			var days = parseInt( retention.value, 10 );

			if ( ! days || days < 1 ) {
				summary.textContent = strings.retentionForever || '';

				return;
			}

			var cutoff = new Date();

			cutoff.setDate( cutoff.getDate() - days );

			summary.textContent = ( strings.retentionSummary || '' )
				.replace( '%1$d', String( days ) )
				.replace( '%2$s', cutoff.toLocaleDateString() );
		};

		retention.addEventListener( 'change', syncRetention );
		retention.addEventListener( 'input', syncRetention );

		syncRetention();
	}
}());
