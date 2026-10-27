<?php
/**
 * Runs when the plugin is deleted from the Plugins screen: remove its data.
 *
 * Settings and saved tables (including tables imported from TablePress) are
 * deleted. Tables built inside posts live in those posts and stay there;
 * they show again if the plugin is reinstalled. TablePress's own tables are
 * never touched.
 *
 * @package Responsive_Tables
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'responsive_tables_settings' );
delete_option( 'responsive_tables_tablepress_map' );
delete_transient( 'responsive_tables_usage' );

// Plugin Update Checker's cached update state.
delete_site_option( 'external_updates-responsive-tables' );

// Saved tables, with their revisions and meta.
global $wpdb;

$responsive_tables_ids = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type = %s", 'rtbl_table' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One-off cleanup.

foreach ( $responsive_tables_ids as $responsive_tables_id ) {
	wp_delete_post( (int) $responsive_tables_id, true );
}
