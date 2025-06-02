<?php
/**
 * Authentication Trait - Improved Version
 *
 * Handles AWS Signature Version 4 authentication for S3-compatible storage.
 *
 * @package     ArrayPress\S3\Traits
 * @copyright   Copyright (c) 2025, ArrayPress Limited
 * @license     GPL2+
 * @version     1.0.0
 * @author      David Sherlock
 */

declare( strict_types=1 );

namespace ArrayPress\S3\Traits\Signer;

/**
 * Trait Authentication
 */
trait Authentication {

	/**
	 * Generate authorization headers for an S3 request
	 *
	 * @param string $method       HTTP method (GET, PUT, etc.)
	 * @param string $bucket       Bucket name
	 * @param string $object_key   Object key (if applicable)
	 * @param array  $query_params Query parameters
	 * @param string $payload      Request payload (or empty string)
	 *
	 * @return array Headers with AWS signature
	 */
	public function generate_auth_headers(
		string $method,
		string $bucket,
		string $object_key = '',
		array $query_params = [],
		string $payload = ''
	): array {
		// Format the canonical URI
		$canonical_uri = $this->provider->format_canonical_uri( $bucket, $object_key );

		// Get endpoint from the provider
		$host = $this->provider->get_endpoint();

		$time      = time();
		$amz_date  = gmdate( 'Ymd\THis\Z', $time );
		$datestamp = gmdate( 'Ymd', $time );

		// Calculate payload hash - for empty payloads, use the hash of an empty string
		$payload_hash = hash( 'sha256', $payload );

		// Create a canonical headers string
		$canonical_headers = 'host:' . $host . "\n";
		$canonical_headers .= 'x-amz-content-sha256:' . $payload_hash . "\n";
		$canonical_headers .= 'x-amz-date:' . $amz_date . "\n";

		$signed_headers = 'host;x-amz-content-sha256;x-amz-date';

		// Create canonical query string using helper method
		$canonical_querystring = $this->build_canonical_query_string( $query_params );

		// Create canonical request
		$canonical_request = $method . "\n" .
		                     $canonical_uri . "\n" .
		                     $canonical_querystring . "\n" .
		                     $canonical_headers . "\n" .
		                     $signed_headers . "\n" .
		                     $payload_hash;

		// Debug the canonical request if callback is set
		$this->debug( "Canonical Request", $canonical_request );

		// Create credential scope
		$credential_scope = $datestamp . '/' . $this->provider->get_region() . '/s3/aws4_request';

		// Create a string to sign
		$string_to_sign = "AWS4-HMAC-SHA256\n" .
		                  $amz_date . "\n" .
		                  $credential_scope . "\n" .
		                  hash( 'sha256', $canonical_request );

		// Debug the string to sign if callback is set
		$this->debug( "String to Sign", $string_to_sign );

		// Calculate signature
		$signature = $this->calculate_signature( $string_to_sign, $datestamp );

		// Create an authorization header
		$authorization = "AWS4-HMAC-SHA256 " .
		                 "Credential={$this->access_key}/{$credential_scope}, " .
		                 "SignedHeaders={$signed_headers}, " .
		                 "Signature={$signature}";

		// Return headers needed for the request
		return [
			'Host'                 => $host,
			'X-Amz-Date'           => $amz_date,
			'X-Amz-Content-SHA256' => $payload_hash,
			'Authorization'        => $authorization,
			// Explicitly request XML format
			'Accept'               => 'application/xml'
		];
	}

	/**
	 * Build canonical query string for AWS Signature V4
	 *
	 * This method is extracted from the main authentication method to make it reusable
	 * and testable. It follows AWS specifications exactly.
	 *
	 * @param array $query_params Query parameters to encode
	 *
	 * @return string Canonical query string
	 */
	protected function build_canonical_query_string( array $query_params ): string {
		if ( empty( $query_params ) ) {
			return '';
		}

		// Sort parameters by key name
		ksort( $query_params );

		$canonical_querystring = '';

		foreach ( $query_params as $key => $value ) {
			if ( $canonical_querystring !== '' ) {
				$canonical_querystring .= '&';
			}
			// URL-encode keys and values separately per AWS spec
			$canonical_querystring .= rawurlencode( $key ) . '=' . rawurlencode( (string) $value );
		}

		return $canonical_querystring;
	}

	/**
	 * Calculate AWS Signature Version 4
	 *
	 * @param string $string_to_sign String to sign
	 * @param string $datestamp      Date in format YYYYMMDD
	 *
	 * @return string Signature
	 */
	protected function calculate_signature( string $string_to_sign, string $datestamp ): string {
		// Create the signing key
		$k_secret  = 'AWS4' . $this->secret_key;
		$k_date    = hash_hmac( 'sha256', $datestamp, $k_secret, true );
		$k_region  = hash_hmac( 'sha256', $this->provider->get_region(), $k_date, true );
		$k_service = hash_hmac( 'sha256', 's3', $k_region, true );
		$k_signing = hash_hmac( 'sha256', 'aws4_request', $k_service, true );

		// Calculate signature
		return hash_hmac( 'sha256', $string_to_sign, $k_signing );
	}

}