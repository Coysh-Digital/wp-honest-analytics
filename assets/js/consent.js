/**
 * Honest Analytics - the consent API.
 *
 * Only loaded when consented tracking is switched on. A site's own banner, or
 * its consent platform, calls honestAnalytics.consent( 'granted' ) and this
 * tells the server. Nothing here decides anything: the server resolves the
 * state, and a browser privacy signal outranks whatever is passed in.
 */
(function () {
	'use strict';

	var script = document.currentScript;

	if ( ! script || ! navigator ) {
		return;
	}

	var endpoint = script.getAttribute( 'data-consent-endpoint' );

	if ( ! endpoint ) {
		return;
	}

	var api = window.honestAnalytics = window.honestAnalytics || {};
	var valid = { granted: 1, denied: 1, withdrawn: 1 };
	var last = '';

	api.gpcDetected = true === navigator.globalPrivacyControl;

	/**
	 * Tell the server what the visitor decided.
	 *
	 * honestAnalytics.consent( 'granted' );
	 * honestAnalytics.consent( 'withdrawn', 'cmp' );
	 */
	api.consent = function ( state, method ) {
		if ( ! valid[ state ] ) {
			return;
		}

		// Consent platforms re-announce the same decision on every page. Only a
		// change is worth a request and a row in the log.
		var signature = state + ':' + ( method || 'js' );

		if ( signature === last ) {
			return;
		}

		last = signature;

		var body = new URLSearchParams();

		body.set( 'state', state );
		body.set( 'method', method || 'js' );

		if ( navigator.sendBeacon ) {
			navigator.sendBeacon( endpoint, body );

			return;
		}

		if ( window.fetch ) {
			fetch( endpoint, { method: 'POST', body: body, keepalive: true, credentials: 'same-origin', mode: 'same-origin' } ).catch( function () {} );
		}
	};

	// A banner that loaded before this file could queue its calls.
	if ( Array.isArray( api.q ) ) {
		api.q.forEach( function ( args ) {
			api.consent.apply( null, args );
		} );

		api.q = [];
	}
}());
