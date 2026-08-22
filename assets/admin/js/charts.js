/**
 * Honest Analytics - chart rendering.
 *
 * PHP emits labels, numbers and series *tokens*; this file turns tokens into
 * colours by reading CSS custom properties off the figure. That is why no
 * colour ever crosses the boundary: the palette lives in the stylesheet, it
 * follows the admin colour scheme, and a future dark mode is a stylesheet
 * change rather than a PHP change.
 *
 * Every chart ships with a visually hidden table of the same numbers. If
 * anything here fails - the library did not load, the JSON was mangled, a
 * browser refused canvas - the table is revealed instead. A chart that cannot
 * draw should show the figures, not an apology.
 */
(function () {
	'use strict';

	var figures = document.querySelectorAll( '[data-ha-chart]' );

	if ( ! figures.length ) {
		return;
	}

	/**
	 * Hide the chart and show the numbers.
	 */
	function giveUp( figure, id, reason ) {
		figure.hidden = true;

		var fallback = document.getElementById( id + '-fallback' );

		if ( fallback ) {
			fallback.hidden = false;
		}

		var table = document.getElementById( id + '-table' );

		if ( table ) {
			table.classList.remove( 'ha-visually-hidden' );
		}

		if ( window.console && console.warn ) {
			console.warn( 'honest-analytics: ' + id + ' could not be drawn - ' + reason );
		}
	}

	function giveUpAll( reason ) {
		Array.prototype.forEach.call( figures, function ( figure ) {
			giveUp( figure, figure.getAttribute( 'data-ha-chart' ), reason );
		} );
	}

	if ( 'undefined' === typeof Chart || ! Chart.defaults ) {
		giveUpAll( 'the charting library did not load.' );

		return;
	}

	/**
	 * Resolve a series token against the figure's own custom properties.
	 *
	 * Read from the figure rather than from :root so the plugin's scope wins,
	 * and so a theme applied to one screen is picked up with no change here.
	 */
	function token( figure, name, fallback ) {
		var value = getComputedStyle( figure ).getPropertyValue( '--ha-' + name );

		value = value ? value.trim() : '';

		return value || fallback || '#888888';
	}

	/**
	 * Fade a colour, whatever notation it arrived in.
	 *
	 * Painting it onto a scratch canvas normalises hex, rgb(), a var() chain
	 * and color-mix() alike, which is more robust than parsing the string.
	 */
	var scratch = document.createElement( 'canvas' ).getContext( '2d' );

	function fade( colour, alpha ) {
		if ( ! scratch ) {
			return colour;
		}

		scratch.fillStyle = '#000';
		scratch.fillStyle = colour;

		var normalised = scratch.fillStyle;

		if ( '#' === normalised.charAt( 0 ) && 7 === normalised.length ) {
			return 'rgba(' + parseInt( normalised.slice( 1, 3 ), 16 ) + ',' +
				parseInt( normalised.slice( 3, 5 ), 16 ) + ',' +
				parseInt( normalised.slice( 5, 7 ), 16 ) + ',' + alpha + ')';
		}

		if ( 0 === normalised.indexOf( 'rgb(' ) ) {
			return normalised.replace( 'rgb(', 'rgba(' ).replace( ')', ',' + alpha + ')' );
		}

		return normalised;
	}

	/**
	 * The same compact formatting the PHP side uses, so the axis and the table
	 * beneath it never disagree.
	 */
	function compact( value, locale ) {
		function trim( number ) {
			return number.toFixed( 1 ).replace( /\.0$/, '' );
		}

		if ( value >= 1000000 ) {
			return trim( value / 1000000 ) + 'M';
		}

		if ( value >= 1000 ) {
			return trim( value / 1000 ) + 'k';
		}

		return Number( value ).toLocaleString( locale );
	}

	function count( value, locale ) {
		return Number( value ).toLocaleString( locale );
	}

	Chart.defaults.font.family = getComputedStyle( document.body ).fontFamily;
	Chart.defaults.font.size = 11;
	Chart.defaults.maintainAspectRatio = false;
	Chart.defaults.responsive = true;

	if ( window.matchMedia && window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches ) {
		Chart.defaults.animation = false;
		Chart.defaults.animations = false;
		Chart.defaults.transitions.active.animation.duration = 0;
	}

	function baseOptions( figure, payload ) {
		return {
			plugins: {
				// The legend is server-rendered HTML on every screen that has
				// one. Chart.js draws its legend onto the canvas, where a screen
				// reader cannot reach it and identity rests on colour alone.
				legend: { display: false },
				tooltip: {
					backgroundColor: token( figure, 'tooltip-bg', '#1d2327' ),
					titleColor: token( figure, 'tooltip-fg', '#ffffff' ),
					bodyColor: token( figure, 'tooltip-fg', '#ffffff' ),
					padding: 10,
					displayColors: true,
					boxWidth: 8,
					boxHeight: 8,
					usePointStyle: true,
					callbacks: {
						title: function ( items ) {
							if ( ! payload.compareFull || ! items.length ) {
								return undefined;
							}

							// A comparison series is aligned by position, not by
							// calendar date, so the default title (the primary
							// range's date) needs the comparison range's date for
							// the same point spelled out beside it.
							var index = items[ 0 ].dataIndex;
							var primary = ( payload.full && payload.full[ index ] ) || items[ 0 ].label;
							var compare = payload.compareFull[ index ];

							return compare ? primary + ' / ' + compare : primary;
						},
						label: function ( context ) {
							// Named as well as coloured.
							return ' ' + context.dataset.label + ': ' + count( context.parsed.y ?? context.parsed, payload.locale );
						}
					}
				}
			}
		};
	}

	function scaleOptions( figure, payload, stacked ) {
		return {
			x: {
				type: 'category',
				stacked: stacked,
				grid: { display: false },
				border: { color: token( figure, 'border', '#dcdcde' ) },
				ticks: {
					color: token( figure, 'muted', '#646970' ),
					autoSkip: true,
					maxTicksLimit: ( payload.options && payload.options.maxTicks ) || 8,
					maxRotation: 0
				}
			},
			y: {
				beginAtZero: true,
				stacked: stacked,
				border: { display: false },
				grid: { color: token( figure, 'hairline', '#f0f0f1' ) },
				ticks: {
					color: token( figure, 'muted', '#646970' ),
					maxTicksLimit: 6,
					callback: function ( value ) {
						return compact( value, payload.locale );
					}
				}
			}
		};
	}

	function lineDatasets( figure, payload, stacked ) {
		return payload.datasets.map( function ( dataset, index ) {
			var colour = token( figure, dataset.token || ( 'series-' + ( index + 1 ) ) );

			return {
				label: dataset.label,
				data: dataset.data,
				borderColor: colour,
				backgroundColor: fade( colour, stacked ? 0.55 : 0.14 ),
				fill: stacked ? ( 0 === index ? 'origin' : '-1' ) : ( dataset.fill ? 'origin' : false ),
				borderWidth: 2,
				borderDash: dataset.dashed ? [ 6, 4 ] : undefined,
				pointRadius: 0,
				pointHoverRadius: 4,
				pointBackgroundColor: colour,
				pointHoverBorderColor: colour,
				tension: 0.25
			};
		} );
	}

	var builders = {
		line: function ( figure, payload ) {
			return {
				type: 'line',
				data: { labels: payload.labels, datasets: lineDatasets( figure, payload, false ) },
				options: Object.assign( baseOptions( figure, payload ), {
					interaction: { mode: 'index', intersect: false },
					scales: scaleOptions( figure, payload, false )
				} )
			};
		},

		stackedArea: function ( figure, payload ) {
			return {
				type: 'line',
				data: { labels: payload.labels, datasets: lineDatasets( figure, payload, true ) },
				options: Object.assign( baseOptions( figure, payload ), {
					interaction: { mode: 'index', intersect: false },
					scales: scaleOptions( figure, payload, true )
				} )
			};
		},

		doughnut: function ( figure, payload ) {
			var options = baseOptions( figure, payload );

			options.cutout = '62%';
			options.interaction = { mode: 'nearest', intersect: true };
			options.plugins.tooltip.callbacks.label = function ( context ) {
				var share = payload.shares && payload.shares[ context.dataIndex ];

				return ' ' + context.label + ': ' + count( context.parsed, payload.locale ) +
					( undefined !== share ? ' (' + share.toFixed( 1 ) + '%)' : '' );
			};

			return {
				type: 'doughnut',
				data: {
					labels: payload.labels,
					datasets: [ {
						label: payload.datasets[0] ? payload.datasets[0].label : '',
						data: payload.datasets[0] ? payload.datasets[0].data : [],
						backgroundColor: payload.labels.map( function ( label, index ) {
							return token( figure, 'series-' + ( ( index % 5 ) + 1 ) );
						} ),
						borderColor: token( figure, 'surface', '#ffffff' ),
						borderWidth: 2
					} ]
				},
				options: options
			};
		}
	};

	var charts = [];

	function draw( figure ) {
		var id = figure.getAttribute( 'data-ha-chart' );
		var canvas = document.getElementById( id );

		if ( ! canvas ) {
			return;
		}

		var block = document.getElementById( id + '-data' );

		if ( ! block ) {
			giveUp( figure, id, 'the data block is not on the page.' );

			return;
		}

		var payload;

		try {
			payload = JSON.parse( block.textContent );
		} catch ( error ) {
			giveUp( figure, id, 'the chart data could not be parsed.' );

			return;
		}

		var builder = builders[ payload.type ];

		if ( ! builder ) {
			giveUp( figure, id, 'unknown chart type "' + payload.type + '".' );

			return;
		}

		try {
			charts.push( {
				figure: figure,
				id: id,
				instance: new Chart( canvas.getContext( '2d' ), builder( figure, payload ) )
			} );
		} catch ( error ) {
			giveUp( figure, id, error.message || 'the charting library threw.' );
		}
	}

	Array.prototype.forEach.call( figures, draw );

	// Chart.js bakes its colours into the canvas at construction, so a scheme
	// change has to redraw rather than restyle.
	if ( window.matchMedia ) {
		var dark = window.matchMedia( '(prefers-color-scheme: dark)' );

		if ( dark.addEventListener ) {
			dark.addEventListener( 'change', function () {
				charts.splice( 0, charts.length ).forEach( function ( chart ) {
					chart.instance.destroy();
					draw( chart.figure );
				} );
			} );
		}
	}
}());
