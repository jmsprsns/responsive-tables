<?php
/**
 * REST controller for saved tables.
 *
 * WordPress's posts controller lets anyone read published posts of any type
 * shown in REST. Saved tables are only meant to appear where someone placed
 * them, so this controller asks for the right to edit them before listing or
 * reading any, published or not.
 *
 * @package Responsive_Tables
 */

defined( 'ABSPATH' ) || exit;

/**
 * Saved tables REST controller.
 */
class Responsive_Tables_REST_Controller extends WP_REST_Posts_Controller {

	/**
	 * Listing saved tables needs the right to edit them.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return true|WP_Error
	 */
	public function get_items_permissions_check( $request ) {
		$type = get_post_type_object( $this->post_type );

		if ( ! $type || ! current_user_can( $type->cap->edit_posts ) ) {
			return new WP_Error( 'rest_forbidden', __( 'Sorry, you are not allowed to view saved tables.', 'responsive-tables' ), array( 'status' => rest_authorization_required_code() ) );
		}

		return parent::get_items_permissions_check( $request );
	}

	/**
	 * Reading one saved table needs the right to edit it.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return true|WP_Error
	 */
	public function get_item_permissions_check( $request ) {
		$post = $this->get_post( $request['id'] );

		if ( is_wp_error( $post ) ) {
			return $post;
		}

		if ( ! current_user_can( 'edit_post', $post->ID ) ) {
			return new WP_Error( 'rest_forbidden', __( 'Sorry, you are not allowed to view this table.', 'responsive-tables' ), array( 'status' => rest_authorization_required_code() ) );
		}

		return parent::get_item_permissions_check( $request );
	}
}
