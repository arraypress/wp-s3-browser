<?php
declare( strict_types=1 );

namespace ArrayPress\S3\Tests\Admin;

use ArrayPress\S3\Admin\Config;
use ArrayPress\S3\Admin\Screen;
use PHPUnit\Framework\TestCase;

/**
 * Whether the browser belongs on the page being rendered.
 *
 * These decide whether a media tab appears at all. Answering yes too often
 * puts a bucket browser on every post type on the site; answering no too often
 * makes a configured integration silently not exist.
 */
final class ScreenTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['test_user_can']      = true;
		$GLOBALS['wp_test_screen']     = null;
		$GLOBALS['wp_test_post_types'] = [];
		unset( $_REQUEST['post_id'] );
	}

	private function screen( array $post_types = [] ): Screen {
		return new Screen( new Config( 'r2', 'Cloudflare R2', 'upload_files', 'bucket', $post_types, 'edd' ) );
	}

	// -- Post type restrictions -------------------------------------------

	public function test_an_unrestricted_browser_allows_any_post_type(): void {
		$this->assertTrue( $this->screen()->allows_post_type( 'anything' ) );
	}

	public function test_a_restricted_browser_allows_only_its_own(): void {
		$screen = $this->screen( [ 'download' ] );

		$this->assertTrue( $screen->allows_post_type( 'download' ) );
		$this->assertFalse( $screen->allows_post_type( 'product' ) );
	}

	// -- The current post --------------------------------------------------

	public function test_the_post_id_comes_from_the_request(): void {
		$_REQUEST['post_id'] = '42';

		$this->assertSame( 42, $this->screen()->current_post_id() );
	}

	public function test_no_post_means_zero(): void {
		$this->assertSame( 0, $this->screen()->current_post_id() );
	}

	/**
	 * The media modal is an iframe with no post loaded, so a browser confined
	 * to one post type has no way to know it belongs, and stays hidden. An
	 * unrestricted one has nothing to check and appears.
	 */
	public function test_with_no_post_a_restricted_browser_hides_and_an_open_one_shows(): void {
		$this->assertFalse( $this->screen( [ 'download' ] )->allows_current_post() );
		$this->assertTrue( $this->screen()->allows_current_post() );
	}

	public function test_with_a_post_the_restriction_is_applied(): void {
		$_REQUEST['post_id']              = '7';
		$GLOBALS['wp_test_post_types'][7] = 'download';

		$this->assertTrue( $this->screen( [ 'download' ] )->allows_current_post() );
		$this->assertFalse( $this->screen( [ 'product' ] )->allows_current_post() );
	}

	// -- Editing screens ---------------------------------------------------

	public function test_an_edit_screen_for_the_right_post_type_is_recognised(): void {
		$GLOBALS['wp_test_screen'] = (object) [ 'post_type' => 'download' ];

		$screen = $this->screen( [ 'download' ] );

		$this->assertTrue( $screen->is_editing( 'download', 'post.php' ) );
		$this->assertTrue( $screen->is_editing( 'download', 'post-new.php' ) );
	}

	/**
	 * The post type list screen is not the editor, and loading the browser
	 * there does nothing but cost a request.
	 */
	public function test_a_list_screen_is_not_an_edit_screen(): void {
		$GLOBALS['wp_test_screen'] = (object) [ 'post_type' => 'download' ];

		$this->assertFalse( $this->screen( [ 'download' ] )->is_editing( 'download', 'edit.php' ) );
	}

	public function test_the_wrong_post_type_is_refused(): void {
		$GLOBALS['wp_test_screen'] = (object) [ 'post_type' => 'product' ];

		$this->assertFalse( $this->screen( [ 'download' ] )->is_editing( 'download', 'post.php' ) );
	}

	public function test_a_user_without_the_capability_is_refused(): void {
		$GLOBALS['wp_test_screen'] = (object) [ 'post_type' => 'download' ];
		$GLOBALS['test_user_can']  = false;

		$this->assertFalse( $this->screen( [ 'download' ] )->is_editing( 'download', 'post.php' ) );
		$this->assertFalse( $this->screen( [ 'download' ] )->is_showing( 'download' ) );
	}

	public function test_no_screen_at_all_is_refused(): void {
		$this->assertFalse( $this->screen()->is_showing( 'download' ) );
	}
}
