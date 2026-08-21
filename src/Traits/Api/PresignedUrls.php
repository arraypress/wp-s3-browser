<?php
/**
 * Presigned URL Operations Trait
 *
 * @package     ArrayPress\S3\Traits
 * @copyright   Copyright (c) 2025, ArrayPress Limited
 * @license     GPL2+
 * @version     2.0.0
 * @author      David Sherlock
 */

declare( strict_types=1 );

namespace ArrayPress\S3\Traits\Api;

use ArrayPress\S3\Interfaces\Response as ResponseInterface;
use ArrayPress\S3\Responses\PresignedUrlResponse;
use ArrayPress\S3\Responses\ErrorResponse;
use ArrayPress\S3\Utils\Timestamp;
use ArrayPress\S3Signer\Method;
use InvalidArgumentException;

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
		return $this->build_presigned_url( Method::GET, $bucket, $object_key, $expires );
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
		return $this->build_presigned_url( Method::PUT, $bucket, $object_key, $expires );
	}

	/**
	 * Build a presigned URL for a given method
	 *
	 * @param Method $method     HTTP method.
	 * @param string $bucket     Bucket name.
	 * @param string $object_key Object key.
	 * @param int    $expires    Expiration in minutes.
	 *
	 * @return ResponseInterface
	 */
	protected function build_presigned_url(
		Method $method,
		string $bucket,
		string $object_key,
		int $expires
	): ResponseInterface {
		if ( '' === $bucket || '' === $object_key ) {
			return new ErrorResponse(
				__( 'Bucket and object key are required', 'arraypress' ),
				'invalid_parameters',
				400
			);
		}

		// Negative or zero values produce malformed URLs; absurdly large ones
		// are rejected by the provider, but only after the credential scope has
		// gone over the wire. Clamp before signing.
		$expires    = max( 1, min( $expires, self::$max_presign_minutes ) );
		$expires_at = Timestamp::in_minutes( $expires );

		try {
			$url = $this->sigv4()->presign( $method, $bucket, $object_key, [], $expires * 60 );
		} catch ( InvalidArgumentException $e ) {
			return new ErrorResponse( $e->getMessage(), 'invalid_parameters', 400 );
		}

		return new PresignedUrlResponse( $url, $expires_at );
	}

}
