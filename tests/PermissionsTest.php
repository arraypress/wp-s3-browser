<?php
declare( strict_types=1 );

namespace ArrayPress\S3\Tests;

use ArrayPress\S3\Client;
use ArrayPress\S3\Provider;
use ArrayPress\S3\Tests\Support\FakeHttp;
use PHPUnit\Framework\TestCase;

/**
 * Working out what a set of credentials can do.
 *
 * S3 has no call that answers this, so the only way to know whether a token
 * may write is to write something. That makes this the one part of the library
 * that deliberately modifies a customer's bucket, and these tests are mostly
 * about keeping that rare and tidy.
 */
final class PermissionsTest extends TestCase {

	protected function setUp(): void {
		FakeHttp::reset();
		$GLOBALS['wp_test_options'] = [];
	}

	private function client( bool $cache = true ): Client {
		return new Client( Provider::r2( 'account123' ), 'key', 'secret', $cache );
	}

	/** Count requests that changed something in the bucket. */
	private function mutations(): array {
		return array_values( array_filter(
			FakeHttp::requests(),
			static fn( $r ) => in_array( strtoupper( $r['args']['method'] ?? 'GET' ), [ 'PUT', 'POST', 'DELETE' ], true )
		) );
	}

	public function test_a_full_access_token_reports_everything(): void {
		FakeHttp::queue_fixture( 'list-objects-empty.xml' );  // read probe
		FakeHttp::queue( 200 );                               // upload
		FakeHttp::queue( 204 );                               // delete

		$result = $this->client()->permissions()->check( 'test-bucket' );

		$this->assertTrue( $result['read'] );
		$this->assertTrue( $result['write'] );
		$this->assertTrue( $result['delete'] );
		$this->assertSame( [], $result['errors'] );
	}

	/**
	 * A token that cannot list is not going to be allowed to write, so the
	 * destructive half of the probe never runs.
	 */
	public function test_a_refused_read_stops_before_writing_anything(): void {
		FakeHttp::queue_fixture( 'error-access-denied-r2.xml', 403 );

		$result = $this->client()->permissions()->check( 'test-bucket' );

		$this->assertFalse( $result['read'] );
		$this->assertFalse( $result['write'] );
		$this->assertSame( [], $this->mutations(), 'Nothing may be written to a bucket that cannot be read' );
	}

	public function test_a_read_only_token_reports_no_write(): void {
		FakeHttp::queue_fixture( 'list-objects-empty.xml' );          // read probe
		FakeHttp::queue_fixture( 'error-access-denied-r2.xml', 403 ); // upload refused

		$result = $this->client()->permissions()->check( 'test-bucket' );

		$this->assertTrue( $result['read'] );
		$this->assertFalse( $result['write'] );
		$this->assertFalse( $result['delete'] );
		$this->assertArrayHasKey( 'write', $result['errors'] );
	}

	/**
	 * The previous implementation answered a failed delete by uploading a
	 * second file explaining the first. That one is equally undeletable, so
	 * every probe doubled the litter it could not clean up.
	 */
	public function test_a_stranded_probe_object_is_named_not_papered_over(): void {
		FakeHttp::queue_fixture( 'list-objects-empty.xml' );          // read probe
		FakeHttp::queue( 200 );                                       // upload
		FakeHttp::queue_fixture( 'error-access-denied-r2.xml', 403 ); // delete refused

		$result = $this->client()->permissions()->check( 'test-bucket' );

		$this->assertTrue( $result['write'] );
		$this->assertFalse( $result['delete'] );
		$this->assertArrayHasKey( 'leftover_key', $result );
		$this->assertStringContainsString( $result['leftover_key'], $result['errors']['delete'] );

		// One upload, one attempted delete -- and no second upload.
		$uploads = array_filter( $this->mutations(), static fn( $r ) => 'PUT' === strtoupper( $r['args']['method'] ) );
		$this->assertCount( 1, $uploads );
	}

	/**
	 * The old cache was a property on the client, so it never survived a
	 * request -- and the probe runs from a REST endpoint, which builds a fresh
	 * client every time. Opening bucket details wrote to the bucket on every
	 * single view.
	 */
	public function test_a_second_check_does_not_touch_the_bucket_again(): void {
		FakeHttp::queue_fixture( 'list-objects-empty.xml' );
		FakeHttp::queue( 200 );
		FakeHttp::queue( 204 );

		$this->client()->permissions()->check( 'test-bucket' );
		$after_first = count( FakeHttp::requests() );

		// A different client instance, as a second REST request would build.
		$again = $this->client()->permissions()->check( 'test-bucket' );

		$this->assertSame( $after_first, count( FakeHttp::requests() ), 'Cached result must not re-probe' );
		$this->assertTrue( $again['write'] );
	}

	public function test_forgetting_a_bucket_allows_a_fresh_probe(): void {
		FakeHttp::queue_fixture( 'list-objects-empty.xml' );
		FakeHttp::queue( 200 );
		FakeHttp::queue( 204 );

		$permissions = $this->client()->permissions();
		$permissions->check( 'test-bucket' );
		$after_first = count( FakeHttp::requests() );

		$permissions->forget( 'test-bucket' );

		FakeHttp::queue_fixture( 'list-objects-empty.xml' );
		FakeHttp::queue( 200 );
		FakeHttp::queue( 204 );
		$permissions->check( 'test-bucket' );

		$this->assertGreaterThan( $after_first, count( FakeHttp::requests() ) );
	}

	/**
	 * A caller that only wants to know whether the credentials can read should
	 * not have to accept a write to find out.
	 */
	public function test_the_write_probe_can_be_declined(): void {
		FakeHttp::queue_fixture( 'list-objects-empty.xml' );

		$result = $this->client()->permissions()->check( 'test-bucket', true, false );

		$this->assertTrue( $result['read'] );
		$this->assertFalse( $result['write'] );
		$this->assertSame( [], $this->mutations() );
	}

	public function test_the_probe_object_says_what_it_is(): void {
		FakeHttp::queue_fixture( 'list-objects-empty.xml' );
		FakeHttp::queue( 200 );
		FakeHttp::queue( 204 );

		$this->client()->permissions()->check( 'test-bucket' );

		$upload = array_values( array_filter(
			FakeHttp::requests(),
			static fn( $r ) => 'PUT' === strtoupper( $r['args']['method'] ?? '' )
		) )[0];

		$this->assertStringContainsString( 'Safe to delete', $upload['args']['body'] );
		$this->assertStringContainsString( 'permissions-test-', $upload['url'] );
	}
}
