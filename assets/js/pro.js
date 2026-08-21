/**
 * Honest Analytics - events, outbound clicks, downloads and scroll depth.
 *
 * Loaded only when the Pro reports that need it are switched on. Scroll depth
 * rides the pageview beacon rather than sending requests of its own, so reading
 * to the bottom of a long article costs nothing extra.
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

	var trackEvents = '1' === script.getAttribute( 'data-events' );
	var trackOutbound = '1' === script.getAttribute( 'data-outbound' );
	var trackDownloads = '1' === script.getAttribute( 'data-downloads' );
	var trackScroll = '1' === script.getAttribute( 'data-scroll' );
	var extensions = ( script.getAttribute( 'data-extensions' ) || '' ).split( ',' ).filter( Boolean );

	var api = window.honestAnalytics = window.honestAnalytics || {};

	function send( params ) {
		var body = new URLSearchParams();

		body.set( 'p', location.pathname + location.search );

		for ( var key in params ) {
			if ( Object.prototype.hasOwnProperty.call( params, key ) ) {
				var value = params[ key ];

				if ( null !== value && undefined !== value && '' !== value ) {
					body.set( key, String( value ) );
				}
			}
		}

		if ( navigator.sendBeacon ) {
			navigator.sendBeacon( endpoint, body );
		} else if ( window.fetch ) {
			fetch( endpoint, { method: 'POST', body: body, keepalive: true, credentials: 'same-origin', mode: 'same-origin' } ).catch( function () {} );
		}
	}

	/**
	 * Record a custom event.
	 *
	 * honestAnalytics.event( 'newsletter-signup' );
	 * honestAnalytics.event( 'quote-requested', { value: 250 } );
	 */
	api.event = function ( name, options ) {
		if ( ! trackEvents || 'string' !== typeof name || ! name ) {
			return;
		}

		options = options || {};

		send( {
			k: 'event',
			en: name.slice( 0, 120 ),
			ev: 'number' === typeof options.value ? options.value : null,
			p: options.path || location.pathname + location.search
		} );
	};

	if ( trackScroll ) {
		var deepest = 0;

		function depth() {
			var scrollable = document.documentElement.scrollHeight - window.innerHeight;

			// A page shorter than the window was read to the end by definition.
			if ( scrollable <= 0 ) {
				return 100;
			}

			return Math.min( 100, Math.round( ( window.scrollY / scrollable ) * 100 ) );
		}

		function bucket( percent ) {
			return percent >= 100 ? 100 : percent >= 75 ? 75 : percent >= 50 ? 50 : percent >= 25 ? 25 : 0;
		}

		addEventListener( 'scroll', function () {
			deepest = Math.max( deepest, depth() );
		}, { passive: true } );

		if ( api.extend ) {
			api.extend( function () {
				var reached = bucket( Math.max( deepest, depth() ) );

				return reached > 0 ? { s: reached } : {};
			} );
		}
	}

	if ( trackOutbound || trackDownloads ) {
		function isDownload( url ) {
			var pathname = url.pathname.toLowerCase();
			var dot = pathname.lastIndexOf( '.' );

			if ( dot < 0 ) {
				return false;
			}

			return extensions.indexOf( pathname.slice( dot + 1 ) ) !== -1;
		}

		// Capture phase, so a click is recorded even when the page's own
		// handlers stop propagation or navigate away immediately.
		addEventListener( 'click', function ( event ) {
			var anchor = event.target && event.target.closest ? event.target.closest( 'a[href]' ) : null;

			if ( ! anchor ) {
				return;
			}

			var url;

			try {
				url = new URL( anchor.href, location.href );
			} catch ( error ) {
				return;
			}

			if ( 'http:' !== url.protocol && 'https:' !== url.protocol ) {
				return;
			}

			var external = url.host !== location.host;

			if ( external && trackOutbound ) {
				send( { k: 'outbound', t: url.href.slice( 0, 500 ) } );
			} else if ( ! external && trackDownloads && isDownload( url ) ) {
				send( { k: 'download', t: url.href.slice( 0, 500 ) } );
			}
		}, true );
	}
}());
