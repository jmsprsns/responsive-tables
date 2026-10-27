<?php
/**
 * TablePress import, and keeping TablePress shortcodes working afterwards.
 *
 * Import copies each TablePress table into a saved table: its cells, header
 * and footer rows, first-column headers, name and description, alternating
 * row colours, and its sorting, search and pagination settings. TablePress's
 * own data is left untouched. Formulas are turned into their results when
 * TablePress is active during the import, and kept as text otherwise.
 *
 * Once a table is imported, [table id=N /], [table-info id=N field=name /]
 * and the TablePress block show the imported copy, whether or not TablePress
 * is still active. IDs that weren't imported are passed back to TablePress
 * (or whatever else registered the shortcode before).
 *
 * @package Responsive_Tables
 */

defined( 'ABSPATH' ) || exit;

/**
 * TablePress support.
 */
final class Responsive_Tables_TablePress {

	/**
	 * Option mapping TablePress table IDs to saved table post IDs.
	 */
	const MAP_OPTION = 'responsive_tables_tablepress_map';

	/**
	 * AJAX action (and nonce action) for imports.
	 */
	const AJAX = 'responsive_tables_tablepress_import';

	/**
	 * Tables imported per request, so a big import never times out.
	 */
	const BATCH = 5;

	/**
	 * Post meta holding the TablePress ID a saved table came from.
	 */
	const META = '_rtbl_tablepress_id';

	/**
	 * Shortcode handlers that were registered before ours.
	 *
	 * @var array<string,callable>
	 */
	private static $previous = array();

	/**
	 * Hook in.
	 *
	 * @return void
	 */
	public static function init() {
		// TablePress registers its shortcodes on init at priority 20.
		add_action( 'init', array( __CLASS__, 'shortcodes' ), 30 );
		add_filter( 'pre_render_block', array( __CLASS__, 'block' ), 10, 2 );
		add_action( 'wp_ajax_' . self::AJAX, array( __CLASS__, 'ajax_import' ) );
	}

	/**
	 * Whether TablePress shortcodes are ours to show.
	 *
	 * @return bool
	 */
	public static function takes_over() {
		return (bool) responsive_tables_plugin()->get_setting( 'tablepress' );
	}

	/**
	 * Take over [table] and [table-info].
	 *
	 * @return void
	 */
	public static function shortcodes() {
		global $shortcode_tags;

		if ( ! self::takes_over() ) {
			return;
		}

		foreach ( array(
			'table'      => 'shortcode_table',
			'table-info' => 'shortcode_info',
		) as $tag => $method ) {
			if ( isset( $shortcode_tags[ $tag ] ) && is_callable( $shortcode_tags[ $tag ] ) ) {
				self::$previous[ $tag ] = $shortcode_tags[ $tag ];
			}
			add_shortcode( $tag, array( __CLASS__, $method ) );
		}
	}

	/**
	 * [table id=N /].
	 *
	 * @param array|string $atts    Shortcode attributes.
	 * @param string|null  $content Enclosed content.
	 * @param string       $tag     Shortcode name.
	 * @return string
	 */
	public static function shortcode_table( $atts, $content = null, $tag = 'table' ) {
		$atts    = is_array( $atts ) ? $atts : array();
		$post_id = self::post_for( isset( $atts['id'] ) ? $atts['id'] : '' );

		if ( ! $post_id ) {
			return self::pass_on( 'table', $atts, $content, $tag );
		}

		return Responsive_Tables_Library::render_saved( $post_id, array(), self::overrides( $atts, $post_id ) );
	}

	/**
	 * [table-info id=N field=name|description|last_modified /].
	 *
	 * @param array|string $atts    Shortcode attributes.
	 * @param string|null  $content Enclosed content.
	 * @param string       $tag     Shortcode name.
	 * @return string
	 */
	public static function shortcode_info( $atts, $content = null, $tag = 'table-info' ) {
		$atts    = is_array( $atts ) ? $atts : array();
		$post_id = self::post_for( isset( $atts['id'] ) ? $atts['id'] : '' );

		if ( ! $post_id || ! Responsive_Tables_Library::can_use( $post_id ) ) {
			return self::pass_on( 'table-info', $atts, $content, $tag );
		}

		$post   = get_post( $post_id );
		$field  = isset( $atts['field'] ) ? strtolower( (string) $atts['field'] ) : '';
		$format = isset( $atts['format'] ) ? strtolower( (string) $atts['format'] ) : '';

		switch ( $field ) {
			case 'name':
				return esc_html( $post->post_title );

			case 'description':
				return esc_html( $post->post_excerpt );

			case 'last_modified':
				$time = (int) get_post_modified_time( 'U', true, $post );
				if ( 'raw' === $format || 'mysql' === $format ) {
					return esc_html( $post->post_modified );
				}
				if ( 'human' === $format ) {
					/* translators: %s: time span, e.g. "2 days". */
					return esc_html( sprintf( __( '%s ago', 'responsive-tables' ), human_time_diff( $time ) ) );
				}
				if ( 'date' === $format ) {
					return esc_html( wp_date( get_option( 'date_format' ), $time ) );
				}
				if ( 'time' === $format ) {
					return esc_html( wp_date( get_option( 'time_format' ), $time ) );
				}
				return esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $time ) );
		}

		return '';
	}

	/**
	 * The TablePress block, while TablePress is still active.
	 *
	 * @param string|null $pre   Output so far (null to render normally).
	 * @param array       $block Parsed block.
	 * @return string|null
	 */
	public static function block( $pre, $block ) {
		if ( null !== $pre || ! isset( $block['blockName'] ) || 'tablepress/table' !== $block['blockName'] || ! self::takes_over() ) {
			return $pre;
		}

		$id      = isset( $block['attrs']['id'] ) ? $block['attrs']['id'] : '';
		$post_id = self::post_for( $id );

		if ( ! $post_id ) {
			return $pre;
		}

		$atts = ! empty( $block['attrs']['parameters'] ) && is_string( $block['attrs']['parameters'] ) ? shortcode_parse_atts( $block['attrs']['parameters'] ) : array();

		return Responsive_Tables_Library::render_saved( $post_id, array(), self::overrides( is_array( $atts ) ? $atts : array(), $post_id ) );
	}

	/**
	 * Hand a shortcode we don't have a table for back to its earlier owner.
	 *
	 * @param string       $name    Shortcode we registered.
	 * @param array        $atts    Attributes.
	 * @param string|null  $content Enclosed content.
	 * @param string       $tag     Tag used.
	 * @return string
	 */
	private static function pass_on( $name, $atts, $content, $tag ) {
		if ( isset( self::$previous[ $name ] ) ) {
			return (string) call_user_func( self::$previous[ $name ], $atts, $content, $tag );
		}

		return '';
	}

	/**
	 * Saved table for a TablePress ID.
	 *
	 * @param mixed $tablepress_id TablePress table ID.
	 * @return int Post ID, or 0.
	 */
	public static function post_for( $tablepress_id ) {
		$tablepress_id = self::clean_id( $tablepress_id );

		if ( '' === $tablepress_id ) {
			return 0;
		}

		$map = self::map();

		if ( empty( $map[ $tablepress_id ] ) ) {
			return 0;
		}

		$post = get_post( (int) $map[ $tablepress_id ] );

		return $post && Responsive_Tables_Library::TYPE === $post->post_type && 'trash' !== $post->post_status ? $post->ID : 0;
	}

	/**
	 * TablePress ID to saved table, as stored.
	 *
	 * @return array<string,int>
	 */
	public static function map() {
		$map = get_option( self::MAP_OPTION, array() );

		return is_array( $map ) ? $map : array();
	}

	/**
	 * Shortcode attributes TablePress (and TablePress Pro) understood that
	 * have an equivalent here.
	 *
	 * @param array<string,mixed> $atts    Shortcode attributes.
	 * @param int                 $post_id Saved table.
	 * @return array<string,mixed> Attribute overrides.
	 */
	public static function overrides( array $atts, $post_id ) {
		$out  = array();
		$flag = function ( $key ) use ( $atts ) {
			if ( ! isset( $atts[ $key ] ) ) {
				return null;
			}
			return in_array( strtolower( trim( (string) $atts[ $key ] ) ), array( '1', 'true', 'yes', 'on' ), true );
		};

		if ( isset( $atts['responsive'] ) ) {
			$modes = array(
				'stack'    => 'stack',
				'flip'     => 'stack',
				'collapse' => 'collapse',
				'scroll'   => 'scroll',
			);
			$mode  = strtolower( (string) $atts['responsive'] );
			if ( isset( $modes[ $mode ] ) ) {
				$out['mode'] = $modes[ $mode ];
			}
		}

		if ( isset( $atts['responsive_breakpoint'] ) ) {
			$sizes = array(
				'phone'   => 768,
				'tablet'  => 980,
				'desktop' => 1200,
				'all'     => Responsive_Tables_Render::MAX_BREAKPOINT,
			);
			$size  = strtolower( (string) $atts['responsive_breakpoint'] );
			if ( isset( $sizes[ $size ] ) ) {
				$out['breakpoint'] = $sizes[ $size ];
			} elseif ( is_numeric( $size ) ) {
				$out['breakpoint'] = (int) $size;
			}
		}

		if ( false === $flag( 'use_datatables' ) ) {
			$out['sort']     = false;
			$out['search']   = false;
			$out['paginate'] = false;
		}

		foreach ( array(
			'datatables_sort'     => 'sort',
			'datatables_filter'   => 'search',
			'datatables_paginate' => 'paginate',
			'table_foot'          => 'footer',
			'first_column_th'     => 'rowHeaders',
		) as $key => $attr ) {
			if ( null !== $flag( $key ) ) {
				$out[ $attr ] = $flag( $key );
			}
		}

		if ( isset( $atts['table_head'] ) ) {
			$out['header'] = is_numeric( $atts['table_head'] ) ? (int) $atts['table_head'] > 0 : (bool) $flag( 'table_head' );
		}

		if ( isset( $atts['datatables_paginate_entries'] ) && is_numeric( $atts['datatables_paginate_entries'] ) ) {
			$out['perPage'] = (int) $atts['datatables_paginate_entries'];
		}

		if ( $flag( 'print_name' ) ) {
			$out['caption'] = esc_html( get_the_title( $post_id ) );
		}

		if ( ! empty( $atts['extra_css_classes'] ) ) {
			$out['className'] = (string) $atts['extra_css_classes'];
		}

		return $out;
	}

	/**
	 * TablePress tables in this site's database, whether or not TablePress is active.
	 *
	 * @return array<string,WP_Post> TablePress ID => its post.
	 */
	public static function found() {
		$raw   = get_option( 'tablepress_tables' );
		$data  = is_string( $raw ) ? json_decode( $raw, true ) : $raw;
		$pairs = is_array( $data ) && isset( $data['table_post'] ) && is_array( $data['table_post'] ) ? $data['table_post'] : array();
		$found = array();

		foreach ( $pairs as $tablepress_id => $post_id ) {
			$tablepress_id = self::clean_id( $tablepress_id );
			$post          = get_post( (int) $post_id );

			if ( '' !== $tablepress_id && $post && 'tablepress_table' === $post->post_type ) {
				$found[ $tablepress_id ] = $post;
			}
		}

		uksort( $found, 'strnatcasecmp' );

		return $found;
	}

	/**
	 * Rows and columns of a TablePress table.
	 *
	 * @param WP_Post $post TablePress post.
	 * @return int[]
	 */
	public static function size( $post ) {
		$data = json_decode( $post->post_content, true );

		if ( ! is_array( $data ) ) {
			return array( 0, 0 );
		}

		$cols = 0;
		foreach ( $data as $row ) {
			$cols = max( $cols, is_array( $row ) ? count( $row ) : 0 );
		}

		return array( count( $data ), $cols );
	}

	/**
	 * AJAX: import a batch of TablePress tables.
	 *
	 * @return void
	 */
	public static function ajax_import() {
		check_ajax_referer( self::AJAX, 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'You don\'t have permission to import tables.', 'responsive-tables' ) ), 403 );
		}

		$ids     = isset( $_POST['ids'] ) && is_array( $_POST['ids'] ) ? array_map( array( __CLASS__, 'clean_id' ), wp_unslash( $_POST['ids'] ) ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Each ID is cleaned by clean_id().
		$ids     = array_slice( array_values( array_filter( array_unique( $ids ), 'strlen' ) ), 0, self::BATCH );
		$replace = ! empty( $_POST['replace'] );
		$results = array();

		foreach ( $ids as $id ) {
			$results[] = self::import( $id, $replace );
		}

		if ( ! self::takes_over() && wp_list_filter( $results, array( 'status' => 'imported' ) ) ) {
			$settings               = responsive_tables_plugin()->get_settings();
			$settings['tablepress'] = 1;
			update_option( Responsive_Tables_Plugin::OPTION, $settings );
		}

		wp_send_json_success( array( 'results' => $results ) );
	}

	/**
	 * Import one TablePress table.
	 *
	 * @param string $tablepress_id TablePress table ID.
	 * @param bool   $replace       Replace an earlier import of it.
	 * @return array<string,mixed> Result for the import screen.
	 */
	public static function import( $tablepress_id, $replace = false ) {
		$found  = self::found();
		$result = array(
			'id'     => $tablepress_id,
			'status' => 'failed',
			'notes'  => array(),
		);

		if ( ! isset( $found[ $tablepress_id ] ) ) {
			$result['notes'][] = __( 'TablePress table not found.', 'responsive-tables' );
			return $result;
		}

		$existing = self::post_for( $tablepress_id );

		if ( $existing && ! $replace ) {
			$result['status']  = 'skipped';
			$result['post']    = $existing;
			$result['editUrl'] = get_edit_post_link( $existing, 'raw' );
			return $result;
		}

		$source = $found[ $tablepress_id ];
		$attrs  = self::convert( $source, $tablepress_id, $result['notes'] );

		if ( null === $attrs ) {
			$result['notes'][] = __( 'The table\'s data couldn\'t be read.', 'responsive-tables' );
			return $result;
		}

		$name    = trim( $source->post_title );
		$postarr = array(
			'post_type'    => Responsive_Tables_Library::TYPE,
			'post_status'  => 'publish',
			/* translators: %s: TablePress table ID. */
			'post_title'   => '' !== $name ? $name : sprintf( __( 'TablePress table %s', 'responsive-tables' ), $tablepress_id ),
			'post_excerpt' => $source->post_excerpt,
			'post_content' => get_comment_delimited_block_content( Responsive_Tables_Block::NAME, $attrs, '' ),
		);

		if ( $existing ) {
			$postarr['ID'] = $existing;
		}

		$post_id = wp_insert_post( wp_slash( $postarr ), true );

		if ( is_wp_error( $post_id ) || ! $post_id ) {
			$result['notes'][] = is_wp_error( $post_id ) ? $post_id->get_error_message() : __( 'The table couldn\'t be saved.', 'responsive-tables' );
			return $result;
		}

		update_post_meta( $post_id, self::META, $tablepress_id );

		$map                   = self::map();
		$map[ $tablepress_id ] = (int) $post_id;
		update_option( self::MAP_OPTION, $map, false );

		$result['status']  = 'imported';
		$result['post']    = (int) $post_id;
		$result['editUrl'] = get_edit_post_link( $post_id, 'raw' );

		return $result;
	}

	/**
	 * Block attributes for a TablePress table.
	 *
	 * @param WP_Post  $post          TablePress post.
	 * @param string   $tablepress_id TablePress table ID.
	 * @param string[] $notes         Notes for the import screen (by reference).
	 * @return array<string,mixed>|null
	 */
	public static function convert( $post, $tablepress_id, &$notes ) {
		$data = json_decode( $post->post_content, true );

		if ( ! is_array( $data ) || ! $data ) {
			return null;
		}

		$options    = json_decode( (string) get_post_meta( $post->ID, '_tablepress_table_options', true ), true );
		$options    = is_array( $options ) ? $options : array();
		$visibility = json_decode( (string) get_post_meta( $post->ID, '_tablepress_table_visibility', true ), true );
		$visibility = is_array( $visibility ) ? $visibility : array();
		$hidden_r   = isset( $visibility['rows'] ) && is_array( $visibility['rows'] ) ? $visibility['rows'] : array();
		$hidden_c   = isset( $visibility['columns'] ) && is_array( $visibility['columns'] ) ? $visibility['columns'] : array();
		$formulas   = 0;
		$data       = self::evaluate( $data, $tablepress_id, $formulas );
		$rows       = array();
		$merged     = 0;
		$skipped_r  = 0;
		$skipped_c  = 0;

		foreach ( array_values( $data ) as $r => $row ) {
			if ( isset( $hidden_r[ $r ] ) && 0 === (int) $hidden_r[ $r ] ) {
				++$skipped_r;
				continue;
			}

			$cells = array();
			foreach ( array_values( is_array( $row ) ? $row : array( $row ) ) as $c => $cell ) {
				if ( isset( $hidden_c[ $c ] ) && 0 === (int) $hidden_c[ $c ] ) {
					continue;
				}

				$cell = is_scalar( $cell ) ? (string) $cell : '';

				if ( in_array( trim( $cell ), array( '#colspan#', '#rowspan#', '#span#' ), true ) ) {
					++$merged;
					$cell = '';
				}

				$cells[] = $cell;
			}

			$rows[] = $cells;
		}

		foreach ( $hidden_c as $visible ) {
			if ( 0 === (int) $visible ) {
				++$skipped_c;
			}
		}

		$option = function ( $key, $fallback ) use ( $options ) {
			return array_key_exists( $key, $options ) ? $options[ $key ] : $fallback;
		};

		$datatables = (bool) $option( 'use_datatables', true );
		$per_page   = max( 1, min( Responsive_Tables_Render::MAX_PER_PAGE, (int) $option( 'datatables_paginate_entries', 10 ) ) );
		$attrs      = array(
			'rows'       => $rows,
			'header'     => (int) $option( 'table_head', 1 ) > 0,
			'footer'     => (int) $option( 'table_foot', 0 ) > 0,
			'rowHeaders' => (bool) $option( 'first_column_th', false ),
			'caption'    => $option( 'print_name', false ) ? $post->post_title : '',
			'note'       => $option( 'print_description', false ) ? $post->post_excerpt : '',
			'tableStyle' => $option( 'alternating_row_colors', true ) ? 'striped' : '',
			'sort'       => $datatables && (bool) $option( 'datatables_sort', true ),
			'search'     => $datatables && (bool) $option( 'datatables_filter', true ),
			'paginate'   => $datatables && (bool) $option( 'datatables_paginate', true ),
			'perPage'    => $per_page,
		);

		$classes = array_filter( array_map( 'sanitize_html_class', preg_split( '/\s+/', (string) $option( 'extra_css_classes', '' ) ) ) );
		if ( $classes ) {
			$attrs['className'] = implode( ' ', $classes );
		}

		if ( (int) $option( 'table_head', 1 ) > 1 ) {
			$notes[] = __( 'Only the first header row is used as the header; the others became ordinary rows.', 'responsive-tables' );
		}
		if ( $skipped_r ) {
			/* translators: %d: number of rows. */
			$notes[] = sprintf( _n( '%d hidden row was left out.', '%d hidden rows were left out.', $skipped_r, 'responsive-tables' ), $skipped_r );
		}
		if ( $skipped_c ) {
			/* translators: %d: number of columns. */
			$notes[] = sprintf( _n( '%d hidden column was left out.', '%d hidden columns were left out.', $skipped_c, 'responsive-tables' ), $skipped_c );
		}
		if ( $merged ) {
			/* translators: %d: number of cells. */
			$notes[] = sprintf( _n( '%d merged cell was split (merged cells don\'t reflow on phones).', '%d merged cells were split (merged cells don\'t reflow on phones).', $merged, 'responsive-tables' ), $merged );
		}
		if ( $formulas ) {
			/* translators: %d: number of cells. */
			$notes[] = sprintf( _n( '%d formula was kept as text. Activate TablePress and import again to keep its result.', '%d formulas were kept as text. Activate TablePress and import again to keep their results.', $formulas, 'responsive-tables' ), $formulas );
		}

		return $attrs;
	}

	/**
	 * Turn formulas into their results with TablePress's own evaluator, when
	 * TablePress is active. Otherwise count them.
	 *
	 * @param array  $data          Table data.
	 * @param string $tablepress_id TablePress table ID.
	 * @param int    $left          Formulas left unevaluated (by reference).
	 * @return array
	 */
	private static function evaluate( array $data, $tablepress_id, &$left ) {
		$count = 0;

		foreach ( $data as $row ) {
			foreach ( (array) $row as $cell ) {
				if ( is_string( $cell ) && '' !== $cell && '=' === $cell[0] && strlen( $cell ) > 1 ) {
					++$count;
				}
			}
		}

		if ( ! $count ) {
			return $data;
		}

		if ( class_exists( 'TablePress' ) && is_callable( array( 'TablePress', 'load_class' ) ) ) {
			try {
				$evaluator = TablePress::load_class( 'TablePress_Evaluate', 'class-evaluate.php', 'classes' );

				if ( is_object( $evaluator ) && method_exists( $evaluator, 'evaluate_table_data' ) ) {
					$result = $evaluator->evaluate_table_data( $data, (string) $tablepress_id );

					if ( is_array( $result ) ) {
						return $result;
					}
				}
			} catch ( \Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- Fall back to the raw formulas.
			}
		}

		$left = $count;

		return $data;
	}

	/**
	 * A TablePress table ID as text: letters, numbers, - and _ only.
	 *
	 * @param mixed $id Raw ID.
	 * @return string
	 */
	public static function clean_id( $id ) {
		if ( ! is_scalar( $id ) ) {
			return '';
		}

		$id = trim( (string) $id );

		return preg_match( '/^[A-Za-z0-9_-]{1,64}$/', $id ) ? $id : '';
	}
}
