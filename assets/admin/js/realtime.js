/**
 * Honest Analytics - real-time polling.
 *
 * Reads an authenticated endpoint every fifteen seconds and pauses entirely
 * while the tab is hidden: a dashboard left open in a background tab overnight
 * should not spend the night talking to the server.
 */
(function () {
	'use strict';

	var config = window.honestAnalyticsRealtime;

	if ( ! config || ! window.fetch ) {
		return;
	}

	var containers = document.querySelectorAll( config.selector );

	if ( ! containers.length ) {
		return;
	}

	var timer = null;

	function text( value ) {
		return document.createTextNode( String( value ) );
	}

	function relative( seconds ) {
		if ( seconds < 10 ) {
			return config.strings.justNow;
		}

		if ( seconds < 60 ) {
			return config.strings.secondsAgo.replace( '%d', String( seconds ) );
		}

		return config.strings.minutesAgo.replace( '%d', String( Math.round( seconds / 60 ) ) );
	}

	function duration( milliseconds ) {
		var seconds = Math.round( milliseconds / 1000 );

		if ( seconds >= 60 ) {
			return Math.floor( seconds / 60 ) + 'm ' + ( '0' + ( seconds % 60 ) ).slice( -2 ) + 's';
		}

		return seconds + 's';
	}

	function render( container, snapshot ) {
		var counts = container.querySelectorAll( '[data-ha-count]' );

		Array.prototype.forEach.call( counts, function ( node ) {
			var key = node.getAttribute( 'data-ha-count' );

			if ( 'pagesPerVisitor' === key ) {
				node.textContent = snapshot.visitors > 0 ? String( snapshot.pagesPerVisitor ) : '-';

				return;
			}

			node.textContent = Number( snapshot[ key ] || 0 ).toLocaleString();
		} );

		var updated = container.querySelector( '[data-ha-updated]' );

		if ( updated ) {
			updated.textContent = config.strings.updated.replace( '%s', config.strings.justNow );
		}

		var body = container.querySelector( '[data-ha-sessions]' );

		if ( ! body ) {
			return;
		}

		// Rebuilt with DOM nodes rather than innerHTML: every value here came
		// from a visitor's browser, and a table in an administrator's screen is
		// the last place that should be pasted into as markup.
		while ( body.firstChild ) {
			body.removeChild( body.firstChild );
		}

		if ( ! snapshot.active.length ) {
			var emptyRow = document.createElement( 'tr' );
			var emptyCell = document.createElement( 'td' );

			emptyCell.colSpan = 5;
			emptyCell.className = 'ha-muted';
			emptyCell.appendChild( text( config.strings.nobody ) );
			emptyRow.appendChild( emptyCell );
			body.appendChild( emptyRow );

			return;
		}

		snapshot.active.forEach( function ( session ) {
			var row = document.createElement( 'tr' );

			var current = document.createElement( 'td' );
			current.className = 'ha-path-cell';
			current.appendChild( text( session.currentPath ) );
			row.appendChild( current );

			var entry = document.createElement( 'td' );
			entry.className = 'ha-path-cell ha-muted';
			entry.appendChild( text( session.entryPath ) );
			row.appendChild( entry );

			var pages = document.createElement( 'td' );
			pages.className = 'ha-num';
			pages.appendChild( text( session.pageviews ) );
			row.appendChild( pages );

			var time = document.createElement( 'td' );
			time.className = 'ha-num-muted';
			time.appendChild( text( session.isNew ? config.strings.justArrived : duration( session.durationMs ) ) );
			row.appendChild( time );

			var seen = document.createElement( 'td' );
			seen.className = 'ha-num-muted';
			seen.appendChild( text( relative( session.secondsAgo ) ) );
			row.appendChild( seen );

			body.appendChild( row );
		} );
	}

	function poll() {
		fetch( config.endpoint, {
			headers: { 'X-WP-Nonce': config.nonce },
			credentials: 'same-origin'
		} )
			.then( function ( response ) {
				return response.ok ? response.json() : null;
			} )
			.then( function ( snapshot ) {
				if ( ! snapshot ) {
					return;
				}

				Array.prototype.forEach.call( containers, function ( container ) {
					render( container, snapshot );
				} );
			} )
			.catch( function () {} );
	}

	function start() {
		stop();

		timer = window.setInterval( poll, config.interval );
	}

	function stop() {
		if ( timer ) {
			window.clearInterval( timer );

			timer = null;
		}
	}

	document.addEventListener( 'visibilitychange', function () {
		if ( 'hidden' === document.visibilityState ) {
			stop();
		} else {
			poll();
			start();
		}
	} );

	start();
}());
