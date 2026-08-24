/**
 * Honest Analytics - import progress.
 *
 * Two jobs, and the second is the interesting one. It keeps the progress bar
 * honest while somebody watches, and it also *drives* the import: each poll
 * asks the server to do another batch. The admin is the one part of a
 * WordPress site that is never page-cached, so an open tab is the most reliable
 * clock available. Cron catches the tab that was closed.
 *
 * Nothing here is load-bearing. Close the page and the import carries on; the
 * only thing lost is the bar moving in front of you.
 */
(function () {
	'use strict';

	var config = window.honestAnalyticsImport;

	if ( ! config || ! window.fetch ) {
		return;
	}

	var container = document.querySelector( '[data-ha-import]' );

	if ( ! container ) {
		return;
	}

	var bar = container.querySelector( '[data-ha-progress]' );
	var fill = container.querySelector( '.ha-progress-fill' );
	var percentNode = container.querySelector( '[data-ha-percent]' );
	var processedNode = container.querySelector( '[data-ha-processed]' );
	var statusNode = container.querySelector( '[data-ha-import-status]' );

	var timer = null;
	var failures = 0;
	var stopped = false;

	function number( value ) {
		return Number( value || 0 ).toLocaleString( config.locale || undefined );
	}

	function say( message ) {
		if ( statusNode && statusNode.textContent !== message ) {
			statusNode.textContent = message;
		}
	}

	function paint( state ) {
		var percent = Math.max( 0, Math.min( 100, Number( state.percent || 0 ) ) );

		if ( fill ) {
			fill.style.width = percent + '%';
		}

		if ( bar ) {
			bar.setAttribute( 'aria-valuenow', String( percent ) );
		}

		if ( percentNode ) {
			percentNode.textContent = percent + '%';
		}

		if ( processedNode ) {
			processedNode.textContent = config.strings.processed.replace(
				'%s',
				number( state.recordsProcessed )
			);
		}
	}

	function finish() {
		stopped = true;

		if ( timer ) {
			window.clearTimeout( timer );
			timer = null;
		}

		window.location.href = config.done;
	}

	function schedule( delay ) {
		if ( stopped ) {
			return;
		}

		if ( timer ) {
			window.clearTimeout( timer );
		}

		timer = window.setTimeout( poll, delay || config.interval );
	}

	function poll() {
		if ( stopped ) {
			return;
		}

		// A hidden tab drives nothing. Cron is already the safety net, and a
		// background tab quietly hammering the server for an hour is not a
		// contribution.
		if ( document.hidden ) {
			schedule( config.interval );

			return;
		}

		window.fetch( config.tick, {
			method: 'POST',
			credentials: 'same-origin',
			headers: {
				'X-WP-Nonce': config.nonce,
				'Content-Type': 'application/json'
			}
		} ).then( function ( response ) {
			if ( ! response.ok ) {
				throw new Error( 'tick refused' );
			}

			return response.json();
		} ).then( function ( state ) {
			failures = 0;

			paint( state );

			if ( state.finished || 'complete' === state.status ) {
				finish();

				return;
			}

			if ( 'cancelled' === state.status || 'failed' === state.status ) {
				say( state.message || '' );
				stopped = true;

				window.location.reload();

				return;
			}

			if ( state.message ) {
				say( state.message );
			} else if ( 'waiting' === state.status ) {
				say( config.strings.waiting );
			}

			// The server tells us when it would rather be left alone, and a
			// rate limit is an ordinary part of reading years out of an API
			// rather than something to retry impatiently.
			schedule( state.retryAfter ? Number( state.retryAfter ) * 1000 : config.interval );
		} ).catch( function () {
			failures += 1;

			if ( failures >= 4 ) {
				say( config.strings.stalled );
				stopped = true;

				return;
			}

			// Back off rather than pile on: whatever went wrong is unlikely to
			// be fixed by asking again immediately.
			schedule( config.interval * Math.pow( 2, failures ) );
		} );
	}

	document.addEventListener( 'visibilitychange', function () {
		if ( ! document.hidden && ! stopped ) {
			schedule( 250 );
		}
	} );

	say( config.strings.starting );

	schedule( 500 );
}());
