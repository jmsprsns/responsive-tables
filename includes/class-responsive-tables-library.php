<?php
/**
 * Saved tables: tables kept outside any post, for reuse in several places
 * and for shortcodes. Tables imported from TablePress live here too.
 *
 * Each saved table is a private "rtbl_table" post whose content is a single
 * Responsive Table block, edited in the block editor like any post (the
 * block is locked in place). [responsive_table id="123"] shows one, and so
 * does the block when it points at one ("Use a saved table").
 *
 * Saved tables never have a public URL, and the REST API only shows them to
 * people who can edit them, so a draft table can't leak before it's used.
 *
 * @package Responsive_Tables
 */

defined( 'ABSPATH' ) || exit;

/**
 * Saved tables.
 */
final class Responsive_Tables_Library {

	/**
	 * Post type.
	 */
	const TYPE = 'rtbl_table';

	/**
	 * Shortcode.
	 */
	const SHORTCODE = 'responsive_table';

	/**
	 * Saved tables being drawn right now, so one can't contain itself.
	 *
	 * @var int[]
	 */
	private static $stack = array();

	/**
	 * Parsed attributes by post ID, for this request.
	 *
	 * @var array<int,array<string,mixed>|null>
	 */
	private static $cache = array();

	/**
	 * Hook in.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'register' ) );
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
		add_shortcode( self::SHORTCODE, array( __CLASS__, 'shortcode' ) );

		add_filter( 'parent_file', array( __CLASS__, 'parent_file' ) );
		add_filter( 'submenu_file', array( __CLASS__, 'submenu_file' ) );
		add_filter( 'enter_title_here', array( __CLASS__, 'title_placeholder' ), 10, 2 );
		add_action( 'save_post', array( __CLASS__, 'forget_usage' ) );
	}

	/**
	 * Register the post type.
	 *
	 * @return void
	 */
	public static function register() {
		register_post_type(
			self::TYPE,
			array(
				'labels'                => array(
					'name'                     => __( 'Saved Tables', 'responsive-tables' ),
					'singular_name'            => __( 'Saved Table', 'responsive-tables' ),
					'add_new'                  => __( 'Add New Table', 'responsive-tables' ),
					'add_new_item'             => __( 'Add New Table', 'responsive-tables' ),
					'edit_item'                => __( 'Edit Table', 'responsive-tables' ),
					'new_item'                 => __( 'New Table', 'responsive-tables' ),
					'search_items'             => __( 'Search Tables', 'responsive-tables' ),
					'not_found'                => __( 'No tables found.', 'responsive-tables' ),
					'not_found_in_trash'       => __( 'No tables found in Trash.', 'responsive-tables' ),
					'all_items'                => __( 'Saved Tables', 'responsive-tables' ),
					'item_published'           => __( 'Table saved.', 'responsive-tables' ),
					'item_published_privately' => __( 'Table saved privately.', 'responsive-tables' ),
					'item_reverted_to_draft'   => __( 'Table reverted to draft.', 'responsive-tables' ),
					'item_scheduled'           => __( 'Table scheduled.', 'responsive-tables' ),
					'item_updated'             => __( 'Table updated.', 'responsive-tables' ),
				),
				'public'                => false,
				'publicly_queryable'    => false,
				'exclude_from_search'   => true,
				'show_ui'               => true,
				'show_in_menu'          => false,
				'show_in_admin_bar'     => false,
				'show_in_nav_menus'     => false,
				'has_archive'           => false,
				'rewrite'               => false,
				'query_var'             => false,
				'can_export'            => true,
				'delete_with_user'      => false,
				'capability_type'       => 'page',
				'map_meta_cap'          => true,
				'hierarchical'          => false,
				'supports'              => array( 'title', 'editor', 'revisions' ),
				'show_in_rest'          => true,
				'rest_base'             => 'rtbl-tables',
				'rest_controller_class' => 'Responsive_Tables_REST_Controller',
				'template'              => array( array( Responsive_Tables_Block::NAME ) ),
				'template_lock'         => 'all',
			)
		);
	}

	/**
	 * REST routes the block uses to list saved tables and copy one.
	 *
	 * @return void
	 */
	public static function routes() {
		register_rest_route(
			'responsive-tables/v1',
			'/tables',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'rest_list' ),
				'permission_callback' => function () {
					return current_user_can( 'edit_posts' );
				},
			)
		);

		register_rest_route(
			'responsive-tables/v1',
			'/tables/(?P<id>\d+)',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'rest_get' ),
				'permission_callback' => function ( $request ) {
					return current_user_can( 'edit_posts' ) && Responsive_Tables_Library::can_use( (int) $request['id'] );
				},
				'args'                => array(
					'id' => array(
						'validate_callback' => function ( $value ) {
							return is_numeric( $value );
						},
					),
				),
			)
		);
	}

	/**
	 * REST: published saved tables, for the block's picker.
	 *
	 * @return WP_REST_Response
	 */
	public static function rest_list() {
		$tables = array();

		foreach ( self::all( 'publish', 300 ) as $post ) {
			$size     = self::size( $post );
			$tables[] = array(
				'id'    => $post->ID,
				'title' => self::title( $post ),
				'rows'  => $size[0],
				'cols'  => $size[1],
			);
		}

		return rest_ensure_response( $tables );
	}

	/**
	 * REST: one saved table's attributes, for "Make an editable copy".
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function rest_get( $request ) {
		$attrs = self::attributes_of( get_post( (int) $request['id'] ) );

		if ( null === $attrs ) {
			return new WP_Error( 'rtbl_not_found', __( 'That table couldn\'t be found.', 'responsive-tables' ), array( 'status' => 404 ) );
		}

		unset( $attrs['ref'] );

		return rest_ensure_response( $attrs );
	}

	/**
	 * Whether the current visitor may see a saved table: published ones are
	 * public once placed in a post; drafts only for people who can edit them.
	 *
	 * @param int $id Post ID.
	 * @return bool
	 */
	public static function can_use( $id ) {
		$post = get_post( $id );

		if ( ! $post || self::TYPE !== $post->post_type ) {
			return false;
		}

		return 'publish' === $post->post_status || current_user_can( 'edit_post', $post->ID );
	}

	/**
	 * Draw a saved table.
	 *
	 * @param int                 $id        Post ID.
	 * @param array<string,mixed> $args      Passed to Responsive_Tables_Render::table().
	 * @param array<string,mixed> $overrides Attributes that replace the saved ones.
	 * @return string
	 */
	public static function render_saved( $id, $args = array(), $overrides = array() ) {
		$id = (int) $id;

		if ( ! $id || in_array( $id, self::$stack, true ) || ! self::can_use( $id ) ) {
			return '';
		}

		$attrs = self::attributes_of( get_post( $id ) );

		if ( null === $attrs ) {
			return '';
		}

		self::$stack[] = $id;

		try {
			$html = Responsive_Tables_Render::table( array_merge( $attrs, $overrides ), $args );
		} finally {
			array_pop( self::$stack );
		}

		return $html;
	}

	/**
	 * [responsive_table id="123"]. Optional mode="stack|collapse|scroll" and
	 * breakpoint="700" override the table's own.
	 *
	 * @param array|string $atts Shortcode attributes.
	 * @return string
	 */
	public static function shortcode( $atts ) {
		$atts      = shortcode_atts(
			array(
				'id'         => 0,
				'mode'       => '',
				'breakpoint' => '',
			),
			$atts,
			self::SHORTCODE
		);
		$overrides = array();

		if ( in_array( $atts['mode'], Responsive_Tables_Plugin::choices()['mode'], true ) ) {
			$overrides['mode'] = $atts['mode'];
		}
		if ( is_numeric( $atts['breakpoint'] ) ) {
			$overrides['breakpoint'] = (int) $atts['breakpoint'];
		}

		return self::render_saved( absint( $atts['id'] ), array(), $overrides );
	}

	/**
	 * The attributes of the first Responsive Table block in a saved table.
	 *
	 * @param WP_Post|null $post Saved table.
	 * @return array<string,mixed>|null
	 */
	public static function attributes_of( $post ) {
		if ( ! $post instanceof WP_Post || self::TYPE !== $post->post_type ) {
			return null;
		}

		$key = $post->ID . ':' . md5( $post->post_content );

		if ( ! array_key_exists( $key, self::$cache ) ) {
			self::$cache[ $key ] = self::find_block( parse_blocks( $post->post_content ) );
		}

		return self::$cache[ $key ];
	}

	/**
	 * Find the first table block, looking inside nested blocks too.
	 *
	 * @param array $blocks Parsed blocks.
	 * @return array<string,mixed>|null
	 */
	private static function find_block( $blocks ) {
		foreach ( (array) $blocks as $block ) {
			if ( isset( $block['blockName'] ) && Responsive_Tables_Block::NAME === $block['blockName'] ) {
				$attrs = isset( $block['attrs'] ) && is_array( $block['attrs'] ) ? $block['attrs'] : array();
				unset( $attrs['ref'] );
				return $attrs;
			}

			if ( ! empty( $block['innerBlocks'] ) ) {
				$found = self::find_block( $block['innerBlocks'] );
				if ( null !== $found ) {
					return $found;
				}
			}
		}

		return null;
	}

	/**
	 * Saved tables, newest first.
	 *
	 * @param string|string[] $status Post statuses.
	 * @param int             $limit  Most to return.
	 * @return WP_Post[]
	 */
	public static function all( $status = array( 'publish', 'draft', 'pending', 'private', 'future' ), $limit = 500 ) {
		return get_posts(
			array(
				'post_type'        => self::TYPE,
				'post_status'      => $status,
				'numberposts'      => (int) $limit,
				'orderby'          => 'modified',
				'order'            => 'DESC',
				'suppress_filters' => true,
			)
		);
	}

	/**
	 * Rows and columns in a saved table.
	 *
	 * @param WP_Post $post Saved table.
	 * @return int[] Rows, columns.
	 */
	public static function size( $post ) {
		$attrs = self::attributes_of( $post );
		$rows  = $attrs && isset( $attrs['rows'] ) && is_array( $attrs['rows'] ) ? $attrs['rows'] : array();
		$cols  = 0;

		foreach ( $rows as $row ) {
			$cols = max( $cols, is_array( $row ) ? count( $row ) : 1 );
		}

		return array( count( $rows ), $cols );
	}

	/**
	 * A saved table's name, or a stand-in.
	 *
	 * @param WP_Post $post Saved table.
	 * @return string
	 */
	public static function title( $post ) {
		$title = trim( $post->post_title );

		/* translators: %d: table ID. */
		return '' !== $title ? $title : sprintf( __( 'Table #%d', 'responsive-tables' ), $post->ID );
	}

	/**
	 * Keep Content Powered > Responsive Tables open in the menu while a saved
	 * table is being edited.
	 *
	 * @param string $parent_file Parent menu slug.
	 * @return string
	 */
	public static function parent_file( $parent_file ) {
		return self::on_screen() ? Responsive_Tables_Menu::SLUG : $parent_file;
	}

	/**
	 * Highlight the Responsive Tables item while a saved table is being edited.
	 *
	 * @param string|null $submenu_file Submenu slug.
	 * @return string|null
	 */
	public static function submenu_file( $submenu_file ) {
		return self::on_screen() ? Responsive_Tables_Admin::PAGE : $submenu_file;
	}

	/**
	 * "Table name" in the title field.
	 *
	 * @param string  $text Placeholder.
	 * @param WP_Post $post Post being edited.
	 * @return string
	 */
	public static function title_placeholder( $text, $post ) {
		return $post instanceof WP_Post && self::TYPE === $post->post_type ? __( 'Table name', 'responsive-tables' ) : $text;
	}

	/**
	 * Forget the cached list of posts with tables after any post is saved.
	 *
	 * @return void
	 */
	public static function forget_usage() {
		delete_transient( 'responsive_tables_usage' );
	}

	/**
	 * Whether the current admin screen is a saved-table screen.
	 *
	 * @return bool
	 */
	private static function on_screen() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		return $screen && self::TYPE === $screen->post_type;
	}
}
