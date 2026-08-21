<?php
/**
 * Presigned URL Operations Trait
 *
 * Handles presigned URL operations for S3-compatible storage.
 *
 * @package     ArrayPress\S3\Traits
 * @copyright   Copyright (c) 2025, ArrayPress Limited
 * @license     GPL2+
 * @version     1.0.0
 * @author      David Sherlock
 */

declare( strict_types=1 );

namespace ArrayPress\S3\Traits\Signer;

use ArrayPress\S3\Interfaces\Response as ResponseInterface;
use ArrayPress\S3\Responses\PresignedUrlResponse;
use ArrayPress\S3\Responses\ErrorResponse;
use ArrayPress\S3\Utils\Timestamp;

/**
 * Trait PresignedUrls
 */
trait PresignedUrls {

	/**
	 * SigV4 caps presigned-URL validity at 7 days.
	 */
	private static int $max_presign_minutes = 10080;

	/**
	 * Generate a pre-signed URL for downloading an object
	 *
	 * @param string $bucket     Bucket name
	 * @param string $object_key Object key
	 * @param int    $expires    Expiration time in minutes
	 *
	 * @return ResponseInterface Presigned URL response
	 */
	public function get_presigned_url( string $bucket, string $object_key, int $expires = 60 ): ResponseInterface {
		return $this->build_presigned_url( 'GET', $bucket, $object_key, $expires );
	}

	/**
	 * Generate a pre-signed URL for uploading (PUT) an object
	 *
	 * @param string $bucket     Bucket name
	 * @param string $object_key Object key
	 * @param int    $expires    Expiration time in minutes
	 *
	 * @return ResponseInterface Presigned URL response
	 */
	public function get_presigned_upload_url( string $bucket, string $object_key, int $expires = 15 ): ResponseInterface {
		return $this->build_presigned_url( 'PUT', $bucket, $object_key, $expires );
	}

	/**
	 * Build a presigned URL for an arbitrary method
	 *
	 * GET and PUT presigning differ only in the verb on the first line of the
	 * canonical request, so both share this implementation. Keeping one copy
	 * matters here: the canonical URI, the Host header and the query string
	 * must agree exactly with what is sent, and two hand-maintained copies of
	 * that logic drift.
	 *
	 * @param string $method     HTTP method ('GET' or 'PUT')
	 * @param string $bucket     Bucket name
	 * @param string $object_key Object key
	 * @param int    $expires    Expiration time in minutes
	 *
	 * @return ResponseInterface Presigned URL response
	 */
	protected function build_presigned_url(
		string $method,
		string $bucket,
		string $object_key,
		int $expires
	): ResponseInterface {
		if ( empty( $bucket ) || empty( $object_key ) ) {
			return new ErrorResponse(
				__( 'Bucket and object key are required', 'arraypress' ),
				'invalid_parameters',
				400
			);
		}

		// Negative or zero values produce malformed URLs; absurdly large values
		// get rejected by the provider but leak credential metadata over the
		// wire first. Clamp here so every caller stays inside the spec.
		$expires         = max( 1, min( $expires, self::$max_presign_minutes ) );
		$expires_seconds = $expires * 60;
		$expires_at      = Timestamp::in_minutes( $expires );

		$time      = time();
		$amz_date  = gmdate( 'Ymd\THis\Z', $time );
		$datestamp = gmdate( 'Ymd', $time );

		// Ask the provider for both, so path-style and virtual-hosted addressing
		// stay consistent. Hardcoding "/bucket/key" here signs a path that a
		// virtual-hosted provider never receives — the bucket is in the host.
		$host          = $this->provider->get_request_host( $bucket );
		$canonical_uri = $this->provider->format_canonical_uri( $bucket, $object_key );

		$credential_scope = $datestamp . '/' . $this->provider->get_region() . '/s3/aws4_request';

		$query_params = [
			'X-Amz-Algorithm'     => 'AWS4-HMAC-SHA256',
			'X-Amz-Credential'    => $this->access_key . '/' . $credential_scope,
			'X-Amz-Date'          => $amz_date,
			'X-Amz-Expires'       => (string) $expires_seconds,
			'X-Amz-SignedHeaders' => 'host',
		];

		$canonical_querystring = $this->build_canonical_query_string( $query_params );

		$canonical_request = $method . "\n" .
		                     $canonical_uri . "\n" .
		                     $canonical_querystring . "\n" .
		                     'host:' . $host . "\n" .
		                     "\n" .
		                     "host\n" .
		                     'UNSIGNED-PAYLOAD';

		$this->debug( "Presigned {$method} Canonical Request", $canonical_request );

		$string_to_sign = "AWS4-HMAC-SHA256\n" .
		                  $amz_date . "\n" .
		                  $credential_scope . "\n" .
		                  hash( 'sha256', $canonical_request );

		$signature = $this->calculate_signature( $string_to_sign, $datestamp );

		// The signed canonical URI is the request path, so reuse it verbatim
		// rather than re-deriving (and re-encoding) it.
		$presigned_url = 'https://' . $host . $canonical_uri
		                 . '?' . $canonical_querystring
		                 . '&X-Amz-Signature=' . $signature;

		return new PresignedUrlResponse( $presigned_url, $expires_at );
	}

}
