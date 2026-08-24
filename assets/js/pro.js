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
	var trackClicks = '1' === script.getAttribute( 'data-clicks' );
	var extensions = ( script.getAttribute( 'data-extensions' ) || '' ).split( ',' ).filter( Boolean );
	var clickAttribute = script.getAttribute( 'data-click-attribute' ) || 'data-honest-event';
	var clickSelectors = ( script.getAttribute( 'data-click-selectors' ) || '' ).split( '||' ).filter( Boolean ).map( function ( pair ) {
		var at = pair.indexOf( '=>' );

		return { selector: pair.slice( 0, at ), eventName: pair.slice( at + 2 ) };
	} );

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

		// Function *expressions*, not declarations. A declaration inside a
		// block is a SyntaxError in ES5 strict mode, and one SyntaxError
		// throws out the whole file - which would take the tracker's
		// companions with it on exactly the older browsers tracker.js says it
		// supports.
		var depth = function () {
			var scrollable = document.documentElement.scrollHeight - window.innerHeight;

			// A page shorter than the window was read to the end by definition.
			if ( scrollable <= 0 ) {
				return 100;
			}

			return Math.min( 100, Math.round( ( window.scrollY / scrollable ) * 100 ) );
		};

		var bucket = function ( percent ) {
			return percent >= 100 ? 100 : percent >= 75 ? 75 : percent >= 50 ? 50 : percent >= 25 ? 25 : 0;
		};

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

	if ( trackOutbound || trackDownloads || trackClicks ) {
		var isDownload = function ( url ) {
			var pathname = url.pathname.toLowerCase();
			var dot = pathname.lastIndexOf( '.' );

			if ( dot < 0 ) {
				return false;
			}

			return extensions.indexOf( pathname.slice( dot + 1 ) ) !== -1;
		};

		// Capture phase, so a click is recorded even when the page's own
		// handlers stop propagation or navigate away immediately.
		addEventListener( 'click', function ( event ) {
			var target = event.target;

			if ( trackClicks && target && target.closest ) {
				// Independent of the outbound/download check below - a click can
				// legitimately be both (an outbound link an author also marked
				// with the attribute), and neither write should stop the other.
				var named = target.closest( '[' + clickAttribute + ']' );

				if ( named ) {
					var name = named.getAttribute( clickAttribute );

					if ( name ) {
						send( { k: 'event', en: name.slice( 0, 120 ) } );
					}
				}

				for ( var i = 0; i < clickSelectors.length; i++ ) {
					try {
						if ( clickSelectors[ i ].selector && target.closest( clickSelectors[ i ].selector ) ) {
							send( { k: 'event', en: clickSelectors[ i ].eventName.slice( 0, 120 ) } );
						}
					} catch ( error ) {
						// An invalid selector throws rather than simply failing to
						// match - caught here so one bad line does not stop the
						// rest of the list, or the outbound/download check below.
					}
				}
			}

			var anchor = target && target.closest ? target.closest( 'a[href]' ) : null;

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
