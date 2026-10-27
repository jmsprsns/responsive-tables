<?php
/**
 * Draws tables: the markup, the mobile layout CSS for each breakpoint, and
 * loading the stylesheet and (only when needed) the script.
 *
 * The mobile layouts are pure CSS. Each table sits in a CSS container, and a
 * container query switches it to cards (stack), tap-to-open rows (collapse)
 * or a sideways-scrolling table with a frozen first column (scroll) once the
 * space it has is narrower than its breakpoint. Media queries take over in
 * the few browsers without container queries. The rules for a breakpoint are
 * printed once per page, just before the first table that uses it.
 *
 * Screen readers keep the table in both layouts: every element carries its
 * ARIA role (a table changed to display:block loses its semantics in some
 * browsers otherwise), header cells use scope, and the labels shown beside
 * values on phones are aria-hidden copies of the column headers.
 *
 * The script (sorting, search, pages) is only enqueued by a table that uses one
 * of them, so pages without such a table load no JavaScript from this plugin.
 *
 * @package Responsive_Tables
 */

defined( 'ABSPATH' ) || exit;

/**
 * Table renderer.
 */
final class Responsive_Tables_Render {

	/**
	 * Script and style handle.
	 */
	const HANDLE = 'responsive-tables';

	/**
	 * Breakpoint limits, in px.
	 */
	const MIN_BREAKPOINT = 240;
	const MAX_BREAKPOINT = 1600;

	/**
	 * Limits that keep a huge or hostile table from exhausting the server.
	 */
	const MAX_ROWS     = 5000;
	const MAX_COLS     = 60;
	const MAX_CELLS    = 60000;
	const MAX_PER_PAGE = 500;

	/**
	 * Deepest a table may be drawn inside another table's cell.
	 */
	const MAX_DEPTH = 3;

	/**
	 * Tables drawn so far in this request, for unique IDs.
	 *
	 * @var int
	 */
	private static $count = 0;

	/**
	 * Breakpoints whose CSS has been printed.
	 *
	 * @var array<int,bool>
	 */
	private static $printed = array();

	/**
	 * How deep inside other tables the current one is.
	 *
	 * @var int
	 */
	private static $depth = 0;

	/**
	 * Hook in.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'register_assets' ), 5 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_early' ) );

		// Keep page optimizers from delaying the script or stripping CSS that
		// only applies after a tap, a search or on phones.
		add_filter( 'rocket_delay_js_exclusions', array( __CLASS__, 'exclude_script' ) );
		add_filter( 'rocket_exclude_defer_js', array( __CLASS__, 'exclude_script' ) );
		add_filter( 'rocket_rucss_external_exclusions', array( __CLASS__, 'exclude_stylesheet' ) );
		add_filter( 'rocket_rucss_inline_atts_exclusions', array( __CLASS__, 'exclude_inline_style' ) );
	}

	/**
	 * Register the front-end stylesheet and script.
	 *
	 * @return void
	 */
	public static function register_assets() {
		wp_register_style( self::HANDLE, RESPONSIVE_TABLES_URL . 'assets/table.css', array(), RESPONSIVE_TABLES_VERSION );
		wp_register_script( self::HANDLE, RESPONSIVE_TABLES_URL . 'assets/table.min.js', array(), RESPONSIVE_TABLES_VERSION, true );

		wp_localize_script(
			self::HANDLE,
			'responsiveTables',
			array(
				/* translators: 1: first row shown, 2: last row shown, 3: number of rows. */
				'range'    => __( '%1$s–%2$s of %3$s', 'responsive-tables' ),
				'previous' => __( 'Previous page', 'responsive-tables' ),
				'next'     => __( 'Next page', 'responsive-tables' ),
				/* translators: %s: page number. */
				'page'     => __( 'Page %s', 'responsive-tables' ),
				/* translators: %s: number of rows. */
				'matches'  => __( '%s rows match', 'responsive-tables' ),
				'oneMatch' => __( '1 row matches', 'responsive-tables' ),
				'noMatch'  => __( 'No matching rows', 'responsive-tables' ),
				/* translators: %s: column name. */
				'sorted'   => __( 'Sorted by %s', 'responsive-tables' ),
			)
		);
	}

	/**
	 * Load the stylesheet in <head> when the page's content clearly has a
	 * table. Anything missed here (a table in a widget, a page builder) still
	 * gets it, printed just before the table.
	 *
	 * @return void
	 */
	public static function enqueue_early() {
		global $wp_query;

		if ( ! $wp_query instanceof WP_Query || empty( $wp_query->posts ) ) {
			return;
		}

		$needles = array( '<!-- wp:responsive-tables/table', '[responsive_table' );

		if ( Responsive_Tables_TablePress::takes_over() ) {
			$needles[] = '[table ';
			$needles[] = '<!-- wp:tablepress/table';
		}

		foreach ( $wp_query->posts as $post ) {
			if ( ! $post instanceof WP_Post ) {
				continue;
			}

			foreach ( $needles as $needle ) {
				if ( false !== strpos( $post->post_content, $needle ) ) {
					wp_enqueue_style( self::HANDLE );
					return;
				}
			}
		}
	}

	/**
	 * Draw a table.
	 *
	 * @param array<string,mixed> $attrs Block attributes (see Responsive_Tables_Block::attributes()).
	 * @param array<string,mixed> $args  Optional: 'wrapper' (bool, use the block wrapper attributes).
	 * @return string HTML, or an empty string for an empty table.
	 */
	public static function table( $attrs, $args = array() ) {
		if ( self::$depth >= self::MAX_DEPTH ) {
			return '';
		}

		$table = self::normalize( $attrs );

		if ( ! $table['rows'] ) {
			return '';
		}

		++self::$depth;

		try {
			$html = is_feed() ? self::feed_markup( $table ) : self::markup( $table, $args );
		} finally {
			--self::$depth;
		}

		return $html;
	}

	/**
	 * Clean every attribute and fill in the site defaults.
	 *
	 * @param mixed $attrs Raw block attributes.
	 * @return array<string,mixed>
	 */
	public static function normalize( $attrs ) {
		$attrs    = is_array( $attrs ) ? $attrs : array();
		$settings = responsive_tables_plugin()->get_settings();
		$choices  = Responsive_Tables_Plugin::choices();

		// Rows: a rectangular grid of strings, within the size limits.
		$rows  = array();
		$cols  = 0;
		$cells = 0;

		if ( isset( $attrs['rows'] ) && is_array( $attrs['rows'] ) ) {
			foreach ( $attrs['rows'] as $row ) {
				if ( count( $rows ) >= self::MAX_ROWS ) {
					break;
				}

				$row    = is_array( $row ) ? array_values( array_slice( $row, 0, self::MAX_COLS ) ) : array( $row );
				$row    = array_map( array( 'Responsive_Tables_Cells', 'clip' ), $row );
				$cells += max( 1, count( $row ) );

				if ( $cells > self::MAX_CELLS ) {
					break;
				}

				$cols   = max( $cols, count( $row ) );
				$rows[] = $row;
			}
		}

		foreach ( $rows as $i => $row ) {
			if ( count( $row ) < $cols ) {
				$rows[ $i ] = array_pad( $row, $cols, '' );
			}
		}

		$columns = array();
		for ( $c = 0; $c < $cols; $c++ ) {
			$raw         = isset( $attrs['columns'][ $c ] ) && is_array( $attrs['columns'][ $c ] ) ? $attrs['columns'][ $c ] : array();
			$align       = isset( $raw['align'] ) ? (string) $raw['align'] : '';
			$columns[ $c ] = array(
				'align'      => in_array( $align, array( 'left', 'center', 'right' ), true ) ? $align : '',
				'highlight'  => ! empty( $raw['highlight'] ),
				'badge'      => isset( $raw['badge'] ) ? self::short_text( $raw['badge'] ) : '',
				'hideMobile' => ! empty( $raw['hideMobile'] ),
			);
		}

		$row_opts = array();
		foreach ( array_keys( $rows ) as $r ) {
			$raw            = isset( $attrs['rowOpts'][ $r ] ) && is_array( $attrs['rowOpts'][ $r ] ) ? $attrs['rowOpts'][ $r ] : array();
			$row_opts[ $r ] = array(
				'highlight' => ! empty( $raw['highlight'] ),
				'badge'     => isset( $raw['badge'] ) ? self::short_text( $raw['badge'] ) : '',
			);
		}

		$pick = function ( $key, $setting ) use ( $attrs, $settings, $choices ) {
			$value = isset( $attrs[ $key ] ) ? (string) $attrs[ $key ] : '';
			return in_array( $value, $choices[ $setting ], true ) ? $value : $settings[ $setting ];
		};

		$breakpoint = isset( $attrs['breakpoint'] ) ? (int) $attrs['breakpoint'] : 0;
		$accent     = isset( $attrs['accent'] ) ? sanitize_hex_color( (string) $attrs['accent'] ) : '';
		$align      = isset( $attrs['align'] ) ? (string) $attrs['align'] : '';
		$classes    = array();

		if ( ! empty( $attrs['className'] ) && is_string( $attrs['className'] ) ) {
			foreach ( preg_split( '/\s+/', $attrs['className'] ) as $class ) {
				$class = sanitize_html_class( $class );
				if ( '' !== $class ) {
					$classes[] = $class;
				}
			}
		}

		return array(
			'rows'        => $rows,
			'cols'        => $cols,
			'columns'     => $columns,
			'rowOpts'     => $row_opts,
			'header'      => ! isset( $attrs['header'] ) || ! empty( $attrs['header'] ),
			'footer'      => ! empty( $attrs['footer'] ),
			'rowHeaders'  => ! empty( $attrs['rowHeaders'] ),
			'caption'     => isset( $attrs['caption'] ) ? self::inline_html( $attrs['caption'] ) : '',
			'note'        => isset( $attrs['note'] ) ? self::inline_html( $attrs['note'] ) : '',
			'mode'        => $pick( 'mode', 'mode' ),
			'breakpoint'  => $breakpoint > 0 ? Responsive_Tables_Plugin::clamp( $breakpoint, self::MIN_BREAKPOINT, self::MAX_BREAKPOINT, 600 ) : (int) $settings['breakpoint'],
			'style'       => $pick( 'tableStyle', 'style' ),
			'headerStyle' => $pick( 'headerStyle', 'header' ),
			'accent'      => $accent ? strtolower( $accent ) : (string) $settings['accent'],
			'sort'        => ! empty( $attrs['sort'] ),
			'search'      => ! empty( $attrs['search'] ),
			'paginate'    => ! empty( $attrs['paginate'] ),
			'perPage'     => Responsive_Tables_Plugin::clamp( isset( $attrs['perPage'] ) ? $attrs['perPage'] : 0, 1, self::MAX_PER_PAGE, (int) $settings['per_page'] ),
			'align'       => in_array( $align, array( 'wide', 'full', 'left', 'right', 'center' ), true ) ? $align : '',
			'className'   => implode( ' ', array_unique( $classes ) ),
			'shortcodes'  => ! empty( $settings['shortcodes'] ),
		);
	}

	/**
	 * The table's HTML.
	 *
	 * @param array<string,mixed> $t    Normalized table.
	 * @param array<string,mixed> $args See {@see table()}.
	 * @return string
	 */
	private static function markup( array $t, array $args ) {
		$uid    = 'rtbl-' . ( ++self::$count );
		$rows   = $t['rows'];
		$head   = $t['header'] ? $rows[0] : null;
		$first  = $t['header'] ? 1 : 0;
		$last   = count( $rows ) - 1;
		$foot   = $t['footer'] && $last >= $first + 1 ? $last : -1;
		$end    = -1 === $foot ? $last : $last - 1;
		$body   = $first <= $end ? range( $first, $end ) : array();
		$labels = array();

		for ( $c = 0; $c < $t['cols']; $c++ ) {
			$labels[ $c ] = null === $head ? '' : Responsive_Tables_Cells::text( $head[ $c ] );
		}

		$paged    = $t['paginate'] && count( $body ) > $t['perPage'];
		$scripted = $t['sort'] || $t['search'] || $paged;
		$sortable = array();

		if ( $t['sort'] && null !== $head ) {
			for ( $c = 0; $c < $t['cols']; $c++ ) {
				if ( '' !== $labels[ $c ] && ! preg_match( '#<(?:a|button|input|select|textarea)[\s>]#i', $head[ $c ] ) ) {
					$sortable[ $c ] = true;
				}
			}
		}

		if ( $scripted ) {
			wp_enqueue_script( self::HANDLE );
		}

		$classes = array(
			'rtbl',
			'rtbl--' . $t['mode'],
			'scroll' === $t['mode'] ? 'rtbl--wide' : 'rtbl--cards',
			'rtbl--' . $t['style'],
			'rtbl--head-' . $t['headerStyle'],
			'rtbl-bp-' . $t['breakpoint'],
		);

		if ( $t['rowHeaders'] ) {
			$classes[] = 'rtbl--row-headers';
		}
		if ( $t['align'] && empty( $args['wrapper'] ) ) {
			$classes[] = 'align' . $t['align'];
		}
		if ( '' !== $t['className'] && empty( $args['wrapper'] ) ) {
			$classes[] = $t['className'];
		}

		$style  = '--rtbl-accent:' . $t['accent'] . ';--rtbl-on-accent:' . self::on_color( $t['accent'] );
		$config = array(
			'sort'    => (bool) $sortable,
			'search'  => $t['search'],
			'perPage' => $t['paginate'] ? $t['perPage'] : 0,
		);

		if ( ! empty( $args['wrapper'] ) && function_exists( 'get_block_wrapper_attributes' ) ) {
			$wrapper = get_block_wrapper_attributes(
				array(
					'class' => implode( ' ', $classes ),
					'style' => $style,
				)
			);
		} else {
			$wrapper = 'class="' . esc_attr( implode( ' ', $classes ) ) . '" style="' . esc_attr( $style ) . '"';
		}

		$html  = self::stylesheet();
		$html .= self::breakpoint_style( $t['breakpoint'] );
		$html .= '<div ' . $wrapper . ' data-rtbl="' . esc_attr( (string) wp_json_encode( $config ) ) . '">';

		if ( $t['search'] || $sortable ) {
			$html .= self::tools( $uid, $t, $labels, $sortable );
		}

		$scroll_attrs = '';
		if ( 'scroll' === $t['mode'] ) {
			$region_label  = '' !== $t['caption'] ? wp_strip_all_tags( $t['caption'] ) : __( 'Table', 'responsive-tables' );
			$scroll_attrs  = ' tabindex="0" role="region" aria-label="' . esc_attr(
				/* translators: %s: table caption. */
				sprintf( __( '%s (scrolls sideways)', 'responsive-tables' ), $region_label )
			) . '"';
		}

		$html .= '<div class="rtbl-scroll"' . $scroll_attrs . '>';
		$html .= '<table class="rtbl-table" id="' . esc_attr( $uid ) . '" role="table">';

		if ( '' !== $t['caption'] ) {
			$html .= '<caption class="rtbl-caption">' . $t['caption'] . '</caption>';
		}

		if ( null !== $head ) {
			$html .= '<thead role="rowgroup"><tr role="row">';
			for ( $c = 0; $c < $t['cols']; $c++ ) {
				$html .= self::head_cell( $head[ $c ], $c, $t, isset( $sortable[ $c ] ) );
			}
			$html .= '</tr></thead>';
		}

		$html .= '<tbody role="rowgroup">';
		foreach ( $body as $position => $r ) {
			$html .= self::body_row( $rows[ $r ], $r, $t, $labels, $uid, $paged && $position >= $t['perPage'], true );
		}
		$html .= '</tbody>';

		if ( -1 !== $foot ) {
			$html .= '<tfoot role="rowgroup">' . self::body_row( $rows[ $foot ], $foot, $t, $labels, $uid, false, false ) . '</tfoot>';
		}

		$html .= '</table></div>';

		if ( $t['search'] ) {
			$html .= '<p class="rtbl-none" hidden>' . esc_html__( 'No matching rows.', 'responsive-tables' ) . '</p>';
		}

		if ( $paged ) {
			$html .= self::pages_nav( count( $body ), $t['perPage'] );
		}

		if ( $scripted ) {
			$html .= '<p class="rtbl-status rtbl-sr" aria-live="polite"></p>';
		}

		if ( '' !== $t['note'] ) {
			$html .= '<p class="rtbl-note">' . $t['note'] . '</p>';
		}

		$html .= '</div>';

		return $html;
	}

	/**
	 * One header cell.
	 *
	 * @param string              $raw      Cell content.
	 * @param int                 $c        Column index.
	 * @param array<string,mixed> $t        Normalized table.
	 * @param bool                $sortable Whether the column can be sorted.
	 * @return string
	 */
	private static function head_cell( $raw, $c, array $t, $sortable ) {
		$col     = $t['columns'][ $c ];
		$cell    = Responsive_Tables_Cells::render( $raw, $t['shortcodes'] );
		$classes = self::cell_classes( $c, $col, '' === $cell['html'] );
		$badge   = $col['highlight'] && '' !== $col['badge'] ? '<span class="rtbl-badge">' . esc_html( $col['badge'] ) . '</span>' : '';
		$inner   = $cell['html'];

		if ( $sortable ) {
			$inner = '<button type="button" class="rtbl-sort" data-col="' . (int) $c . '"><span class="rtbl-value">' . $inner . '</span><span class="rtbl-sort-ico" aria-hidden="true"></span></button>';
		} else {
			$inner = '<div class="rtbl-value">' . $inner . '</div>';
		}

		return '<th scope="col" role="columnheader" class="' . esc_attr( $classes ) . '">' . $badge . $inner . '</th>';
	}

	/**
	 * One body or footer row.
	 *
	 * @param string[]            $row    Cells.
	 * @param int                 $r      Row index in the full table.
	 * @param array<string,mixed> $t      Normalized table.
	 * @param string[]            $labels Column labels.
	 * @param string              $uid    Table ID.
	 * @param bool                $later  On a later page (hidden at first).
	 * @param bool                $body   In the body (gets the collapse toggle).
	 * @return string
	 */
	private static function body_row( array $row, $r, array $t, array $labels, $uid, $later, $body ) {
		$opts    = $t['rowOpts'][ $r ];
		$classes = array();

		if ( $opts['highlight'] ) {
			$classes[] = 'is-hl';
		}
		if ( $later ) {
			$classes[] = 'rtbl-later';
		}

		$html = '<tr role="row"' . ( $classes ? ' class="' . esc_attr( implode( ' ', $classes ) ) . '"' : '' ) . '>';

		for ( $c = 0; $c < $t['cols']; $c++ ) {
			$col      = $t['columns'][ $c ];
			$cell     = Responsive_Tables_Cells::render( $row[ $c ], $t['shortcodes'] );
			$is_title = 0 === $c && $t['rowHeaders'];
			$tag      = $is_title ? 'th' : 'td';
			$attrs    = $is_title ? ' scope="row" role="rowheader"' : ' role="cell"';
			$inner    = '';

			if ( null !== $cell['sort'] ) {
				$attrs .= ' data-sort="' . esc_attr( $cell['sort'] ) . '"';
			}

			if ( 0 === $c && $body && 'collapse' === $t['mode'] && $t['cols'] > 1 ) {
				$name   = Responsive_Tables_Cells::text( $row[0] );
				$label  = '' !== $name
					/* translators: %s: the row's first cell. */
					? sprintf( __( 'Details: %s', 'responsive-tables' ), $name )
					: __( 'Details', 'responsive-tables' );
				$inner .= '<input type="checkbox" class="rtbl-toggle" id="' . esc_attr( $uid . '-r' . $r ) . '" aria-label="' . esc_attr( $label ) . '">';
				$inner .= '<label class="rtbl-toggle-btn" for="' . esc_attr( $uid . '-r' . $r ) . '" aria-hidden="true">' . self::chevron() . '</label>';
			}

			if ( 0 === $c && $opts['highlight'] && '' !== $opts['badge'] ) {
				$inner .= '<span class="rtbl-badge">' . esc_html( $opts['badge'] ) . '</span>';
			}

			if ( '' !== $labels[ $c ] ) {
				$badge  = $col['highlight'] && '' !== $col['badge'] ? ' <span class="rtbl-badge">' . esc_html( $col['badge'] ) . '</span>' : '';
				$inner .= '<span class="rtbl-label" aria-hidden="true">' . esc_html( $labels[ $c ] ) . $badge . '</span>';
			}

			$inner  .= '<div class="rtbl-value">' . $cell['html'] . '</div>';
			$classes = self::cell_classes( $c, $col, '' === $cell['html'] ) . ( '' === $labels[ $c ] ? ' is-unlabeled' : '' );

			$html .= '<' . $tag . $attrs . ' class="' . esc_attr( $classes ) . '">' . $inner . '</' . $tag . '>';
		}

		return $html . '</tr>';
	}

	/**
	 * Classes for a cell.
	 *
	 * @param int                 $c     Column index.
	 * @param array<string,mixed> $col   Column options.
	 * @param bool                $empty Whether the cell is empty.
	 * @return string
	 */
	private static function cell_classes( $c, array $col, $empty ) {
		$classes = array( 'rtbl-c' . ( $c + 1 ) );

		if ( $col['highlight'] ) {
			$classes[] = 'is-hl';
		}
		if ( '' !== $col['align'] ) {
			$classes[] = 'rtbl-al-' . $col['align'];
		}
		if ( $col['hideMobile'] ) {
			$classes[] = 'rtbl-hide-m';
		}
		if ( $empty ) {
			$classes[] = 'is-empty';
		}

		return implode( ' ', $classes );
	}

	/**
	 * Search box, and the "Sort by" menu that stands in for the column
	 * headers in the card layouts.
	 *
	 * @param string              $uid      Table ID.
	 * @param array<string,mixed> $t        Normalized table.
	 * @param string[]            $labels   Column labels.
	 * @param array<int,bool>     $sortable Sortable columns.
	 * @return string
	 */
	private static function tools( $uid, array $t, array $labels, array $sortable ) {
		$html = '<div class="rtbl-tools">';

		if ( $t['search'] ) {
			$html .= '<div class="rtbl-search">'
				. '<label class="rtbl-sr" for="' . esc_attr( $uid ) . '-q">' . esc_html__( 'Search this table', 'responsive-tables' ) . '</label>'
				. '<svg class="rtbl-search-icon" viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="m21 21-4.34-4.34"/><circle cx="11" cy="11" r="8"/></svg>'
				. '<input type="search" class="rtbl-search-input" id="' . esc_attr( $uid ) . '-q" placeholder="' . esc_attr__( 'Search…', 'responsive-tables' ) . '" autocomplete="off" aria-controls="' . esc_attr( $uid ) . '">'
				. '</div>';
		}

		if ( $sortable && 'scroll' !== $t['mode'] ) {
			$html .= '<div class="rtbl-sortby">'
				. '<label for="' . esc_attr( $uid ) . '-s">' . esc_html__( 'Sort by', 'responsive-tables' ) . '</label>'
				. '<select class="rtbl-sortby-select" id="' . esc_attr( $uid ) . '-s" aria-controls="' . esc_attr( $uid ) . '">'
				. '<option value="">' . esc_html__( 'Original order', 'responsive-tables' ) . '</option>';

			foreach ( array_keys( $sortable ) as $c ) {
				/* translators: %s: column name. */
				$html .= '<option value="' . (int) $c . ':asc">' . esc_html( sprintf( __( '%s, ascending', 'responsive-tables' ), $labels[ $c ] ) ) . '</option>';
				/* translators: %s: column name. */
				$html .= '<option value="' . (int) $c . ':desc">' . esc_html( sprintf( __( '%s, descending', 'responsive-tables' ), $labels[ $c ] ) ) . '</option>';
			}

			$html .= '</select></div>';
		}

		return $html . '</div>';
	}

	/**
	 * The page links under a paged table, as they look on page 1. The script
	 * redraws them as visitors page, search and sort.
	 *
	 * @param int $total    Rows.
	 * @param int $per_page Rows per page.
	 * @return string
	 */
	private static function pages_nav( $total, $per_page ) {
		$pages = (int) ceil( $total / $per_page );
		$html  = '<nav class="rtbl-pages" aria-label="' . esc_attr__( 'Table pages', 'responsive-tables' ) . '">';
		$html .= '<span class="rtbl-pages-info">' . esc_html(
			sprintf(
				/* translators: 1: first row shown, 2: last row shown, 3: number of rows. */
				__( '%1$s–%2$s of %3$s', 'responsive-tables' ),
				number_format_i18n( 1 ),
				number_format_i18n( min( $per_page, $total ) ),
				number_format_i18n( $total )
			)
		) . '</span><span class="rtbl-pages-btns">';

		$html .= '<button type="button" class="rtbl-page rtbl-page-prev" data-page="prev" aria-label="' . esc_attr__( 'Previous page', 'responsive-tables' ) . '" disabled>' . self::chevron( 'left' ) . '</button>';

		$previous = 0;
		foreach ( self::page_list( 1, $pages ) as $page ) {
			if ( $page - $previous > 1 ) {
				$html .= '<span class="rtbl-page-gap" aria-hidden="true">…</span>';
			}
			$html    .= '<button type="button" class="rtbl-page' . ( 1 === $page ? ' is-current' : '' ) . '" data-page="' . (int) $page . '"' . ( 1 === $page ? ' aria-current="page"' : '' ) . ' aria-label="' . esc_attr(
				/* translators: %s: page number. */
				sprintf( __( 'Page %s', 'responsive-tables' ), number_format_i18n( $page ) )
			) . '">' . esc_html( number_format_i18n( $page ) ) . '</button>';
			$previous = $page;
		}

		$html .= '<button type="button" class="rtbl-page rtbl-page-next" data-page="next" aria-label="' . esc_attr__( 'Next page', 'responsive-tables' ) . '">' . self::chevron( 'right' ) . '</button>';

		return $html . '</span></nav>';
	}

	/**
	 * Page numbers to show: the first, the last, and the current one with its
	 * neighbours. table.js uses the same rule.
	 *
	 * @param int $current Current page.
	 * @param int $pages   Number of pages.
	 * @return int[]
	 */
	public static function page_list( $current, $pages ) {
		$list = array();

		for ( $page = 1; $page <= $pages; $page++ ) {
			if ( 1 === $page || $pages === $page || abs( $page - $current ) <= 1 || ( $current <= 3 && $page <= 4 ) || ( $current >= $pages - 2 && $page >= $pages - 3 ) ) {
				$list[] = $page;
			}
		}

		return $list;
	}

	/**
	 * A plain table for feeds: no styles, scripts or controls.
	 *
	 * @param array<string,mixed> $t Normalized table.
	 * @return string
	 */
	private static function feed_markup( array $t ) {
		$html = '<table>';

		if ( '' !== $t['caption'] ) {
			$html .= '<caption>' . $t['caption'] . '</caption>';
		}

		foreach ( $t['rows'] as $r => $row ) {
			$is_head = 0 === $r && $t['header'];
			$html   .= '<tr>';
			foreach ( $row as $raw ) {
				$cell  = Responsive_Tables_Cells::render( $raw, $t['shortcodes'] );
				$tag   = $is_head ? 'th' : 'td';
				$html .= '<' . $tag . '>' . $cell['html'] . '</' . $tag . '>';
			}
			$html .= '</tr>';
		}

		return $html . '</table>';
	}

	/**
	 * Make sure the stylesheet loads: enqueue it while <head> is still to
	 * come, or print it right here when it's already gone out.
	 *
	 * @return string A <link> tag, or an empty string.
	 */
	public static function stylesheet() {
		if ( wp_style_is( self::HANDLE, 'done' ) ) {
			return '';
		}

		if ( did_action( 'wp_head' ) && ! wp_doing_ajax() && ! ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			ob_start();
			wp_print_styles( array( self::HANDLE ) );
			return (string) ob_get_clean();
		}

		wp_enqueue_style( self::HANDLE );

		return '';
	}

	/**
	 * The mobile layout rules for one breakpoint, the first time it's used.
	 *
	 * @param int $breakpoint Breakpoint in px.
	 * @return string A <style> tag, or an empty string.
	 */
	public static function breakpoint_style( $breakpoint ) {
		$breakpoint = (int) $breakpoint;

		if ( isset( self::$printed[ $breakpoint ] ) ) {
			return '';
		}

		self::$printed[ $breakpoint ] = true;

		return '<style id="rtbl-css-' . $breakpoint . '">' . self::breakpoint_css( $breakpoint ) . '</style>';
	}

	/**
	 * CSS for one breakpoint. Only whole numbers go into it.
	 *
	 * @param int $breakpoint Breakpoint in px.
	 * @return string
	 */
	public static function breakpoint_css( $breakpoint ) {
		$bp    = (int) $breakpoint;
		$b     = '.rtbl-bp-' . $bp;
		$query = '(max-width:' . ( $bp - 1 ) . '.98px)';
		$cards = function ( $selectors ) use ( $b ) {
			$out = array();
			foreach ( (array) $selectors as $selector ) {
				$out[] = $b . '.rtbl--cards ' . $selector;
			}
			return implode( ',', $out );
		};

		$rules  = $cards( array( '.rtbl-table', '.rtbl-table>tbody', '.rtbl-table>tfoot', '.rtbl-table>caption' ) ) . '{display:block;width:100%;min-width:0;border:0;border-radius:0;background:none;box-shadow:none}';
		$rules .= $cards( '.rtbl-scroll' ) . '{overflow:visible;border:0;border-radius:0;box-shadow:none}';
		$rules .= $cards( '.rtbl-table>thead' ) . '{position:absolute!important;width:1px;height:1px;margin:-1px;padding:0;overflow:hidden;clip:rect(0,0,0,0);clip-path:inset(50%);white-space:nowrap;border:0}';
		$rules .= $cards( '.rtbl-table tr' ) . '{display:block;margin:0 0 .85em;border:1px solid var(--rtbl-line);border-radius:var(--rtbl-radius);overflow:hidden;background:var(--rtbl-card)}';
		$rules .= $cards( '.rtbl-table tr.is-hl' ) . '{border-color:var(--rtbl-accent);box-shadow:0 0 0 1px var(--rtbl-accent)}';
		$rules .= $cards( '.rtbl-table tr>*' ) . '{display:grid;grid-template-columns:minmax(5.5em,38%) minmax(0,1fr);gap:.15em 1em;align-items:baseline;width:auto;padding:.6em .95em;border:0;border-top:1px solid var(--rtbl-line);background:none;box-shadow:none;text-align:start}';
		$rules .= $cards( '.rtbl-table tr>.is-unlabeled' ) . '{display:block}';
		$rules .= $cards( array( '.rtbl-table tr>:first-child', '.rtbl-table tr.is-hl>:first-child' ) ) . '{display:block;border-top:0;background:var(--rtbl-soft);font-weight:700;font-size:1.05em}';
		$rules .= $cards( '.rtbl-table tr>.is-hl:not(:first-child)' ) . '{background:var(--rtbl-soft)}';
		$rules .= $cards( '.rtbl-table tr>:first-child .rtbl-label' ) . '{display:none}';
		$rules .= $cards( '.rtbl-label' ) . '{display:block}';
		$rules .= $cards( array( '.rtbl-table tr>.is-empty:not(:first-child)', '.rtbl-table .rtbl-hide-m' ) ) . '{display:none}';
		$rules .= $cards( '.rtbl-badge' ) . '{margin:0 0 .35em}';
		$rules .= $cards( '.rtbl-label .rtbl-badge' ) . '{margin:0 0 0 .4em}';
		$rules .= $cards( '.rtbl-sortby' ) . '{display:flex}';
		$rules .= $b . '.rtbl--collapse .rtbl-table>tbody>tr>:first-child{position:relative;padding-inline-end:3em}';

		// Collapse needs :has() to open a row from the checkbox in its first
		// cell. Browsers without it show every cell, like stack.
		$collapse  = $b . '.rtbl--collapse .rtbl-toggle{display:block}' . $b . '.rtbl--collapse .rtbl-toggle-btn{display:flex}';
		$collapse .= $b . '.rtbl--collapse .rtbl-table>tbody>tr:not(:has(>:first-child>.rtbl-toggle:checked))>:not(:first-child){display:none}';

		$css  = '@container rtbl ' . $query . '{' . $rules . '}';
		$css .= '@supports selector(:has(a)){@container rtbl ' . $query . '{' . $collapse . '}}';
		$css .= '@supports not (container-type:inline-size){@media ' . $query . '{' . $rules . '}}';
		$css .= $b . '.rtbl--scroll .rtbl-table{min-width:' . $bp . 'px}';

		return $css;
	}

	/**
	 * White or near-black, whichever reads better on the accent colour.
	 *
	 * @param string $hex "#rrggbb" or "#rgb".
	 * @return string
	 */
	public static function on_color( $hex ) {
		$hex = ltrim( $hex, '#' );

		if ( 3 === strlen( $hex ) ) {
			$hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
		}

		if ( 6 !== strlen( $hex ) ) {
			return '#fff';
		}

		$channels = array();
		foreach ( array( 0, 2, 4 ) as $offset ) {
			$value      = hexdec( substr( $hex, $offset, 2 ) ) / 255;
			$channels[] = $value <= 0.03928 ? $value / 12.92 : pow( ( $value + 0.055 ) / 1.055, 2.4 );
		}

		$luminance = 0.2126 * $channels[0] + 0.7152 * $channels[1] + 0.0722 * $channels[2];

		// Contrast against white vs. against #111.
		return ( 1.05 / ( $luminance + 0.05 ) ) >= ( ( $luminance + 0.05 ) / 0.0566 ) ? '#fff' : '#111';
	}

	/**
	 * Chevron icon.
	 *
	 * @param string $direction down, left or right.
	 * @return string
	 */
	private static function chevron( $direction = 'down' ) {
		$paths = array(
			'down'  => 'm6 9 6 6 6-6',
			'left'  => 'm15 18-6-6 6-6',
			'right' => 'm9 18 6-6-6-6',
		);

		return '<svg class="rtbl-chev" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="' . $paths[ $direction ] . '"/></svg>';
	}

	/**
	 * Caption and note: text with a few inline tags.
	 *
	 * @param mixed $value Raw value.
	 * @return string Safe HTML.
	 */
	private static function inline_html( $value ) {
		if ( ! is_scalar( $value ) ) {
			return '';
		}

		$allowed = array(
			'a'      => array(
				'href'   => true,
				'target' => true,
				'rel'    => true,
			),
			'strong' => array(),
			'b'      => array(),
			'em'     => array(),
			'i'      => array(),
			'code'   => array(),
			'br'     => array(),
			'span'   => array( 'class' => true ),
		);

		return trim( wp_kses( Responsive_Tables_Cells::clip( $value ), $allowed ) );
	}

	/**
	 * Badge text: plain, short.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	private static function short_text( $value ) {
		if ( ! is_scalar( $value ) ) {
			return '';
		}

		$text = sanitize_text_field( (string) $value );

		return function_exists( 'mb_substr' ) ? mb_substr( $text, 0, 40, 'UTF-8' ) : substr( $text, 0, 40 );
	}

	/**
	 * WP Rocket: don't delay or defer the table script.
	 *
	 * @param mixed $list Exclusions.
	 * @return mixed
	 */
	public static function exclude_script( $list ) {
		if ( is_array( $list ) ) {
			$list[] = 'responsive-tables/assets/table';
		}

		return $list;
	}

	/**
	 * WP Rocket Remove Unused CSS: keep the stylesheet whole, since some of
	 * its rules only match after a search, a sort or a tap.
	 *
	 * @param mixed $list Exclusions.
	 * @return mixed
	 */
	public static function exclude_stylesheet( $list ) {
		if ( is_array( $list ) ) {
			$list[] = '/responsive-tables/assets/table.css';
		}

		return $list;
	}

	/**
	 * WP Rocket Remove Unused CSS: keep the breakpoint <style> tags.
	 *
	 * @param mixed $list Exclusions.
	 * @return mixed
	 */
	public static function exclude_inline_style( $list ) {
		if ( is_array( $list ) ) {
			$list[] = 'rtbl-css-';
		}

		return $list;
	}
}
