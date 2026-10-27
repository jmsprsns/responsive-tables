/**
 * Content Powered > Responsive Tables page behaviour. Only loaded on that page.
 *
 * Tabs, the colour picker, the live preview, "copy shortcode" and the
 * TablePress import (in batches, so big imports never time out).
 * responsive_tables_admin.ajaxUrl, .nonce and .i18n are passed in from PHP
 * (see Responsive_Tables_Admin::enqueue_assets).
 */

/* global responsive_tables_admin, jQuery */
( function () {
	'use strict';

	var data = window.responsive_tables_admin;

	document.addEventListener( 'DOMContentLoaded', function () {
		if ( typeof data === 'undefined' ) {
			return;
		}

		var form = document.querySelector( '.rtbla-form' );

		initTabs( form );
		initPreview( form );
		initCopy();
		initImport();
	} );

	function format( text ) {
		var args = arguments;
		var next = 0;

		return String( text || '' ).replace( /%(\d+)\$s|%s/g, function ( match, position ) {
			return args[ position ? Number( position ) : ++next ];
		} );
	}

	// Tabs ---------------------------------------------------------------------

	function initTabs( form ) {
		var tabs = document.querySelectorAll( '[data-rtbla-tab]' );
		var panels = document.querySelectorAll( '[data-rtbla-panel]' );
		var referer = form ? form.querySelector( 'input[name="_wp_http_referer"]' ) : null;
		var refererBase = referer ? referer.value.split( '#' )[ 0 ] : '';

		function show( id ) {
			var found = false;

			tabs.forEach( function ( tab ) {
				var active = tab.getAttribute( 'data-rtbla-tab' ) === id;

				found = found || active;
				tab.classList.toggle( 'is-active', active );
				tab.setAttribute( 'aria-selected', active ? 'true' : 'false' );
			} );

			if ( ! found && tabs.length ) {
				show( tabs[ 0 ].getAttribute( 'data-rtbla-tab' ) );
				return;
			}

			panels.forEach( function ( panel ) {
				panel.hidden = panel.getAttribute( 'data-rtbla-panel' ) !== id;
			} );

			// Come back to this tab after saving.
			if ( referer ) {
				referer.value = refererBase + '#' + id;
			}
		}

		tabs.forEach( function ( tab ) {
			tab.addEventListener( 'click', function ( event ) {
				var id = tab.getAttribute( 'data-rtbla-tab' );

				event.preventDefault();
				show( id );
				history.replaceState( null, '', '#' + id );
			} );
		} );

		window.addEventListener( 'hashchange', function () {
			show( window.location.hash.replace( '#', '' ) );
		} );

		show( window.location.hash.replace( '#', '' ) );
	}

	// Live preview -------------------------------------------------------------

	// White or near-black text on the accent, the same rule as the PHP side.
	function onColor( hex ) {
		var value = String( hex || '' ).replace( '#', '' );
		var channels;
		var luminance;

		if ( 3 === value.length ) {
			value = value.replace( /./g, '$&$&' );
		}
		if ( 6 !== value.length ) {
			return '#fff';
		}

		channels = [ 0, 2, 4 ].map( function ( offset ) {
			var c = parseInt( value.substr( offset, 2 ), 16 ) / 255;
			return c <= 0.03928 ? c / 12.92 : Math.pow( ( c + 0.055 ) / 1.055, 2.4 );
		} );
		luminance = 0.2126 * channels[ 0 ] + 0.7152 * channels[ 1 ] + 0.0722 * channels[ 2 ];

		return 1.05 / ( luminance + 0.05 ) >= ( luminance + 0.05 ) / 0.0566 ? '#fff' : '#111';
	}

	function initPreview( form ) {
		var preview = document.querySelector( '[data-rtbla-preview]' );
		var note = document.querySelector( '[data-rtbla-bp-note]' );
		var breakpoint = form ? form.querySelector( '[data-rtbla-setting="breakpoint"]' ) : null;
		var savedBreakpoint = breakpoint ? breakpoint.value : '';
		var color = form ? form.querySelector( '.rtbla-color' ) : null;

		if ( ! form || ! preview ) {
			return;
		}

		function value( key ) {
			var checked = form.querySelector( '[data-rtbla-setting="' + key + '"]:checked' );

			return checked ? checked.value : '';
		}

		function swap( table, group, chosen ) {
			Array.prototype.slice.call( table.classList ).forEach( function ( name ) {
				if ( 0 === name.indexOf( group ) ) {
					table.classList.remove( name );
				}
			} );
			table.classList.add( group + chosen );
		}

		function update( accent ) {
			preview.querySelectorAll( '.rtbl' ).forEach( function ( table ) {
				var mode = value( 'mode' );
				var style = value( 'style' );
				var header = value( 'header' );

				if ( mode ) {
					table.classList.remove( 'rtbl--stack', 'rtbl--collapse', 'rtbl--scroll' );
					table.classList.add( 'rtbl--' + mode );
				}
				if ( style ) {
					table.classList.remove( 'rtbl--clean', 'rtbl--striped', 'rtbl--bordered', 'rtbl--minimal' );
					table.classList.add( 'rtbl--' + style );
				}
				if ( header ) {
					swap( table, 'rtbl--head-', header );
				}
				if ( accent && /^#([0-9a-f]{3}|[0-9a-f]{6})$/i.test( accent ) ) {
					table.style.setProperty( '--rtbl-accent', accent );
					table.style.setProperty( '--rtbl-on-accent', onColor( accent ) );
				}
			} );

			if ( note && breakpoint ) {
				note.hidden = breakpoint.value === savedBreakpoint;
				note.textContent = data.i18n.bpChanged;
			}
		}

		form.addEventListener( 'change', function () {
			update( color ? color.value : '' );
		} );
		form.addEventListener( 'input', function () {
			update( color ? color.value : '' );
		} );

		// The collapse preview's rows open with a tap, like on a phone.
		preview.addEventListener( 'click', function ( event ) {
			if ( event.target.closest && event.target.closest( 'a' ) ) {
				event.preventDefault();
			}
		} );

		if ( color && window.jQuery && jQuery.fn.wpColorPicker ) {
			jQuery( color ).wpColorPicker( {
				change: function ( event, ui ) {
					update( ui.color.toString() );
				},
				clear: function () {
					update( color.getAttribute( 'data-default-color' ) );
				},
			} );
		}
	}

	// Copy a shortcode -----------------------------------------------------------

	function initCopy() {
		document.addEventListener( 'click', function ( event ) {
			var button = event.target.closest ? event.target.closest( '.rtbla-copy' ) : null;
			var text;

			if ( ! button ) {
				return;
			}

			text = button.getAttribute( 'data-copy' ) || '';

			function done() {
				button.classList.add( 'is-copied' );
				button.setAttribute( 'title', data.i18n.copied );
				window.setTimeout( function () {
					button.classList.remove( 'is-copied' );
				}, 1600 );
			}

			if ( navigator.clipboard && navigator.clipboard.writeText ) {
				navigator.clipboard.writeText( text ).then( done, function () {} );
			} else {
				var area = document.createElement( 'textarea' );

				area.value = text;
				area.setAttribute( 'readonly', '' );
				area.style.position = 'absolute';
				area.style.left = '-9999px';
				document.body.appendChild( area );
				area.select();
				try {
					document.execCommand( 'copy' );
					done();
				} catch ( e ) {}
				document.body.removeChild( area );
			}
		} );
	}

	// TablePress import -----------------------------------------------------------

	function initImport() {
		var all = document.querySelector( '.rtbla-import-all' );
		var status = document.querySelector( '.rtbla-import-status' );
		var busy = false;

		function row( id ) {
			return document.querySelector( '.rtbla-tp-table tr[data-tp-id="' + String( id ).replace( /["\\]/g, '' ) + '"]' );
		}

		function setStatus( text, kind ) {
			if ( ! status ) {
				return;
			}
			status.textContent = text;
			status.className = 'rtbla-import-status' + ( kind ? ' is-' + kind : '' );
		}

		function show( result ) {
			var tr = row( result.id );
			var cell = tr ? tr.querySelector( '.rtbla-tp-state' ) : null;
			var button = tr ? tr.querySelector( '.rtbla-import-one' ) : null;
			var tag;
			var link;
			var list;

			if ( ! cell ) {
				return;
			}

			cell.textContent = '';
			tag = document.createElement( 'span' );
			tag.className = 'rtbla-tag ' + ( 'failed' === result.status ? 'is-bad' : 'is-good' );
			tag.textContent = 'imported' === result.status ? data.i18n.imported : ( 'skipped' === result.status ? data.i18n.skipped : data.i18n.failed );
			cell.appendChild( tag );

			if ( result.editUrl ) {
				link = document.createElement( 'a' );
				link.href = result.editUrl;
				link.textContent = data.i18n.edit;
				cell.appendChild( link );
			}

			if ( result.notes && result.notes.length ) {
				list = document.createElement( 'ul' );
				list.className = 'rtbla-tp-notes';
				result.notes.forEach( function ( note ) {
					var li = document.createElement( 'li' );

					li.textContent = note;
					list.appendChild( li );
				} );
				cell.appendChild( list );
			}

			if ( button && 'failed' !== result.status ) {
				button.textContent = data.i18n.again;
				button.setAttribute( 'data-replace', '1' );
			}
		}

		function run( ids, replace, finished ) {
			var total = ids.length;
			var count = 0;
			var imported = 0;
			var queue = ids.slice();

			busy = true;
			document.querySelectorAll( '.rtbla-import-all, .rtbla-import-one' ).forEach( function ( button ) {
				button.disabled = true;
			} );

			function next() {
				var batch = queue.splice( 0, data.batch || 5 );
				var body = new window.FormData();

				if ( ! batch.length ) {
					busy = false;
					document.querySelectorAll( '.rtbla-import-one' ).forEach( function ( button ) {
						button.disabled = false;
					} );
					finished( imported );
					return;
				}

				body.append( 'action', data.action );
				body.append( 'nonce', data.nonce );
				body.append( 'replace', replace ? '1' : '' );
				batch.forEach( function ( id ) {
					body.append( 'ids[]', id );
				} );

				setStatus( format( data.i18n.progress, count, total ) );

				window.fetch( data.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body } )
					.then( function ( response ) {
						return response.json();
					} )
					.then( function ( json ) {
						if ( ! json || ! json.success || ! json.data || ! json.data.results ) {
							throw new Error( 'bad response' );
						}
						json.data.results.forEach( function ( result ) {
							count++;
							if ( 'imported' === result.status ) {
								imported++;
							}
							show( result );
						} );

						// The server turned shortcode takeover on; keep a later
						// Save on this page from turning it off again.
						if ( imported ) {
							var takeover = document.querySelector( '[data-rtbla-setting="tablepress"]' );

							if ( takeover ) {
								takeover.checked = true;
							}
						}
						next();
					} )
					.catch( function () {
						busy = false;
						setStatus( data.i18n.error, 'error' );
					} );
			}

			next();
		}

		if ( all ) {
			all.addEventListener( 'click', function () {
				var ids;

				try {
					ids = JSON.parse( all.getAttribute( 'data-ids' ) || '[]' );
				} catch ( e ) {
					ids = [];
				}
				if ( busy || ! ids.length ) {
					return;
				}
				run( ids, false, function ( imported ) {
					setStatus( format( data.i18n.done, imported ), 'ok' );
					all.disabled = true;
				} );
			} );
		}

		document.querySelectorAll( '.rtbla-import-one' ).forEach( function ( button ) {
			button.addEventListener( 'click', function () {
				var replace = '1' === button.getAttribute( 'data-replace' );

				// eslint-disable-next-line no-alert
				if ( busy || ( replace && ! window.confirm( data.i18n.confirm ) ) ) {
					return;
				}
				run( [ button.getAttribute( 'data-id' ) ], replace, function ( imported ) {
					setStatus( imported ? format( data.i18n.done, imported ) : '', imported ? 'ok' : '' );
					if ( all ) {
						all.disabled = false;
					}
				} );
			} );
		} );
	}
}() );
