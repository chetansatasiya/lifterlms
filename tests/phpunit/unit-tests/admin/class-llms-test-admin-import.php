<?php
/**
 * Tests for LLMS_Admin_Review class
 *
 * @package LifterLMS_Tests/Admin
 *
 * @group admin
 * @group admin_import
 *
 * @since 3.35.0
 * @since 3.37.8 Update path to assets directory.
 * @since 4.7.0 Test success message generation.
 */
class LLMS_Test_Admin_Import extends LLMS_UnitTestCase {

	/**
	 * Setup test case.
	 *
	 * @since 3.35.0
	 *
	 * @return void
	 */
	public function setUp() {

		parent::setUp();

		include_once LLMS_PLUGIN_DIR . 'includes/admin/class.llms.admin.import.php';
		include_once LLMS_PLUGIN_DIR . 'includes/admin/class.llms.admin.notices.php';

		$this->import = new LLMS_Admin_Import();

	}

	/**
	 * Tear down test case.
	 *
	 * @since 3.35.0
	 *
	 * @return void
	 */
	public function tearDown() {

		parent::tearDown();
		unset( $_FILES['llms_import'] );

	}

	/**
	 * Mock a file upload for some test data.
	 *
	 * @since 3.35.0
	 *
	 * @param int $err Mock a PHP file upload error code, see https://www.php.net/manual/en/features.file-upload.errors.php.
	 * @param string $import Filename to use for the import, see `import-*.json` files in the `tests/assets` directory.
	 * @return void
	 */
	private function mock_file_upload( $err = 0, $import = null ) {

		$file = is_null( $import ) ? LLMS_PLUGIN_DIR . 'sample-data/sample-course.json' : $import;

		$_FILES['llms_import'] = array(
			'name' => basename( $file ),
			'tmp_name' => $file,
			'type' => 'application/json',
			'error' => $err,
			'size' => filesize( $file ),
		);

	}

	/**
	 * Test get_success_message()
	 *
	 * @since 4.7.0
	 *
	 * @return void
	 */
	public function test_get_success_message() {

		wp_set_current_user( $this->factory->user->create( array( 'role' => 'administrator' ) ) );

		$generator = new LLMS_Generator( array() );
		$course = $this->factory->post->create_many( 2, array( 'post_type' => 'course' ) );
		$user = $this->factory->user->create_many( 1 );
		LLMS_Unit_Test_Util::set_private_property( $generator, 'generated', compact( 'course', 'user' ) );

		$res = LLMS_Unit_Test_Util::call_method( $this->import, 'get_success_message', array( $generator ) );

		$this->assertStringContains( 'Import Successful!', $res );

		foreach( $course as $id ) {
			$this->assertStringContains( esc_url( get_edit_post_link( $id ) ), $res );
			$this->assertStringContains( get_the_title( $id ), $res );
		}

		$user = new WP_User( $user[0] );
		$this->assertStringContains( esc_url( get_edit_user_link( $user->ID ) ), $res );
		$this->assertStringContains( $user->display_name, $res );

	}

	/**
	 * Upload form not submitted.
	 *
	 * @since 3.35.0
	 *
	 * @return [type]
	 */
	public function test_import_not_submitted() {

		$this->assertFalse( $this->import->upload_import() );

	}

	/**
	 * Submitted with an invalid nonce.
	 *
	 * @since 3.35.0
	 *
	 * @return void
	 */
	public function test_upload_import_invalid_nonce() {

		$this->mockPostRequest( array(
			'llms_importer_nonce' => 'fake',
		) );
		$this->assertFalse( $this->import->upload_import() );

	}

	/**
	 * Submitted without files.
	 *
	 * @since 3.35.0
	 *
	 * @return void
	 */
	public function test_upload_import_missing_files() {

		$this->mockPostRequest( array(
			'llms_importer_nonce' => wp_create_nonce( 'llms-importer' ),
		) );
		$this->assertFalse( $this->import->upload_import() );

	}

	/**
	 * Submitted by a user without proper permissions.
	 *
	 * @since 3.35.0
	 *
	 * @return void
	 */
	public function test_upload_import_invalid_permissions() {

		$this->mockPostRequest( array(
			'llms_importer_nonce' => wp_create_nonce( 'llms-importer' ),
		) );
		$this->mock_file_upload();
		$this->assertFalse( $this->import->upload_import() );


	}

	/**
	 * File encountered validation errors.
	 *
	 * @since 3.35.0
	 *
	 * @return void
	 */
	public function test_upload_import_validation_issues() {

		wp_set_current_user( $this->factory->student->create( array( 'role' => 'administrator' ) ) );
		$this->mockPostRequest( array(
			'llms_importer_nonce' => wp_create_nonce( 'llms-importer' ),
		) );

		// Test all the possible PHP file errors.
		$errs = array(
			1 => 'The uploaded file exceeds the upload_max_filesize directive in php.ini.',
			2 => 'The uploaded file exceeds the MAX_FILE_SIZE directive that was specified in the HTML form.',
			3 => 'The uploaded file was only partially uploaded.',
			4 => 'No file was uploaded.',
			6 => 'Missing a temporary folder.',
			7 => 'Failed to write file to disk.',
			8 => 'File upload stopped by extension.',
			9 => 'Unknown upload error.',
		);
		foreach ( $errs as $i => $msg ) {

			$this->mock_file_upload( $i );
			$err = $this->import->upload_import();
			$this->assertIsWPError( $err );
			$this->assertWPErrorMessageEquals( $msg, $err );

		}

		// invalid filetype.
		$this->mock_file_upload();
		$_FILES['llms_import']['name'] = 'mock.txt';

		$err = $this->import->upload_import();
		$this->assertIsWPError( $err );
		$this->assertWPErrorMessageEquals( 'Only valid JSON files can be imported.', $err );

	}

	/**
	 * Generator encountered an issues when setting the generator method.
	 *
	 * @since 3.35.0
	 * @since 3.37.8 Update path to assets directory.
	 *
	 * @return void
	 */
	public function test_upload_import_invalid_generator_error() {

		wp_set_current_user( $this->factory->student->create( array( 'role' => 'administrator' ) ) );
		$this->mockPostRequest( array(
			'llms_importer_nonce' => wp_create_nonce( 'llms-importer' ),
		) );

		global $lifterlms_tests;
		$this->mock_file_upload( 0, $lifterlms_tests->assets_dir . 'import-fake-generator.json' );

		$err = $this->import->upload_import();
		$this->assertIsWPError( $err );
		$this->assertWPErrorCodeEquals( 'invalid-generator', $err );

	}

	/**
	 * Error during generation (missing required data)
	 *
	 * @since 3.35.0
	 * @since 3.37.8 Update path to assets directory.
	 *
	 * @return void
	 */
	public function test_upload_import_generation_error() {

		wp_set_current_user( $this->factory->student->create( array( 'role' => 'administrator' ) ) );
		$this->mockPostRequest( array(
			'llms_importer_nonce' => wp_create_nonce( 'llms-importer' ),
		) );

		global $lifterlms_tests;
		$this->mock_file_upload( 0, $lifterlms_tests->assets_dir . 'import-error.json' );

		$err = $this->import->upload_import();
		$this->assertIsWPError( $err );
		$this->assertWPErrorCodeEquals( 'exception', $err );

	}

	/**
	 * Success.
	 *
	 * @since 3.35.0
	 *
	 * @return void
	 */
	public function test_upload_import_success() {

		wp_set_current_user( $this->factory->student->create( array( 'role' => 'administrator' ) ) );
		$this->mockPostRequest( array(
			'llms_importer_nonce' => wp_create_nonce( 'llms-importer' ),
		) );
		$this->mock_file_upload();

		$this->assertTrue( $this->import->upload_import() );

	}

	/**
	 * Test output() method
	 *
	 * @since 4.7.0
	 *
	 * @return void
	 */
	public function test_output() {

		$this->assertOutputContains( '<div class="wrap lifterlms llms-import-export">', array( $this->import, 'output' ) );

	}

}
