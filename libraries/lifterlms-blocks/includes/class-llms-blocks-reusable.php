<?php
/**
 * LLMS_Blocks_Reusable class file
 *
 * @package LifterLMS_Blocks/Classes
 *
 * @since 5.0.0-beta.1
 * @version 5.0.0-beta.1
 */

defined( 'ABSPATH' ) || exit;

/**
 * Manage customizations to reusable blocks
 *
 * @since 5.0.0-beta.1
 */
class LLMS_Blocks_Reusable {

	/**
	 * Constructor
	 *
	 * @since 5.0.0-beta.1
	 *
	 * @return void
	 */
	public function __construct() {

		add_action( 'rest_api_init', array( $this, 'rest_register_fields' ) );
		add_filter( 'rest_wp_block_query', array( $this, 'mod_wp_block_query' ), 20, 2 );

	}

	/**
	 * Read rest field read callback
	 *
	 * @since 5.0.0-beta.1
	 *
	 * @param array           $obj     Associative array representing the `wp_block` post.
	 * @param WP_REST_Request $request Request object.
	 * @return WP_Error|array Error when current user isn't authorized to read the data or the post association array on success.
	 */
	public function rest_callback_get( $obj, $request ) {
		return llms_parse_bool( get_post_meta( $obj['id'], '_is_llms_field', true ) ) ? 'yes' : 'no';
	}

	/**
	 * Rest field update callback
	 *
	 * @since 5.0.0-beta.1
	 *
	 * @param array   $value Post association array.
	 * @param WP_Post $obj   Post object for the `wp_block` post.
	 * @param string  $key   Field key.
	 * @return WP_Error|boolean Returns an error object when current user lacks permission to update the form or `true` on success.
	 */
	public function rest_callback_update( $value, $obj, $key ) {
		$value = llms_parse_bool( $value ) ? 'yes' : 'no';
		return update_post_meta( $obj->ID, '_is_llms_field', $value ) ? true : false;
	}

	/**
	 * Register custom rest fields
	 *
	 * @since 5.0.0-beta.1
	 *
	 * @return void
	 */
	public function rest_register_fields() {

		register_rest_field(
			'wp_block',
			'is_llms_field',
			array(
				'get_callback'    => array( $this, 'rest_callback_get' ),
				'update_callback' => array( $this, 'rest_callback_update' ),
			)
		);

	}

	/**
	 * Modify the rest request query used to list reusable blocks within the block editor
	 *
	 * Ensures that reusable blocks containing LifterLMS Form Fields can only be inserted/viewed
	 * in the context that we allow them to be used within.
	 *
	 * + When viewing a `wp_block` post, all reusable blocks should be displayed.
	 * + When viewing an `llms_form` post, only blocks that specify `is_llms_field` as 'yes' can be displayed.
	 * + When viewing any other post, any post with `is_llms_field` of 'yes' is excluded.
	 *
	 * @since 5.0.0-beta.1
	 *
	 * @see [Reference]
	 * @link [URL]
	 *
	 * @param arrays          $args    WP_Query arguments.
	 * @param WP_REST_Request $request Request object.
	 * @return array
	 */
	public function mod_wp_block_query( $args, $request ) {

		// Get referring post.
		$referer = $request->get_header( 'referer' );
		if ( empty( $referer ) ) {
			return $args;
		}

		$query_args = array();
		wp_parse_str( wp_parse_url( $referer, PHP_URL_QUERY ), $query_args );
		if ( empty( $query_args['post'] ) ) {
			return $args;
		}

		// Reusable blocks can contain blocks.
		$post_type = get_post_type( $query_args['post'] );
		if ( 'wp_block' === $post_type ) {
			return $args;
		}

		// Add a meta query if it doesn't already exist.
		if ( empty( $args['meta_query'] ) ) {
			$args['meta_query'] = array(
				'relation' => 'AND',
			);
		}

		// Forms should show only blocks with forms and everything else should exclude blocks with forms.
		$include_fields       = 'llms_form' === $post_type;
		$args['meta_query'][] = $this->get_meta_query( $include_fields );

		return $args;

	}

	/**
	 * Retrieve a meta query array depending on the post type of the referring rest request
	 *
	 * @since 5.0.0-beta.1
	 *
	 * @param boolean $include_fields Whether or not to include form fields.
	 * @return array
	 */
	private function get_meta_query( $include_fields ) {

		// Default 	query when including fields.
		$meta_query = array(
			'key'   => '_is_llms_field',
			'value' => 'yes',
		);

		// Excluding fields.
		if ( ! $include_fields ) {

			$meta_query = array(
				'relation' => 'OR',
				wp_parse_args(
					array(
						'compare' => '!=',
					),
					$meta_query
				),
				array(
					'key'     => '_is_llms_field',
					'compare' => 'NOT EXISTS',
				),
			);
		}

		return $meta_query;

	}

}

return new LLMS_Blocks_Reusable();
