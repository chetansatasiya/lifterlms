<?php
/**
* Test updates functions when updating to 4.15.0
 *
 * @package LifterLMS/Tests/Functions/Updates
 *
 * @group functions
 * @group updates
 * @group updates_500
 *
 * @since [version]
 * @version [version]
 */
class LLMS_Test_Functions_Updates_500 extends LLMS_UnitTestCase {

	/**
	 * Setup before class
	 *
	 * Include update functions file.
	 *
	 * @since [version]
	 *
	 * @return void
	 */
	public static function setupBeforeClass() {
		parent::setupBeforeClass();
		require_once LLMS_PLUGIN_DIR . 'includes/functions/updates/llms-functions-updates-500.php';
		require_once LLMS_PLUGIN_DIR . 'includes/admin/class.llms.admin.notices.php';
	}

	/**
	 * Teardown the test case
	 *
	 * @since [version]
	 *
	 * @return void
	 */
	public function tearDown() {
		parent::tearDown();
		// Delete transients.
		delete_transient( 'llms_update_500_autoload_off_legacy_options' );
	}


	/**
	 * Test llms_update_500_legacy_options_autoload_off() method
	 *
	 * @since [version]
	 *
	 * @return void
	 */
	public function test_llms_update_500_legacy_options_autoload_off() {

		global $wpdb;

		$legacy_options_to_stop_autoloading = array(
			'lifterlms_registration_generate_username',
			'lifterlms_registration_password_strength',
			'lifterlms_registration_password_min_strength',
		);

		// Firs create them, by default they are autoloaded.
		array_map( 'add_option', $legacy_options_to_stop_autoloading, array_fill( 0, count( $legacy_options_to_stop_autoloading ), 'yes' ) );

		$check_options_query  = "SELECT option_name FROM $wpdb->options WHERE option_name IN (" . implode( ', ', array_fill( 0, count( $legacy_options_to_stop_autoloading ), '%s' ) ) . ')';
		$check_autoload_query = $check_options_query. ' AND autoload="yes"';

		// Check they are autoloaded.
		$this->assertEquals( count( $legacy_options_to_stop_autoloading ), $wpdb->query( $wpdb->prepare( $check_autoload_query, $legacy_options_to_stop_autoloading ) ) );

		llms_update_500_legacy_options_autoload_off();

		// Check they are not autoloaded anymore and check they exist :D.
		$this->assertEquals( 0, $wpdb->query( $wpdb->prepare( $check_autoload_query, $legacy_options_to_stop_autoloading ) ) );
		$this->assertEqualSets( $legacy_options_to_stop_autoloading, $wpdb->get_col( $wpdb->prepare( $check_options_query, $legacy_options_to_stop_autoloading ) ) );

		array_map( 'delete_option', $legacy_options_to_stop_autoloading );

	}


	/**
	 * Test llms_update_500_update_db_version()
	 *
	 * @since [version]
	 *
	 * @return void
	 */
	public function test_update_db_version() {

		$orig = get_option( 'lifterlms_db_version' );

		// Remove existing db version.
		delete_option( 'lifterlms_db_version' );

		llms_update_500_update_db_version();

		$this->assertNotEquals( '5.0.0', get_option( 'lifterlms_db_version' ) );

		// Unlock the db version update.
		set_transient( 'llms_update_500_autoload_off_legacy_options', 'complete', DAY_IN_SECONDS );

		llms_update_500_update_db_version();

		$this->assertEquals( '5.0.0', get_option( 'lifterlms_db_version' ) );

		update_option( 'lifterlms_db_version', $orig );

	}

}
