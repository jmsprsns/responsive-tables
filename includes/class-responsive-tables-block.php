<?php
/**
 * The Responsive Table block.
 *
 * A dynamic block: the table lives in the block's attributes and the server
 * draws it (Responsive_Tables_Render), so markup and security fixes reach
 * every table without re-saving posts. The editor (assets/block.js) is a
 * small spreadsheet that takes pastes from Excel and Google Sheets, imports
 * and exports CSV, and previews the table at desktop and phone width.
 *
 * @package Responsive_Tables
 */

defined( 'ABSPATH' ) || exit;

/**
 * Block registration and editor assets.
 */
final class Responsive_Tables_Block {

	/**
	 * Block name.
	 */
	const NAME = 'responsive-tables/table';

	/**
	 * Editor script and style handle.
	 */
	const EDITOR = 'responsive-tables-block';

	/**
	 * Hook in.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'register' ) );
		add_action( 'enqueue_block_editor_assets', array( __CLASS__, 'editor_data' ) );
	}

	/**
	 * Block attributes. Empty strings and 0 mean "use the site default"
	 * (Content Powered > Responsive Tables).
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public static function attributes() {
		$string  = array(
			'type'    => 'string',
			'default' => '',
		);
		$boolean = array(
			'type'    => 'boolean',
			'default' => false,
		);
		$objects = array(
			'type'    => 'array',
			'default' => array(),
			'items'   => array( 'type' => 'object' ),
		);

		return array(
			'rows'        => array(
				'type'    => 'array',
				'default' => array(),
				'items'   => array(
					'type'  => 'array',
					'items' => array( 'type' => 'string' ),
				),
			),
			'header'      => array(
				'type'    => 'boolean',
				'default' => true,
			),
			'footer'      => $boolean,
			'rowHeaders'  => $boolean,
			'caption'     => $string,
			'note'        => $string,
			'mode'        => $string,
			'breakpoint'  => array(
				'type'    => 'number',
				'default' => 0,
			),
			'tableStyle'  => $string,
			'headerStyle' => $string,
			'accent'      => $string,
			'sort'        => $boolean,
			'search'      => $boolean,
			'paginate'    => $boolean,
			'perPage'     => array(
				'type'    => 'number',
				'default' => 10,
			),
			'columns'     => $objects,
			'rowOpts'     => $objects,
			'ref'         => array(
				'type'    => 'number',
				'default' => 0,
			),
			// No defaults: WordPress adds the align and custom class names itself.
			'align'       => array( 'type' => 'string' ),
			'className'   => array( 'type' => 'string' ),
		);
	}

	/**
	 * Register the block and its editor assets.
	 *
	 * @return void
	 */
	public static function register() {
		if ( ! function_exists( 'register_block_type' ) ) {
			return;
		}

		wp_register_script(
			self::EDITOR,
			RESPONSIVE_TABLES_URL . 'assets/block.js',
			array( 'wp-blocks', 'wp-element', 'wp-components', 'wp-block-editor', 'wp-i18n', 'wp-api-fetch', 'wp-server-side-render', 'wp-data' ),
			RESPONSIVE_TABLES_VERSION,
			true
		);

		// Loaded into the editor (and its iframe) with the front-end styles,
		// so the preview matches the site.
		wp_register_style( self::EDITOR, RESPONSIVE_TABLES_URL . 'assets/block-editor.css', array( Responsive_Tables_Render::HANDLE ), RESPONSIVE_TABLES_VERSION );

		if ( function_exists( 'wp_set_script_translations' ) ) {
			wp_set_script_translations( self::EDITOR, 'responsive-tables' );
		}

		register_block_type(
			self::NAME,
			array(
				'api_version'     => 3,
				'editor_script'   => self::EDITOR,
				'editor_style'    => self::EDITOR,
				'render_callback' => array( __CLASS__, 'render' ),
				'attributes'      => self::attributes(),
				'supports'        => array(
					'align' => array( 'wide', 'full' ),
					'html'  => false,
				),
			)
		);
	}

	/**
	 * Draw the block.
	 *
	 * @param array<string,mixed> $attributes Block attributes.
	 * @return string
	 */
	public static function render( $attributes ) {
		$attributes = is_array( $attributes ) ? $attributes : array();

		if ( ! empty( $attributes['ref'] ) ) {
			return Responsive_Tables_Library::render_saved( (int) $attributes['ref'], array( 'wrapper' => true ) );
		}

		return Responsive_Tables_Render::table( $attributes, array( 'wrapper' => true ) );
	}

	/**
	 * Settings the editor needs: the site defaults and a few links.
	 *
	 * @return void
	 */
	public static function editor_data() {
		$settings = responsive_tables_plugin()->get_settings();
		$screen   = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		wp_localize_script(
			self::EDITOR,
			'rtblBlock',
			array(
				'defaults'    => array(
					'mode'        => $settings['mode'],
					'breakpoint'  => (int) $settings['breakpoint'],
					'tableStyle'  => $settings['style'],
					'headerStyle' => $settings['header'],
					'accent'      => $settings['accent'],
					'sort'        => (bool) $settings['sort'],
					'search'      => (bool) $settings['search'],
					'paginate'    => (bool) $settings['paginate'],
					'perPage'     => (int) $settings['per_page'],
				),
				'limits'      => array(
					'rows'       => Responsive_Tables_Render::MAX_ROWS,
					'cols'       => Responsive_Tables_Render::MAX_COLS,
					'minBp'      => Responsive_Tables_Render::MIN_BREAKPOINT,
					'maxBp'      => Responsive_Tables_Render::MAX_BREAKPOINT,
					'maxPerPage' => Responsive_Tables_Render::MAX_PER_PAGE,
				),
				'attributes'  => self::attributes(),
				'isLibrary'   => $screen && Responsive_Tables_Library::TYPE === $screen->post_type,
				'settingsUrl' => current_user_can( 'manage_options' ) ? admin_url( 'admin.php?page=' . Responsive_Tables_Admin::PAGE ) : '',
				'editUrl'     => admin_url( 'post.php?action=edit&post=' ),
			)
		);
	}
}
