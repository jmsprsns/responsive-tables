/**
 * The Responsive Table block's editor: a small spreadsheet.
 *
 * Type in the cells, paste a range from Excel or Google Sheets, or import a
 * CSV file. Enter moves down (Shift+Enter starts a new line), Tab moves
 * right, and the arrow keys move between cells at the edge of the text.
 * Shift+click selects a range to copy, cut or clear. The row numbers and
 * column letters open menus to insert, move, highlight or delete.
 *
 * The table itself is drawn by the server (Responsive_Tables_Render), so the
 * Desktop and Phone previews show exactly what visitors get. Written without
 * JSX so there's no build step. window.rtblBlock holds the site defaults and
 * the block's attributes (see Responsive_Tables_Block::editor_data).
 */
( function ( wp ) {
	'use strict';

	if ( ! wp || ! wp.blocks || ! wp.element || ! wp.components ) {
		return;
	}

	var el = wp.element.createElement;
	var Fragment = wp.element.Fragment;
	var useState = wp.element.useState;
	var useEffect = wp.element.useEffect;
	var useLayoutEffect = wp.element.useLayoutEffect || useEffect;
	var useRef = wp.element.useRef;
	var useMemo = wp.element.useMemo;
	var memo = wp.element.memo || function ( component ) {
		return component;
	};
	var c = wp.components;
	var be = wp.blockEditor || wp.editor;
	var __ = wp.i18n.__;
	var _n = wp.i18n._n;
	var sprintf = wp.i18n.sprintf;
	var apiFetch = wp.apiFetch;
	var ServerSideRender = wp.serverSideRender || c.ServerSideRender;
	var useSelect = wp.data && wp.data.useSelect;
	var data = window.rtblBlock || {};
	var D = data.defaults || {};
	var LIMITS = data.limits || { rows: 5000, cols: 60, minBp: 240, maxBp: 1600, maxPerPage: 500 };
	var NAME = 'responsive-tables/table';
	var savedTables = null;

	// Icons ------------------------------------------------------------------

	function svg( children, size ) {
		// Inline fill: WordPress's ".components-button svg { fill: currentColor }" beats the attribute.
		return el( 'svg', { width: size || 24, height: size || 24, viewBox: '0 0 24 24', fill: 'none', style: { fill: 'none' }, stroke: 'currentColor', strokeWidth: 2, strokeLinecap: 'round', strokeLinejoin: 'round', 'aria-hidden': true, focusable: false }, children );
	}

	function paths( list, size ) {
		return svg( list.map( function ( d, i ) {
			return el( 'path', { key: i, d: d } );
		} ), size );
	}

	var icons = {
		block: svg( [
			el( 'rect', { key: 1, x: 3, y: 3, width: 18, height: 18, rx: 2 } ),
			el( 'path', { key: 2, d: 'M3 9h18' } ),
			el( 'path', { key: 3, d: 'M3 15h18' } ),
			el( 'path', { key: 4, d: 'M9 9v12' } ),
		] ),
		edit: paths( [ 'M12 3H5a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7', 'M18.375 2.625a1 1 0 0 1 3 3l-9.013 9.014a2 2 0 0 1-.853.505l-2.873.84a.5.5 0 0 1-.62-.62l.84-2.873a2 2 0 0 1 .506-.852z' ] ),
		desktop: svg( [
			el( 'rect', { key: 1, x: 2, y: 3, width: 20, height: 14, rx: 2 } ),
			el( 'path', { key: 2, d: 'M8 21h8' } ),
			el( 'path', { key: 3, d: 'M12 17v4' } ),
		] ),
		phone: svg( [
			el( 'rect', { key: 1, x: 5, y: 2, width: 14, height: 20, rx: 2 } ),
			el( 'path', { key: 2, d: 'M12 18h.01' } ),
		] ),
		bold: paths( [ 'M6 12h9a4 4 0 0 1 0 8H7a1 1 0 0 1-1-1V5a1 1 0 0 1 1-1h7a4 4 0 0 1 0 8' ], 20 ),
		italic: paths( [ 'M19 4h-9', 'M14 20H5', 'M15 4 9 20' ], 20 ),
		link: paths( [ 'M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71', 'M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71' ], 20 ),
		check: paths( [ 'M20 6 9 17l-5-5' ], 20 ),
		cross: paths( [ 'M18 6 6 18', 'm6 6 12 12' ], 20 ),
		star: paths( [ 'M11.525 2.295a.53.53 0 0 1 .95 0l2.31 4.679a2.123 2.123 0 0 0 1.595 1.16l5.166.756a.53.53 0 0 1 .294.904l-3.736 3.638a2.123 2.123 0 0 0-.611 1.878l.882 5.14a.53.53 0 0 1-.771.56l-4.618-2.428a2.122 2.122 0 0 0-1.973 0L6.396 21.01a.53.53 0 0 1-.77-.56l.881-5.139a2.122 2.122 0 0 0-.611-1.879L2.16 9.795a.53.53 0 0 1 .294-.906l5.165-.755a2.122 2.122 0 0 0 1.597-1.16z' ], 20 ),
		button: svg( [
			el( 'rect', { key: 1, x: 2, y: 7, width: 20, height: 10, rx: 3 } ),
			el( 'path', { key: 2, d: 'M8 12h8' } ),
		], 20 ),
		image: svg( [
			el( 'rect', { key: 1, x: 3, y: 3, width: 18, height: 18, rx: 2 } ),
			el( 'circle', { key: 2, cx: 9, cy: 9, r: 2 } ),
			el( 'path', { key: 3, d: 'm21 15-3.086-3.086a2 2 0 0 0-2.828 0L6 21' } ),
		], 20 ),
		rows: paths( [ 'M3 12h18', 'M3 6h18', 'M3 18h18' ], 20 ),
		columns: svg( [
			el( 'rect', { key: 1, x: 3, y: 3, width: 18, height: 18, rx: 2 } ),
			el( 'path', { key: 2, d: 'M9 3v18' } ),
			el( 'path', { key: 3, d: 'M15 3v18' } ),
		], 20 ),
		plus: paths( [ 'M5 12h14', 'M12 5v14' ], 18 ),
		upload: paths( [ 'M12 3v12', 'm17 8-5-5-5 5', 'M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4' ], 20 ),
		download: paths( [ 'M12 15V3', 'M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4', 'm7 10 5 5 5-5' ], 20 ),
		paste: svg( [
			el( 'rect', { key: 1, x: 8, y: 2, width: 8, height: 4, rx: 1 } ),
			el( 'path', { key: 2, d: 'M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2' } ),
		], 20 ),
		saved: paths( [ 'M15.2 3a2 2 0 0 1 1.4.6l3.8 3.8a2 2 0 0 1 .6 1.4V19a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2z', 'M17 21v-7a1 1 0 0 0-1-1H8a1 1 0 0 0-1 1v7', 'M7 3v4a1 1 0 0 0 1 1h7' ], 20 ),
		external: paths( [ 'M15 3h6v6', 'M10 14 21 3', 'M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6' ], 18 ),
		stack: svg( [
			el( 'rect', { key: 1, x: 5, y: 3, width: 14, height: 8, rx: 2 } ),
			el( 'rect', { key: 2, x: 5, y: 13, width: 14, height: 8, rx: 2 } ),
		] ),
		collapse: paths( [ 'M4 5h16', 'M4 12h16', 'M4 19h16', 'm15 8 2 2 2-2' ] ),
		scroll: svg( [
			el( 'rect', { key: 1, x: 3, y: 4, width: 18, height: 16, rx: 2 } ),
			el( 'path', { key: 2, d: 'M9 4v16' } ),
			el( 'path', { key: 3, d: 'm14 12 3 0' } ),
			el( 'path', { key: 4, d: 'm16 10 2 2-2 2' } ),
		] ),
		auto: paths( [ 'M12 3v3', 'M18.4 5.6l-2.1 2.1', 'M21 12h-3', 'M6 12H3', 'M7.8 7.8 5.6 5.6', 'M12 12l4 6', 'M8 18l4-6' ] ),
	};

	// Data helpers ------------------------------------------------------------

	function str( value ) {
		return null === value || undefined === value ? '' : String( value );
	}

	function width( rows ) {
		return ( rows || [] ).reduce( function ( max, row ) {
			return Math.max( max, Array.isArray( row ) ? row.length : 1 );
		}, 0 );
	}

	// Rectangular grid of strings.
	function grid( rows ) {
		var cols = width( rows );

		return ( rows || [] ).map( function ( row ) {
			var out = Array.isArray( row ) ? row.map( str ) : [ str( row ) ];

			while ( out.length < cols ) {
				out.push( '' );
			}
			return out;
		} );
	}

	function blank( rows, cols ) {
		var out = [];
		var r;
		var i;

		for ( r = 0; r < rows; r++ ) {
			out.push( [] );
			for ( i = 0; i < cols; i++ ) {
				out[ r ].push( '' );
			}
		}
		return out;
	}

	function fill( list, length, make ) {
		var out = ( list || [] ).slice( 0, length ).map( function ( item ) {
			return item && 'object' === typeof item ? item : make();
		} );

		while ( out.length < length ) {
			out.push( make() );
		}
		return out;
	}

	function newColumn() {
		return { align: '', highlight: false, badge: '', hideMobile: false };
	}

	function newRowOpt() {
		return { highlight: false, badge: '' };
	}

	function letter( index ) {
		var name = '';

		index += 1;
		while ( index > 0 ) {
			name = String.fromCharCode( 65 + ( ( index - 1 ) % 26 ) ) + name;
			index = Math.floor( ( index - 1 ) / 26 );
		}
		return name;
	}

	function move( list, from, to ) {
		var out = list.slice();
		var item = out.splice( from, 1 )[ 0 ];

		out.splice( to, 0, item );
		return out;
	}

	// Parse CSV or tab-separated text, quotes and all.
	function parseDelimited( text, delimiter ) {
		var rows = [];
		var row = [];
		var cell = '';
		var quoted = false;
		var i;
		var ch;

		text = str( text ).replace( /^﻿/, '' );

		for ( i = 0; i < text.length; i++ ) {
			ch = text[ i ];

			if ( quoted ) {
				if ( '"' === ch ) {
					if ( '"' === text[ i + 1 ] ) {
						cell += '"';
						i++;
					} else {
						quoted = false;
					}
				} else {
					cell += ch;
				}
			} else if ( '"' === ch && '' === cell ) {
				quoted = true;
			} else if ( ch === delimiter ) {
				row.push( cell );
				cell = '';
			} else if ( '\n' === ch || '\r' === ch ) {
				if ( '\r' === ch && '\n' === text[ i + 1 ] ) {
					i++;
				}
				row.push( cell );
				rows.push( row );
				row = [];
				cell = '';
			} else {
				cell += ch;
			}
		}

		if ( '' !== cell || row.length ) {
			row.push( cell );
			rows.push( row );
		}

		return grid( rows.slice( 0, LIMITS.rows ).map( function ( r ) {
			return r.slice( 0, LIMITS.cols );
		} ) );
	}

	function detectDelimiter( text ) {
		var line = str( text ).replace( /^﻿/, '' ).split( /\r?\n/ )[ 0 ].replace( /"[^"]*"/g, '' );
		var best = ',';
		var most = -1;

		[ '\t', ',', ';', '|' ].forEach( function ( candidate ) {
			var count = line.split( candidate ).length - 1;

			if ( count > most ) {
				most = count;
				best = candidate;
			}
		} );

		return best;
	}

	function toDelimited( rows, delimiter, safe ) {
		return rows.map( function ( row ) {
			return row.map( function ( value ) {
				value = str( value );

				// Keep spreadsheet apps from running a cell as a formula.
				if ( safe && /^[=@\t\r]|^[+\-][^\d.,\s]/.test( value ) ) {
					value = '\'' + value;
				}
				if ( value.indexOf( delimiter ) !== -1 || /["\r\n]/.test( value ) ) {
					value = '"' + value.replace( /"/g, '""' ) + '"';
				}
				return value;
			} ).join( delimiter );
		} ).join( '\r\n' );
	}

	function escapeHtml( text ) {
		return str( text ).replace( /&/g, '&amp;' ).replace( /</g, '&lt;' ).replace( />/g, '&gt;' ).replace( /"/g, '&quot;' );
	}

	function plain( html ) {
		return str( html ).replace( /\{(?:yes|check)[^}]*\}/gi, '1' ).replace( /\{(?:no|cross)[^}]*\}/gi, '0' ).replace( /\{stars\s+([\d.,]+)[^}]*\}/gi, '$1' ).replace( /<[^>]*>/g, '' ).replace( /\s+/g, ' ' ).trim();
	}

	function sortNumber( text ) {
		var match = /^[^\d+\-]{0,5}([+\-]?\d[\d.,\s']*)(?:[^\d]{0,12}|\s*\/\s*\d+)$/.exec( text );
		var value;

		if ( ! match ) {
			return null;
		}
		value = match[ 1 ].replace( /[\s']/g, '' );
		if ( value.indexOf( '.' ) > -1 && value.indexOf( ',' ) > -1 ) {
			value = value.lastIndexOf( ',' ) > value.lastIndexOf( '.' ) ? value.replace( /\./g, '' ).replace( ',', '.' ) : value.replace( /,/g, '' );
		} else if ( value.indexOf( ',' ) > -1 ) {
			value = /^[+\-]?\d{1,3}(,\d{3})+$/.test( value ) ? value.replace( /,/g, '' ) : value.replace( ',', '.' );
		}
		value = parseFloat( value );
		return isFinite( value ) ? value : null;
	}

	function slug( text ) {
		return str( text ).toLowerCase().replace( /<[^>]*>/g, '' ).replace( /[^a-z0-9]+/g, '-' ).replace( /^-+|-+$/g, '' ).slice( 0, 60 ) || 'table';
	}

	function download( name, text ) {
		var blob = new window.Blob( [ '﻿' + text ], { type: 'text/csv;charset=utf-8' } );
		var url = window.URL.createObjectURL( blob );
		var link = document.createElement( 'a' );

		link.href = url;
		link.download = name;
		document.body.appendChild( link );
		link.click();
		document.body.removeChild( link );
		window.setTimeout( function () {
			window.URL.revokeObjectURL( url );
		}, 1000 );
	}

	function readFile( file, done ) {
		var reader = new window.FileReader();

		reader.onload = function () {
			done( str( reader.result ) );
		};
		reader.readAsText( file );
	}

	// Starter tables. The links are placeholders to replace.
	function starters() {
		return {
			comparison: {
				label: __( 'Product comparison', 'responsive-tables' ),
				attrs: {
					rows: [
						[ __( 'Product', 'responsive-tables' ), __( 'Rating', 'responsive-tables' ), __( 'Battery life', 'responsive-tables' ), __( 'Waterproof', 'responsive-tables' ), __( 'Price', 'responsive-tables' ), '' ],
						[ 'Aero Buds Pro', '{stars 4.8}', __( '32 hours', 'responsive-tables' ), '{yes}', '$129', '{button Check price | https://example.com | new, sponsored}' ],
						[ 'Pulse Air 2', '{stars 4.4}', __( '24 hours', 'responsive-tables' ), '{yes}', '$89', '{button Check price | https://example.com | new, sponsored}' ],
						[ 'Studio One', '{stars 4.1}', __( '40 hours', 'responsive-tables' ), '{no}', '$149', '{button Check price | https://example.com | new, sponsored}' ],
					],
					rowHeaders: true,
					rowOpts: [ newRowOpt(), { highlight: true, badge: __( 'Our pick', 'responsive-tables' ) } ],
					columns: [ newColumn(), newColumn(), newColumn(), { align: 'center', highlight: false, badge: '', hideMobile: false }, newColumn(), { align: 'right', highlight: false, badge: '', hideMobile: false } ],
					sort: true,
				},
			},
			pricing: {
				label: __( 'Pricing plans', 'responsive-tables' ),
				attrs: {
					rows: [
						[ '', __( 'Starter', 'responsive-tables' ), __( 'Pro', 'responsive-tables' ), __( 'Business', 'responsive-tables' ) ],
						[ __( 'Price', 'responsive-tables' ), __( '$9 / month', 'responsive-tables' ), __( '$29 / month', 'responsive-tables' ), __( '$79 / month', 'responsive-tables' ) ],
						[ __( 'Websites', 'responsive-tables' ), '1', '5', __( 'Unlimited', 'responsive-tables' ) ],
						[ __( 'Storage', 'responsive-tables' ), '10 GB', '100 GB', '1 TB' ],
						[ __( 'Email support', 'responsive-tables' ), '{yes}', '{yes}', '{yes}' ],
						[ __( 'Phone support', 'responsive-tables' ), '{no}', '{yes}', '{yes}' ],
						[ '', '{button Choose | https://example.com}', '{button Choose | https://example.com}', '{button Choose | https://example.com}' ],
					],
					rowHeaders: true,
					mode: 'scroll',
					columns: [ newColumn(), { align: 'center', highlight: false, badge: '', hideMobile: false }, { align: 'center', highlight: true, badge: __( 'Most popular', 'responsive-tables' ), hideMobile: false }, { align: 'center', highlight: false, badge: '', hideMobile: false } ],
				},
			},
			schedule: {
				label: __( 'Schedule', 'responsive-tables' ),
				attrs: {
					rows: [
						[ __( 'Time', 'responsive-tables' ), __( 'Session', 'responsive-tables' ), __( 'Speaker', 'responsive-tables' ), __( 'Room', 'responsive-tables' ) ],
						[ '9:00', __( 'Welcome and coffee', 'responsive-tables' ), '', __( 'Lobby', 'responsive-tables' ) ],
						[ '9:30', __( 'Opening keynote', 'responsive-tables' ), 'Maya Chen', __( 'Main hall', 'responsive-tables' ) ],
						[ '10:30', __( 'Hands-on workshop', 'responsive-tables' ), 'Leo Park', __( 'Room B', 'responsive-tables' ) ],
					],
					mode: 'collapse',
				},
			},
			specs: {
				label: __( 'Spec sheet', 'responsive-tables' ),
				attrs: {
					rows: [
						[ __( 'Specification', 'responsive-tables' ), __( 'Details', 'responsive-tables' ) ],
						[ __( 'Dimensions', 'responsive-tables' ), '142 × 68 × 8 mm' ],
						[ __( 'Weight', 'responsive-tables' ), '168 g' ],
						[ __( 'Display', 'responsive-tables' ), __( '6.1-inch OLED', 'responsive-tables' ) ],
						[ __( 'Water resistance', 'responsive-tables' ), 'IP68' ],
					],
					rowHeaders: true,
					tableStyle: 'striped',
				},
			},
		};
	}

	// A new table from a grid (and optional extra attributes), with the site's defaults.
	function newTable( rows, extra ) {
		var out = Object.assign( {
			header: true,
			sort: !! D.sort,
			search: !! D.search,
			paginate: !! D.paginate,
			perPage: D.perPage || 10,
		}, extra || {} );

		out.rows = grid( rows );
		out.columns = fill( out.columns, width( out.rows ), newColumn );
		out.rowOpts = fill( out.rowOpts, out.rows.length, newRowOpt );
		out.ref = 0;

		return out;
	}

	// Small UI pieces ------------------------------------------------------------

	function menu( props ) {
		return el( c.Dropdown, {
			className: props.className,
			popoverProps: { placement: 'bottom-start' },
			renderToggle: function ( toggle ) {
				return el( c.Button, {
					className: props.toggleClass,
					onClick: toggle.onToggle,
					'aria-expanded': toggle.isOpen,
					'aria-haspopup': 'true',
					label: props.label,
					showTooltip: false,
					icon: props.icon,
					disabled: props.disabled,
					onMouseDown: keepFocus,
				}, props.text );
			},
			renderContent: function ( dropdown ) {
				return el( 'div', { className: 'rtbl-menu' }, props.groups.filter( Boolean ).map( function ( group, g ) {
					return el( c.MenuGroup, { key: g, label: group.label }, group.items.filter( Boolean ).map( function ( item, i ) {
						return el( c.MenuItem, {
							key: i,
							icon: item.icon,
							isSelected: item.selected,
							role: undefined !== item.selected ? 'menuitemcheckbox' : 'menuitem',
							disabled: item.disabled,
							isDestructive: item.danger,
							onClick: function () {
								dropdown.onClose();
								item.onClick();
							},
						}, item.label );
					} ) );
				} ) );
			},
		} );
	}

	function keepFocus( event ) {
		event.preventDefault();
	}

	function SimpleModal( props ) {
		return el(
			c.Modal,
			{ title: props.title, onRequestClose: props.onClose, className: 'rtbl-modal' },
			el( 'form', {
				onSubmit: function ( event ) {
					event.preventDefault();
					props.onSubmit();
				},
			},
			props.children,
			el( 'div', { className: 'rtbl-modal-actions' },
				el( c.Button, { variant: 'tertiary', onClick: props.onClose }, __( 'Cancel', 'responsive-tables' ) ),
				el( c.Button, { variant: 'primary', type: 'submit', disabled: props.disabled }, props.submit || __( 'Insert', 'responsive-tables' ) )
			) )
		);
	}

	function LinkModal( props ) {
		var u = useState( '' );
		var t = useState( props.text || '' );
		var n = useState( false );
		var s = useState( false );

		return el( SimpleModal, {
			title: __( 'Add a link', 'responsive-tables' ),
			onClose: props.onClose,
			disabled: ! u[ 0 ].trim(),
			onSubmit: function () {
				var rel = [];
				var attrs = ' href="' + escapeHtml( u[ 0 ].trim() ) + '"';

				if ( n[ 0 ] ) {
					attrs += ' target="_blank"';
					rel.push( 'noopener' );
				}
				if ( s[ 0 ] ) {
					rel.push( 'sponsored', 'nofollow' );
				}
				if ( rel.length ) {
					attrs += ' rel="' + rel.join( ' ' ) + '"';
				}
				props.onInsert( '<a' + attrs + '>' + ( props.text ? props.text : escapeHtml( t[ 0 ] || u[ 0 ] ) ) + '</a>' );
			},
		},
		el( c.TextControl, { __next40pxDefaultSize: true, label: __( 'URL', 'responsive-tables' ), type: 'url', value: u[ 0 ], onChange: u[ 1 ], placeholder: 'https://', autoFocus: true, __nextHasNoMarginBottom: true } ),
		props.text ? null : el( c.TextControl, { __next40pxDefaultSize: true, label: __( 'Link text', 'responsive-tables' ), value: t[ 0 ], onChange: t[ 1 ], __nextHasNoMarginBottom: true } ),
		el( c.ToggleControl, { label: __( 'Open in a new tab', 'responsive-tables' ), checked: n[ 0 ], onChange: n[ 1 ], __nextHasNoMarginBottom: true } ),
		el( c.ToggleControl, { label: __( 'Affiliate or sponsored link', 'responsive-tables' ), help: __( 'Adds rel="sponsored nofollow".', 'responsive-tables' ), checked: s[ 0 ], onChange: s[ 1 ], __nextHasNoMarginBottom: true } ) );
	}

	function ButtonModal( props ) {
		var t = useState( props.text ? plain( props.text ) : __( 'Check price', 'responsive-tables' ) );
		var u = useState( '' );
		var n = useState( true );
		var s = useState( false );

		return el( SimpleModal, {
			title: __( 'Add a button', 'responsive-tables' ),
			onClose: props.onClose,
			disabled: ! u[ 0 ].trim(),
			onSubmit: function () {
				var flags = [];
				var clean = function ( value ) {
					return str( value ).replace( /[{}|\r\n]/g, ' ' ).trim();
				};

				if ( n[ 0 ] ) {
					flags.push( 'new' );
				}
				if ( s[ 0 ] ) {
					flags.push( 'sponsored' );
				}
				props.onInsert( '{button ' + clean( t[ 0 ] ) + ' | ' + clean( u[ 0 ] ) + ( flags.length ? ' | ' + flags.join( ', ' ) : '' ) + '}', true );
			},
		},
		el( c.TextControl, { __next40pxDefaultSize: true, label: __( 'Button text', 'responsive-tables' ), value: t[ 0 ], onChange: t[ 1 ], autoFocus: true, __nextHasNoMarginBottom: true } ),
		el( c.TextControl, { __next40pxDefaultSize: true, label: __( 'URL', 'responsive-tables' ), type: 'url', value: u[ 0 ], onChange: u[ 1 ], placeholder: 'https://', __nextHasNoMarginBottom: true } ),
		el( c.ToggleControl, { label: __( 'Open in a new tab', 'responsive-tables' ), checked: n[ 0 ], onChange: n[ 1 ], __nextHasNoMarginBottom: true } ),
		el( c.ToggleControl, { label: __( 'Affiliate or sponsored link', 'responsive-tables' ), help: __( 'Adds rel="sponsored".', 'responsive-tables' ), checked: s[ 0 ], onChange: s[ 1 ], __nextHasNoMarginBottom: true } ) );
	}

	function StarsModal( props ) {
		var v = useState( 4.5 );
		var o = useState( '5' );

		return el( SimpleModal, {
			title: __( 'Add a star rating', 'responsive-tables' ),
			onClose: props.onClose,
			onSubmit: function () {
				props.onInsert( '{stars ' + v[ 0 ] + ( '5' === o[ 0 ] ? '' : '/' + o[ 0 ] ) + '}', true );
			},
		},
		el( c.SelectControl, {
			__next40pxDefaultSize: true,
			label: __( 'Out of', 'responsive-tables' ),
			value: o[ 0 ],
			options: [ { value: '5', label: '5' }, { value: '10', label: '10' } ],
			onChange: function ( value ) {
				o[ 1 ]( value );
				v[ 1 ]( Math.min( v[ 0 ], Number( value ) ) );
			},
			__nextHasNoMarginBottom: true,
		} ),
		el( c.RangeControl, { __next40pxDefaultSize: true, label: __( 'Rating', 'responsive-tables' ), value: v[ 0 ], onChange: function ( value ) {
			v[ 1 ]( Number( value ) || 0 );
		}, min: 0, max: Number( o[ 0 ] ), step: 0.1, __nextHasNoMarginBottom: true } ),
		el( 'p', { className: 'rtbl-modal-note' }, __( 'Shown as five stars filled to the rating, with the number beside them. Screen readers hear "Rated 4.5 out of 5".', 'responsive-tables' ) ) );
	}

	function PasteModal( props ) {
		var t = useState( '' );

		return el( SimpleModal, {
			title: __( 'Paste a table', 'responsive-tables' ),
			onClose: props.onClose,
			submit: __( 'Use this data', 'responsive-tables' ),
			disabled: ! t[ 0 ].trim(),
			onSubmit: function () {
				props.onInsert( parseDelimited( t[ 0 ], detectDelimiter( t[ 0 ] ) ) );
			},
		},
		el( 'p', { className: 'rtbl-modal-note' }, __( 'Copy cells in Excel, Google Sheets or Numbers and paste them here. CSV and tab-separated text work too.', 'responsive-tables' ) ),
		el( c.TextareaControl, { label: __( 'Table data', 'responsive-tables' ), hideLabelFromVision: true, value: t[ 0 ], onChange: t[ 1 ], rows: 10, autoFocus: true, className: 'rtbl-paste-area', __nextHasNoMarginBottom: true } ) );
	}

	// The grid -------------------------------------------------------------------

	var Cell = memo( function ( props ) {
		var ref = useRef( null );

		useLayoutEffect( function () {
			var node = ref.current;

			// Grow with the text (CSS field-sizing would also widen the column).
			if ( node ) {
				node.style.height = 'auto';
				node.style.height = node.scrollHeight + 'px';
			}
		}, [ props.value ] );

		return el( 'textarea', {
			ref: ref,
			className: 'rtbl-cell-input',
			value: props.value,
			rows: 1,
			spellCheck: true,
			'data-r': props.r,
			'data-c': props.c,
			'aria-label': sprintf(
				/* translators: %s: cell name, e.g. "B3". */
				__( 'Cell %s', 'responsive-tables' ),
				letter( props.c ) + ( props.r + 1 )
			),
			onChange: function ( event ) {
				props.api.current.change( props.r, props.c, event.target.value );
			},
			onFocus: function () {
				props.api.current.focus( props.r, props.c );
			},
			onMouseDown: function ( event ) {
				if ( event.shiftKey ) {
					event.preventDefault();
					props.api.current.extend( props.r, props.c );
				}
			},
			onKeyDown: function ( event ) {
				props.api.current.key( event, props.r, props.c );
			},
			onPaste: function ( event ) {
				props.api.current.paste( event, props.r, props.c );
			},
			onCopy: function ( event ) {
				props.api.current.copy( event, false );
			},
			onCut: function ( event ) {
				props.api.current.copy( event, true );
			},
		} );
	} );

	// The menu behind a row number. Its actions go through api.current when
	// clicked, so a row that skipped re-rendering never acts on an old table.
	function RowMenu( props ) {
		var r = props.r;
		var act = function ( action ) {
			return function () {
				props.api.current.rowAction( action, r );
			};
		};

		return menu( {
			className: 'rtbl-grid-menu',
			toggleClass: 'rtbl-grid-label' + ( props.isActive ? ' is-active' : '' ),
			label: sprintf(
				/* translators: %d: row number. */
				__( 'Row %d options', 'responsive-tables' ),
				r + 1
			),
			text: String( r + 1 ),
			groups: [
				{ items: [
					{ label: __( 'Insert row above', 'responsive-tables' ), icon: 'arrow-up-alt', onClick: act( 'above' ) },
					{ label: __( 'Insert row below', 'responsive-tables' ), icon: 'arrow-down-alt', onClick: act( 'below' ) },
					{ label: __( 'Move up', 'responsive-tables' ), icon: 'arrow-up-alt2', disabled: 0 === r, onClick: act( 'up' ) },
					{ label: __( 'Move down', 'responsive-tables' ), icon: 'arrow-down-alt2', disabled: r === props.count - 1, onClick: act( 'down' ) },
				] },
				props.isHead ? null : { items: [
					{ label: __( 'Highlight this row', 'responsive-tables' ), icon: 'star-filled', selected: !! props.opt.highlight, onClick: act( 'highlight' ) },
				] },
				{ items: [
					{ label: __( 'Delete row', 'responsive-tables' ), icon: 'trash', danger: true, disabled: props.count <= 1, onClick: act( 'delete' ) },
				] },
			],
		} );
	}

	var Row = memo( function ( props ) {
		var api = props.api;
		var cells = props.cells;
		var r = props.r;

		return el( 'tr', { className: [ props.isHead ? 'is-head' : '', props.isFoot ? 'is-foot' : '', props.rowOpt.highlight ? 'is-hl' : '' ].join( ' ' ).trim() || undefined },
			el( 'th', { className: 'rtbl-grid-gutter', scope: 'row' }, el( RowMenu, { r: r, api: api, opt: props.rowOpt, count: props.count, isHead: props.isHead, isActive: props.isActiveRow } ) ),
			cells.map( function ( value, col ) {
				var column = props.columns[ col ] || {};
				var inRange = props.range && col >= props.range[ 0 ] && col <= props.range[ 1 ];

				return el( 'td', {
					key: col,
					className: [
						props.activeCol === col ? 'is-active' : '',
						inRange ? 'is-in-range' : '',
						column.highlight ? 'is-hl' : '',
						column.align ? 'is-' + column.align : '',
						props.rowHeaders && 0 === col ? 'is-row-head' : '',
					].join( ' ' ).trim() || undefined,
				}, el( Cell, { r: r, c: col, value: value, api: api } ) );
			} )
		);
	} );

	function Grid( props ) {
		var a = props.attributes;
		var set = props.setAttributes;
		var rows = a.rows;
		var cols = width( rows );
		// Stable while nothing changes, so unchanged rows skip re-rendering.
		var columns = useMemo( function () {
			return fill( a.columns, cols, newColumn );
		}, [ a.columns, cols ] );
		var rowOpts = useMemo( function () {
			return fill( a.rowOpts, rows.length, newRowOpt );
		}, [ a.rowOpts, rows.length ] );
		var active = props.active;
		var setActive = props.setActive;
		var range = props.range;
		var setRange = props.setRange;
		var wrap = useRef( null );
		var pending = useRef( null );
		var api = useRef( {} );
		var m = useState( null );
		var modal = m[ 0 ];
		var setModal = m[ 1 ];

		function node( r, col ) {
			return wrap.current ? wrap.current.querySelector( 'textarea[data-r="' + r + '"][data-c="' + col + '"]' ) : null;
		}

		function focusCell( r, col, caret ) {
			pending.current = { r: r, c: col, caret: caret };
		}

		useEffect( function () {
			var target = pending.current;
			var input;

			if ( ! target ) {
				return;
			}
			pending.current = null;
			input = node( target.r, target.c );
			if ( input ) {
				input.focus();
				if ( 'number' === typeof target.caret ) {
					input.setSelectionRange( target.caret, target.caret );
				} else if ( 'all' === target.caret ) {
					input.select();
				} else if ( 'start' === target.caret ) {
					input.setSelectionRange( 0, 0 );
				} else {
					input.setSelectionRange( input.value.length, input.value.length );
				}
			}
		} );

		// Apply a change to rows, keeping the column and row options lined up.
		function update( nextRows, nextColumns, nextRowOpts ) {
			var patch = { rows: nextRows };

			patch.columns = fill( nextColumns || columns, width( nextRows ), newColumn );
			patch.rowOpts = fill( nextRowOpts || rowOpts, nextRows.length, newRowOpt );
			set( patch );
		}

		function setCell( r, col, value ) {
			var next = rows.slice();

			next[ r ] = next[ r ].slice();
			next[ r ][ col ] = value;
			set( { rows: next } );
		}

		function addRow( at ) {
			var row = [];
			var i;

			if ( rows.length >= LIMITS.rows ) {
				return;
			}
			for ( i = 0; i < cols; i++ ) {
				row.push( '' );
			}
			update( rows.slice( 0, at ).concat( [ row ], rows.slice( at ) ), null, rowOpts.slice( 0, at ).concat( [ newRowOpt() ], rowOpts.slice( at ) ) );
		}

		function addColumn( at ) {
			if ( cols >= LIMITS.cols ) {
				return;
			}
			update( rows.map( function ( row ) {
				return row.slice( 0, at ).concat( [ '' ], row.slice( at ) );
			} ), columns.slice( 0, at ).concat( [ newColumn() ], columns.slice( at ) ) );
		}

		function removeRow( at ) {
			if ( rows.length <= 1 ) {
				return;
			}
			update( rows.filter( function ( row, i ) {
				return i !== at;
			} ), null, rowOpts.filter( function ( opt, i ) {
				return i !== at;
			} ) );
			setActive( [ Math.min( at, rows.length - 2 ), active ? active[ 1 ] : 0 ] );
			setRange( null );
		}

		function removeColumn( at ) {
			if ( cols <= 1 ) {
				return;
			}
			update( rows.map( function ( row ) {
				return row.filter( function ( cell, i ) {
					return i !== at;
				} );
			} ), columns.filter( function ( col, i ) {
				return i !== at;
			} ) );
			setActive( [ active ? active[ 0 ] : 0, Math.min( at, cols - 2 ) ] );
			setRange( null );
		}

		function moveRow( from, to ) {
			if ( to < 0 || to >= rows.length ) {
				return;
			}
			update( move( rows, from, to ), null, move( rowOpts, from, to ) );
			setActive( [ to, active ? active[ 1 ] : 0 ] );
		}

		function moveColumn( from, to ) {
			if ( to < 0 || to >= cols ) {
				return;
			}
			update( rows.map( function ( row ) {
				return move( row, from, to );
			} ), move( columns, from, to ) );
			setActive( [ active ? active[ 0 ] : 0, to ] );
		}

		function setColumn( at, patch ) {
			var next = columns.slice();

			next[ at ] = Object.assign( {}, next[ at ], patch );
			set( { columns: next } );
		}

		function setRowOpt( at, patch ) {
			var next = rowOpts.slice();

			next[ at ] = Object.assign( {}, next[ at ], patch );
			set( { rowOpts: next } );
		}

		// Sort the body rows by a column, for good (the header and footer stay put).
		function sortRows( col, dir ) {
			var first = a.header ? 1 : 0;
			var last = a.footer && rows.length - first > 1 ? rows.length - 1 : rows.length;
			var body = rows.slice( first, last ).map( function ( row, i ) {
				return { row: row, opt: rowOpts[ first + i ], i: i, text: plain( row[ col ] ), num: sortNumber( plain( row[ col ] ) ) };
			} );

			body.sort( function ( x, y ) {
				if ( ! x.text !== ! y.text ) {
					return x.text ? -1 : 1;
				}
				if ( null !== x.num && null !== y.num ) {
					return ( x.num - y.num ) * dir || x.i - y.i;
				}
				if ( null !== x.num || null !== y.num ) {
					return ( null !== x.num ? -1 : 1 ) * dir;
				}
				return x.text.localeCompare( y.text, undefined, { numeric: true, sensitivity: 'base' } ) * dir || x.i - y.i;
			} );

			update(
				rows.slice( 0, first ).concat( body.map( function ( item ) {
					return item.row;
				} ), rows.slice( last ) ),
				null,
				rowOpts.slice( 0, first ).concat( body.map( function ( item ) {
					return item.opt;
				} ), rowOpts.slice( last ) )
			);
		}

		// Write a block of cells at r, col, growing the table as needed.
		function put( block, r, col ) {
			var next = rows.map( function ( row ) {
				return row.slice();
			} );
			var needRows = Math.min( LIMITS.rows, r + block.length );
			var needCols = Math.min( LIMITS.cols, col + width( block ) );
			var nextCols = Math.max( cols, needCols );

			while ( next.length < needRows ) {
				next.push( [] );
			}
			next = next.map( function ( row ) {
				while ( row.length < nextCols ) {
					row.push( '' );
				}
				return row;
			} );
			block.forEach( function ( line, i ) {
				line.forEach( function ( value, j ) {
					if ( r + i < next.length && col + j < nextCols ) {
						next[ r + i ][ col + j ] = value;
					}
				} );
			} );

			update( next );
			setActive( [ r, col ] );
			setRange( [ r, col, Math.min( next.length, r + block.length ) - 1, Math.min( nextCols, col + width( block ) ) - 1 ] );
		}

		function rangeCells() {
			var box = range || ( active ? [ active[ 0 ], active[ 1 ], active[ 0 ], active[ 1 ] ] : null );

			if ( ! box ) {
				return null;
			}
			return rows.slice( Math.min( box[ 0 ], box[ 2 ] ), Math.max( box[ 0 ], box[ 2 ] ) + 1 ).map( function ( row ) {
				return row.slice( Math.min( box[ 1 ], box[ 3 ] ), Math.max( box[ 1 ], box[ 3 ] ) + 1 );
			} );
		}

		function clearRange() {
			var box = range;
			var next;

			if ( ! box ) {
				return;
			}
			next = rows.map( function ( row, r ) {
				if ( r < Math.min( box[ 0 ], box[ 2 ] ) || r > Math.max( box[ 0 ], box[ 2 ] ) ) {
					return row;
				}
				return row.map( function ( value, col ) {
					return col >= Math.min( box[ 1 ], box[ 3 ] ) && col <= Math.max( box[ 1 ], box[ 3 ] ) ? '' : value;
				} );
			} );
			set( { rows: next } );
		}

		// Insert text at the active cell's caret (or around its selection).
		function insert( before, after, whole, selection ) {
			var sel = selection || props.lastSelection();
			var value;
			var start;
			var end;
			var picked;

			if ( ! sel ) {
				return;
			}
			value = rows[ sel.r ][ sel.c ];
			start = Math.min( sel.start, value.length );
			end = Math.min( sel.end, value.length );
			picked = whole ? '' : value.slice( start, end );
			if ( whole && start === end && start > 0 && ! /\s$/.test( value.slice( 0, start ) ) ) {
				before = ' ' + before;
			}
			setCell( sel.r, sel.c, value.slice( 0, start ) + before + picked + after + value.slice( end ) );
			focusCell( sel.r, sel.c, start + before.length + picked.length + after.length );
		}

		function columnMenu( col ) {
			var column = columns[ col ] || newColumn();
			var isActive = active && active[ 1 ] === col;

			return menu( {
				className: 'rtbl-grid-menu',
				toggleClass: 'rtbl-grid-label' + ( isActive ? ' is-active' : '' ) + ( column.highlight ? ' is-hl' : '' ),
				label: sprintf(
					/* translators: %s: column letter. */
					__( 'Column %s options', 'responsive-tables' ),
					letter( col )
				),
				text: letter( col ),
				groups: [
					{ items: [
						{ label: __( 'Insert column left', 'responsive-tables' ), icon: 'arrow-left-alt', onClick: function () {
							addColumn( col );
						} },
						{ label: __( 'Insert column right', 'responsive-tables' ), icon: 'arrow-right-alt', onClick: function () {
							addColumn( col + 1 );
						} },
						{ label: __( 'Move left', 'responsive-tables' ), icon: 'arrow-left-alt2', disabled: 0 === col, onClick: function () {
							moveColumn( col, col - 1 );
						} },
						{ label: __( 'Move right', 'responsive-tables' ), icon: 'arrow-right-alt2', disabled: col === cols - 1, onClick: function () {
							moveColumn( col, col + 1 );
						} },
					] },
					{ label: __( 'Rows', 'responsive-tables' ), items: [
						{ label: __( 'Sort rows A → Z', 'responsive-tables' ), icon: 'sort', onClick: function () {
							sortRows( col, 1 );
						} },
						{ label: __( 'Sort rows Z → A', 'responsive-tables' ), icon: 'sort', onClick: function () {
							sortRows( col, -1 );
						} },
					] },
					{ label: __( 'Column', 'responsive-tables' ), items: [
						{ label: __( 'Highlight this column', 'responsive-tables' ), icon: 'star-filled', selected: !! column.highlight, onClick: function () {
							setColumn( col, { highlight: ! column.highlight } );
							setActive( [ active ? active[ 0 ] : 0, col ] );
						} },
						{ label: __( 'Hide on phones', 'responsive-tables' ), icon: 'hidden', selected: !! column.hideMobile, onClick: function () {
							setColumn( col, { hideMobile: ! column.hideMobile } );
						} },
						{ label: __( 'Align left', 'responsive-tables' ), icon: 'editor-alignleft', selected: ! column.align || 'left' === column.align, onClick: function () {
							setColumn( col, { align: '' } );
						} },
						{ label: __( 'Align center', 'responsive-tables' ), icon: 'editor-aligncenter', selected: 'center' === column.align, onClick: function () {
							setColumn( col, { align: 'center' } );
						} },
						{ label: __( 'Align right', 'responsive-tables' ), icon: 'editor-alignright', selected: 'right' === column.align, onClick: function () {
							setColumn( col, { align: 'right' } );
						} },
					] },
					{ items: [
						{ label: __( 'Delete column', 'responsive-tables' ), icon: 'trash', danger: true, disabled: cols <= 1, onClick: function () {
							removeColumn( col );
						} },
					] },
				],
			} );
		}

		// Everything a cell calls, refreshed every render so it sees the latest table.
		api.current = {
			change: setCell,
			rowAction: function ( action, r ) {
				var opt = rowOpts[ r ] || newRowOpt();

				if ( 'above' === action ) {
					addRow( r );
				} else if ( 'below' === action ) {
					addRow( r + 1 );
				} else if ( 'up' === action ) {
					moveRow( r, r - 1 );
				} else if ( 'down' === action ) {
					moveRow( r, r + 1 );
				} else if ( 'highlight' === action ) {
					setRowOpt( r, { highlight: ! opt.highlight } );
					setActive( [ r, active ? active[ 1 ] : 0 ] );
				} else if ( 'delete' === action ) {
					removeRow( r );
				}
			},
			focus: function ( r, col ) {
				if ( ! active || active[ 0 ] !== r || active[ 1 ] !== col ) {
					setActive( [ r, col ] );
				}
				if ( range ) {
					setRange( null );
				}
			},
			extend: function ( r, col ) {
				var from = active || [ r, col ];

				setRange( [ from[ 0 ], from[ 1 ], r, col ] );
			},
			key: function ( event, r, col ) {
				var input = event.target;
				var value = input.value;
				var atStart = 0 === input.selectionStart && 0 === input.selectionEnd;
				var atEnd = input.selectionStart === value.length && input.selectionEnd === value.length;
				var handled = true;
				var composing = event.nativeEvent && event.nativeEvent.isComposing;

				if ( composing ) {
					return;
				}

				if ( range && ( 'Delete' === event.key || 'Backspace' === event.key ) ) {
					clearRange();
				} else if ( range && 'Escape' === event.key ) {
					setRange( null );
				} else if ( 'Enter' === event.key && ! event.shiftKey && ! event.altKey && ! event.metaKey && ! event.ctrlKey ) {
					if ( r === rows.length - 1 ) {
						addRow( rows.length );
					}
					focusCell( r + 1, col );
				} else if ( 'Tab' === event.key && ! event.altKey && ! event.metaKey && ! event.ctrlKey ) {
					if ( event.shiftKey ) {
						if ( col > 0 ) {
							focusCell( r, col - 1, 'all' );
						} else if ( r > 0 ) {
							focusCell( r - 1, cols - 1, 'all' );
						} else {
							handled = false;
						}
					} else if ( col < cols - 1 ) {
						focusCell( r, col + 1, 'all' );
					} else if ( r < rows.length - 1 ) {
						focusCell( r + 1, 0, 'all' );
					} else {
						handled = false;
					}
				} else if ( 'ArrowUp' === event.key && ! event.shiftKey && r > 0 && -1 === value.slice( 0, input.selectionStart ).indexOf( '\n' ) ) {
					focusCell( r - 1, col );
				} else if ( 'ArrowDown' === event.key && ! event.shiftKey && r < rows.length - 1 && -1 === value.slice( input.selectionEnd ).indexOf( '\n' ) ) {
					focusCell( r + 1, col );
				} else if ( 'ArrowLeft' === event.key && ! event.shiftKey && atStart && col > 0 ) {
					focusCell( r, col - 1 );
				} else if ( 'ArrowRight' === event.key && ! event.shiftKey && atEnd && col < cols - 1 ) {
					focusCell( r, col + 1, 'start' );
				} else {
					handled = false;
				}

				if ( handled ) {
					event.preventDefault();
					event.stopPropagation();
					if ( pending.current ) {
						// Focus right away when the cell already exists.
						var target = pending.current;
						var next = node( target.r, target.c );

						if ( next ) {
							pending.current = null;
							next.focus();
							if ( 'all' === target.caret ) {
								next.select();
							} else if ( 'start' === target.caret ) {
								next.setSelectionRange( 0, 0 );
							} else {
								next.setSelectionRange( next.value.length, next.value.length );
							}
						}
					}
				}
			},
			paste: function ( event, r, col ) {
				var clip = event.clipboardData;
				var text = clip ? clip.getData( 'text/plain' ) : '';
				var html = clip ? clip.getData( 'text/html' ) : '';
				var trimmed = text.replace( /(\r?\n)+$/, '' );

				if ( ! text ) {
					return;
				}
				// A range from a spreadsheet has tabs, or several lines with a <table> on the clipboard.
				if ( -1 === trimmed.indexOf( '\t' ) && ! ( /<table[\s>]/i.test( html ) && /\n/.test( trimmed ) ) ) {
					return;
				}
				event.preventDefault();
				put( parseDelimited( trimmed, '\t' ), r, col );
			},
			copy: function ( event, cut ) {
				var cells;

				if ( ! range || ! event.clipboardData ) {
					return;
				}
				cells = rangeCells();
				if ( ! cells || ( 1 === cells.length && 1 === cells[ 0 ].length ) ) {
					return;
				}
				event.preventDefault();
				event.clipboardData.setData( 'text/plain', toDelimited( cells, '\t', false ) );
				event.clipboardData.setData( 'text/html', '<table>' + cells.map( function ( row ) {
					return '<tr>' + row.map( function ( value ) {
						return '<td>' + escapeHtml( value ) + '</td>';
					} ).join( '' ) + '</tr>';
				} ).join( '' ) + '</table>' );
				if ( cut ) {
					clearRange();
				}
			},
		};

		props.exposeInsert( insert, setModal );

		var box = range ? [ Math.min( range[ 0 ], range[ 2 ] ), Math.min( range[ 1 ], range[ 3 ] ), Math.max( range[ 0 ], range[ 2 ] ), Math.max( range[ 1 ], range[ 3 ] ) ] : null;

		return el( 'div', { className: 'rtbl-grid-wrap', ref: wrap },
			el( 'table', { className: 'rtbl-grid' + ( a.rowHeaders ? ' has-row-heads' : '' ) },
				el( 'thead', null, el( 'tr', null,
					el( 'th', { className: 'rtbl-grid-corner' } ),
					columns.map( function ( column, col ) {
						return el( 'th', { key: col, className: 'rtbl-grid-colhead' + ( column.highlight ? ' is-hl' : '' ), scope: 'col' },
							columnMenu( col ),
							column.highlight && column.badge ? el( 'span', { className: 'rtbl-grid-badge' }, column.badge ) : null,
							column.hideMobile ? el( 'span', { className: 'rtbl-grid-flag', title: __( 'Hidden on phones', 'responsive-tables' ) }, icons.phone ) : null
						);
					} ),
					el( 'th', { className: 'rtbl-grid-add' }, el( c.Button, {
						icon: icons.plus,
						label: __( 'Add a column', 'responsive-tables' ),
						onClick: function () {
							addColumn( cols );
						},
						disabled: cols >= LIMITS.cols,
					} ) )
				) ),
				el( 'tbody', null, rows.map( function ( cells, r ) {
					return el( Row, {
						key: r,
						r: r,
						cells: cells,
						api: api,
						columns: columns,
						rowOpt: rowOpts[ r ],
						count: rows.length,
						rowHeaders: a.rowHeaders,
						isHead: a.header && 0 === r,
						isFoot: a.footer && r === rows.length - 1 && rows.length > ( a.header ? 2 : 1 ),
						isActiveRow: !! active && active[ 0 ] === r,
						activeCol: active && active[ 0 ] === r ? active[ 1 ] : -1,
						range: box && r >= box[ 0 ] && r <= box[ 2 ] ? [ box[ 1 ], box[ 3 ] ] : null,
					} );
				} ) )
			),
			el( 'div', { className: 'rtbl-grid-foot' },
				el( c.Button, {
					variant: 'secondary',
					icon: icons.plus,
					onClick: function () {
						addRow( rows.length );
						focusCell( rows.length, active ? active[ 1 ] : 0 );
					},
					disabled: rows.length >= LIMITS.rows,
				}, __( 'Add row', 'responsive-tables' ) ),
				el( 'span', { className: 'rtbl-grid-size' }, sprintf(
					/* translators: 1: number of rows, 2: number of columns. */
					__( '%1$s × %2$s', 'responsive-tables' ),
					sprintf( _n( '%d row', '%d rows', rows.length, 'responsive-tables' ), rows.length ),
					sprintf( _n( '%d column', '%d columns', cols, 'responsive-tables' ), cols )
				) )
			),
			modal ? modal : null
		);
	}

	// The bar above the grid: formatting and cell shortcuts for the active cell.
	function CellBar( props ) {
		var on = !! props.active;
		var insert = props.insert;
		var MediaUpload = be.MediaUpload;
		var MediaUploadCheck = be.MediaUploadCheck || Fragment;

		function tool( icon, label, onClick, extra ) {
			return el( c.Button, Object.assign( {
				icon: icon,
				label: label,
				disabled: ! on,
				onMouseDown: keepFocus,
				onClick: onClick,
				className: 'rtbl-bar-btn',
			}, extra || {} ) );
		}

		function withSelection( open ) {
			var sel = props.lastSelection();

			if ( sel ) {
				open( sel );
			}
		}

		return el( 'div', { className: 'rtbl-bar', role: 'toolbar', 'aria-label': __( 'Cell tools', 'responsive-tables' ) },
			el( 'span', { className: 'rtbl-bar-cell' }, on ? letter( props.active[ 1 ] ) + ( props.active[ 0 ] + 1 ) : '–' ),
			tool( icons.bold, __( 'Bold', 'responsive-tables' ), function () {
				insert( '<strong>', '</strong>' );
			} ),
			tool( icons.italic, __( 'Italic', 'responsive-tables' ), function () {
				insert( '<em>', '</em>' );
			} ),
			tool( icons.link, __( 'Link', 'responsive-tables' ), function () {
				withSelection( function ( sel ) {
					var value = props.rows[ sel.r ][ sel.c ];
					var picked = value.slice( sel.start, sel.end );

					props.openModal( el( LinkModal, {
						text: picked,
						onClose: function () {
							props.openModal( null );
						},
						onInsert: function ( html ) {
							props.openModal( null );
							insert( html, '', true, sel );
						},
					} ) );
				} );
			} ),
			el( 'span', { className: 'rtbl-bar-sep', 'aria-hidden': true } ),
			tool( icons.check, __( 'Check mark ({yes})', 'responsive-tables' ), function () {
				insert( '{yes}', '', true );
			}, { className: 'rtbl-bar-btn is-yes' } ),
			tool( icons.cross, __( 'Cross ({no})', 'responsive-tables' ), function () {
				insert( '{no}', '', true );
			}, { className: 'rtbl-bar-btn is-no' } ),
			tool( icons.star, __( 'Star rating', 'responsive-tables' ), function () {
				withSelection( function ( sel ) {
					props.openModal( el( StarsModal, {
						onClose: function () {
							props.openModal( null );
						},
						onInsert: function ( token ) {
							props.openModal( null );
							insert( token, '', true, sel );
						},
					} ) );
				} );
			}, { className: 'rtbl-bar-btn is-star' } ),
			tool( icons.button, __( 'Button', 'responsive-tables' ), function () {
				withSelection( function ( sel ) {
					props.openModal( el( ButtonModal, {
						text: props.rows[ sel.r ][ sel.c ].slice( sel.start, sel.end ),
						onClose: function () {
							props.openModal( null );
						},
						onInsert: function ( token ) {
							props.openModal( null );
							insert( token, '', true, sel );
						},
					} ) );
				} );
			} ),
			MediaUpload ? el( MediaUploadCheck, null, el( MediaUpload, {
				allowedTypes: [ 'image' ],
				onSelect: function ( media ) {
					var sel = props.lastSelection();

					if ( media && media.id && sel ) {
						insert( '{image ' + parseInt( media.id, 10 ) + '}', '', true, sel );
					}
				},
				render: function ( upload ) {
					return tool( icons.image, __( 'Image', 'responsive-tables' ), upload.open );
				},
			} ) ) : null,
			el( 'span', { className: 'rtbl-bar-hint' }, props.range
				? __( 'Copy, cut or press Delete to clear the selected cells.', 'responsive-tables' )
				: __( 'Paste cells from Excel or Google Sheets. Enter: next row. Shift+Enter: new line.', 'responsive-tables' ) )
		);
	}

	// Sidebar --------------------------------------------------------------------

	function modeLabel( mode ) {
		return {
			stack: __( 'Stack', 'responsive-tables' ),
			collapse: __( 'Collapse', 'responsive-tables' ),
			scroll: __( 'Scroll', 'responsive-tables' ),
		}[ mode ] || mode;
	}

	function ModePicker( props ) {
		var choices = [
			{ value: '', icon: icons.auto, label: sprintf(
				/* translators: %s: layout name. */
				__( 'Default (%s)', 'responsive-tables' ),
				modeLabel( D.mode )
			) },
			{ value: 'stack', icon: icons.stack, label: modeLabel( 'stack' ), help: __( 'Each row becomes a card, with the column headers as labels.', 'responsive-tables' ) },
			{ value: 'collapse', icon: icons.collapse, label: modeLabel( 'collapse' ), help: __( 'Rows show their first column and open with a tap.', 'responsive-tables' ) },
			{ value: 'scroll', icon: icons.scroll, label: modeLabel( 'scroll' ), help: __( 'The table scrolls sideways; the first column stays put.', 'responsive-tables' ) },
		];
		var current = choices.filter( function ( choice ) {
			return choice.value === ( props.value || '' );
		} )[ 0 ] || choices[ 0 ];
		var shown = current.help || ( choices.filter( function ( choice ) {
			return choice.value === D.mode;
		} )[ 0 ] || {} ).help;

		return el( 'div', { className: 'rtbl-modes' },
			el( 'div', { className: 'rtbl-modes-grid', role: 'radiogroup', 'aria-label': __( 'Layout on phones', 'responsive-tables' ) }, choices.map( function ( choice ) {
				var selected = choice.value === ( props.value || '' );

				return el( c.Button, {
					key: choice.value || 'default',
					className: 'rtbl-mode' + ( selected ? ' is-selected' : '' ),
					role: 'radio',
					'aria-checked': selected,
					onClick: function () {
						props.onChange( choice.value );
					},
				}, choice.icon, el( 'span', null, choice.label ) );
			} ) ),
			shown ? el( 'p', { className: 'rtbl-modes-help' }, shown ) : null
		);
	}

	function Sidebar( props ) {
		var a = props.attributes;
		var set = props.setAttributes;
		var active = props.active;
		var cols = width( a.rows );
		var columns = fill( a.columns, cols, newColumn );
		var rowOpts = fill( a.rowOpts, a.rows.length, newRowOpt );
		var breakpoint = a.breakpoint || D.breakpoint || 600;
		var csvInput = useRef( null );

		function setColumn( at, patch ) {
			var next = columns.slice();

			next[ at ] = Object.assign( {}, next[ at ], patch );
			set( { columns: next } );
		}

		function setRowOpt( at, patch ) {
			var next = rowOpts.slice();

			next[ at ] = Object.assign( {}, next[ at ], patch );
			set( { rowOpts: next } );
		}

		var column = active ? columns[ active[ 1 ] ] : null;
		var rowOpt = active ? rowOpts[ active[ 0 ] ] : null;
		var activeIsHead = active && a.header && 0 === active[ 0 ];

		return el( be.InspectorControls, null,
			el( c.PanelBody, { title: __( 'On phones', 'responsive-tables' ), initialOpen: true },
				el( ModePicker, { value: a.mode, onChange: function ( value ) {
					set( { mode: value } );
				} } ),
				el( c.RangeControl, {
					__next40pxDefaultSize: true,
					label: __( 'Switch below this width (px)', 'responsive-tables' ),
					value: breakpoint,
					min: LIMITS.minBp,
					max: LIMITS.maxBp,
					step: 10,
					onChange: function ( value ) {
						set( { breakpoint: Number( value ) || 0 } );
					},
					help: 'scroll' === ( a.mode || D.mode )
						? __( 'Columns stop shrinking at this width and the table scrolls sideways instead.', 'responsive-tables' )
						: __( 'The table switches layout when the space it has is narrower than this, on any screen.', 'responsive-tables' ),
					__nextHasNoMarginBottom: true,
				} ),
				a.breakpoint ? el( c.Button, { variant: 'link', className: 'rtbl-reset', onClick: function () {
					set( { breakpoint: 0 } );
				} }, sprintf(
					/* translators: %d: breakpoint in px. */
					__( 'Use the site default (%dpx)', 'responsive-tables' ),
					D.breakpoint
				) ) : null
			),
			active ? el( c.PanelBody, {
				title: sprintf(
					/* translators: %s: cell name, e.g. "B3". */
					__( 'Selected cell: %s', 'responsive-tables' ),
					letter( active[ 1 ] ) + ( active[ 0 ] + 1 )
				),
				initialOpen: true,
			},
			el( 'p', { className: 'rtbl-side-heading' }, sprintf(
				/* translators: %s: column letter. */
				__( 'Column %s', 'responsive-tables' ),
				letter( active[ 1 ] )
			) ),
			el( c.ToggleControl, { label: __( 'Highlight this column', 'responsive-tables' ), help: __( 'For "Best value" or "Our pick" in a comparison.', 'responsive-tables' ), checked: !! column.highlight, onChange: function ( value ) {
				setColumn( active[ 1 ], { highlight: value } );
			}, __nextHasNoMarginBottom: true } ),
			column.highlight ? el( c.TextControl, { __next40pxDefaultSize: true, label: __( 'Badge text', 'responsive-tables' ), value: column.badge || '', maxLength: 40, placeholder: __( 'Best value', 'responsive-tables' ), onChange: function ( value ) {
				setColumn( active[ 1 ], { badge: value } );
			}, __nextHasNoMarginBottom: true } ) : null,
			el( c.SelectControl, { __next40pxDefaultSize: true, label: __( 'Align', 'responsive-tables' ), value: column.align || '', options: [
				{ value: '', label: __( 'Left', 'responsive-tables' ) },
				{ value: 'center', label: __( 'Center', 'responsive-tables' ) },
				{ value: 'right', label: __( 'Right', 'responsive-tables' ) },
			], onChange: function ( value ) {
				setColumn( active[ 1 ], { align: value } );
			}, __nextHasNoMarginBottom: true } ),
			el( c.ToggleControl, { label: __( 'Hide on phones', 'responsive-tables' ), help: __( 'Leaves this column out of the phone layout.', 'responsive-tables' ), checked: !! column.hideMobile, onChange: function ( value ) {
				setColumn( active[ 1 ], { hideMobile: value } );
			}, __nextHasNoMarginBottom: true } ),
			activeIsHead ? null : el( Fragment, null,
				el( 'p', { className: 'rtbl-side-heading' }, sprintf(
					/* translators: %d: row number. */
					__( 'Row %d', 'responsive-tables' ),
					active[ 0 ] + 1
				) ),
				el( c.ToggleControl, { label: __( 'Highlight this row', 'responsive-tables' ), checked: !! rowOpt.highlight, onChange: function ( value ) {
					setRowOpt( active[ 0 ], { highlight: value } );
				}, __nextHasNoMarginBottom: true } ),
				rowOpt.highlight ? el( c.TextControl, { __next40pxDefaultSize: true, label: __( 'Badge text', 'responsive-tables' ), value: rowOpt.badge || '', maxLength: 40, placeholder: __( 'Our pick', 'responsive-tables' ), onChange: function ( value ) {
					setRowOpt( active[ 0 ], { badge: value } );
				}, __nextHasNoMarginBottom: true } ) : null
			) ) : null,
			el( c.PanelBody, { title: __( 'Table', 'responsive-tables' ), initialOpen: ! active },
				el( c.ToggleControl, { label: __( 'Header row', 'responsive-tables' ), help: __( 'The first row names the columns. Its labels appear beside each value on phones.', 'responsive-tables' ), checked: !! a.header, onChange: function ( value ) {
					set( { header: value } );
				}, __nextHasNoMarginBottom: true } ),
				el( c.ToggleControl, { label: __( 'First column as row headers', 'responsive-tables' ), checked: !! a.rowHeaders, onChange: function ( value ) {
					set( { rowHeaders: value } );
				}, __nextHasNoMarginBottom: true } ),
				el( c.ToggleControl, { label: __( 'Footer row', 'responsive-tables' ), help: __( 'The last row is a footer, e.g. totals.', 'responsive-tables' ), checked: !! a.footer, onChange: function ( value ) {
					set( { footer: value } );
				}, __nextHasNoMarginBottom: true } ),
				el( c.TextControl, { __next40pxDefaultSize: true, label: __( 'Caption', 'responsive-tables' ), help: __( 'Shown above the table and read out by screen readers.', 'responsive-tables' ), value: a.caption || '', onChange: function ( value ) {
					set( { caption: value } );
				}, __nextHasNoMarginBottom: true } ),
				el( c.TextControl, { __next40pxDefaultSize: true, label: __( 'Note below the table', 'responsive-tables' ), placeholder: __( 'e.g. Prices checked October 2026', 'responsive-tables' ), value: a.note || '', onChange: function ( value ) {
					set( { note: value } );
				}, __nextHasNoMarginBottom: true } )
			),
			el( c.PanelBody, { title: __( 'Sorting, search and pages', 'responsive-tables' ), initialOpen: false },
				el( c.ToggleControl, { label: __( 'Let visitors sort', 'responsive-tables' ), help: __( 'Click a column header to sort. On phones, a "Sort by" menu.', 'responsive-tables' ), checked: !! a.sort, onChange: function ( value ) {
					set( { sort: value } );
				}, __nextHasNoMarginBottom: true } ),
				el( c.ToggleControl, { label: __( 'Search box', 'responsive-tables' ), checked: !! a.search, onChange: function ( value ) {
					set( { search: value } );
				}, __nextHasNoMarginBottom: true } ),
				el( c.ToggleControl, { label: __( 'Split into pages', 'responsive-tables' ), checked: !! a.paginate, onChange: function ( value ) {
					set( { paginate: value } );
				}, __nextHasNoMarginBottom: true } ),
				a.paginate ? el( c.RangeControl, { __next40pxDefaultSize: true, label: __( 'Rows per page', 'responsive-tables' ), value: a.perPage || 10, min: 1, max: 100, onChange: function ( value ) {
					set( { perPage: Math.max( 1, Math.min( LIMITS.maxPerPage, Number( value ) || 10 ) ) } );
				}, __nextHasNoMarginBottom: true } ) : null,
				el( 'p', { className: 'rtbl-side-note' }, __( 'A small script loads only on pages with a table that uses these. Every row stays in the page for search engines.', 'responsive-tables' ) )
			),
			el( c.PanelBody, { title: __( 'Look', 'responsive-tables' ), initialOpen: false },
				el( c.SelectControl, { __next40pxDefaultSize: true, label: __( 'Style', 'responsive-tables' ), value: a.tableStyle || '', options: [
					{ value: '', label: __( 'Site default', 'responsive-tables' ) },
					{ value: 'clean', label: __( 'Clean', 'responsive-tables' ) },
					{ value: 'striped', label: __( 'Striped rows', 'responsive-tables' ) },
					{ value: 'bordered', label: __( 'Bordered', 'responsive-tables' ) },
					{ value: 'minimal', label: __( 'Minimal', 'responsive-tables' ) },
				], onChange: function ( value ) {
					set( { tableStyle: value } );
				}, __nextHasNoMarginBottom: true } ),
				el( c.SelectControl, { __next40pxDefaultSize: true, label: __( 'Header', 'responsive-tables' ), value: a.headerStyle || '', options: [
					{ value: '', label: __( 'Site default', 'responsive-tables' ) },
					{ value: 'light', label: __( 'Light tint', 'responsive-tables' ) },
					{ value: 'solid', label: __( 'Solid color', 'responsive-tables' ) },
					{ value: 'plain', label: __( 'Plain', 'responsive-tables' ) },
				], onChange: function ( value ) {
					set( { headerStyle: value } );
				}, __nextHasNoMarginBottom: true } ),
				el( 'p', { className: 'rtbl-side-heading' }, __( 'Accent color', 'responsive-tables' ) ),
				el( c.ColorPalette, {
					value: a.accent || undefined,
					colors: [
						{ name: __( 'Blue', 'responsive-tables' ), color: '#2563eb' },
						{ name: __( 'Teal', 'responsive-tables' ), color: '#0d9488' },
						{ name: __( 'Green', 'responsive-tables' ), color: '#16a34a' },
						{ name: __( 'Orange', 'responsive-tables' ), color: '#ea580c' },
						{ name: __( 'Red', 'responsive-tables' ), color: '#dc2626' },
						{ name: __( 'Purple', 'responsive-tables' ), color: '#7c3aed' },
						{ name: __( 'Slate', 'responsive-tables' ), color: '#334155' },
					],
					onChange: function ( value ) {
						set( { accent: value && /^#[0-9a-f]{3,6}$/i.test( value ) ? value : '' } );
					},
					clearable: true,
					disableCustomColors: false,
				} ),
				el( 'p', { className: 'rtbl-side-note' }, a.accent ? '' : sprintf(
					/* translators: %s: color code. */
					__( 'Using the site default, %s.', 'responsive-tables' ),
					D.accent
				) )
			),
			el( c.PanelBody, { title: __( 'Data', 'responsive-tables' ), initialOpen: false },
				el( 'div', { className: 'rtbl-side-buttons' },
					el( c.Button, { variant: 'secondary', icon: icons.paste, onClick: props.openPaste }, __( 'Paste data…', 'responsive-tables' ) ),
					el( c.Button, { variant: 'secondary', icon: icons.upload, onClick: function () {
						if ( csvInput.current ) {
							csvInput.current.click();
						}
					} }, __( 'Import CSV…', 'responsive-tables' ) ),
					el( 'input', { type: 'file', accept: '.csv,.tsv,.txt,text/csv,text/tab-separated-values', ref: csvInput, hidden: true, onChange: function ( event ) {
						var file = event.target.files && event.target.files[ 0 ];

						event.target.value = '';
						if ( file ) {
							readFile( file, function ( text ) {
								props.replaceData( parseDelimited( text, detectDelimiter( text ) ) );
							} );
						}
					} } ),
					el( c.Button, { variant: 'secondary', icon: icons.download, onClick: function () {
						download( slug( a.caption || props.title || 'table' ) + '.csv', toDelimited( a.rows, ',', true ) );
					} }, __( 'Export CSV', 'responsive-tables' ) )
				),
				el( 'p', { className: 'rtbl-side-note' }, __( 'Importing replaces the cells in this table. Exported files open in Excel, Google Sheets and Numbers.', 'responsive-tables' ) )
			),
			el( c.PanelBody, { title: __( 'Cell shortcuts', 'responsive-tables' ), initialOpen: false },
				el( 'ul', { className: 'rtbl-shortcuts' },
					[
						[ '{yes}  {no}', __( 'Check mark or cross. Add text after it: {yes Free shipping}', 'responsive-tables' ) ],
						[ '{stars 4.5}', __( 'Star rating out of 5. Or out of 10: {stars 8/10}', 'responsive-tables' ) ],
						[ '{button Buy now | https://…}', __( 'Button link. Add | new, sponsored to open in a new tab and mark it as an affiliate link.', 'responsive-tables' ) ],
						[ '{image 123}', __( 'Image from the media library (use the Image button).', 'responsive-tables' ) ],
						[ '<a href="…">', __( 'Links, bold and other simple HTML work too.', 'responsive-tables' ) ],
					].map( function ( item, i ) {
						return el( 'li', { key: i }, el( 'code', null, item[ 0 ] ), el( 'span', null, item[ 1 ] ) );
					} )
				)
			)
		);
	}

	// Views ----------------------------------------------------------------------

	function Preview( props ) {
		return el( 'div', {
			className: 'rtbl-preview is-' + props.size,
			onClickCapture: function ( event ) {
				// Let rows open (collapse) but keep links from leaving the editor.
				if ( event.target.closest && event.target.closest( 'a, button:not(.rtbl-sort)' ) ) {
					event.preventDefault();
				}
			},
		},
		'phone' === props.size ? el( 'div', { className: 'rtbl-phone-bar', 'aria-hidden': true } ) : null,
		el( ServerSideRender, { block: NAME, attributes: props.attributes, httpMethod: 'POST' } ) );
	}

	function StaticPreview( props ) {
		var rows = grid( props.attributes.rows ).slice( 0, 4 );

		return el( 'table', { className: 'rtbl-static' }, el( 'tbody', null, rows.map( function ( row, r ) {
			return el( 'tr', { key: r }, row.map( function ( value, col ) {
				return el( 0 === r ? 'th' : 'td', { key: col }, plain( value ).replace( /^1$/, '✓' ).replace( /^0$/, '✕' ) );
			} ) );
		} ) ) );
	}

	function Start( props ) {
		var s = useState( { cols: 4, rows: 4 } );
		var size = s[ 0 ];
		var setSize = s[ 1 ];
		var p = useState( false );
		var csvInput = useRef( null );
		var t = useState( null );
		var tables = t[ 0 ];
		var setTables = t[ 1 ];
		var pick = useState( '' );
		var all = starters();

		useEffect( function () {
			if ( data.isLibrary || ! apiFetch ) {
				return;
			}
			if ( savedTables ) {
				setTables( savedTables );
				return;
			}
			apiFetch( { path: '/responsive-tables/v1/tables' } ).then( function ( list ) {
				savedTables = Array.isArray( list ) ? list : [];
				setTables( savedTables );
			}, function () {
				setTables( [] );
			} );
		}, [] );

		return el( c.Placeholder, {
			icon: icons.block,
			label: __( 'Responsive Table', 'responsive-tables' ),
			instructions: __( 'A table that stays readable on phones. Start with a blank table, a starter, or your own data.', 'responsive-tables' ),
			className: 'rtbl-start',
		},
		el( 'form', { className: 'rtbl-start-size', onSubmit: function ( event ) {
			event.preventDefault();
			props.create( blank( Math.max( 1, Math.min( 500, size.rows ) ), Math.max( 1, Math.min( LIMITS.cols, size.cols ) ) ) );
		} },
		el( c.TextControl, { __next40pxDefaultSize: true, type: 'number', label: __( 'Columns', 'responsive-tables' ), min: 1, max: LIMITS.cols, value: size.cols, onChange: function ( value ) {
			setSize( { cols: parseInt( value, 10 ) || 1, rows: size.rows } );
		}, __nextHasNoMarginBottom: true } ),
		el( c.TextControl, { __next40pxDefaultSize: true, type: 'number', label: __( 'Rows', 'responsive-tables' ), min: 1, max: 500, value: size.rows, onChange: function ( value ) {
			setSize( { cols: size.cols, rows: parseInt( value, 10 ) || 1 } );
		}, __nextHasNoMarginBottom: true } ),
		el( c.Button, { variant: 'primary', type: 'submit' }, __( 'Create table', 'responsive-tables' ) ) ),
		el( 'div', { className: 'rtbl-start-row' },
			el( 'span', { className: 'rtbl-start-label' }, __( 'Starters:', 'responsive-tables' ) ),
			Object.keys( all ).map( function ( key ) {
				return el( c.Button, { key: key, variant: 'secondary', onClick: function () {
					props.create( all[ key ].attrs.rows, all[ key ].attrs );
				} }, all[ key ].label );
			} )
		),
		el( 'div', { className: 'rtbl-start-row' },
			el( 'span', { className: 'rtbl-start-label' }, __( 'Your data:', 'responsive-tables' ) ),
			el( c.Button, { variant: 'secondary', icon: icons.paste, onClick: function () {
				p[ 1 ]( true );
			} }, __( 'Paste from a spreadsheet', 'responsive-tables' ) ),
			el( c.Button, { variant: 'secondary', icon: icons.upload, onClick: function () {
				if ( csvInput.current ) {
					csvInput.current.click();
				}
			} }, __( 'Import CSV', 'responsive-tables' ) ),
			el( 'input', { type: 'file', accept: '.csv,.tsv,.txt,text/csv,text/tab-separated-values', ref: csvInput, hidden: true, onChange: function ( event ) {
				var file = event.target.files && event.target.files[ 0 ];

				event.target.value = '';
				if ( file ) {
					readFile( file, function ( text ) {
						props.create( parseDelimited( text, detectDelimiter( text ) ) );
					} );
				}
			} } )
		),
		tables && tables.length ? el( 'div', { className: 'rtbl-start-row' },
			el( 'span', { className: 'rtbl-start-label' }, __( 'Saved table:', 'responsive-tables' ) ),
			el( c.SelectControl, {
				__next40pxDefaultSize: true,
				label: __( 'Saved table', 'responsive-tables' ),
				hideLabelFromVision: true,
				value: pick[ 0 ],
				options: [ { value: '', label: __( 'Choose a saved table…', 'responsive-tables' ) } ].concat( tables.map( function ( table ) {
					return { value: String( table.id ), label: table.title + ' (' + table.rows + ' × ' + table.cols + ')' };
				} ) ),
				onChange: pick[ 1 ],
				__nextHasNoMarginBottom: true,
			} ),
			el( c.Button, { variant: 'secondary', icon: icons.saved, disabled: ! pick[ 0 ], onClick: function () {
				props.setAttributes( { ref: parseInt( pick[ 0 ], 10 ) } );
			} }, __( 'Use it', 'responsive-tables' ) )
		) : null,
		p[ 0 ] ? el( PasteModal, {
			onClose: function () {
				p[ 1 ]( false );
			},
			onInsert: function ( rows ) {
				p[ 1 ]( false );
				props.create( rows );
			},
		} ) : null );
	}

	function SavedView( props ) {
		var a = props.attributes;
		var t = useState( null );
		var title = t[ 0 ];
		var b = useState( false );
		var busy = b[ 0 ];

		useEffect( function () {
			var found;

			if ( savedTables ) {
				found = savedTables.filter( function ( table ) {
					return table.id === a.ref;
				} )[ 0 ];
				t[ 1 ]( found ? found.title : '' );
				return;
			}
			apiFetch( { path: '/responsive-tables/v1/tables' } ).then( function ( list ) {
				savedTables = Array.isArray( list ) ? list : [];
				found = savedTables.filter( function ( table ) {
					return table.id === a.ref;
				} )[ 0 ];
				t[ 1 ]( found ? found.title : '' );
			}, function () {
				t[ 1 ]( '' );
			} );
		}, [ a.ref ] );

		return el( Fragment, null,
			el( 'div', { className: 'rtbl-saved-bar' },
				icons.saved,
				el( 'span', { className: 'rtbl-saved-title' }, title ? sprintf(
					/* translators: %s: table name. */
					__( 'Saved table: %s', 'responsive-tables' ),
					title
				) : __( 'Saved table', 'responsive-tables' ) ),
				el( c.Button, { variant: 'secondary', icon: icons.external, href: data.editUrl + a.ref, target: '_blank', rel: 'noopener' }, __( 'Edit table', 'responsive-tables' ) ),
				el( c.Button, { variant: 'secondary', isBusy: busy, disabled: busy, onClick: function () {
					b[ 1 ]( true );
					apiFetch( { path: '/responsive-tables/v1/tables/' + a.ref } ).then( function ( attrs ) {
						b[ 1 ]( false );
						props.setAttributes( newTable( attrs.rows, Object.assign( {}, attrs, { ref: 0 } ) ) );
					}, function () {
						b[ 1 ]( false );
					} );
				} }, __( 'Make an editable copy', 'responsive-tables' ) ),
				el( c.Button, { variant: 'tertiary', onClick: function () {
					props.setAttributes( { ref: 0 } );
				} }, __( 'Choose another', 'responsive-tables' ) )
			),
			el( 'p', { className: 'rtbl-saved-note' }, __( 'Changes to a saved table show everywhere it\'s used.', 'responsive-tables' ) ),
			el( Preview, { size: 'desktop', attributes: { ref: a.ref } } )
		);
	}

	function Editor( props ) {
		var a = props.attributes;
		var v = useState( 'edit' );
		var view = v[ 0 ];
		var setView = v[ 1 ];
		var s = useState( null );
		var active = s[ 0 ];
		var setActive = s[ 1 ];
		var r = useState( null );
		var range = r[ 0 ];
		var setRange = r[ 1 ];
		var p = useState( false );
		var insertRef = useRef( null );
		var modalRef = useRef( null );
		var selectionRef = useRef( null );
		var gridWrap = useRef( null );
		var cols = width( a.rows );

		// Keep the active cell inside the table after rows or columns go.
		if ( active && ( active[ 0 ] >= a.rows.length || active[ 1 ] >= cols ) ) {
			active = null;
		}

		function lastSelection() {
			var input;

			if ( ! active ) {
				return null;
			}
			input = gridWrap.current ? gridWrap.current.querySelector( 'textarea[data-r="' + active[ 0 ] + '"][data-c="' + active[ 1 ] + '"]' ) : null;
			if ( input ) {
				selectionRef.current = { r: active[ 0 ], c: active[ 1 ], start: input.selectionStart, end: input.selectionEnd };
			} else if ( ! selectionRef.current || selectionRef.current.r !== active[ 0 ] || selectionRef.current.c !== active[ 1 ] ) {
				selectionRef.current = { r: active[ 0 ], c: active[ 1 ], start: a.rows[ active[ 0 ] ][ active[ 1 ] ].length, end: a.rows[ active[ 0 ] ][ active[ 1 ] ].length };
			}
			return selectionRef.current;
		}

		function replaceData( rows ) {
			if ( ! rows.length ) {
				return;
			}
			// eslint-disable-next-line no-alert
			if ( a.rows.some( function ( row ) {
				return row.some( function ( value ) {
					return '' !== str( value ).trim();
				} );
			} ) && ! window.confirm( __( 'Replace the cells in this table with the imported data?', 'responsive-tables' ) ) ) {
				return;
			}
			props.setAttributes( {
				rows: rows,
				columns: fill( a.columns, width( rows ), newColumn ),
				rowOpts: fill( a.rowOpts, rows.length, newRowOpt ),
			} );
			setActive( null );
			setRange( null );
			setView( 'edit' );
		}

		var BlockControls = be.BlockControls;
		var toolbar = c.ToolbarGroup || c.Toolbar;
		var views = [
			{ id: 'edit', icon: icons.edit, label: __( 'Edit cells', 'responsive-tables' ) },
			{ id: 'desktop', icon: icons.desktop, label: __( 'Preview on desktop', 'responsive-tables' ) },
			{ id: 'phone', icon: icons.phone, label: __( 'Preview on a phone', 'responsive-tables' ) },
		];

		return el( Fragment, null,
			el( BlockControls, null, el( toolbar, null, views.map( function ( item ) {
				return el( c.ToolbarButton || c.Button, {
					key: item.id,
					icon: item.icon,
					label: item.label,
					isPressed: view === item.id,
					onClick: function () {
						setView( item.id );
					},
				} );
			} ) ) ),
			el( Sidebar, {
				attributes: a,
				setAttributes: props.setAttributes,
				active: 'edit' === view ? active : null,
				openPaste: function () {
					p[ 1 ]( true );
				},
				replaceData: replaceData,
				title: props.title,
			} ),
			el( 'div', { className: 'rtbl-views', role: 'tablist', 'aria-label': __( 'View', 'responsive-tables' ) }, views.map( function ( item ) {
				return el( c.Button, {
					key: item.id,
					role: 'tab',
					'aria-selected': view === item.id,
					className: 'rtbl-view' + ( view === item.id ? ' is-selected' : '' ),
					icon: item.icon,
					onClick: function () {
						setView( item.id );
					},
				}, { edit: __( 'Edit', 'responsive-tables' ), desktop: __( 'Desktop', 'responsive-tables' ), phone: __( 'Phone', 'responsive-tables' ) }[ item.id ] );
			} ) ),
			'edit' === view ? el( 'div', { className: 'rtbl-editor', ref: gridWrap },
				el( CellBar, {
					active: active,
					range: range,
					rows: a.rows,
					lastSelection: lastSelection,
					insert: function ( before, after, whole, sel ) {
						if ( insertRef.current ) {
							insertRef.current( before, after, whole, sel );
						}
					},
					openModal: function ( modal ) {
						if ( modalRef.current ) {
							modalRef.current( modal );
						}
					},
				} ),
				el( Grid, {
					attributes: a,
					setAttributes: props.setAttributes,
					active: active,
					setActive: setActive,
					range: range,
					setRange: setRange,
					lastSelection: lastSelection,
					exposeInsert: function ( insert, setModal ) {
						insertRef.current = insert;
						modalRef.current = setModal;
					},
				} )
			) : el( Preview, { size: view, attributes: a } ),
			p[ 0 ] ? el( PasteModal, {
				onClose: function () {
					p[ 1 ]( false );
				},
				onInsert: function ( rows ) {
					p[ 1 ]( false );
					replaceData( rows );
				},
			} ) : null
		);
	}

	function Edit( props ) {
		var a = props.attributes;
		var blockProps = be.useBlockProps ? be.useBlockProps( { className: 'rtbl-block' } ) : { className: 'rtbl-block ' + ( props.className || '' ) };
		var isPreview = useSelect ? useSelect( function ( select ) {
			var store = select( 'core/block-editor' );
			var settings = store && store.getSettings ? store.getSettings() : {};

			// isPreviewMode since WordPress 6.8; reading the old name logs a deprecation.
			return !! ( 'isPreviewMode' in settings ? settings.isPreviewMode : settings.__unstableIsPreviewMode );
		}, [] ) : false;
		var title = useSelect ? useSelect( function ( select ) {
			var editor = select( 'core/editor' );

			return editor && editor.getEditedPostAttribute ? editor.getEditedPostAttribute( 'title' ) : '';
		}, [] ) : '';
		var body;

		if ( isPreview ) {
			body = el( StaticPreview, { attributes: a } );
		} else if ( a.ref ) {
			body = el( SavedView, props );
		} else if ( ! a.rows || ! a.rows.length ) {
			body = el( Start, {
				setAttributes: props.setAttributes,
				create: function ( rows, extra ) {
					props.setAttributes( newTable( rows, extra ) );
				},
			} );
		} else {
			body = el( Editor, Object.assign( {}, props, { title: title } ) );
		}

		return el( 'div', blockProps, body );
	}

	// Core Table blocks convert both ways.
	function cellsOf( section ) {
		return ( section || [] ).map( function ( row ) {
			return ( row.cells || [] ).map( function ( cell ) {
				return str( cell.content && 'object' === typeof cell.content && cell.content.toHTMLString ? cell.content.toHTMLString() : cell.content );
			} );
		} );
	}

	function coreSection( rows, tag ) {
		return rows.map( function ( row ) {
			return { cells: row.map( function ( value ) {
				return { content: value, tag: tag };
			} ) };
		} );
	}

	wp.blocks.registerBlockType( NAME, {
		apiVersion: 3,
		title: __( 'Responsive Table', 'responsive-tables' ),
		description: __( 'A table that stays readable on phones: rows become cards, open with a tap, or scroll with a frozen first column.', 'responsive-tables' ),
		category: 'text',
		icon: icons.block,
		keywords: [ __( 'table', 'responsive-tables' ), __( 'comparison', 'responsive-tables' ), __( 'pricing', 'responsive-tables' ), __( 'spreadsheet', 'responsive-tables' ), 'csv', 'tablepress' ],
		attributes: data.attributes || undefined,
		supports: { align: [ 'wide', 'full' ], html: false },
		example: {
			attributes: {
				rows: [
					[ __( 'Product', 'responsive-tables' ), __( 'Rating', 'responsive-tables' ), __( 'Price', 'responsive-tables' ) ],
					[ 'Aero Buds Pro', '{stars 4.8}', '$129' ],
					[ 'Pulse Air 2', '{stars 4.4}', '$89' ],
				],
			},
		},
		transforms: {
			from: [ {
				type: 'block',
				blocks: [ 'core/table' ],
				transform: function ( attrs ) {
					var head = cellsOf( attrs.head );
					var body = cellsOf( attrs.body );
					var foot = cellsOf( attrs.foot );

					return wp.blocks.createBlock( NAME, newTable( head.concat( body, foot ), {
						header: head.length > 0,
						footer: foot.length > 0,
						caption: str( attrs.caption && attrs.caption.toHTMLString ? attrs.caption.toHTMLString() : attrs.caption ),
					} ) );
				},
			} ],
			to: [ {
				type: 'block',
				blocks: [ 'core/table' ],
				isMatch: function ( attrs ) {
					return ! attrs.ref && attrs.rows && attrs.rows.length > 0;
				},
				transform: function ( attrs ) {
					var rows = grid( attrs.rows );
					var head = attrs.header ? rows.slice( 0, 1 ) : [];
					var rest = attrs.header ? rows.slice( 1 ) : rows;
					var foot = attrs.footer && rest.length > 1 ? rest.slice( -1 ) : [];
					var body = foot.length ? rest.slice( 0, -1 ) : rest;

					return wp.blocks.createBlock( 'core/table', {
						head: coreSection( head, 'th' ),
						body: coreSection( body, 'td' ),
						foot: coreSection( foot, 'td' ),
						caption: attrs.caption || '',
					} );
				},
			} ],
		},
		edit: Edit,
		save: function () {
			return null;
		},
	} );
}( window.wp ) );
