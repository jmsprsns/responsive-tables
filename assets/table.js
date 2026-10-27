/**
 * Responsive Tables: sorting, search and pages.
 *
 * Only loaded on pages with a table that turns one of them on. The table,
 * its page links and the first page are already in the HTML, so nothing
 * jumps when this runs; it just wires them up. Rows are only ever moved or
 * hidden, never rebuilt, and all text is read with textContent.
 *
 * Edit this file, then run bin/build.sh to rebuild table.min.js.
 */
( function () {
	'use strict';

	var i18n = window.responsiveTables || {};
	var collator = window.Intl && window.Intl.Collator ? new window.Intl.Collator( undefined, { numeric: true, sensitivity: 'base' } ) : null;

	// A number with an optional short prefix or suffix: "$1,299", "45%", "4.5/5", "12 hours".
	var NUMBER = /^[^\d+\-]{0,5}([+\-]?\d[\d.,\s  ']*)(?:[^\d]{0,12}|\s*\/\s*\d+)$/;

	function format( text ) {
		var args = arguments;
		var next = 0;

		return String( text || '' ).replace( /%(\d+)\$s|%s/g, function ( match, position ) {
			return args[ position ? Number( position ) : ++next ];
		} );
	}

	function localNumber( value ) {
		try {
			return value.toLocaleString();
		} catch ( e ) {
			return String( value );
		}
	}

	function parseNumber( text ) {
		var match = NUMBER.exec( text );
		var value;
		var dot;
		var comma;

		if ( ! match ) {
			return null;
		}

		value = match[ 1 ].replace( /[\s  ']/g, '' );
		dot = value.lastIndexOf( '.' );
		comma = value.lastIndexOf( ',' );

		if ( dot > -1 && comma > -1 ) {
			value = comma > dot ? value.replace( /\./g, '' ).replace( ',', '.' ) : value.replace( /,/g, '' );
		} else if ( comma > -1 ) {
			value = /^[+\-]?\d{1,3}(,\d{3})+$/.test( value ) ? value.replace( /,/g, '' ) : value.replace( ',', '.' );
		} else if ( /^[+\-]?\d{1,3}(\.\d{3}){2,}$/.test( value ) ) {
			value = value.replace( /\./g, '' );
		}

		value = parseFloat( value );

		return isFinite( value ) ? value : null;
	}

	function fold( text ) {
		text = String( text ).toLowerCase();

		return text.normalize ? text.normalize( 'NFD' ).replace( /[̀-ͯ]/g, '' ) : text;
	}

	function valueOf( cell ) {
		var value = cell && cell.querySelector( '.rtbl-value' );

		return ( value || cell || { textContent: '' } ).textContent.replace( /\s+/g, ' ' ).trim();
	}

	function setup( root ) {
		var config = {};
		var table = root.querySelector( '.rtbl-table' );
		var body = table && table.tBodies[ 0 ];

		if ( ! body || root.rtblReady ) {
			return;
		}

		root.rtblReady = true;

		try {
			config = JSON.parse( root.getAttribute( 'data-rtbl' ) || '{}' );
		} catch ( e ) {
			config = {};
		}

		var perPage = Number( config.perPage ) || 0;
		var search = root.querySelector( '.rtbl-search-input' );
		var select = root.querySelector( '.rtbl-sortby-select' );
		var nav = root.querySelector( '.rtbl-pages' );
		var none = root.querySelector( '.rtbl-none' );
		var status = root.querySelector( '.rtbl-status' );
		var headers = table.tHead && table.tHead.rows[ 0 ] ? Array.prototype.slice.call( table.tHead.rows[ 0 ].cells ) : [];
		var state = { col: -1, dir: 1, query: '', page: 1 };
		var items = Array.prototype.slice.call( body.rows ).map( function ( row, index ) {
			row.classList.remove( 'rtbl-later' );
			return { row: row, index: index, keys: {}, text: null, on: true };
		} );
		var order = items.slice();
		var timer = 0;

		root.classList.add( 'is-ready' );

		function key( item, col ) {
			var cell;
			var text;
			var sortValue;

			if ( ! item.keys[ col ] ) {
				cell = item.row.cells[ col ];
				sortValue = cell ? cell.getAttribute( 'data-sort' ) : null;
				text = valueOf( cell );
				item.keys[ col ] = {
					empty: '' === text && null === sortValue,
					num: null !== sortValue && '' !== sortValue ? parseFloat( sortValue ) : parseNumber( text ),
					text: text,
				};
			}

			return item.keys[ col ];
		}

		function compare( a, b ) {
			if ( null !== a.num && null !== b.num ) {
				return a.num - b.num;
			}
			if ( null !== a.num ) {
				return -1;
			}
			if ( null !== b.num ) {
				return 1;
			}
			if ( collator ) {
				return collator.compare( a.text, b.text );
			}
			return a.text < b.text ? -1 : ( a.text > b.text ? 1 : 0 );
		}

		function searchText( item ) {
			if ( null === item.text ) {
				item.text = fold( Array.prototype.map.call( item.row.cells, valueOf ).join( ' ' ) );
			}
			return item.text;
		}

		function announce( message ) {
			if ( ! status ) {
				return;
			}
			status.textContent = '';
			window.setTimeout( function () {
				status.textContent = message;
			}, 60 );
		}

		function sortBy( col, dir ) {
			state.col = col;
			state.dir = dir;

			if ( col < 0 ) {
				order = items.slice();
			} else {
				order = items.slice().sort( function ( a, b ) {
					var ka = key( a, col );
					var kb = key( b, col );

					if ( ka.empty !== kb.empty ) {
						return ka.empty ? 1 : -1;
					}

					return compare( ka, kb ) * dir || a.index - b.index;
				} );
			}

			order.forEach( function ( item ) {
				body.appendChild( item.row );
			} );

			headers.forEach( function ( th, index ) {
				if ( index === col ) {
					th.setAttribute( 'aria-sort', dir > 0 ? 'ascending' : 'descending' );
				} else {
					th.removeAttribute( 'aria-sort' );
				}
			} );

			if ( select ) {
				select.value = col < 0 ? '' : col + ':' + ( dir > 0 ? 'asc' : 'desc' );
			}

			state.page = 1;
			draw();

			if ( col >= 0 && headers[ col ] ) {
				announce( format( i18n.sorted, valueOf( headers[ col ] ) ) );
			}
		}

		function draw() {
			var words = state.query ? fold( state.query ).split( /\s+/ ).filter( Boolean ) : [];
			var matched = order.filter( function ( item ) {
				var text;

				if ( ! words.length ) {
					return true;
				}

				text = searchText( item );

				return words.every( function ( word ) {
					return -1 !== text.indexOf( word );
				} );
			} );
			var pages = perPage ? Math.max( 1, Math.ceil( matched.length / perPage ) ) : 1;
			var start;

			state.page = Math.min( Math.max( 1, state.page ), pages );
			start = perPage ? ( state.page - 1 ) * perPage : 0;

			items.forEach( function ( item ) {
				item.on = false;
			} );

			matched.slice( start, perPage ? start + perPage : matched.length ).forEach( function ( item ) {
				item.on = true;
			} );

			items.forEach( function ( item ) {
				item.row.hidden = ! item.on;
			} );

			if ( none ) {
				none.hidden = matched.length > 0;
			}

			if ( nav ) {
				drawPages( matched.length, pages, start );
			}

			return matched.length;
		}

		function button( label, page, current, disabled, html ) {
			var el = document.createElement( 'button' );

			el.type = 'button';
			el.className = 'rtbl-page' + ( current ? ' is-current' : '' ) + ( 'prev' === page ? ' rtbl-page-prev' : '' ) + ( 'next' === page ? ' rtbl-page-next' : '' );
			el.setAttribute( 'data-page', page );
			el.setAttribute( 'aria-label', label );

			if ( current ) {
				el.setAttribute( 'aria-current', 'page' );
			}
			if ( disabled ) {
				el.disabled = true;
			}
			if ( html ) {
				el.appendChild( html );
			} else {
				el.textContent = localNumber( page );
			}

			return el;
		}

		function chevron( from ) {
			var icon = from ? from.querySelector( 'svg' ) : null;

			return icon ? icon.cloneNode( true ) : document.createTextNode( '' );
		}

		function drawPages( total, pages, start ) {
			var info = nav.querySelector( '.rtbl-pages-info' );
			var buttons = nav.querySelector( '.rtbl-pages-btns' );
			var prevIcon = chevron( nav.querySelector( '.rtbl-page-prev' ) );
			var nextIcon = chevron( nav.querySelector( '.rtbl-page-next' ) );
			var previous = 0;
			var page;
			var gap;

			nav.hidden = total <= perPage;

			if ( info ) {
				info.textContent = format( i18n.range, localNumber( total ? start + 1 : 0 ), localNumber( Math.min( start + perPage, total ) ), localNumber( total ) );
			}

			if ( ! buttons ) {
				return;
			}

			while ( buttons.firstChild ) {
				buttons.removeChild( buttons.firstChild );
			}

			buttons.appendChild( button( i18n.previous, 'prev', false, state.page <= 1, prevIcon ) );

			for ( page = 1; page <= pages; page++ ) {
				if ( 1 === page || pages === page || Math.abs( page - state.page ) <= 1 || ( state.page <= 3 && page <= 4 ) || ( state.page >= pages - 2 && page >= pages - 3 ) ) {
					if ( page - previous > 1 ) {
						gap = document.createElement( 'span' );
						gap.className = 'rtbl-page-gap';
						gap.setAttribute( 'aria-hidden', 'true' );
						gap.textContent = '…';
						buttons.appendChild( gap );
					}
					buttons.appendChild( button( format( i18n.page, localNumber( page ) ), String( page ), page === state.page, false ) );
					previous = page;
				}
			}

			buttons.appendChild( button( i18n.next, 'next', false, state.page >= pages, nextIcon ) );
		}

		// Column headers: ascending, descending, then back to the original order.
		headers.forEach( function ( th, col ) {
			var sort = th.querySelector( '.rtbl-sort' );

			if ( ! sort ) {
				return;
			}

			sort.addEventListener( 'click', function () {
				if ( state.col !== col ) {
					sortBy( col, 1 );
				} else if ( state.dir > 0 ) {
					sortBy( col, -1 );
				} else {
					sortBy( -1, 1 );
				}
			} );
		} );

		// "Sort by", shown instead of the headers on phones.
		if ( select ) {
			select.addEventListener( 'change', function () {
				var parts = select.value.split( ':' );

				sortBy( '' === select.value ? -1 : parseInt( parts[ 0 ], 10 ), 'desc' === parts[ 1 ] ? -1 : 1 );
			} );
		}

		if ( search ) {
			search.addEventListener( 'input', function () {
				window.clearTimeout( timer );
				timer = window.setTimeout( function () {
					var count;

					state.query = search.value.trim();
					state.page = 1;
					count = draw();

					if ( state.query ) {
						announce( 0 === count ? i18n.noMatch : ( 1 === count ? i18n.oneMatch : format( i18n.matches, localNumber( count ) ) ) );
					}
				}, 120 );
			} );
		}

		if ( nav ) {
			nav.addEventListener( 'click', function ( event ) {
				var target = event.target.closest ? event.target.closest( '[data-page]' ) : null;
				var page;
				var focus;
				var top;

				if ( ! target || target.disabled ) {
					return;
				}

				page = target.getAttribute( 'data-page' );
				state.page = 'prev' === page ? state.page - 1 : ( 'next' === page ? state.page + 1 : parseInt( page, 10 ) || 1 );
				draw();

				// Keep keyboard focus on the same control, or on the new current page.
				focus = nav.querySelector( '[data-page="' + page + '"]:not([disabled])' ) || nav.querySelector( '.is-current' );
				if ( focus ) {
					focus.focus( { preventScroll: true } );
				}

				top = root.getBoundingClientRect().top;
				if ( top < 0 ) {
					root.scrollIntoView( { block: 'start' } );
				}
			} );
		}

		draw();
	}

	function init() {
		Array.prototype.forEach.call( document.querySelectorAll( '.rtbl[data-rtbl]' ), setup );
	}

	// For tables added to the page later (infinite scroll, popups, AJAX).
	window.responsiveTablesInit = init;

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
}() );
