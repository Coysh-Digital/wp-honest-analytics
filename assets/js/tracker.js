/**
 * Honest Analytics - the front-end tracker.
 *
 * Two requests per pageview and nothing else. The first goes out as soon as
 * this file runs, so a page served from a cache is counted immediately rather
 * than only if the visitor happens to leave tidily. The second goes out when
 * they leave, carrying how long they stayed.
 *
 * A client-side route change is both: the view being left is closed with its
 * own dwell, and the new one opens.
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

	// Captured when the view begins, not read when it is sent. On a
	// client-routed theme those differ: four articles read without a page load
	// used to be one view, credited to whichever URL was current at the end.
	var current = path();

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

		body.set( 'p', current );

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

		body.set( 'p', current );
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

	function route() {
		var next = path();

		// replaceState syncs state as often as it navigates, and rewriting the
		// query string a page already had is not a pageview.
		if ( next === current ) {
			return;
		}

		engagement();

		current = next;
		leaving = false;
		started = clock();

		// The nonce authorises the one delivery the server rendered; a route
		// the browser assembled has no such claim.
		nonce = '';

		view();
	}

	// Neither fires an event, so wrapping is the only way to hear them. The
	// original's return value is handed straight back, so a framework reading
	// it is unaffected.
	function patch( name ) {
		var original = history[ name ];

		if ( 'function' !== typeof original ) {
			return;
		}

		history[ name ] = function () {
			var result = original.apply( this, arguments );

			route();

			return result;
		};
	}

	if ( window.history ) {
		patch( 'pushState' );
		patch( 'replaceState' );
	}

	// Back and forward. No hashchange: path() excludes the fragment on purpose,
	// because #comments is a place on a page rather than a page - so a
	// fragment-only router is invisible here, and counting one would make every
	// anchor link on every ordinary site a pageview.
	addEventListener( 'popstate', route );

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
			current = path();
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
