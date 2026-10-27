<?php
/**
 * Admin side: the Content Powered > Responsive Tables page and the
 * Plugins-screen link.
 *
 * The page uses WordPress's own pieces: a header with tabs, the post
 * editor's two-column box layout (#poststuff, .postbox) and the admin
 * accent colour. The tabs are switched in the browser, so one form saves
 * every setting at once.
 *
 * @package Responsive_Tables
 */

defined( 'ABSPATH' ) || exit;

/**
 * Settings page controller.
 */
final class Responsive_Tables_Admin {

	/**
	 * Settings page slug, also used as the settings group.
	 */
	const PAGE = 'responsive-tables';

	/**
	 * Lucide icon shown beside the menu item and page title.
	 */
	const ICON = 'table-properties';

	/**
	 * Tabs and their icons.
	 */
	const TABS = array(
		'tables'     => 'table',
		'defaults'   => 'sliders-horizontal',
		'tablepress' => 'import',
		'help'       => 'life-buoy',
	);

	/**
	 * Transient caching the posts that contain tables.
	 */
	const USAGE = 'responsive_tables_usage';

	/**
	 * Plugin instance.
	 *
	 * @var Responsive_Tables_Plugin
	 */
	private $plugin;

	/**
	 * Hook suffix of the settings page.
	 *
	 * @var string
	 */
	private $hook_suffix = '';

	/**
	 * Constructor.
	 *
	 * @param Responsive_Tables_Plugin $plugin Plugin instance.
	 */
	public function __construct( Responsive_Tables_Plugin $plugin ) {
		$this->plugin = $plugin;

		Responsive_Tables_Menu::init();
		Responsive_Tables_Feedback::init();

		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_head', array( $this, 'print_menu_icon_style' ) );

		add_filter(
			'plugin_action_links_' . plugin_basename( RESPONSIVE_TABLES_FILE ),
			array( $this, 'add_settings_link' )
		);
	}

	/**
	 * Add a "Settings" link to the plugin's row on the Plugins screen.
	 *
	 * @param array $links Existing action links (Deactivate, etc.).
	 * @return array
	 */
	public function add_settings_link( $links ) {
		if ( ! is_array( $links ) ) {
			return $links;
		}

		$settings_link = sprintf(
			'<a href="%s">%s</a>',
			esc_url( admin_url( 'admin.php?page=' . self::PAGE ) ),
			esc_html__( 'Settings', 'responsive-tables' )
		);

		array_unshift( $links, $settings_link );

		return $links;
	}

	/**
	 * Add the page under the shared Content Powered menu.
	 *
	 * @return void
	 */
	public function register_menu() {
		$icon       = Responsive_Tables_Icons::get( self::ICON, 17, 'responsive-tables-menu-icon' );
		$menu_title = $icon . '<span class="responsive-tables-menu-label">' . esc_html__( 'Responsive Tables', 'responsive-tables' ) . '</span>';

		$this->hook_suffix = add_submenu_page(
			Responsive_Tables_Menu::SLUG,
			__( 'Responsive Tables', 'responsive-tables' ),
			$menu_title,
			'manage_options',
			self::PAGE,
			array( $this, 'render_page' )
		);

		if ( $this->hook_suffix ) {
			add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
			add_filter( 'admin_body_class', array( $this, 'body_class' ) );
		}
	}

	/**
	 * Mark the settings page's <body>, so its header can run edge to edge.
	 *
	 * @param string $classes Space-separated body classes.
	 * @return string
	 */
	public function body_class( $classes ) {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		return $screen && $screen->id === $this->hook_suffix ? $classes . ' rtbla-page' : $classes;
	}

	/**
	 * Register the setting.
	 *
	 * @return void
	 */
	public function register_settings() {
		register_setting(
			self::PAGE,
			Responsive_Tables_Plugin::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( 'Responsive_Tables_Plugin', 'sanitize_settings' ),
				'default'           => Responsive_Tables_Plugin::defaults(),
			)
		);
	}

	/**
	 * Tiny inline style so the menu icon sits neatly beside its label, even
	 * when a long plugin name wraps onto a second line.
	 *
	 * @return void
	 */
	public function print_menu_icon_style() {
		echo '<style id="responsive-tables-menu-icon-style">'
			. '#adminmenu a:has(>.responsive-tables-menu-icon){display:flex;align-items:flex-start;gap:7px}'
			. '#adminmenu .responsive-tables-menu-icon{flex:none;margin-top:1px;opacity:.7}'
			. '#adminmenu li.current .responsive-tables-menu-icon,#adminmenu a:hover .responsive-tables-menu-icon,#adminmenu a:focus .responsive-tables-menu-icon{opacity:1}'
			. '</style>';
	}

	/**
	 * Load the settings-page styles and scripts.
	 *
	 * @param string $hook Current admin page hook suffix.
	 * @return void
	 */
	public function enqueue_assets( $hook ) {
		if ( $hook !== $this->hook_suffix ) {
			return;
		}

		wp_enqueue_style( 'wp-color-picker' );
		wp_enqueue_style( Responsive_Tables_Render::HANDLE );
		wp_enqueue_style( 'responsive-tables-admin', RESPONSIVE_TABLES_URL . 'assets/admin.css', array( Responsive_Tables_Render::HANDLE ), RESPONSIVE_TABLES_VERSION );
		wp_enqueue_script( 'responsive-tables-admin', RESPONSIVE_TABLES_URL . 'assets/admin.js', array( 'jquery', 'wp-color-picker' ), RESPONSIVE_TABLES_VERSION, true );

		wp_localize_script(
			'responsive-tables-admin',
			'responsive_tables_admin',
			array(
				'ajaxUrl'    => admin_url( 'admin-ajax.php' ),
				'action'     => Responsive_Tables_TablePress::AJAX,
				'nonce'      => wp_create_nonce( Responsive_Tables_TablePress::AJAX ),
				'batch'      => Responsive_Tables_TablePress::BATCH,
				'tablesUrl'  => admin_url( 'admin.php?page=' . self::PAGE . '#tables' ),
				'i18n'       => array(
					'copied'     => __( 'Copied', 'responsive-tables' ),
					'importing'  => __( 'Importing…', 'responsive-tables' ),
					'imported'   => __( 'Imported', 'responsive-tables' ),
					'skipped'    => __( 'Already imported', 'responsive-tables' ),
					'failed'     => __( 'Failed', 'responsive-tables' ),
					'edit'       => __( 'Edit', 'responsive-tables' ),
					'again'      => __( 'Import again', 'responsive-tables' ),
					'error'      => __( 'The import stopped: the server didn\'t answer. Reload the page and try again.', 'responsive-tables' ),
					'confirm'    => __( 'Import this table again? Its earlier copy here is replaced, and any edits you made to that copy are lost.', 'responsive-tables' ),
					/* translators: 1: tables imported, 2: tables to import. */
					'progress'   => __( 'Imported %1$s of %2$s…', 'responsive-tables' ),
					/* translators: %s: number of tables. */
					'done'       => __( 'Done. %s tables imported, and their shortcodes now show the new tables.', 'responsive-tables' ),
					'bpChanged'  => __( 'Save to preview the new width.', 'responsive-tables' ),
				),
			)
		);
	}

	/**
	 * Inline icon.
	 *
	 * @param string $name Lucide icon.
	 * @param int    $size Size in px.
	 * @return string
	 */
	private static function icon( $name, $size = 18 ) {
		return Responsive_Tables_Icons::get( $name, $size );
	}

	/**
	 * Tab labels.
	 *
	 * @param string $id Tab.
	 * @return string
	 */
	private function tab_label( $id ) {
		$labels = array(
			'tables'     => __( 'Tables', 'responsive-tables' ),
			'defaults'   => __( 'Defaults', 'responsive-tables' ),
			'tablepress' => __( 'TablePress Import', 'responsive-tables' ),
			'help'       => __( 'Help', 'responsive-tables' ),
		);

		return isset( $labels[ $id ] ) ? $labels[ $id ] : $id;
	}

	/**
	 * The page's boxes, in order: the tab each one is on, its icon, title,
	 * intro and what draws it.
	 *
	 * @return array<string,array<string,string>>
	 */
	private function sections() {
		return array(
			'saved'      => array(
				'panel'  => 'tables',
				'icon'   => 'library',
				'title'  => __( 'Saved tables', 'responsive-tables' ),
				'intro'  => __( 'Most tables live right inside your posts: add the Responsive Table block anywhere in the block editor. Saved tables are for using one table in several places, or with a shortcode. Tables imported from TablePress are kept here too.', 'responsive-tables' ),
				'render' => 'render_saved',
			),
			'usage'      => array(
				'panel'  => 'tables',
				'icon'   => 'file-text',
				'title'  => __( 'Tables in your posts', 'responsive-tables' ),
				'intro'  => '',
				'render' => 'render_usage',
			),
			'layout'     => array(
				'panel'  => 'defaults',
				'icon'   => 'smartphone',
				'title'  => __( 'On phones', 'responsive-tables' ),
				'intro'  => __( 'How tables reorganize themselves when there isn\'t room for every column. Each table can pick its own layout in the block\'s settings; these are the defaults.', 'responsive-tables' ),
				'render' => 'render_layout',
			),
			'look'       => array(
				'panel'  => 'defaults',
				'icon'   => 'palette',
				'title'  => __( 'Look', 'responsive-tables' ),
				'intro'  => '',
				'render' => 'render_look',
			),
			'preview'    => array(
				'panel'  => 'defaults',
				'icon'   => 'eye',
				'title'  => __( 'Preview', 'responsive-tables' ),
				'intro'  => '',
				'render' => 'render_preview',
			),
			'features'   => array(
				'panel'  => 'defaults',
				'icon'   => 'arrow-up-down',
				'title'  => __( 'New tables start with', 'responsive-tables' ),
				'intro'  => __( 'Sorting, search and pages are set per table. New tables start with these, and tables you\'ve already made keep their own.', 'responsive-tables' ),
				'render' => 'render_features',
			),
			'cells'      => array(
				'panel'  => 'defaults',
				'icon'   => 'type',
				'title'  => __( 'Cell content', 'responsive-tables' ),
				'intro'  => '',
				'render' => 'render_cells',
			),
			'tp_import'  => array(
				'panel'  => 'tablepress',
				'icon'   => 'import',
				'title'  => __( 'Import from TablePress', 'responsive-tables' ),
				'intro'  => __( 'Copies each TablePress table into a saved table here: its cells, header and footer rows, name, description, row colors, and its sorting, search and pagination settings. TablePress\'s own tables aren\'t changed.', 'responsive-tables' ),
				'render' => 'render_tablepress',
			),
			'tp_codes'   => array(
				'panel'  => 'tablepress',
				'icon'   => 'brackets',
				'title'  => __( 'TablePress shortcodes', 'responsive-tables' ),
				'intro'  => '',
				'render' => 'render_tablepress_codes',
			),
			'start'      => array(
				'panel'  => 'help',
				'icon'   => 'rocket',
				'title'  => __( 'Getting started', 'responsive-tables' ),
				'intro'  => '',
				'render' => 'render_help_start',
			),
			'shortcuts'  => array(
				'panel'  => 'help',
				'icon'   => 'sparkles',
				'title'  => __( 'Cell shortcuts', 'responsive-tables' ),
				'intro'  => __( 'Type these in any cell, or use the buttons above the cells in the editor.', 'responsive-tables' ),
				'render' => 'render_help_shortcuts',
			),
			'keys'       => array(
				'panel'  => 'help',
				'icon'   => 'keyboard',
				'title'  => __( 'Editing like a spreadsheet', 'responsive-tables' ),
				'intro'  => '',
				'render' => 'render_help_keys',
			),
			'shortcodes' => array(
				'panel'  => 'help',
				'icon'   => 'code-xml',
				'title'  => __( 'Shortcodes and styling', 'responsive-tables' ),
				'intro'  => '',
				'render' => 'render_help_shortcodes',
			),
		);
	}

	/**
	 * Render the settings page: a header with the tabs, then WordPress's
	 * two-column box layout (settings on the left, Save and status on the right).
	 *
	 * @return void
	 */
	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		?>
		<div class="rtbla-header">
			<div class="rtbla-header-title">
				<span class="rtbla-header-icon"><?php echo self::icon( self::ICON, 20 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?></span>
				<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
				<span class="rtbla-version"><?php echo esc_html( 'v' . RESPONSIVE_TABLES_VERSION ); ?></span>
			</div>
			<nav class="rtbla-tabs" role="tablist" aria-label="<?php esc_attr_e( 'Settings sections', 'responsive-tables' ); ?>">
				<?php foreach ( self::TABS as $id => $icon ) : ?>
					<a href="#<?php echo esc_attr( $id ); ?>" class="rtbla-tab" role="tab" data-rtbla-tab="<?php echo esc_attr( $id ); ?>">
						<?php echo self::icon( $icon, 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?>
						<?php echo esc_html( $this->tab_label( $id ) ); ?>
					</a>
				<?php endforeach; ?>
			</nav>
		</div>

		<div class="wrap rtbla-wrap">
			<hr class="wp-header-end">

			<?php
			// Pages outside the Settings menu have to show "Settings saved." themselves.
			settings_errors();
			?>

			<form action="options.php" method="post" class="rtbla-form">
				<?php settings_fields( self::PAGE ); ?>

				<div id="poststuff">
					<div id="post-body" class="metabox-holder columns-2">
						<div id="post-body-content">
							<?php
							foreach ( $this->sections() as $id => $section ) {
								$this->render_section( $id, $section );
							}
							?>
						</div>

						<div id="postbox-container-1" class="postbox-container">
							<div class="rtbla-sidebar">
								<?php $this->render_sidebar(); ?>
							</div>
						</div>
					</div>
					<br class="clear">
				</div>
			</form>
		</div>
		<?php
	}

	/**
	 * One settings box.
	 *
	 * @param string               $id      Section ID.
	 * @param array<string,string> $section Section details from {@see sections()}.
	 * @return void
	 */
	private function render_section( $id, array $section ) {
		?>
		<div class="postbox rtbla-box" id="rtbla-<?php echo esc_attr( $id ); ?>" data-rtbla-panel="<?php echo esc_attr( $section['panel'] ); ?>">
			<div class="postbox-header">
				<h2 class="hndle">
					<?php echo self::icon( $section['icon'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?>
					<?php echo esc_html( $section['title'] ); ?>
				</h2>
			</div>
			<div class="inside">
				<?php if ( '' !== $section['intro'] ) : ?>
					<p class="rtbla-intro"><?php echo esc_html( $section['intro'] ); ?></p>
				<?php endif; ?>
				<?php call_user_func( array( $this, $section['render'] ) ); ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Name attribute for a setting.
	 *
	 * @param string $key Setting key.
	 * @return string
	 */
	private static function name( $key ) {
		return Responsive_Tables_Plugin::OPTION . '[' . $key . ']';
	}

	/**
	 * Checkbox with a label and an optional description.
	 *
	 * @param string $key   Setting key.
	 * @param string $label Label.
	 * @param string $desc  Description under it.
	 * @return void
	 */
	private function checkbox( $key, $label, $desc = '' ) {
		printf(
			'<label class="rtbla-check"><input type="checkbox" name="%1$s" value="1" %2$s data-rtbla-setting="%3$s"> <span>%4$s</span></label>',
			esc_attr( self::name( $key ) ),
			checked( 1, (int) $this->plugin->get_setting( $key ), false ),
			esc_attr( $key ),
			esc_html( $label )
		);

		if ( '' !== $desc ) {
			echo '<p class="description">' . esc_html( $desc ) . '</p>';
		}
	}

	/**
	 * Picture cards for a choice setting.
	 *
	 * @param string                          $key     Setting key.
	 * @param array<string,array<int,string>> $options Value => array( icon, title, description ).
	 * @param string                          $class   Extra class for the group.
	 * @return void
	 */
	private function option_cards( $key, array $options, $class = '' ) {
		$current = (string) $this->plugin->get_setting( $key );

		echo '<div class="rtbla-options ' . esc_attr( $class ) . '" role="radiogroup">';

		foreach ( $options as $value => $option ) {
			printf(
				'<label class="rtbla-option"><input type="radio" name="%1$s" value="%2$s" %3$s data-rtbla-setting="%4$s"><span class="rtbla-option-icon">%5$s</span><span class="rtbla-option-text"><strong>%6$s</strong>%7$s</span></label>',
				esc_attr( self::name( $key ) ),
				esc_attr( $value ),
				checked( $current, $value, false ),
				esc_attr( $key ),
				self::icon( $option[0], 18 ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG.
				esc_html( $option[1] ),
				'' !== $option[2] ? '<span>' . esc_html( $option[2] ) . '</span>' : ''
			);
		}

		echo '</div>';
	}

	/**
	 * Form table row.
	 *
	 * @param string   $label     Row label.
	 * @param callable $content   Prints the row's controls.
	 * @param string   $label_for ID the label points at.
	 * @return void
	 */
	private function row( $label, $content, $label_for = '' ) {
		echo '<tr><th scope="row">';
		echo '' !== $label_for ? '<label for="' . esc_attr( $label_for ) . '">' . esc_html( $label ) . '</label>' : esc_html( $label );
		echo '</th><td>';
		call_user_func( $content );
		echo '</td></tr>';
	}

	/**
	 * Name of a phone layout.
	 *
	 * @param string $mode stack, collapse or scroll.
	 * @return string
	 */
	public static function mode_label( $mode ) {
		$labels = array(
			'stack'    => __( 'Stack', 'responsive-tables' ),
			'collapse' => __( 'Collapse', 'responsive-tables' ),
			'scroll'   => __( 'Scroll', 'responsive-tables' ),
		);

		return isset( $labels[ $mode ] ) ? $labels[ $mode ] : $mode;
	}

	/**
	 * Tables tab: the saved tables.
	 *
	 * @return void
	 */
	public function render_saved() {
		$tables  = Responsive_Tables_Library::all();
		$type    = get_post_type_object( Responsive_Tables_Library::TYPE );
		$can_add = $type && current_user_can( $type->cap->create_posts );
		$trashed = (int) wp_count_posts( Responsive_Tables_Library::TYPE )->trash;
		?>
		<div class="rtbla-actions">
			<?php if ( $can_add ) : ?>
				<a class="button button-primary" href="<?php echo esc_url( admin_url( 'post-new.php?post_type=' . Responsive_Tables_Library::TYPE ) ); ?>"><?php echo self::icon( 'plus', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?><?php esc_html_e( 'Add New Table', 'responsive-tables' ); ?></a>
			<?php endif; ?>
			<?php if ( $tables || $trashed ) : ?>
				<a class="button" href="<?php echo esc_url( admin_url( 'edit.php?post_type=' . Responsive_Tables_Library::TYPE ) ); ?>"><?php esc_html_e( 'Manage all', 'responsive-tables' ); ?></a>
			<?php endif; ?>
		</div>

		<?php if ( ! $tables ) : ?>
			<div class="rtbla-empty">
				<span class="rtbla-empty-icon"><?php echo self::icon( 'table-2', 28 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?></span>
				<p><strong><?php esc_html_e( 'No saved tables yet.', 'responsive-tables' ); ?></strong></p>
				<p><?php esc_html_e( 'To add a table to a post, open it in the block editor, press +, and choose Responsive Table. Coming from TablePress? The TablePress Import tab brings your tables over in one click.', 'responsive-tables' ); ?></p>
			</div>
			<?php
			return;
		endif;
		?>

		<table class="widefat striped rtbla-table">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Name', 'responsive-tables' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Shortcode', 'responsive-tables' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Size', 'responsive-tables' ); ?></th>
					<th scope="col"><?php esc_html_e( 'On phones', 'responsive-tables' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Updated', 'responsive-tables' ); ?></th>
					<th scope="col" class="rtbla-actions-cell"><span class="screen-reader-text"><?php esc_html_e( 'Actions', 'responsive-tables' ); ?></span></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $tables as $post ) : ?>
					<?php
					$size      = Responsive_Tables_Library::size( $post );
					$attrs     = Responsive_Tables_Library::attributes_of( $post );
					$mode      = $attrs && ! empty( $attrs['mode'] ) ? (string) $attrs['mode'] : (string) $this->plugin->get_setting( 'mode' );
					$from_tp   = get_post_meta( $post->ID, Responsive_Tables_TablePress::META, true );
					$shortcode = '[' . Responsive_Tables_Library::SHORTCODE . ' id="' . $post->ID . '"]';
					$can_edit  = current_user_can( 'edit_post', $post->ID );
					?>
					<tr>
						<td>
							<?php if ( $can_edit ) : ?>
								<a class="rtbla-name" href="<?php echo esc_url( (string) get_edit_post_link( $post->ID ) ); ?>"><?php echo esc_html( Responsive_Tables_Library::title( $post ) ); ?></a>
							<?php else : ?>
								<span class="rtbla-name"><?php echo esc_html( Responsive_Tables_Library::title( $post ) ); ?></span>
							<?php endif; ?>
							<?php if ( 'publish' !== $post->post_status ) : ?>
								<span class="rtbla-tag is-none"><?php echo esc_html( get_post_status_object( $post->post_status ) ? get_post_status_object( $post->post_status )->label : $post->post_status ); ?></span>
							<?php endif; ?>
							<?php if ( '' !== (string) $from_tp ) : ?>
								<span class="rtbla-tag is-info">
									<?php
									/* translators: %s: TablePress table ID. */
									echo esc_html( sprintf( __( 'TablePress #%s', 'responsive-tables' ), $from_tp ) );
									?>
								</span>
							<?php endif; ?>
						</td>
						<td>
							<span class="rtbla-code">
								<code><?php echo esc_html( $shortcode ); ?></code>
								<button type="button" class="button-link rtbla-copy" data-copy="<?php echo esc_attr( $shortcode ); ?>" aria-label="<?php esc_attr_e( 'Copy shortcode', 'responsive-tables' ); ?>"><?php echo self::icon( 'copy', 15 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?></button>
							</span>
						</td>
						<td class="rtbla-num">
							<?php
							/* translators: 1: rows, 2: columns. */
							echo esc_html( sprintf( __( '%1$s × %2$s', 'responsive-tables' ), number_format_i18n( $size[0] ), number_format_i18n( $size[1] ) ) );
							?>
						</td>
						<td><?php echo esc_html( self::mode_label( $mode ) ); ?></td>
						<td class="rtbla-muted">
							<?php
							/* translators: %s: time span, e.g. "2 days". */
							echo esc_html( sprintf( __( '%s ago', 'responsive-tables' ), human_time_diff( (int) get_post_modified_time( 'U', true, $post ) ) ) );
							?>
						</td>
						<td class="rtbla-actions-cell">
							<?php if ( $can_edit ) : ?>
								<a class="button button-small" href="<?php echo esc_url( (string) get_edit_post_link( $post->ID ) ); ?>"><?php echo self::icon( 'pencil', 14 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?><?php esc_html_e( 'Edit', 'responsive-tables' ); ?></a>
							<?php endif; ?>
							<?php if ( current_user_can( 'delete_post', $post->ID ) ) : ?>
								<a class="button button-small rtbla-delete" href="<?php echo esc_url( (string) get_delete_post_link( $post->ID ) ); ?>" aria-label="<?php echo esc_attr( sprintf( /* translators: %s: table name. */ __( 'Move %s to the Trash', 'responsive-tables' ), Responsive_Tables_Library::title( $post ) ) ); ?>"><?php echo self::icon( 'trash-2', 14 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?></a>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php if ( $trashed ) : ?>
			<p class="rtbla-muted rtbla-below">
				<a href="<?php echo esc_url( admin_url( 'edit.php?post_status=trash&post_type=' . Responsive_Tables_Library::TYPE ) ); ?>">
					<?php
					/* translators: %s: number of tables. */
					echo esc_html( sprintf( _n( '%s table in the Trash', '%s tables in the Trash', $trashed, 'responsive-tables' ), number_format_i18n( $trashed ) ) );
					?>
				</a>
			</p>
		<?php endif; ?>
		<?php
	}

	/**
	 * Posts and pages that contain tables, newest first (cached for 10 minutes,
	 * and cleared whenever a post is saved).
	 *
	 * @return array{total:int,posts:array<int,array<string,mixed>>}
	 */
	public static function usage() {
		global $wpdb;

		$cached = get_transient( self::USAGE );

		if ( is_array( $cached ) && isset( $cached['total'], $cached['posts'] ) ) {
			return $cached;
		}

		$block = '%' . $wpdb->esc_like( '<!-- wp:' . Responsive_Tables_Block::NAME ) . '%';
		$code  = '%' . $wpdb->esc_like( '[' . Responsive_Tables_Library::SHORTCODE ) . '%';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Cached in a transient above.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT ID, post_title, post_type, post_status, post_content FROM {$wpdb->posts}
				WHERE post_status IN ( 'publish', 'future', 'draft', 'pending', 'private' )
				AND post_type NOT IN ( 'revision', 'customize_changeset', 'oembed_cache', 'wp_global_styles', %s )
				AND ( post_content LIKE %s OR post_content LIKE %s )
				ORDER BY post_modified DESC
				LIMIT 200",
				Responsive_Tables_Library::TYPE,
				$block,
				$code
			)
		);

		$usage = array(
			'total' => 0,
			'posts' => array(),
		);

		foreach ( (array) $rows as $row ) {
			$count = substr_count( $row->post_content, '<!-- wp:' . Responsive_Tables_Block::NAME . ' ' ) + substr_count( $row->post_content, '<!-- wp:' . Responsive_Tables_Block::NAME . ' /-->' ) + substr_count( $row->post_content, '[' . Responsive_Tables_Library::SHORTCODE );

			$usage['total'] += max( 1, $count );

			if ( count( $usage['posts'] ) < 12 ) {
				$usage['posts'][] = array(
					'id'     => (int) $row->ID,
					'title'  => $row->post_title,
					'type'   => $row->post_type,
					'status' => $row->post_status,
					'count'  => max( 1, $count ),
				);
			}
		}

		$usage['pages'] = count( (array) $rows );

		set_transient( self::USAGE, $usage, 10 * MINUTE_IN_SECONDS );

		return $usage;
	}

	/**
	 * Tables tab: where tables are used.
	 *
	 * @return void
	 */
	public function render_usage() {
		$usage = self::usage();
		$pages = isset( $usage['pages'] ) ? (int) $usage['pages'] : count( $usage['posts'] );

		if ( ! $usage['posts'] ) {
			echo '<p class="rtbla-intro">' . esc_html__( 'No posts or pages have a Responsive Table yet.', 'responsive-tables' ) . '</p>';
			return;
		}
		?>
		<p class="rtbla-intro">
			<?php
			echo esc_html(
				sprintf(
					/* translators: 1: number of tables, 2: number of posts. */
					_n( '%1$s table across %2$s post or page.', '%1$s tables across %2$s posts and pages.', $usage['total'], 'responsive-tables' ),
					number_format_i18n( $usage['total'] ),
					number_format_i18n( $pages )
				)
			);
			?>
		</p>
		<ul class="rtbla-usage">
			<?php foreach ( $usage['posts'] as $item ) : ?>
				<?php
				$type  = get_post_type_object( $item['type'] );
				$link  = current_user_can( 'edit_post', $item['id'] ) ? get_edit_post_link( $item['id'] ) : '';
				$title = '' !== trim( $item['title'] ) ? $item['title'] : __( '(no title)', 'responsive-tables' );
				?>
				<li>
					<?php echo self::icon( 'page' === $item['type'] ? 'file' : 'file-text', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?>
					<?php if ( $link ) : ?>
						<a href="<?php echo esc_url( $link ); ?>"><?php echo esc_html( $title ); ?></a>
					<?php else : ?>
						<span><?php echo esc_html( $title ); ?></span>
					<?php endif; ?>
					<span class="rtbla-muted"><?php echo esc_html( $type ? $type->labels->singular_name : $item['type'] ); ?><?php echo 'publish' !== $item['status'] ? ' · ' . esc_html( ( get_post_status_object( $item['status'] ) ? get_post_status_object( $item['status'] )->label : $item['status'] ) ) : ''; ?></span>
					<span class="rtbla-count">
						<?php
						/* translators: %s: number of tables. */
						echo esc_html( sprintf( _n( '%s table', '%s tables', $item['count'], 'responsive-tables' ), number_format_i18n( $item['count'] ) ) );
						?>
					</span>
				</li>
			<?php endforeach; ?>
		</ul>
		<?php
	}

	/**
	 * Defaults tab: the phone layout and breakpoint.
	 *
	 * @return void
	 */
	public function render_layout() {
		$this->option_cards(
			'mode',
			array(
				'stack'    => array( 'layers', __( 'Stack', 'responsive-tables' ), __( 'Each row becomes a card, with the column headers as labels beside each value. Best for comparisons and product tables.', 'responsive-tables' ) ),
				'collapse' => array( 'chevrons-down-up', __( 'Collapse', 'responsive-tables' ), __( 'Rows shrink to their first column and open with a tap. Best for long tables people scan, like schedules and directories.', 'responsive-tables' ) ),
				'scroll'   => array( 'arrow-left-right', __( 'Scroll', 'responsive-tables' ), __( 'The table keeps its shape and scrolls sideways, with the first column frozen. Best for wide grids of numbers.', 'responsive-tables' ) ),
			),
			'is-modes'
		);
		?>
		<table class="form-table" role="presentation">
			<?php
			$this->row(
				__( 'Switch below', 'responsive-tables' ),
				function () {
					printf(
						'<span class="rtbla-inline"><input type="number" class="small-text" id="rtbla-breakpoint" name="%1$s" value="%2$d" min="%3$d" max="%4$d" step="10" data-rtbla-setting="breakpoint"> px</span>',
						esc_attr( self::name( 'breakpoint' ) ),
						(int) $this->plugin->get_setting( 'breakpoint' ),
						(int) Responsive_Tables_Render::MIN_BREAKPOINT,
						(int) Responsive_Tables_Render::MAX_BREAKPOINT
					);
					echo '<p class="description">' . esc_html__( 'A table switches to its phone layout when the space it has is narrower than this, on any screen: a phone, or a narrow column on a desktop. 600 px suits most tables; try 768 for tables with six or more columns. In Scroll mode, columns stop shrinking at this width.', 'responsive-tables' ) . '</p>';
				},
				'rtbla-breakpoint'
			);
			?>
		</table>
		<?php
	}

	/**
	 * Defaults tab: style, header and accent colour.
	 *
	 * @return void
	 */
	public function render_look() {
		?>
		<table class="form-table" role="presentation">
			<?php
			$this->row(
				__( 'Style', 'responsive-tables' ),
				function () {
					$this->option_cards(
						'style',
						array(
							'clean'    => array( 'table-2', __( 'Clean', 'responsive-tables' ), '' ),
							'striped'  => array( 'rows-3', __( 'Striped rows', 'responsive-tables' ), '' ),
							'bordered' => array( 'table', __( 'Bordered', 'responsive-tables' ), '' ),
							'minimal'  => array( 'rectangle-ellipsis', __( 'Minimal', 'responsive-tables' ), '' ),
						),
						'is-compact'
					);
				}
			);
			$this->row(
				__( 'Header row', 'responsive-tables' ),
				function () {
					$this->option_cards(
						'header',
						array(
							'light' => array( 'panel-left', __( 'Light tint', 'responsive-tables' ), '' ),
							'solid' => array( 'square-check', __( 'Solid color', 'responsive-tables' ), '' ),
							'plain' => array( 'type', __( 'Plain', 'responsive-tables' ), '' ),
						),
						'is-compact'
					);
				}
			);
			$this->row(
				__( 'Accent color', 'responsive-tables' ),
				function () {
					printf(
						'<input type="text" class="rtbla-color" id="rtbla-accent" name="%1$s" value="%2$s" data-default-color="%3$s" data-rtbla-setting="accent">',
						esc_attr( self::name( 'accent' ) ),
						esc_attr( (string) $this->plugin->get_setting( 'accent' ) ),
						esc_attr( Responsive_Tables_Plugin::defaults()['accent'] )
					);
					echo '<p class="description">' . esc_html__( 'Used for the header, highlighted columns and rows, badges, buttons and page links. Text on it switches between white and black so it stays readable.', 'responsive-tables' ) . '</p>';
				},
				'rtbla-accent'
			);
			?>
		</table>
		<?php
	}

	/**
	 * Defaults tab: a sample table at desktop and phone width, redrawn as the
	 * settings change.
	 *
	 * @return void
	 */
	public function render_preview() {
		$attrs = array(
			'rows'       => array(
				array( __( 'Plan', 'responsive-tables' ), __( 'Rating', 'responsive-tables' ), __( 'Storage', 'responsive-tables' ), __( 'Support', 'responsive-tables' ), __( 'Price', 'responsive-tables' ) ),
				array( __( 'Starter', 'responsive-tables' ), '{stars 4.2}', '10 GB', '{no}', '$9' ),
				array( __( 'Pro', 'responsive-tables' ), '{stars 4.8}', '100 GB', '{yes}', '$29' ),
				array( __( 'Business', 'responsive-tables' ), '{stars 4.6}', '1 TB', '{yes}', '$79' ),
			),
			'rowHeaders' => true,
			'rowOpts'    => array(
				array(),
				array(),
				array(
					'highlight' => true,
					'badge'     => __( 'Best value', 'responsive-tables' ),
				),
			),
		);
		?>
		<div class="rtbla-preview" data-rtbla-preview>
			<div class="rtbla-preview-frame is-desktop">
				<span class="rtbla-preview-label"><?php echo self::icon( 'monitor', 15 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?><?php esc_html_e( 'Desktop', 'responsive-tables' ); ?></span>
				<?php
				// The smallest breakpoint, so this frame always shows the desktop layout.
				echo Responsive_Tables_Render::table( array_merge( $attrs, array( 'breakpoint' => Responsive_Tables_Render::MIN_BREAKPOINT ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built and escaped by the renderer.
				?>
			</div>
			<div class="rtbla-preview-frame is-phone">
				<span class="rtbla-preview-label"><?php echo self::icon( 'smartphone', 15 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?><?php esc_html_e( 'Phone', 'responsive-tables' ); ?></span>
				<div class="rtbla-phone">
					<?php echo Responsive_Tables_Render::table( $attrs ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built and escaped by the renderer. ?>
				</div>
			</div>
		</div>
		<p class="rtbla-muted rtbla-preview-note" data-rtbla-bp-note hidden></p>
		<?php
	}

	/**
	 * Defaults tab: what new tables start with.
	 *
	 * @return void
	 */
	public function render_features() {
		?>
		<div class="rtbla-checks">
			<?php
			$this->checkbox( 'sort', __( 'Visitors can sort by any column', 'responsive-tables' ) );
			$this->checkbox( 'search', __( 'A search box above the table', 'responsive-tables' ) );
			$this->checkbox( 'paginate', __( 'Long tables split into pages', 'responsive-tables' ) );
			?>
		</div>
		<table class="form-table" role="presentation">
			<?php
			$this->row(
				__( 'Rows per page', 'responsive-tables' ),
				function () {
					printf(
						'<input type="number" class="small-text" id="rtbla-per-page" name="%1$s" value="%2$d" min="1" max="%3$d" step="1">',
						esc_attr( self::name( 'per_page' ) ),
						(int) $this->plugin->get_setting( 'per_page' ),
						(int) Responsive_Tables_Render::MAX_PER_PAGE
					);
				},
				'rtbla-per-page'
			);
			?>
		</table>
		<p class="rtbla-callout">
			<?php echo self::icon( 'zap', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?>
			<span><?php esc_html_e( 'The phone layouts are pure CSS. A small script (about 2.5 KB) loads only on pages with a table that sorts, searches or pages, and every row stays in the page for search engines.', 'responsive-tables' ); ?></span>
		</p>
		<?php
	}

	/**
	 * Defaults tab: shortcodes in cells.
	 *
	 * @return void
	 */
	public function render_cells() {
		$this->checkbox( 'shortcodes', __( 'Run shortcodes in cells', 'responsive-tables' ), __( 'Lets cells use other plugins\' shortcodes, like TablePress does. Turn it off if your tables show text in [brackets] that isn\'t meant to be a shortcode.', 'responsive-tables' ) );
		?>
		<p class="rtbla-callout">
			<?php echo self::icon( 'shield-check', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?>
			<span><?php esc_html_e( 'Cells can hold links, images, bold text and other HTML allowed in posts. Scripts, iframes and other code are always removed, whoever wrote the cell, so a table can never run code on your site.', 'responsive-tables' ); ?></span>
		</p>
		<?php
	}

	/**
	 * TablePress tab: the import list.
	 *
	 * @return void
	 */
	public function render_tablepress() {
		$found  = Responsive_Tables_TablePress::found();
		$active = class_exists( 'TablePress' );

		if ( ! $found ) {
			?>
			<div class="rtbla-empty">
				<span class="rtbla-empty-icon"><?php echo self::icon( 'database', 28 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?></span>
				<p><strong><?php esc_html_e( 'No TablePress tables on this site.', 'responsive-tables' ); ?></strong></p>
				<p><?php esc_html_e( 'If you use TablePress, its tables show up here, whether TablePress is active or not. Tables deleted together with TablePress can\'t be imported.', 'responsive-tables' ); ?></p>
			</div>
			<?php
			return;
		}

		$waiting = array();
		foreach ( array_keys( $found ) as $id ) {
			if ( ! Responsive_Tables_TablePress::post_for( $id ) ) {
				$waiting[] = (string) $id;
			}
		}
		?>
		<div class="rtbla-tp-summary">
			<span class="rtbla-tag <?php echo $active ? 'is-good' : 'is-none'; ?>"><?php echo $active ? esc_html__( 'TablePress is active', 'responsive-tables' ) : esc_html__( 'TablePress is inactive', 'responsive-tables' ); ?></span>
			<span>
				<?php
				echo esc_html(
					sprintf(
						/* translators: 1: tables found, 2: tables imported. */
						_n( '%1$s table found, %2$s imported.', '%1$s tables found, %2$s imported.', count( $found ), 'responsive-tables' ),
						number_format_i18n( count( $found ) ),
						number_format_i18n( count( $found ) - count( $waiting ) )
					)
				);
				?>
			</span>
			<button type="button" class="button button-primary rtbla-import-all" data-ids="<?php echo esc_attr( (string) wp_json_encode( $waiting ) ); ?>" <?php disabled( ! $waiting ); ?>>
				<?php echo self::icon( 'import', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?>
				<?php
				echo esc_html(
					$waiting
						/* translators: %s: number of tables. */
						? sprintf( _n( 'Import %s table', 'Import %s tables', count( $waiting ), 'responsive-tables' ), number_format_i18n( count( $waiting ) ) )
						: __( 'Everything is imported', 'responsive-tables' )
				);
				?>
			</button>
		</div>
		<p class="rtbla-import-status" aria-live="polite"></p>

		<?php if ( ! $active ) : ?>
			<p class="rtbla-callout is-warning">
				<?php echo self::icon( 'info', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?>
				<span><?php esc_html_e( 'Tables with formulas (cells starting with =) import their formulas as text while TablePress is inactive. Activate TablePress during the import to bring over the results instead.', 'responsive-tables' ); ?></span>
			</p>
		<?php endif; ?>

		<table class="widefat striped rtbla-table rtbla-tp-table">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'ID', 'responsive-tables' ); ?></th>
					<th scope="col"><?php esc_html_e( 'TablePress table', 'responsive-tables' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Size', 'responsive-tables' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Status', 'responsive-tables' ); ?></th>
					<th scope="col" class="rtbla-actions-cell"><span class="screen-reader-text"><?php esc_html_e( 'Actions', 'responsive-tables' ); ?></span></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $found as $id => $post ) : ?>
					<?php
					$id       = (string) $id;
					$size     = Responsive_Tables_TablePress::size( $post );
					$imported = Responsive_Tables_TablePress::post_for( $id );
					?>
					<tr data-tp-id="<?php echo esc_attr( $id ); ?>">
						<td class="rtbla-num"><code><?php echo esc_html( $id ); ?></code></td>
						<td>
							<strong><?php echo esc_html( '' !== trim( $post->post_title ) ? $post->post_title : __( '(no name)', 'responsive-tables' ) ); ?></strong>
							<span class="rtbla-muted rtbla-block"><code>[table id=<?php echo esc_html( $id ); ?> /]</code></span>
						</td>
						<td class="rtbla-num">
							<?php
							/* translators: 1: rows, 2: columns. */
							echo esc_html( sprintf( __( '%1$s × %2$s', 'responsive-tables' ), number_format_i18n( $size[0] ), number_format_i18n( $size[1] ) ) );
							?>
						</td>
						<td class="rtbla-tp-state">
							<?php if ( $imported ) : ?>
								<span class="rtbla-tag is-good"><?php echo self::icon( 'circle-check', 13 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?><?php esc_html_e( 'Imported', 'responsive-tables' ); ?></span>
								<a href="<?php echo esc_url( (string) get_edit_post_link( $imported ) ); ?>"><?php esc_html_e( 'Edit', 'responsive-tables' ); ?></a>
							<?php else : ?>
								<span class="rtbla-tag is-none"><?php esc_html_e( 'Not imported', 'responsive-tables' ); ?></span>
							<?php endif; ?>
						</td>
						<td class="rtbla-actions-cell">
							<button type="button" class="button button-small rtbla-import-one" data-id="<?php echo esc_attr( $id ); ?>" data-replace="<?php echo $imported ? '1' : '0'; ?>">
								<?php echo $imported ? esc_html__( 'Import again', 'responsive-tables' ) : esc_html__( 'Import', 'responsive-tables' ); ?>
							</button>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * TablePress tab: who shows [table] shortcodes.
	 *
	 * @return void
	 */
	public function render_tablepress_codes() {
		$this->checkbox(
			'tablepress',
			__( 'Show TablePress shortcodes with Responsive Tables', 'responsive-tables' ),
			__( '[table id=N /], [table-info id=N field=name /] and TablePress blocks show the imported copy instead, so every post keeps working without edits, even after TablePress is deactivated. Tables you haven\'t imported still go to TablePress while it\'s active. This turns on by itself after your first import.', 'responsive-tables' )
		);
		?>
		<p class="rtbla-callout">
			<?php echo self::icon( 'info', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?>
			<span><?php esc_html_e( 'TablePress Pro\'s responsive="stack|collapse|scroll" and responsive_breakpoint shortcode options are understood too, along with use_datatables, datatables_sort, datatables_filter and datatables_paginate.', 'responsive-tables' ); ?></span>
		</p>
		<?php
	}

	/**
	 * Help tab: the first steps.
	 *
	 * @return void
	 */
	public function render_help_start() {
		$steps = array(
			__( 'Open a post or page in the block editor, press +, and add a Responsive Table block.', 'responsive-tables' ),
			__( 'Pick a size, a starter table, or bring your own data: paste cells from Excel or Google Sheets, or import a CSV file.', 'responsive-tables' ),
			__( 'Type in the cells like a spreadsheet. Use the row numbers and column letters to insert, move, highlight or delete.', 'responsive-tables' ),
			__( 'Choose how the table looks on phones in the block\'s settings (Stack, Collapse or Scroll), then check it with the Phone preview above the table.', 'responsive-tables' ),
		);
		?>
		<ol class="rtbla-steps">
			<?php foreach ( $steps as $step ) : ?>
				<li><?php echo esc_html( $step ); ?></li>
			<?php endforeach; ?>
		</ol>
		<?php
	}

	/**
	 * Help tab: the cell shortcuts, each with how it looks.
	 *
	 * @return void
	 */
	public function render_help_shortcuts() {
		$items = array(
			array( '{yes}', __( 'A check mark. {check} works too.', 'responsive-tables' ) ),
			array( '{no}', __( 'A cross. {cross} works too.', 'responsive-tables' ) ),
			array( '{yes Free returns}', __( 'A check mark with text after it.', 'responsive-tables' ) ),
			array( '{stars 4.5}', __( 'A star rating out of 5. Out of 10: {stars 8/10}. Sorts by the number.', 'responsive-tables' ) ),
			array( '{button Buy now | https://example.com | new, sponsored}', __( 'A button link. "new" opens it in a new tab; "sponsored" and "nofollow" mark affiliate links.', 'responsive-tables' ) ),
			array( '{image 123}', __( 'An image from the media library by its ID. Add a size: {image 123 medium}. The editor\'s Image button picks it for you.', 'responsive-tables' ) ),
		);
		?>
		<div class="rtbl rtbla-shortcuts" style="--rtbl-accent:<?php echo esc_attr( (string) $this->plugin->get_setting( 'accent' ) ); ?>;--rtbl-on-accent:<?php echo esc_attr( Responsive_Tables_Render::on_color( (string) $this->plugin->get_setting( 'accent' ) ) ); ?>">
			<table class="widefat striped rtbla-table">
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e( 'Type', 'responsive-tables' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Shows', 'responsive-tables' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Notes', 'responsive-tables' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $items as $item ) : ?>
						<?php $cell = Responsive_Tables_Cells::render( $item[0], false ); ?>
						<tr>
							<td><code><?php echo esc_html( $item[0] ); ?></code></td>
							<td class="rtbla-shortcut-demo"><?php echo '{image 123}' === $item[0] ? '<span class="rtbla-muted">' . esc_html__( '(your image)', 'responsive-tables' ) . '</span>' : $cell['html']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built and escaped by Responsive_Tables_Cells. ?></td>
							<td class="rtbla-muted"><?php echo esc_html( $item[1] ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<p class="rtbla-below rtbla-muted"><?php esc_html_e( 'Cells also take links, bold and italic text, line breaks and other HTML allowed in posts, so tables copied from TablePress look the same.', 'responsive-tables' ); ?></p>
		<?php
	}

	/**
	 * Help tab: keyboard and mouse in the editor.
	 *
	 * @return void
	 */
	public function render_help_keys() {
		$keys = array(
			array( __( 'Paste', 'responsive-tables' ), __( 'Copy a range in Excel, Google Sheets or Numbers and paste into any cell. The table grows to fit.', 'responsive-tables' ) ),
			array( 'Enter', __( 'Move down (adds a row at the bottom).', 'responsive-tables' ) ),
			array( 'Shift + Enter', __( 'New line inside a cell.', 'responsive-tables' ) ),
			array( 'Tab / Shift + Tab', __( 'Move right or left.', 'responsive-tables' ) ),
			array( __( 'Arrow keys', 'responsive-tables' ), __( 'Move between cells from the edge of the text.', 'responsive-tables' ) ),
			array( __( 'Shift + click', 'responsive-tables' ), __( 'Select a range to copy, cut, or clear with Delete.', 'responsive-tables' ) ),
			array( __( 'Row number / column letter', 'responsive-tables' ), __( 'Insert, move, highlight, sort, align or delete.', 'responsive-tables' ) ),
		);
		?>
		<table class="rtbla-table-kv">
			<?php foreach ( $keys as $key ) : ?>
				<tr>
					<th scope="row"><kbd><?php echo esc_html( $key[0] ); ?></kbd></th>
					<td><?php echo esc_html( $key[1] ); ?></td>
				</tr>
			<?php endforeach; ?>
		</table>
		<?php
	}

	/**
	 * Help tab: shortcodes, CSS variables and the filter.
	 *
	 * @return void
	 */
	public function render_help_shortcodes() {
		?>
		<table class="rtbla-table-kv">
			<tr>
				<th scope="row"><code>[responsive_table id="123"]</code></th>
				<td><?php esc_html_e( 'Shows a saved table anywhere shortcodes work: classic editor, widgets, page builders. Add mode="stack|collapse|scroll" or breakpoint="768" to override the table\'s own.', 'responsive-tables' ); ?></td>
			</tr>
			<tr>
				<th scope="row"><code>[table id=1 /]</code></th>
				<td><?php esc_html_e( 'TablePress shortcodes show the imported tables once you\'ve imported them (TablePress Import tab).', 'responsive-tables' ); ?></td>
			</tr>
			<tr>
				<th scope="row"><code>--rtbl-surface</code></th>
				<td><?php esc_html_e( 'Background behind the frozen first column in Scroll mode. Set it on .rtbl in your theme\'s CSS if your site has a dark background.', 'responsive-tables' ); ?></td>
			</tr>
			<tr>
				<th scope="row"><code>--rtbl-card</code>, <code>--rtbl-radius</code>, <code>--rtbl-line</code></th>
				<td><?php esc_html_e( 'Card background on phones, corner radius and border color.', 'responsive-tables' ); ?></td>
			</tr>
			<tr>
				<th scope="row"><code>responsive_tables_cell_allowed_html</code></th>
				<td><?php esc_html_e( 'Filter for the HTML tags allowed in cells (the tags allowed in posts by default).', 'responsive-tables' ); ?></td>
			</tr>
		</table>
		<?php
	}

	/**
	 * Sidebar: Save, status, and where updates come from.
	 *
	 * @return void
	 */
	private function render_sidebar() {
		$plugin   = $this->plugin;
		$saved    = (int) wp_count_posts( Responsive_Tables_Library::TYPE )->publish;
		$usage    = self::usage();
		$pages    = isset( $usage['pages'] ) ? (int) $usage['pages'] : count( $usage['posts'] );
		$imported = count( Responsive_Tables_TablePress::map() );
		$status   = array(
			/* translators: 1: layout name, 2: width in px. */
			array( true, sprintf( __( 'Phones: %1$s below %2$s px', 'responsive-tables' ), self::mode_label( (string) $plugin->get_setting( 'mode' ) ), number_format_i18n( (int) $plugin->get_setting( 'breakpoint' ) ) ) ),
			/* translators: %s: number of posts. */
			array( $pages > 0, $pages ? sprintf( _n( 'Tables in %s post or page', 'Tables in %s posts and pages', $pages, 'responsive-tables' ), number_format_i18n( $pages ) ) : __( 'No tables in posts yet', 'responsive-tables' ) ),
			/* translators: %s: number of tables. */
			array( $saved > 0, sprintf( _n( '%s saved table', '%s saved tables', $saved, 'responsive-tables' ), number_format_i18n( $saved ) ) ),
			array(
				Responsive_Tables_TablePress::takes_over(),
				Responsive_Tables_TablePress::takes_over()
					/* translators: %s: number of tables. */
					? sprintf( _n( 'TablePress shortcodes: %s table handled here', 'TablePress shortcodes: %s tables handled here', $imported, 'responsive-tables' ), number_format_i18n( $imported ) )
					: __( 'TablePress shortcodes: not handled here', 'responsive-tables' ),
			),
			array( true, __( 'Scripts load only where needed', 'responsive-tables' ) ),
		);
		?>
		<div class="postbox rtbla-save-box">
			<div class="postbox-header">
				<h2 class="hndle">
					<?php echo self::icon( 'save' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?>
					<?php esc_html_e( 'Save', 'responsive-tables' ); ?>
				</h2>
			</div>
			<div class="inside">
				<p><?php esc_html_e( 'Settings on every tab are saved together. Tables that picked their own layout or look keep it.', 'responsive-tables' ); ?></p>
				<?php submit_button( __( 'Save Changes', 'responsive-tables' ), 'primary large', 'submit', false ); ?>
			</div>
		</div>

		<div class="postbox">
			<div class="postbox-header">
				<h2 class="hndle">
					<?php echo self::icon( 'activity' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?>
					<?php esc_html_e( 'Status', 'responsive-tables' ); ?>
				</h2>
			</div>
			<div class="inside">
				<ul class="rtbla-checklist">
					<?php foreach ( $status as $item ) : ?>
						<li class="<?php echo $item[0] ? 'is-ok' : 'is-off'; ?>"><?php echo self::icon( $item[0] ? 'circle-check' : 'circle-dot', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?><span><?php echo esc_html( $item[1] ); ?></span></li>
					<?php endforeach; ?>
				</ul>
			</div>
		</div>

		<?php
		$this->render_updates_box();
	}

	/**
	 * Show where updates come from, so it's easy to confirm the repo is wired up.
	 *
	 * @return void
	 */
	private function render_updates_box() {
		$repo_label = preg_replace( '#^https?://[^/]+/#', '', untrailingslashit( RESPONSIVE_TABLES_REPO ) );
		$check_url  = Responsive_Tables_Updater::check_now_url();
		?>
		<div class="postbox rtbla-updates-box">
			<div class="postbox-header">
				<h2 class="hndle">
					<?php echo self::icon( 'refresh-cw' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?>
					<?php esc_html_e( 'Updates', 'responsive-tables' ); ?>
				</h2>
			</div>
			<div class="inside">
				<p>
					<?php
					/* translators: %s: installed plugin version. */
					printf( esc_html__( 'Installed version: %s', 'responsive-tables' ), '<strong>' . esc_html( RESPONSIVE_TABLES_VERSION ) . '</strong>' );
					?>
				</p>

				<?php if ( ! Responsive_Tables_Updater::has_repo() ) : ?>
					<p>
						<?php
						printf(
							/* translators: 1: header name, 2: main plugin file. */
							esc_html__( 'No repo set. Add it to the %1$s header in %2$s to turn on automatic updates.', 'responsive-tables' ),
							'<code>Update URI</code>',
							'<code>' . esc_html( plugin_basename( RESPONSIVE_TABLES_FILE ) ) . '</code>'
						);
						?>
					</p>
				<?php elseif ( Responsive_Tables_Updater::is_dev_copy() ) : ?>
					<p>
						<?php
						printf(
							/* translators: 1: repo, 2: constant name. */
							esc_html__( 'Releases come from %1$s, but update checks are off on this development copy so an update can\'t overwrite your work. Define %2$s as false in wp-config.php to test updates here.', 'responsive-tables' ),
							'<a href="' . esc_url( RESPONSIVE_TABLES_REPO ) . '" target="_blank" rel="noopener">' . esc_html( $repo_label ) . '</a>',
							'<code>RESPONSIVE_TABLES_DEV_MODE</code>'
						);
						?>
					</p>
				<?php else : ?>
					<p>
						<?php
						printf(
							/* translators: %s: repo. */
							esc_html__( 'Updates come from the latest release of %s.', 'responsive-tables' ),
							'<a href="' . esc_url( RESPONSIVE_TABLES_REPO ) . '" target="_blank" rel="noopener">' . esc_html( $repo_label ) . '</a>'
						);
						?>
					</p>
					<?php if ( $check_url ) : ?>
						<p><a class="button" href="<?php echo esc_url( $check_url ); ?>"><?php esc_html_e( 'Check for updates', 'responsive-tables' ); ?></a></p>
					<?php endif; ?>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}
}
