<?php
declare( strict_types=1 );

namespace ArrayPress\S3\Tests\Xml;

use ArrayPress\S3\Xml\Parser;
use ArrayPress\S3\Xml\Response;
use PHPUnit\Framework\TestCase;

/**
 * S3 response shapes.
 *
 * The recurring hazard is arity: a listing with one object and a listing with
 * several arrive in different shapes, and code that handles only the plural
 * case silently returns nothing for a bucket holding a single file.
 */
final class ResponseTest extends TestCase {

	private function parse( string $file ): array {
		$parsed = Parser::parse( file_get_contents( __DIR__ . '/../fixtures/' . $file ) );

		$this->assertIsArray( $parsed, "Fixture {$file} did not parse" );

		return $parsed;
	}

	private function xml( string $xml ): array {
		$parsed = Parser::parse( $xml );

		$this->assertIsArray( $parsed );

		return $parsed;
	}

	// -- Objects ----------------------------------------------------------

	public function test_objects_listing_reads_keys_and_prefixes(): void {
		$result = Response::objects( $this->parse( 'list-objects.xml' ) );

		$this->assertNotEmpty( $result['objects'] );
		$this->assertNotEmpty( $result['prefixes'] );
		$this->assertIsBool( $result['truncated'] );
	}

	public function test_objects_listing_with_one_object(): void {
		$result = Response::objects( $this->xml(
			'<ListBucketResult><Contents><Key>solo.txt</Key><Size>12</Size></Contents></ListBucketResult>'
		) );

		$this->assertCount( 1, $result['objects'] );
		$this->assertSame( 'solo.txt', $result['objects'][0]['Key'] );
		$this->assertSame( 12, $result['objects'][0]['Size'] );
	}

	public function test_objects_listing_with_several_objects(): void {
		$result = Response::objects( $this->xml(
			'<ListBucketResult>'
			. '<Contents><Key>a.txt</Key></Contents>'
			. '<Contents><Key>b.txt</Key></Contents>'
			. '<Contents><Key>c.txt</Key></Contents>'
			. '</ListBucketResult>'
		) );

		$this->assertSame( [ 'a.txt', 'b.txt', 'c.txt' ], array_column( $result['objects'], 'Key' ) );
	}

	public function test_objects_listing_with_one_prefix(): void {
		$result = Response::objects( $this->xml(
			'<ListBucketResult><CommonPrefixes><Prefix>docs/</Prefix></CommonPrefixes></ListBucketResult>'
		) );

		$this->assertSame( [ 'docs/' ], $result['prefixes'] );
	}

	public function test_objects_listing_with_several_prefixes(): void {
		$result = Response::objects( $this->xml(
			'<ListBucketResult>'
			. '<CommonPrefixes><Prefix>a/</Prefix></CommonPrefixes>'
			. '<CommonPrefixes><Prefix>b/</Prefix></CommonPrefixes>'
			. '</ListBucketResult>'
		) );

		$this->assertSame( [ 'a/', 'b/' ], $result['prefixes'] );
	}

	public function test_empty_listing_yields_empty_arrays(): void {
		$result = Response::objects( $this->parse( 'list-objects-empty.xml' ) );

		$this->assertSame( [], $result['objects'] );
		$this->assertSame( [], $result['prefixes'] );
		$this->assertFalse( $result['truncated'] );
	}

	/**
	 * The token is only meaningful when the listing is truncated; carrying a
	 * stale one forward would re-request the same page.
	 */
	public function test_continuation_token_only_survives_truncation(): void {
		$truncated = Response::objects( $this->xml(
			'<ListBucketResult><IsTruncated>true</IsTruncated>'
			. '<NextContinuationToken>abc123</NextContinuationToken></ListBucketResult>'
		) );

		$complete = Response::objects( $this->xml(
			'<ListBucketResult><IsTruncated>false</IsTruncated>'
			. '<NextContinuationToken>abc123</NextContinuationToken></ListBucketResult>'
		) );

		$this->assertSame( 'abc123', $truncated['continuation_token'] );
		$this->assertSame( '', $complete['continuation_token'] );
	}

	// -- Buckets ----------------------------------------------------------

	public function test_buckets_listing_reads_names(): void {
		$result = Response::buckets( $this->parse( 'list-buckets.xml' ) );

		$this->assertNotEmpty( $result['buckets'] );
		$this->assertArrayHasKey( 'Name', $result['buckets'][0] );
		$this->assertArrayHasKey( 'CreationDate', $result['buckets'][0] );
	}

	public function test_buckets_listing_with_one_bucket(): void {
		$result = Response::buckets( $this->xml(
			'<ListAllMyBucketsResult><Buckets><Bucket>'
			. '<Name>only-bucket</Name><CreationDate>2024-01-01T00:00:00.000Z</CreationDate>'
			. '</Bucket></Buckets></ListAllMyBucketsResult>'
		) );

		$this->assertCount( 1, $result['buckets'] );
		$this->assertSame( 'only-bucket', $result['buckets'][0]['Name'] );
	}

	public function test_buckets_listing_with_several_buckets(): void {
		$result = Response::buckets( $this->xml(
			'<ListAllMyBucketsResult><Buckets>'
			. '<Bucket><Name>one</Name><CreationDate>2024-01-01T00:00:00.000Z</CreationDate></Bucket>'
			. '<Bucket><Name>two</Name><CreationDate>2024-01-02T00:00:00.000Z</CreationDate></Bucket>'
			. '</Buckets></ListAllMyBucketsResult>'
		) );

		$this->assertSame( [ 'one', 'two' ], array_column( $result['buckets'], 'Name' ) );
	}

	public function test_owner_is_read_when_present(): void {
		$result = Response::buckets( $this->xml(
			'<ListAllMyBucketsResult><Owner><ID>abc</ID><DisplayName>dave</DisplayName></Owner>'
			. '<Buckets></Buckets></ListAllMyBucketsResult>'
		) );

		$this->assertSame( 'abc', $result['owner']['ID'] );
		$this->assertSame( 'dave', $result['owner']['DisplayName'] );
	}

	public function test_owner_is_null_when_absent(): void {
		$this->assertNull( Response::buckets( $this->xml( '<ListAllMyBucketsResult/>' ) )['owner'] );
	}

	// -- CORS -------------------------------------------------------------

	public function test_cors_reads_a_single_rule(): void {
		$rules = Response::cors( $this->xml(
			'<CORSConfiguration><CORSRule>'
			. '<AllowedOrigin>https://example.com</AllowedOrigin>'
			. '<AllowedMethod>GET</AllowedMethod><AllowedMethod>PUT</AllowedMethod>'
			. '<MaxAgeSeconds>3000</MaxAgeSeconds>'
			. '</CORSRule></CORSConfiguration>'
		) );

		$this->assertCount( 1, $rules );
		$this->assertSame( [ 'https://example.com' ], $rules[0]['AllowedOrigins'] );
		$this->assertSame( [ 'GET', 'PUT' ], $rules[0]['AllowedMethods'] );
		$this->assertSame( 3000, $rules[0]['MaxAgeSeconds'] );
	}

	public function test_cors_reads_several_rules(): void {
		$rules = Response::cors( $this->xml(
			'<CORSConfiguration>'
			. '<CORSRule><AllowedOrigin>https://a.com</AllowedOrigin><AllowedMethod>GET</AllowedMethod></CORSRule>'
			. '<CORSRule><AllowedOrigin>https://b.com</AllowedOrigin><AllowedMethod>PUT</AllowedMethod></CORSRule>'
			. '</CORSConfiguration>'
		) );

		$this->assertCount( 2, $rules );
		$this->assertSame( [ 'https://b.com' ], $rules[1]['AllowedOrigins'] );
	}

	/**
	 * A max-age of zero tells the browser not to cache the preflight. It is an
	 * instruction, not an absent field, so it must survive the empty-value filter.
	 */
	public function test_cors_keeps_a_zero_max_age(): void {
		$rules = Response::cors( $this->xml(
			'<CORSConfiguration><CORSRule><AllowedOrigin>*</AllowedOrigin>'
			. '<MaxAgeSeconds>0</MaxAgeSeconds></CORSRule></CORSConfiguration>'
		) );

		$this->assertArrayHasKey( 'MaxAgeSeconds', $rules[0] );
		$this->assertSame( 0, $rules[0]['MaxAgeSeconds'] );
	}

	public function test_cors_of_an_unconfigured_bucket_is_empty(): void {
		$this->assertSame( [], Response::cors( $this->xml( '<CORSConfiguration/>' ) ) );
	}

	// -- Batch delete -----------------------------------------------------

	public function test_batch_delete_counts_one_deletion(): void {
		$result = Response::batch_delete( $this->xml(
			'<DeleteResult><Deleted><Key>gone.txt</Key></Deleted></DeleteResult>'
		) );

		$this->assertSame( 1, $result['success_count'] );
		$this->assertSame( 0, $result['error_count'] );
		$this->assertSame( 'gone.txt', $result['deleted'][0]['key'] );
	}

	public function test_batch_delete_counts_many_deletions(): void {
		$result = Response::batch_delete( $this->xml(
			'<DeleteResult>'
			. '<Deleted><Key>a.txt</Key></Deleted>'
			. '<Deleted><Key>b.txt</Key></Deleted>'
			. '</DeleteResult>'
		) );

		$this->assertSame( 2, $result['success_count'] );
	}

	public function test_batch_delete_separates_failures(): void {
		$result = Response::batch_delete( $this->xml(
			'<DeleteResult>'
			. '<Deleted><Key>ok.txt</Key></Deleted>'
			. '<Error><Key>locked.txt</Key><Code>AccessDenied</Code><Message>Denied</Message></Error>'
			. '</DeleteResult>'
		) );

		$this->assertSame( 1, $result['success_count'] );
		$this->assertSame( 1, $result['error_count'] );
		$this->assertSame( 'AccessDenied', $result['errors'][0]['code'] );
		$this->assertSame( 'locked.txt', $result['errors'][0]['key'] );
	}

	// -- Copy -------------------------------------------------------------

	public function test_copy_result_strips_etag_quotes(): void {
		$result = Response::copy( $this->xml(
			'<CopyObjectResult><ETag>&quot;abc123&quot;</ETag>'
			. '<LastModified>2024-05-01T10:00:00.000Z</LastModified></CopyObjectResult>'
		) );

		$this->assertSame( 'abc123', $result['etag'] );
		$this->assertSame( '2024-05-01T10:00:00.000Z', $result['last_modified'] );
	}

	public function test_copy_result_of_an_unexpected_document_is_empty(): void {
		$this->assertSame(
			[ 'etag' => '', 'last_modified' => '' ],
			Response::copy( $this->xml( '<Something/>' ) )
		);
	}
}
