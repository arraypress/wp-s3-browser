<?php
/**
 * Presigned URL Operations Trait - PHP 7.4 Compatible
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
use ArrayPress\S3\Utils\Encode;

/**
 * Trait PresignedUrls
 */
trait PresignedUrls {

	/**
	 * Generate a pre-signed URL for an object
	 *
	 * @param string $bucket     Bucket name
	 * @param string $object_key Object key
	 * @param int    $expires    Expiration time in minutes
	 *
	 * @return ResponseInterface Presigned URL response
	 */
	public function get_presigned_url( string $bucket, string $object_key, int $expires = 60 ): ResponseInterface {
		if ( empty( $bucket ) || empty( $object_key ) ) {
			return new ErrorResponse(
				__( 'Bucket and object key are required', 'arraypress' ),
				'invalid_parameters',
				400
			);
		}

		// Convert minutes to seconds
		$expires_seconds = $expires * 60;

		$time      = time();
		$amz_date  = gmdate( 'Ymd\THis\Z', $time );
		$datestamp = gmdate( 'Ymd', $time );

		// Use our special encoding method to properly handle special characters
		$encoded_key = Encode::object_key( $object_key );

		// Get endpoint from the provider
		$host = $this->provider->get_endpoint();

		// Format the canonical URI - this specific format is required for signing
		$canonical_uri = '/' . $bucket . '/' . $encoded_key;

		// Format the credential scope
		$credential_scope = $datestamp . '/' . $this->provider->get_region() . '/s3/aws4_request';
		$credential       = $this->access_key . '/' . $credential_scope;

		// Create the query parameters
		$query_params = [
			'X-Amz-Algorithm'     => 'AWS4-HMAC-SHA256',
			'X-Amz-Credential'    => $credential,
			'X-Amz-Date'          => $amz_date,
			'X-Amz-Expires'       => (string) $expires_seconds,
			'X-Amz-SignedHeaders' => 'host'
		];

		// Build the canonical query string
		$canonical_querystring = '';

		// Sort query parameters by key
		ksort( $query_params );

		foreach ( $query_params as $key => $value ) {
			if ( $canonical_querystring !== '' ) {
				$canonical_querystring .= '&';
			}
			$canonical_querystring .= rawurlencode( $key ) . '=' . rawurlencode( (string) $value );
		}

		// Build the canonical request
		$canonical_request = "GET\n";
		$canonical_request .= $canonical_uri . "\n";
		$canonical_request .= $canonical_querystring . "\n";
		$canonical_request .= "host:" . $host . "\n";
		$canonical_request .= "\n";
		$canonical_request .= "host\n";
		$canonical_request .= "UNSIGNED-PAYLOAD";

		// Debug the canonical request
		$this->debug( "Presigned URL Canonical Request", $canonical_request );

		// Create the string to sign
		$string_to_sign = "AWS4-HMAC-SHA256\n";
		$string_to_sign .= $amz_date . "\n";
		$string_to_sign .= $credential_scope . "\n";
		$string_to_sign .= hash( 'sha256', $canonical_request );

		// Calculate the signature
		$signature = $this->calculate_signature( $string_to_sign, $datestamp );

		// Build the final URL based on the path style setting
		if ( $this->provider->uses_path_style() ) {
			$url = 'https://' . $host . '/' . $bucket . '/' . $encoded_key;
		} else {
			$url = 'https://' . $bucket . '.' . $host . '/' . $encoded_key;
		}

		$presigned_url = $url . '?' . $canonical_querystring . '&X-Amz-Signature=' . $signature;

		return new PresignedUrlResponse(
			$presigned_url,
			time() + $expires_seconds
		);
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
		if ( empty( $bucket ) || empty( $object_key ) ) {
			return new ErrorResponse(
				__( 'Bucket and object key are required', 'arraypress' ),
				'invalid_parameters',
				400
			);
		}

		// Convert minutes to seconds
		$expires_seconds = $expires * 60;

		$time      = time();
		$amz_date  = gmdate( 'Ymd\THis\Z', $time );
		$datestamp = gmdate( 'Ymd', $time );

		// Use our special encoding method to properly handle special characters
		$encoded_key = Encode::object_key( $object_key );

		// Format the canonical URI
		$canonical_uri = '/' . $bucket . '/' . $encoded_key;

		// Format the credential scope
		$credential_scope = $datestamp . '/' . $this->provider->get_region() . '/s3/aws4_request';
		$credential       = $this->access_key . '/' . $credential_scope;

		// Create the query parameters - specify a PUT method for upload
		$query_params = [
			'X-Amz-Algorithm'     => 'AWS4-HMAC-SHA256',
			'X-Amz-Credential'    => $credential,
			'X-Amz-Date'          => $amz_date,
			'X-Amz-Expires'       => (string) $expires_seconds,
			'X-Amz-SignedHeaders' => 'host'
		];

		// Build the canonical query string
		$canonical_querystring = '';
		ksort( $query_params );

		foreach ( $query_params as $key => $value ) {
			if ( $canonical_querystring !== '' ) {
				$canonical_querystring .= '&';
			}
			$canonical_querystring .= rawurlencode( $key ) . '=' . rawurlencode( (string) $value );
		}

		// Get endpoint
		$host = $this->provider->get_endpoint();

		// Build the canonical request - note PUT method for upload
		$canonical_request = "PUT\n";
		$canonical_request .= $canonical_uri . "\n";
		$canonical_request .= $canonical_querystring . "\n";
		$canonical_request .= "host:" . $host . "\n";
		$canonical_request .= "\n";
		$canonical_request .= "host\n";
		$canonical_request .= "UNSIGNED-PAYLOAD";

		// Debug the canonical request
		$this->debug( "Presigned Upload URL Canonical Request", $canonical_request );

		// Create the string to sign
		$string_to_sign = "AWS4-HMAC-SHA256\n";
		$string_to_sign .= $amz_date . "\n";
		$string_to_sign .= $credential_scope . "\n";
		$string_to_sign .= hash( 'sha256', $canonical_request );

		// Calculate the signature
		$signature = $this->calculate_signature( $string_to_sign, $datestamp );

		// Build the final URL based on the path style setting
		if ( $this->provider->uses_path_style() ) {
			$url = 'https://' . $host . '/' . $bucket . '/' . $encoded_key;
		} else {
			$url = 'https://' . $bucket . '.' . $host . '/' . $encoded_key;
		}

		$presigned_url = $url . '?' . $canonical_querystring . '&X-Amz-Signature=' . $signature;

		// Return PresignedUrlResponse
		return new PresignedUrlResponse(
			$presigned_url,
			time() + $expires_seconds
		);
	}

}