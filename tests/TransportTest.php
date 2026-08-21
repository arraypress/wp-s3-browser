<?php
declare( strict_types=1 );

namespace ArrayPress\S3\Tests;

use ArrayPress\S3\Client;
use ArrayPress\S3\Provider;
use ArrayPress\S3\Tests\Support\FakeHttp;
use PHPUnit\Framework\TestCase;

/**
 * A request driven end to end: sign, send, parse, model.
 *
 * This is the layer that had no coverage, and every defect found in this
 * release cycle lived in it — an error parser that never matched a real S3
 * document, a scoped-token 403 reported as bad credentials, a query string
 * signed one way and sent another. None of them are visible from a unit test
 * of the pieces; all of them are visible here.
 */
final class TransportTest extends TestCase {

	protected function setUp(): void {
		FakeHttp::reset();
		$GLOBALS['wp_test_options'] = [];
	}

	private function client(): Client {
		return new Client( Provider::r2( 'abc123' ), 'AKIAIOSFODNN7EXAMPLE', 'secret', false );
	}

	/* --- listing ---------------------------------------------------------- */

	public function test_objects_and_folders_are_parsed(): void {
		FakeHttp::queue_fixture( 'list-objects.xml' );

		$data = $this->client()->get_object_models( 'test-bucket', 1000, 'testing/' )->get_data();

		$this->assertCount( 2, $data['objects'] );
		$this->assertCount( 1, $data['prefixes'] );
		$this->assertSame( 'Phonk - Demo Mix.mp3', $data['objects'][0]->get_filename() );
		$this->assertSame( 7340032, $data['objects'][0]->get_size() );
	}

	public function test_unicode_keys_survive_parsing(): void {
		FakeHttp::queue_fixture( 'list-objects.xml' );

		$data = $this->client()->get_object_models( 'test-bucket', 1000, 'testing/' )->get_data();

		$this->assertSame( 'Déjà vu.wav', $data['objects'][1]->get_filename() );
	}

	public function test_truncation_and_continuation_token_are_surfaced(): void {
		FakeHttp::queue_fixture( 'list-objects-truncated.xml' );

		$data = $this->client()->get_object_models( 'test-bucket' )->get_data();

		$this->assertTrue( $data['truncated'] );
		$this->assertSame( '1ueGcxLPRx1Tr/XYExHnhbYLgveDs2J/wm36Hy4vbOwM=', $data['continuation_token'] );
	}

	/* --- what actually goes on the wire ----------------------------------- */

	/**
	 * The signature is computed over RFC 3986 encoding. http_build_query()'s
	 * default renders a space as '+', which is signed one way and sent
	 * another.
	 */
	public function test_prefix_with_a_space_is_sent_rfc3986_encoded(): void {
		FakeHttp::queue_fixture( 'list-objects.xml' );

		$this->client()->get_object_models( 'test-bucket', 1000, 'my photos/' );

		$url = FakeHttp::last()['url'];

		$this->assertStringContainsString( 'prefix=my%20photos%2F', $url );
		$this->assertStringNotContainsString( '+', $url );
	}

	public function test_request_is_signed(): void {
		FakeHttp::queue_fixture( 'list-objects.xml' );

		$this->client()->get_object_models( 'test-bucket' );

		$headers = FakeHttp::last()['args']['headers'];

		$this->assertArrayHasKey( 'Authorization', $headers );
		$this->assertStringStartsWith( 'AWS4-HMAC-SHA256 ', $headers['Authorization'] );
		$this->assertStringContainsString( 'SignedHeaders=host;x-amz-content-sha256;x-amz-date', $headers['Authorization'] );
	}

	public function test_path_style_provider_puts_the_bucket_in_the_path(): void {
		FakeHttp::queue_fixture( 'list-objects.xml' );

		$this->client()->get_object_models( 'test-bucket' );

		$this->assertStringContainsString( '.r2.cloudflarestorage.com/test-bucket', FakeHttp::last()['url'] );
	}

	/* --- errors ----------------------------------------------------------- */

	/**
	 * R2 returns the error document with no XML declaration. Requiring one
	 * meant every R2 failure arrived as the caller's generic default.
	 */
	public function test_r2_error_without_a_prolog_is_parsed(): void {
		FakeHttp::queue_fixture( 'error-access-denied-r2.xml', 403 );

		$result = $this->client()->get_object_models( 'test-bucket' );

		$this->assertFalse( $result->is_successful() );
		$this->assertSame( 'AccessDenied', $result->get_error_code() );
	}

	public function test_no_such_bucket_is_reported_as_itself(): void {
		FakeHttp::queue_fixture( 'error-no-such-bucket.xml', 404 );

		$result = $this->client()->get_object_models( 'nope' );

		$this->assertSame( 'NoSuchBucket', $result->get_error_code() );
		$this->assertStringContainsString( 'does not exist', $result->get_error_message() );
	}

	public function test_bad_credentials_are_distinguishable_from_a_scoped_token(): void {
		FakeHttp::queue_fixture( 'error-signature.xml', 403 );

		$this->assertSame(
			'SignatureDoesNotMatch',
			$this->client()->get_object_models( 'test-bucket' )->get_error_code()
		);
	}

	/**
	 * A bucket-scoped token cannot list buckets. That is the recommended R2
	 * setup, not a credential problem, and must not be reported as one.
	 */
	public function test_scoped_token_listing_denial_is_its_own_condition(): void {
		FakeHttp::queue_fixture( 'error-access-denied-r2.xml', 403 );

		$result = $this->client()->get_bucket_models();

		$this->assertFalse( $result->is_successful() );
		$this->assertSame( 'bucket_listing_forbidden', $result->get_error_code() );
	}

	public function test_non_xml_error_body_falls_back_without_crashing(): void {
		FakeHttp::queue( 502, 'upstream connect error' );

		$result = $this->client()->get_object_models( 'test-bucket' );

		$this->assertFalse( $result->is_successful() );
		$this->assertNotSame( '', $result->get_error_message() );
	}

	/* --- buckets ---------------------------------------------------------- */

	public function test_bucket_listing_is_parsed(): void {
		FakeHttp::queue_fixture( 'list-buckets.xml' );

		$data = $this->client()->get_bucket_models()->get_data();

		$this->assertCount( 2, $data['buckets'] );
		$this->assertSame( 'test-bucket', $data['buckets'][0]->get_name() );
	}

	/**
	 * With listing refused, a known bucket is confirmed by listing one object
	 * from it instead — the path that lets a scoped token browse at all.
	 */
	public function test_known_buckets_are_used_when_listing_is_refused(): void {
		FakeHttp::queue_fixture( 'error-access-denied-r2.xml', 403 );  // ListBuckets
		FakeHttp::queue_fixture( 'list-objects-empty.xml' );           // existence probe

		$client = $this->client();
		$client->set_known_buckets( [ 'test-bucket' ] );

		$data = $client->get_bucket_models()->get_data();

		$this->assertCount( 1, $data['buckets'] );
		$this->assertSame( 'test-bucket', $data['buckets'][0]->get_name() );
		$this->assertTrue( $data['scoped'] );
	}

	/**
	 * Uploading an object server-side.
	 *
	 * The browser uploads through a presigned URL from the client, so this
	 * path had no caller in either plugin and no test -- which in this library
	 * has repeatedly meant "quietly broken". It is the building block any
	 * migration of existing files to storage would use.
	 */
	public function test_put_object_sends_the_body_and_content_type(): void {
		$file = tempnam( sys_get_temp_dir(), 's3' );
		file_put_contents( $file, 'hello world' );

		FakeHttp::queue( 200, '', [ 'etag' => '"abc123"' ] );

		$result = $this->client()->put_object( 'test-bucket', 'docs/hello.txt', $file, true, 'text/plain' );

		unlink( $file );

		$this->assertTrue( $result->is_successful(), $result->is_successful() ? '' : $result->get_error_message() );

		$request = FakeHttp::requests()[0];

		$this->assertSame( 'PUT', strtoupper( $request['args']['method'] ) );
		$this->assertSame( 'hello world', $request['args']['body'] );
		$this->assertStringContainsString( 'docs/hello.txt', $request['url'] );
		$this->assertSame( 'text/plain', $request['args']['headers']['Content-Type'] ?? null );
	}

	public function test_put_object_accepts_a_body_rather_than_a_path(): void {
		FakeHttp::queue( 200 );

		$result = $this->client()->put_object( 'test-bucket', 'notes.txt', 'inline content', false, 'text/plain' );

		$this->assertTrue( $result->is_successful() );
		$this->assertSame( 'inline content', FakeHttp::requests()[0]['args']['body'] );
	}

	public function test_put_object_reports_a_missing_file(): void {
		$result = $this->client()->put_object( 'test-bucket', 'gone.txt', '/no/such/file', true );

		$this->assertFalse( $result->is_successful() );
		$this->assertSame( [], FakeHttp::requests(), 'Nothing should be sent for a file that is not there' );
	}

	public function test_put_object_relays_a_refusal(): void {
		FakeHttp::queue_fixture( 'error-access-denied-r2.xml', 403 );

		$result = $this->client()->put_object( 'test-bucket', 'denied.txt', 'content', false );

		$this->assertFalse( $result->is_successful() );
		$this->assertSame( 'AccessDenied', $result->get_error_code() );
	}
}
