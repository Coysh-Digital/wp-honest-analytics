/**
 * Honest Analytics - the front-end tracker.
 *
 * Two requests per pageview and nothing else. The first goes out as soon as
 * this file runs, so a page served from a cache is counted immediately rather
 * than only if the visitor happens to leave tidily. The second goes out when
 * they leave, carrying how long they stayed.
 *
 * It touches no storage of any kind: no cookies, no localStorage, no
 * sessionStorage, no IndexedDB. It reads its own script tag for configuration
 * and reads nothing else about the browser.
 */
(function () {
	'use strict';

	var script = document.currentScript;

	if ( ! script || ! navigator ) {
		return;
	}

	var endpoint = script.getAttribute( 'data-endpoint' );

	if ( ! endpoint ) {
		return;
	}

	var nonce = script.getAttribute( 'data-nonce' ) || '';
	var clock = window.performance && performance.now ? function () { return performance.now(); } : function () { return Date.now(); };
	var started = clock();
	var leaving = false;
	var extras = [];

	function path() {
		return location.pathname + location.search;
	}

	function send( body ) {
		if ( navigator.sendBeacon ) {
			navigator.sendBeacon( endpoint, body );

			return;
		}

		// Older Safari and a few locked-down browsers have no sendBeacon.
		// keepalive lets the request outlive the page the same way.
		if ( window.fetch ) {
			fetch( endpoint, { method: 'POST', body: body, keepalive: true, credentials: 'same-origin', mode: 'same-origin' } ).catch( function () {} );
		}
	}

	function view() {
		var body = new URLSearchParams();

		body.set( 'p', path() );

		if ( nonce ) {
			body.set( 'n', nonce );
		}

		send( body );
	}

	function engagement() {
		if ( leaving ) {
			return;
		}

		leaving = true;

		var body = new URLSearchParams();

		body.set( 'p', path() );
		body.set( 'd', String( Math.round( clock() - started ) ) );
		body.set( 'e', '1' );

		for ( var i = 0; i < extras.length; i++ ) {
			try {
				var extra = extras[ i ]();

				for ( var key in extra ) {
					if ( Object.prototype.hasOwnProperty.call( extra, key ) ) {
						body.set( key, extra[ key ] );
					}
				}
			} catch ( error ) {}
		}

		send( body );
	}

	// pagehide is the only one iOS delivers reliably; visibilitychange covers
	// tab switches and the desktop cases pagehide misses.
	addEventListener( 'pagehide', engagement );
	addEventListener( 'visibilitychange', function () {
		if ( 'hidden' === document.visibilityState ) {
			engagement();
		}
	} );

	// Restored from the back/forward cache: a new pageview, but the nonce
	// belonged to the original delivery and has already been claimed.
	addEventListener( 'pageshow', function ( event ) {
		if ( event.persisted ) {
			leaving = false;
			nonce = '';
			started = clock();
			view();
		}
	} );

	window.honestAnalytics = window.honestAnalytics || {};
	window.honestAnalytics.extend = function ( fn ) {
		if ( 'function' === typeof fn ) {
			extras.push( fn );
		}
	};

	view();
}());
