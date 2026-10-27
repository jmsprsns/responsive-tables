<?php
/**
 * Plugin Name:       Responsive Tables
 * Plugin URI:        https://www.contentpowered.com
 * Description:       Tables that stay readable on phones. Rows turn into cards, collapse to tap-to-open rows, or scroll with a frozen first column. Spreadsheet-style editing, sorting, search, pagination and one-click TablePress import.
 * Version:           1.0.1
 * Requires at least: 5.8
 * Requires PHP:      7.2
 * Author:            Content Powered
 * Author URI:        https://www.contentpowered.com/wordpress-plugins/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       responsive-tables
 * Update URI:        https://github.com/jmsprsns/responsive-tables
 *
 * @package Responsive_Tables
 */

defined( 'ABSPATH' ) || exit;

// Version and repo are read from the header above, so a release only needs
// "Version" bumped and the GitHub repo lives in one place ("Update URI").
// The Update URI header also stops WordPress.org offering a same-slug plugin
// as an "update" that would overwrite this one.
$responsive_tables_headers = get_file_data(
	__FILE__,
	array(
		'version' => 'Version',
		'repo'    => 'Update URI',
	)
);

define( 'RESPONSIVE_TABLES_VERSION', $responsive_tables_headers['version'] );
define( 'RESPONSIVE_TABLES_REPO', $responsive_tables_headers['repo'] );
define( 'RESPONSIVE_TABLES_FILE', __FILE__ );
define( 'RESPONSIVE_TABLES_DIR', plugin_dir_path( __FILE__ ) );
define( 'RESPONSIVE_TABLES_URL', plugin_dir_url( __FILE__ ) );

unset( $responsive_tables_headers );

require_once RESPONSIVE_TABLES_DIR . 'includes/class-responsive-tables-icons.php';
require_once RESPONSIVE_TABLES_DIR . 'includes/class-responsive-tables-updater.php';
require_once RESPONSIVE_TABLES_DIR . 'includes/class-responsive-tables-menu.php';
require_once RESPONSIVE_TABLES_DIR . 'includes/class-responsive-tables-feedback.php';
require_once RESPONSIVE_TABLES_DIR . 'includes/class-responsive-tables-cells.php';
require_once RESPONSIVE_TABLES_DIR . 'includes/class-responsive-tables-render.php';
require_once RESPONSIVE_TABLES_DIR . 'includes/class-responsive-tables-block.php';
require_once RESPONSIVE_TABLES_DIR . 'includes/class-responsive-tables-library.php';
require_once RESPONSIVE_TABLES_DIR . 'includes/class-responsive-tables-rest-controller.php';
require_once RESPONSIVE_TABLES_DIR . 'includes/class-responsive-tables-tablepress.php';
require_once RESPONSIVE_TABLES_DIR . 'includes/class-responsive-tables-admin.php';
require_once RESPONSIVE_TABLES_DIR . 'includes/class-responsive-tables-plugin.php';

// Built right away, outside any hook, so WP-Cron background updates and
// remote management tools see new releases too (not only logged-in admins).
Responsive_Tables_Updater::init();

/**
 * Boot the plugin once all other plugins have loaded.
 *
 * @return Responsive_Tables_Plugin
 */
function responsive_tables_plugin() {
	return Responsive_Tables_Plugin::instance();
}
add_action( 'plugins_loaded', 'responsive_tables_plugin' );

/**
 * Clean up when the plugin is deactivated.
 *
 * Settings and saved tables stay put (uninstall.php removes them).
 *
 * @return void
 */
function responsive_tables_deactivate() {
	delete_transient( 'responsive_tables_usage' );
}
register_deactivation_hook( RESPONSIVE_TABLES_FILE, 'responsive_tables_deactivate' );
