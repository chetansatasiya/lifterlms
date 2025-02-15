<?php
/**
 * Tests for the LLMS_Post_Model abstract
 *
 * @package LifterLMS/Tests/Abstracts
 *
 * @group abstracts
 * @group post_model_abstract
 * @group post_models
 *
 * @since 4.10.0
 */
class LLMS_Test_Abstract_Post_Model extends LLMS_UnitTestCase {

	private $post_type = 'mock_post_type';

	/**
	 * @since 4.10.0
	 * @var LLMS_Post_Model
	 */
	protected $stub;

	/**
	 * Setup before class.
	 *
	 * @since 4.10.0
	 * @since 5.3.3 Renamed from `setUpBeforeClass()` for compat with WP core changes.
	 *
	 * @return void
	 */
	public static function set_up_before_class() {

		parent::set_up_before_class();
		register_post_type( 'mock_post_type' );

	}

	/**
	 * Teradown after class.
	 *
	 * @since 4.10.0
	 * @since 5.3.3 Renamed from `tearDownAfterClass()` for compat with WP core changes.
	 *
	 * @return void
	 */
	public static function tear_down_after_class() {

		parent::tear_down_after_class();
		unregister_post_type( 'mock_post_type' );

	}

	/**
	 * Setup the test case
	 *
	 * @since 4.10.0
	 * @since 5.3.3 Renamed from `setUp()` for compat with WP core changes.
	 *
	 * @return void
	 */
	public function set_up() {

		parent::set_up();
		$this->stub = $this->get_stub();

	}

	/**
	 * Retrieve the abstract class mock stub
	 *
	 * @since 4.10.0
	 *
	 * @return LLMS_Post_Model
	 */
	private function get_stub() {

		$post = $this->factory->post->create_and_get( array( 'post_type' => $this->post_type ) );
		$stub = $this->getMockForAbstractClass( 'LLMS_Post_Model', array( $post ) );

		LLMS_Unit_Test_Util::set_private_property( $stub, 'db_post_type', $this->post_type );
		LLMS_Unit_Test_Util::set_private_property( $stub, 'model_post_type', $this->post_type );

		return $stub;

	}

	/**
	 * Test get() to ensure properties that should not be scrubbed are not scrubbed.
	 *
	 * @since 4.10.0
	 *
	 * @return void
	 */
	public function test_get_skipped_no_scrub_properties() {

		$tests = array(
			'content' => "<p>has html</p>\n",
			'name'    => 'اسم-آخر', // See https://github.com/gocodebox/lifterlms/pull/1408.
		);

		// Filters should
		foreach ( $tests as $key => $val ) {

			$this->stub->set( $key, $val );

			// The scrub filter should not run when getting the value.
			$actions = did_action( "llms_scrub_{$this->post_type}_field_{$key}" );

			// Characters should not be scrubbed.
			$this->assertEquals( 'name' === $key ? utf8_uri_encode( $val ) : $val, $this->stub->get( $key ) );

			$this->assertSame( $actions, did_action( "llms_scrub_{$this->post_type}_field_{$key}" ) );

		}

	}

	/**
	 * Test `set_bulk()` to ensure single quotes and double quotes are correctly slashed.
	 *
	 * @since 5.3.1
	 *
	 * @return void
	 */
	public function test_set_bulk_quotes() {

		$content = 'Content with "Double" Quotes and \'Single\' Quotes';
		$excerpt = 'Excerpt with "Double" Quotes and \'Single\' Quotes';
		$title   = 'Title with "Double" Quotes and \'Single\' Quotes';

		# Test with KSES filters
		$this->stub->set_bulk( array(
			'content' => $content,
			'excerpt' => $excerpt,
			'title'   => $title,
		) );
		$saved_post = get_post( $this->stub->get( 'id' ) );
		$this->assertEquals( $content, $saved_post->post_content );
		$this->assertEquals( $excerpt, $saved_post->post_excerpt );
		$this->assertEquals( $title, $saved_post->post_title );

		# Test without KSES filters
		kses_remove_filters();
		$this->stub->set_bulk( array(
			'content' => $content,
			'excerpt' => $excerpt,
			'title'   => $title,
		) );
		$saved_post = get_post( $this->stub->get( 'id' ) );
		$this->assertEquals( $content, $saved_post->post_content );
		$this->assertEquals( $excerpt, $saved_post->post_excerpt );
		$this->assertEquals( $title, $saved_post->post_title );
	}

	/**
	 * Test toArray() method.
	 *
	 * @since 5.4.1
	 *
	 * @return void
	 */
	public function test_toArray() {

		// Add custom meta data.
		update_post_meta( $this->stub->get( 'id' ), '_custom_meta', 'meta_value' );

		// Generate the array.
		$array = $this->stub->toArray();

		// Make sure all expected properties are returned.
		$this->assertEqualSets( array_merge( array_keys( $this->stub->get_properties() ), array( 'custom', 'id' ) ), array_keys( $array ) );

		// Values in the array should match the values retrieved by the object getters.
		foreach ( $array as $key => $val ) {

			if ( 'custom' === $key ) {
				$expect = array(
					'_custom_meta' => array(
						'meta_value',
					),
				);
			} elseif ( in_array( $key, array( 'content', 'excerpt', 'title' ), true ) ) {
				$key = "post_{$key}";
				$expect = $this->stub->post->$key;
			} else {
				$expect = $this->stub->get( $key );
			}

			$this->assertEquals( $expect, $val, $key );
		}

	}

	/**
	 * Test toArray() method when the author is expanded.
	 *
	 * @since 5.4.1
	 *
	 * @return void
	 */
	public function test_toArray_expanded_author() {

		$data = array(
			'role'       => 'editor',
			'first_name' => 'Jeffrey',
			'last_name'  => 'Lebowski',
			'description' => "Let me explain something to you. Um, I am not \"Mr. Lebowski\". You're Mr. Lebowski. I'm the Dude. So that's what you call me.",
		);
		$user = $this->factory->user->create_and_get( $data );
		$this->stub->set( 'author', $user->ID );

		unset( $data['role'] );
		$data['id'] = $user->ID;
		$data['email'] = $user->user_email;

		// Generate the array.
		$array = $this->stub->toArray();
		$this->assertEquals( $data, $array['author'] );

	}

}
