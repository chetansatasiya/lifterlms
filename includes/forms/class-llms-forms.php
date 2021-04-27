<?php
/**
 * Register and manage LifterLMS user forms
 *
 * @package LifterLMS/Classes
 *
 * @since [version]
 * @version [version]
 */

defined( 'ABSPATH' ) || exit;

/**
 * LLMS_Forms class
 *
 * @since [version]
 */
class LLMS_Forms {

	/**
	 * Singleton instance
	 *
	 * @var null
	 */
	protected static $instance = null;

	/**
	 * Provide access to the post type manager class
	 *
	 * @var LLMS_Forms_Post_Type
	 */
	public $post_type_manager = null;

	/**
	 * Get Main Singleton Instance.
	 *
	 * @since [version]
	 *
	 * @return LLMS_Forms
	 */
	public static function instance() {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Private Constructor
	 *
	 * @since [version]
	 *
	 * @return void
	 */
	private function __construct() {

		$this->post_type_manager = new LLMS_Form_Post_Type( $this );

		add_filter( 'render_block', array( $this, 'render_field_block' ), 10, 2 );

	}

	/**
	 * Determine if usernames are enabled on the site.
	 *
	 * This method is used to determine if a username can be used to login / reset a user's password.
	 *
	 * A reference to every form with a username block is stored in an option. The option is an array
	 * of integers, the WP_Post IDs of all the form posts containing a username block.
	 *
	 * If the array is empty, there are no forms with username blocks and, therefore, usernames are disabled.
	 * If the array contains at least one item that means there is a form with a username block in it and,
	 * we therefore consider usernames to be enabled for the site.
	 *
	 * This isn't perfect. We're well aware. But usernames are kind of silly anyway, right? Just use the email
	 * address like your average website owner and stop pretending usernames matter.
	 *
	 * @since [version]
	 *
	 * @return bool
	 */
	public function are_usernames_enabled() {

		$locations = get_option( 'llms_forms_username_locations', array() );

		/**
		 * Use this to explicitly enable of disable username fields.
		 *
		 * Note that usage of this filter will not actually disable the llms/form-field-username block.
		 * It's possible to create a confusing user experience by explicitly disabling usernames and
		 * leaving username field blocks on one or more forms. If you decide to explicitly disable via
		 * this filter you should also remove all the username blocks from all of your forms.
		 *
		 * @since [version]
		 *
		 * @param boolean $enabled Whether or not usernames are enabled.
		 */
		return apply_filters( 'llms_are_usernames_enabled', ! empty( $locations ) );

	}

	/**
	 * Converts a block to settings understandable by `llms_form_field()`
	 *
	 * @since [version]
	 *
	 * @param array $block A WP Block array.
	 * @return array
	 */
	private function block_to_field_settings( $block ) {

		$attrs = $block['attrs'];

		// Rename some properties;
		$rename = array(
			'field'      => 'type',
			'className'  => 'classes',
			'html_attrs' => 'attributes',
		);

		foreach ( $rename as $block_prop => $field_prop ) {
			if ( isset( $attrs[ $block_prop ] ) ) {
				$attrs[ $field_prop ] = $attrs[ $block_prop ];
				unset( $attrs[ $block_prop ] );
			}
		}

		// If the field is required and hidden it's impossible for the user to fill it out so it gets marked as optional at runtime.
		if ( ! empty( $attrs['required'] ) && ! $this->is_block_visible( $block ) ) {
			$attrs['required'] = false;
		}

		/**
		 * Filter an LLMS_Form_Field settings array after conversion from a field block
		 *
		 * @since [version]
		 *
		 * @param array $attrs An array of LLMS_Form_Field settings.
		 * @param array $block A WP Block array.
		 */
		return apply_filters( 'llms_forms_block_to_field_settings', $attrs, $block );

	}

	/**
	 * Cascade all llms_visibility attributes down into inner blocks.
	 *
	 * If a parent block has a visibility setting this will apply that visibility to a chlid block *if*
	 * the child block does not have a visibility setting of its own.
	 *
	 * Ultimately this ensures that a field block that's not visible can be marked as "optional" so that
	 * form validation can take place.
	 *
	 * For example, if a columns block is displayed only to logged out users and it's child fields are marked
	 * as required that means that it's required only to logged out users and the field becomes "optional"
	 * (for validation purposes) to logged in users.
	 *
	 * @since [version]
	 *
	 * @param array[]     $blocks     Array of parsed block arrays.
	 * @param string|null $visibility The llms_visibility attribute of the parent block which is applied to all innerBlocks
	 *                                if the innerBlock does not already have it's own visibility attribute.
	 * @return array[]
	 */
	private function cascade_visibility_attrs( $blocks, $visibility = null ) {

		foreach ( $blocks as &$block ) {

			// If a visibility setting has been passed from the parent and the block does not have visibility setting of it's own.
			if ( $visibility && ( empty( $block['attrs']['llms_visibility'] ) || 'off' === $block['attrs']['llms_visibility'] ) ) {
				$block['attrs']['llms_visibility'] = $visibility;
			}

			// This block has a visibility attribute and it should be applied it to all the innerBlocks.
			if ( ! empty( $block['attrs']['llms_visibility'] ) && ! empty( $block['innerBlocks'] ) ) {
				$block['innerBlocks'] = $this->cascade_visibility_attrs( $block['innerBlocks'], $block['attrs']['llms_visibility'] );
			}
		}

		return $blocks;

	}


	/**
	 * Create a form for a given location with the provided data.
	 *
	 * @since [version]
	 *
	 * @param string $location_id Location id.
	 * @param bool   $recreate    If `true` and the form already exists, will recreate the existing form using the existing form's id.
	 * @return int|false Returns the created/update form post ID on success.
	 *                   If the location doesn't exist, returns `false`.
	 *                   If the form already exists and `$recreate` is `false` will return `false`.
	 */
	public function create( $location_id, $recreate = false ) {

		if ( ! $this->is_location_valid( $location_id ) ) {
			return false;
		}

		$locs = $this->get_locations();
		$data = $locs[ $location_id ];

		$existing = $this->get_form_post( $location_id );

		// Form already exists and we haven't requested an update.
		if ( false !== $existing && ! $recreate ) {
			return false;
		}

		$args = array(
			'ID'           => $existing ? $existing->ID : 0,
			'post_content' => LLMS_Form_Templates::get_template( $location_id ),
			'post_status'  => 'publish',
			'post_title'   => $data['title'],
			'post_type'    => $this->get_post_type(),
			'meta_input'   => $data['meta'],
			'post_author'  => $existing ? $existing->post_author : LLMS_Install::get_can_install_user_id(),
		);

		/**
		 * Filter arguments used to install a new form.
		 *
		 * @since [version]
		 *
		 * @param array  $args        Array of arguments to be passed to wp_insert_post
		 * @param string $location_id Location ID/name.
		 * @param array  $data        Array of location information from LLMS_Forms::get_locations().
		 */
		$args = apply_filters( 'llms_forms_install_post_args', $args, $location_id, $data );

		return wp_insert_post( $args );

	}

	/**
	 * Finds the user password form field block within a list of blocks
	 *
	 * There's a gotcha with this function... if a user password field is placed within a wp core columns block
	 * the password strength meter will be added outside the column the password is contained within.
	 *
	 * @since [version]
	 *
	 * @param array[] $blocks       WP_Block list.
	 * @param integer $parent_index Top level index of the parent block. Used to hold a reference to the current index within the toplevel
	 *                              blocks of the form when looking into the innerBlocks of a block. We don't want to add the password meter inside
	 *                              another group, only ever at the top level.
	 * @return boolean|array Returns `false` when no password block found in the given list, otherwise returns a numeric array
	 *                       where item `0` is the index of the block within the list (the index of the items parent if it's in a
	 *                       group) and item `1` is the block array.
	 */
	private function find_password_block( $blocks, $parent_index = null ) {

		foreach ( $blocks as $index => $block ) {

			if ( 'llms/form-field-user-password' === $block['blockName'] ) {
				return array( is_null( $parent_index ) ? $index : $parent_index, $block );
			} elseif ( $block['innerBlocks'] ) {
				$inner = $this->find_password_block( $block['innerBlocks'], is_null( $parent_index ) ? $index : $parent_index );
				if ( false !== $inner ) {
					return $inner;
				}
			}
		}

		return false;

	}

	/**
	 * Retrieve the form management user capability.
	 *
	 * @since [version]
	 *
	 * @return string
	 */
	public function get_capability() {
		return $this->post_type_manager->capability;
	}

	/**
	 * Pull LifterLMS Form Field blocks from an array of parsed WP Blocks.
	 *
	 * Searches innerBlocks arrays recursively.
	 *
	 * @since [version]
	 *
	 * @param  array $blocks Array of WP Block arrays from `parse_blocks()`.
	 * @return array
	 */
	private function get_field_blocks( $blocks ) {

		$fields = array();

		foreach ( $blocks as $block ) {

			if ( $block['innerBlocks'] ) {
				$fields = array_merge( $fields, $this->get_field_blocks( $block['innerBlocks'] ) );
			} elseif ( false !== strpos( $block['blockName'], 'llms/form-field-' ) ) {
				$fields[] = $block;
			} elseif ( 'core/html' === $block['blockName'] && ! empty( $block['attrs'] ) && 'html' === $block['attrs']['type'] ) {
				$fields[] = $block;
			}
		}

		return $fields;

	}

	/**
	 * Retrieve an array of parsed blocks for the form at a given location.
	 *
	 * @since [version]
	 *
	 * @param string $location Form location, one of: "checkout", "registration", or "account".
	 * @param array  $args     Additional arguments passed to the short-circuit filter.
	 * @return array|false
	 */
	public function get_form_blocks( $location, $args = array() ) {

		$post = $this->get_form_post( $location, $args );
		if ( ! $post ) {
			return false;
		}

		$content  = $post->post_content;
		$content .= $this->get_additional_fields_html( $location, $args );

		$blocks = $this->parse_blocks( $content );

		/**
		 * Filters the parsed block list for a given LifterLMS form
		 *
		 * This hook can be used to programmatically modify, insert, or remove
		 * blocks (fields) from a form.
		 *
		 * @since [version]
		 *
		 * @param array[] $blocks   Array of parsed WP_Block arrays.
		 * @param string  $location The request form location ID.
		 * @param array   $args     Additional arguments passed to the short-circuit filter.
		 */
		return apply_filters( 'llms_get_form_blocks', $blocks, $location, $args );

	}

	/**
	 * Retrieve an array of LLMS_Form_Fields settings arrays for the form at a given location.
	 *
	 * This method is used by the LLMS_Form_Handler to perform validations on user-submitted data.
	 *
	 * @since [version]
	 *
	 * @param string $location Form location, one of: "checkout", "registration", or "account".
	 * @param array  $args     Additional arguments passed to the short-circuit filter in `get_form_post()`.
	 * @return false|array
	 */
	public function get_form_fields( $location, $args = array() ) {

		$blocks = $this->get_form_blocks( $location, $args );
		if ( false === $blocks ) {
			return false;
		}

		$fields = array();
		foreach ( $this->get_field_blocks( $blocks ) as $block ) {
			$settings = $this->block_to_field_settings( $block );
			if ( $settings ) {
				$field    = new LLMS_Form_Field( $settings );
				$fields[] = $field->get_settings();
			}
		}

		$fields = array_merge( $fields, $this->get_additional_fields( $location, $args ) );

		/**
		 * Modify the parsed array of LifterLMS Form Fields.
		 *
		 * @since [version]
		 *
		 * @param array[] $fields   Array of LifterLMS Form Field settings data.
		 * @param string  $location Form location, one of: "checkout", "registration", or "account".
		 * @param array   $args     Additional arguments passed to the short-circuit filter in `get_form_post()`.
		 */
		return apply_filters( 'llms_get_form_fields', $fields, $location, $args );

	}

	/**
	 * Retrieve a field item from a list of fields by a key/value pair.
	 *
	 * @since [version]
	 *
	 * @param array[] $fields List of LifterLMS Form Fields.
	 * @param string  $key    Setting key to search for.
	 * @param mixed   $val    Setting valued to search for.
	 * @param string  $return Determine the return value. Use "field" to return the field settings
	 *                        array. Use "index" to return the index of the field in the $fields array.
	 * @return array|int|false `false` when the field isn't found in $fields, otherwise returns the field settings
	 *                          as an array when `$return` is "field". Otherwise returns the field's index as an int.
	 */
	public function get_field_by( $fields, $key, $val, $return = 'field' ) {

		foreach ( $fields as $index => $field ) {
			if ( isset( $field[ $key ] ) && $val === $field[ $key ] ) {
				return 'field' === $return ? $field : $index;
			}
		}

		return false;

	}

	/**
	 * Retrieve the rendered HTML for the form at a given location.
	 *
	 * @since [version]
	 *
	 * @param string $location Form location, one of: "checkout", "registration", or "account".
	 * @param array  $args     Additional arguments passed to the short-circuit filter in `get_form_post()`.
	 * @return string
	 */
	public function get_form_html( $location, $args = array() ) {

		$blocks = $this->get_form_blocks( $location, $args );
		if ( ! $blocks ) {
			return '';
		}

		$disable_visibility = ( 'checkout' !== $location );

		// Force fields to display regardless of visibility settings when viewing account/registration forms.
		if ( $disable_visibility ) {
			add_filter( 'llms_blocks_visibility_should_filter_block', '__return_false', 999 );
		}

		$html = '';
		foreach ( $blocks as $block ) {
			$html .= render_block( $block );
		}

		if ( $disable_visibility ) {
			remove_filter( 'llms_blocks_visibility_should_filter_block', '__return_false', 999 );
		}

		/**
		 * Modify the parsed array of LifterLMS Form Fields.
		 *
		 * @since [version]
		 *
		 * @param string $html     Form fields HTML.
		 * @param string $location Form location, one of: "checkout", "registration", or "account".
		 * @param array  $args     Additional arguments passed to the short-circuit filter in `get_form_post()`.
		 */
		return apply_filters( 'llms_get_form_html', $html, $location, $args );

	}

	/**
	 * Retrieve the WP Post for the form at a given location.
	 *
	 * @since [version]
	 *
	 * @param string $location Form location, one of: "checkout", "registration", or "account".
	 * @param array  $args     Additional arguments passed to the short-circuit filter.
	 * @return WP_Post|false
	 */
	public function get_form_post( $location, $args = array() ) {

		// @todo Add caching. This runs twice on some page loads.

		/**
		 * Skip core lookup of the form for the request location and return a custom form post.
		 *
		 * @since [version]
		 *
		 * @param null|WP_Post $post     Return a WP_Post object to short-circuit default lookup query.
		 * @param string       $location Form location. Either "checkout", "registration", or "account".
		 * @param array        $args     Additional custom arguments.
		 */
		$post = apply_filters( 'llms_get_form_post_pre_query', null, $location, $args );
		if ( is_a( $post, 'WP_Post' ) ) {
			return $post;
		}

		$query = new WP_Query(
			array(
				'post_type'      => $this->get_post_type(),
				'posts_per_page' => 1,
				'no_found_rows'  => true,
				// Only show published forms to end users but allow admins to "preview" drafts.
				'post_status'    => current_user_can( $this->get_capability() ) ? array( 'publish', 'draft' ) : 'publish',
				'meta_query'     => array(
					'relation' => 'AND',
					array(
						'key'   => '_llms_form_location',
						'value' => $location,
					),
					array(
						'key'   => '_llms_form_is_core',
						'value' => 'yes',
					),
				),
			)
		);

		return $query->have_posts() ? $query->posts[0] : false;

	}

	/**
	 * Retrieve additional fields added to the form programmatically.
	 *
	 * @since [version]
	 *
	 * @param string $location Form location, one of: "checkout", "registration", or "account".
	 * @param array  $args     Additional arguments passed to the short-circuit filter.
	 * @return array[]
	 */
	private function get_additional_fields( $location, $args = array() ) {

		/**
		 * Filter to add custom fields to a form programmatically.
		 *
		 * @since 3.0.0
		 * @since [version] Moved from deprecated function `LLMS_Person_Handler::get_available_fields()`.
		 *
		 * @param array[] $fields  Array of field array suitable to pass to `llms_form_field()`.
		 * @param string $location Form location, one of: "checkout", "registration", or "account".
		 * @param array $args      Additional arguments passed to the short-circuit filter.
		 */
		return apply_filters( 'lifterlms_get_person_fields', array(), $location, $args );

	}

	/**
	 * Retrieve HTML for the form's additional programmatically-added fields.
	 *
	 * Gets the HTML for each field from `llms_form_field()` and wraps it as a `wp/html` block.
	 *
	 * @since [version]
	 *
	 * @param string $location Form location, one of: "checkout", "registration", or "account".
	 * @param array  $args     Additional arguments passed to the short-circuit filter.
	 * @return string
	 */
	private function get_additional_fields_html( $location, $args = array() ) {

		$html   = '';
		$fields = $this->get_additional_fields( $location, $args );

		foreach ( $fields as $field ) {
			$html .= "\r" . $this->get_custom_field_block_markup( $field );
		}

		return $html;

	}

	/**
	 * Retrieve the HTML markup for a custom form field block
	 *
	 * Retrieves an array of `LLMS_Form_Field` settings, generates the HTML
	 * for the field, and wraps it in a `wp:html` block.
	 *
	 * @since [version]
	 *
	 * @param array $settings Form field settings (passed to `llms_form_field()`).
	 * @return string
	 */
	public function get_custom_field_block_markup( $settings ) {
		return sprintf( '<!-- wp:html %1$s -->%2$s%3$s%2$s<!-- /wp:html -->', wp_json_encode( $settings ), "\r", llms_form_field( $settings, false ) );
	}

	/**
	 * Retrieve an array of form fields used for the "free enrollment" form
	 *
	 * This is the "one-click" enrollment form used when a logged-in user clicks the "checkout" button
	 * from an access plan.
	 *
	 * This function converts the checkout form to hidden fields, the result is that users with all required fields
	 * will be enrolled into the course with a single click (no need to head to the checkout page) and users
	 * who are missing r equired information will be directed to the checkout page.
	 *
	 * @since [version]
	 *
	 * @param LLMS_Access_Plan $plan Access plan being used for enrollment.
	 * @return array[] List of LLMS_Form_Field settings arrays.
	 */
	public function get_free_enroll_form_fields( $plan ) {

		// Convert all fields to hidden fields and remove any fields hidden by LLMS block-level visibility settings.
		add_filter( 'llms_forms_block_to_field_settings', array( $this, 'prepare_field_for_free_enroll_form' ), 999, 2 );
		$fields = $this->get_form_fields( 'checkout', compact( 'plan' ) );
		remove_filter( 'llms_forms_block_to_field_settings', array( $this, 'prepare_field_for_free_enroll_form' ), 999, 2 );

		// Add additional fields required for form processing.
		$fields[] = array(
			'name'           => 'free_checkout_redirect',
			'type'           => 'hidden',
			'value'          => $plan->get_redirection_url(),
			'data_store_key' => false,
		);

		$fields[] = array(
			'id'             => 'llms-plan-id',
			'name'           => 'llms_plan_id',
			'type'           => 'hidden',
			'value'          => $plan->get( 'id' ),
			'data_store_key' => false,
		);

		/**
		 * Filter the list of LLMS_Form_Fields used to generate the "free enrollment" form
		 *
		 * @since [version]
		 *
		 * @param array[]          $fields List of LLMS_Form_Field settings arrays.
		 * @param LLMS_Access_Plan $plan   Access plan being used for enrollment.
		 */
		return apply_filters( 'llms_forms_get_free_enroll_form_fields', $fields, $plan );

	}

	/**
	 * Retrieve the HTML of form fields used for the "free enrollment" form
	 *
	 * @since [version]
	 *
	 * @see LLMS_Forms::get_free_enroll_form_fields()
	 *
	 * @param LLMS_Access_Plan $plan Access plan being used for enrollment.
	 * @return string
	 */
	public function get_free_enroll_form_html( $plan ) {

		$html = '';
		foreach ( $this->get_free_enroll_form_fields( $plan ) as $field ) {
			$html .= llms_form_field( $field, false );
		}

		return $html;

	}

	/**
	 * Retrieve information on all the available form locations.
	 *
	 * @since [version]
	 *
	 * @return array[] {
	 *     An associative array. The array key is the location ID and each array is a location definition array.
	 *
	 *     @type string $name        The human-readable location name (as displayed on the admin panel).
	 *     @type string $description A description of the form (as displayed on the admin panel).
	 *     @type string $title       The form's post title. This is displayed to the end user when the "Show Form Title" option is enabled.
	 *     @type array  $meta        An associative array of postmeta information for the form. The array key is the meta key and the value is the meta value.
	 * }
	 */
	public function get_locations() {

		/**
		 * Filter the available form locations.
		 *
		 * NOTE: Removing core forms (as well as modifying the ids / keys) may cause areas of LifterLMS to stop working.
		 *
		 * @since [version]
		 *
		 * @param  array[] $locations Associative array of form location information.
		 */
		return apply_filters(
			'llms_forms_get_locations',
			array(
				'checkout'     => array(
					'name'        => __( 'Checkout', 'lifterlms' ),
					'description' => __( 'Handles new user registration and existing user information updates during checkout and enrollment.', 'lifterlms' ),
					'title'       => __( 'Billing Information', 'lifterlms' ),
					'template'    => LLMS_Form_Templates::get_template( 'checkout' ),
					'meta'        => array(
						'_llms_form_location'   => 'checkout',
						'_llms_form_show_title' => 'yes',
						'_llms_form_is_core'    => 'yes',
					),
				),
				'registration' => array(
					'name'        => __( 'Registration', 'lifterlms' ),
					'description' => __( 'Handles new user registration and existing user information updates for open registration on the student dashboard and wherever the [lifterlms_registration] shortcode is used.', 'lifterlms' ),
					'title'       => __( 'Register', 'lifterlms' ),
					'template'    => LLMS_Form_Templates::get_template( 'registration' ),
					'meta'        => array(
						'_llms_form_location'   => 'registration',
						'_llms_form_show_title' => 'yes',
						'_llms_form_is_core'    => 'yes',
					),
				),
				'account'      => array(
					'name'        => __( 'Account', 'lifterlms' ),
					'description' => __( 'Handles user account information updates on the edit account area of the student dashboard.', 'lifterlms' ),
					'title'       => __( 'Edit Account Information', 'lifterlms' ),
					'template'    => LLMS_Form_Templates::get_template( 'account' ),
					'meta'        => array(
						'_llms_form_location'   => 'account',
						'_llms_form_show_title' => 'no',
						'_llms_form_is_core'    => 'yes',
					),
				),
			)
		);

	}

	/**
	 * Retrieve the forms post type name.
	 *
	 * @since [version]
	 *
	 * @return string
	 */
	public function get_post_type() {
		return $this->post_type_manager->post_type;
	}

	/**
	 * Determine if a block is visible based on LifterLMS Visibility Settings.
	 *
	 * @since [version]
	 *
	 * @param array $block Parsed block array.
	 * @return bool
	 */
	private function is_block_visible( $block ) {

		// Make the block return `true` if it's visible, it will already automatically return an empty string if it's invisible.
		add_filter( 'render_block', '__return_true', 5 );

		// Don't run this classes render function on the block during this test.
		remove_filter( 'render_block', array( $this, 'render_field_block' ), 10, 2 );

		// Render the block.
		$render = render_block( $block );

		// Cleanup / reapply filters.
		add_filter( 'render_block', array( $this, 'render_field_block' ), 10, 2 );
		remove_filter( 'render_block', '__return_true', 5 );

		/**
		 * Filter whether or not the block is visible.
		 *
		 * @since [version]
		 *
		 * @param bool  $visible Whether or not the block is visible.
		 * @param array $block   Parsed block array.
		 */
		return apply_filters( 'llms_forms_is_block_visible', llms_parse_bool( $render ), $block );

	}

	/**
	 * Installation function to install core forms.
	 *
	 * @since [version]
	 *
	 * @param bool $recreate Whether or not to recreate an existing form. This is passed to `LLMS_Forms::create()`.
	 * @return WP_Post[] Array of created posts. Array key is the location id and array value is the WP_Post object.
	 */
	public function install( $recreate = false ) {

		$installed = array();

		foreach ( array_keys( $this->get_locations() ) as $location ) {
			$installed[ $location ] = $this->create( $location, $recreate );
		}

		return $installed;

	}

	/**
	 * Determines if a location is a valid & registered form location
	 *
	 * @since [version]
	 *
	 * @param string $location The location id.
	 * @return boolean
	 */
	public function is_location_valid( $location ) {
		return in_array( $location, array_keys( $this->get_locations() ), true );
	}

	/**
	 * Loads reusable blocks into a block list
	 *
	 * By default, a reusable block contains a reference to the block post (which will be
	 * loaded during rendering). This is problematic for us since we want to review then
	 * entire block list so we can see all fields for validation purposes and so on.
	 *
	 * This function will replace each reusable block with the parsed blocks
	 * from it's reference post.
	 *
	 * @since [version]
	 *
	 * @param array[] $blocks List of WP_Block arrays.
	 * @return array[]
	 */
	private function load_reusable_blocks( $blocks ) {

		$loaded = array();

		foreach ( $blocks as $index => $block ) {

			if ( 'core/block' === $block['blockName'] ) {

				$post = get_post( $block['attrs']['ref'] );
				if ( ! $post ) {
					continue;
				}

				$loaded = array_merge( $loaded, $this->parse_blocks( $post->post_content ) );
				continue;

			}

			if ( $block['innerBlocks'] ) {
				$block['innerBlocks'] = $this->load_reusable_blocks( $block['innerBlocks'] );
			}

			$loaded[] = $block;

		}

		return $loaded;

	}

	/**
	 * Adds a password strength meter to a block list
	 *
	 * This function will programmatically add an html block containing the necessary
	 * markup for the password strength meter to function.
	 *
	 * This will locate the user password block and output the meter immediately after
	 * the block. If the password block is within a group it'll output it after the
	 * group block.
	 *
	 * @since [version]
	 *
	 * @param array[] $blocks WP_Block list.
	 * @return array[]
	 */
	private function maybe_add_password_strength_meter( $blocks ) {

		$password = $this->find_password_block( $blocks );

		// No password field in the form.
		if ( ! $password ) {
			return $blocks;
		}

		list( $index, $block ) = $password;

		// Meter not enabled.
		if ( empty( $block['attrs']['meter'] ) || ! llms_parse_bool( $block['attrs']['meter'] ) ) {
			return $blocks;
		}

		// Make the new block.
		$password_block = parse_blocks(
			$this->get_custom_field_block_markup(
				array(
					'type'            => 'html',
					'id'              => 'llms-password-strength-meter',
					'classes'         => 'llms-password-strength-meter',
					'description'     => ! empty( $block['attrs']['meter_description'] ) ? $block['attrs']['meter_description'] : '',
					'min_length'      => ! empty( $block['attrs']['html_attrs']['minlength'] ) ? $block['attrs']['html_attrs']['minlength'] : '',
					'min_strength'    => ! empty( $block['attrs']['min_strength'] ) ? $block['attrs']['min_strength'] : '',
					'llms_visibility' => ! empty( $block['attrs']['llms_visibility'] ) ? $block['attrs']['llms_visibility'] : '',
				)
			)
		);

		// Add it into the form after the password block / group.
		array_splice( $blocks, $index + 1, 0, $password_block );

		return $blocks;

	}

	/**
	 * Parse the post_content of a form into a list of WP_Block arrays.
	 *
	 * This method parses the blocks, loads block data from any reusable blocks,
	 * adds dynamic inserted content (like a password strength meter), and
	 * cascades visibility attributes onto a block's innerBlocks.
	 *
	 * @since [version]
	 *
	 * @param string $content Post content HTML.
	 * @return array[] Array of parsed block arrays.
	 */
	public function parse_blocks( $content ) {

		$blocks = parse_blocks( $content );

		$blocks = $this->load_reusable_blocks( $blocks );

		$blocks = $this->cascade_visibility_attrs( $blocks );

		$blocks = $this->maybe_add_password_strength_meter( $blocks );

		return $blocks;

	}

	/**
	 * Modifies a field for usage in the "free enrollment" checkout form
	 *
	 * If the block is not visible (according to LLMS block-level visibility settings)
	 * it will return an empty array (signaling the field to be removed).
	 *
	 * Otherwise the block will be converted to a hidden field.
	 *
	 * This method is a filter callback and is intended for internal use only.
	 *
	 * Backwards incompatible changes and/or method removal may occur without notice.
	 *
	 * @since [version]
	 *
	 * @access private
	 *
	 * @param array $attrs LLMS_Form_Field settings array for the field.
	 * @param array $block WP_Block settings array.
	 * @return array
	 */
	public function prepare_field_for_free_enroll_form( $attrs, $block ) {

		if ( ! $this->is_block_visible( $block ) ) {
			return array();
		}

		$attrs['type'] = 'hidden';
		return $attrs;

	}

	/**
	 * Render form field blocks.
	 *
	 * @since [version]
	 *
	 * @param string $html  Block HTML.
	 * @param array  $block Array of block information.
	 * @return string
	 */
	public function render_field_block( $html, $block ) {

		// Return HTML for any non llms/form-field blocks.
		if ( false === strpos( $block['blockName'], 'llms/form-field-' ) ) {
			return $html;
		}

		if ( ! empty( $block['innerBlocks'] ) ) {

			$inner_blocks = array_map( 'render_block', $block['innerBlocks'] );
			return implode( "\n", $inner_blocks );

		}

		$attrs = $this->block_to_field_settings( $block );

		return llms_form_field( $attrs, false );

	}

}

return LLMS_Forms::instance();