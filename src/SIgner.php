<?php
/**
 * Signer Class
 *
 * Core implementation of AWS Signature Version 4 for S3-compatible storage.
 *
 * @package     ArrayPress\S3
 * @copyright   Copyright (c) 2025, ArrayPress Limited
 * @license     GPL2+
 * @version     1.0.0
 * @author      ArrayPress Team
 */

declare( strict_types=1 );

namespace ArrayPress\S3;

use ArrayPress\S3\Abstracts\Provider;
use ArrayPress\S3\Interfaces\Signer as SignerInterface;
use ArrayPress\S3\Interfaces\Provider as ProviderInterface;
use ArrayPress\S3\Interfaces\Response as ResponseInterface;
use ArrayPress\S3\Responses\BucketsResponse;
use ArrayPress\S3\Responses\ObjectsResponse;
use ArrayPress\S3\Responses\ObjectResponse;
use ArrayPress\S3\Responses\PresignedUrlResponse;
use ArrayPress\S3\Responses\ErrorResponse;
use ArrayPress\S3\Responses\SuccessResponse;
use ArrayPress\S3\Traits\XmlParser;
use ArrayPress\S3\Utils\File;
use WP_Error;

/**
 * Class Signer
 */
class Signer implements SignerInterface {
	use XmlParser;

	/**
	 * Provider instance
	 *
	 * @var Provider
	 */
	private Provider $provider;

	/**
	 * Access key ID
	 *
	 * @var string
	 */
	private string $access_key;

	/**
	 * Secret access key
	 *
	 * @var string
	 */
	private string $secret_key;

	/**
	 * Debug callback
	 *
	 * @var callable|null
	 */
	private $debug_callback = null;

	/**
	 * Constructor
	 *
	 * @param Provider $provider   Provider instance
	 * @param string   $access_key Access key ID
	 * @param string   $secret_key Secret access key
	 */
	public function __construct(
		Provider $provider,
		string $access_key,
		string $secret_key
	) {
		$this->provider   = $provider;
		$this->access_key = trim( $access_key );
		$this->secret_key = trim( $secret_key );
	}

	/**
	 * Set debug callback
	 *
	 * @param callable $callback Debug callback function
	 *
	 * @return self
	 */
	public function set_debug_callback( callable $callback ): self {
		$this->debug_callback = $callback;

		return $this;
	}

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

		// Create canonical query string
		ksort( $query_params );
		$canonical_querystring = '';

		foreach ( $query_params as $key => $value ) {
			if ( $canonical_querystring !== '' ) {
				$canonical_querystring .= '&';
			}
			// URL-encode keys and values separately
			$canonical_querystring .= rawurlencode( $key ) . '=' . rawurlencode( (string) $value );
		}

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
			return new ErrorResponse( 'Bucket and object key are required', 'invalid_parameters', 400 );
		}

		// Convert minutes to seconds
		$expires_seconds = $expires * 60;

		$time      = time();
		$amz_date  = gmdate( 'Ymd\THis\Z', $time );
		$datestamp = gmdate( 'Ymd', $time );

		// IMPORTANT: Only encode once - don't double-encode special characters
		// For URLs we want to preserve the original structure but make it URL-safe
		$encoded_key = $this->encode_object_key_for_url( $object_key );

		// Format the canonical URI
		$canonical_uri = '/' . $bucket . '/' . ltrim( $encoded_key, '/' );

		// Format the credential scope
		$credential_scope = $datestamp . '/' . $this->provider->get_region() . '/s3/aws4_request';
		$credential       = $this->access_key . '/' . $credential_scope;

		// Create the query parameters
		$query_params = [
			'X-Amz-Algorithm'     => 'AWS4-HMAC-SHA256',
			'X-Amz-Credential'    => $credential,
			'X-Amz-Date'          => $amz_date,
			'X-Amz-Expires'       => (string) $expires_seconds, // Cast to string to avoid TypeError
			'X-Amz-SignedHeaders' => 'host'
		];

		// Build the canonical query string - preserve original values without double encoding
		$canonical_querystring = '';

		// Sort query parameters by key
		ksort( $query_params );

		foreach ( $query_params as $key => $value ) {
			if ( $canonical_querystring !== '' ) {
				$canonical_querystring .= '&';
			}
			// Ensure both key and value are strings before encoding
			$canonical_querystring .= rawurlencode( (string) $key ) . '=' . rawurlencode( (string) $value );
		}

		// Get endpoint
		$host = $this->provider->get_endpoint();

		// Build the canonical request - ensure proper line separations
		$canonical_request = "GET\n";
		$canonical_request .= $canonical_uri . "\n";
		$canonical_request .= $canonical_querystring . "\n";
		$canonical_request .= "host:" . $host . "\n";
		$canonical_request .= "\n";
		$canonical_request .= "host\n";
		$canonical_request .= "UNSIGNED-PAYLOAD";

		// Debug the canonical request if callback is set
		$this->debug( "Presigned URL Canonical Request", $canonical_request );

		// Create the string to sign
		$string_to_sign = "AWS4-HMAC-SHA256\n";
		$string_to_sign .= $amz_date . "\n";
		$string_to_sign .= $credential_scope . "\n";
		$string_to_sign .= hash( 'sha256', $canonical_request );

		// Debug the string to sign if callback is set
		$this->debug( "Presigned URL String to Sign", $string_to_sign );

		// Calculate the signature
		$signature = $this->calculate_signature( $string_to_sign, $datestamp );

		// Build the final URL
		$url           = 'https://' . $host . $canonical_uri;
		$presigned_url = $url . '?' . $canonical_querystring . '&X-Amz-Signature=' . $signature;

		// Return PresignedUrlResponse
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
			return new ErrorResponse( 'Bucket and object key are required', 'invalid_parameters', 400 );
		}

		// Convert minutes to seconds
		$expires_seconds = $expires * 60;

		$time      = time();
		$amz_date  = gmdate( 'Ymd\THis\Z', $time );
		$datestamp = gmdate( 'Ymd', $time );

		// Format the canonical URI
		$encoded_key   = $this->encode_object_key_for_url( $object_key );
		$canonical_uri = '/' . $bucket . '/' . ltrim( $encoded_key, '/' );

		// Format the credential scope
		$credential_scope = $datestamp . '/' . $this->provider->get_region() . '/s3/aws4_request';
		$credential       = $this->access_key . '/' . $credential_scope;

		// Create the query parameters - specify PUT method for upload
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
			$canonical_querystring .= rawurlencode( (string) $key ) . '=' . rawurlencode( (string) $value );
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

		// Debug the canonical request if callback is set
		$this->debug( "Presigned Upload URL Canonical Request", $canonical_request );

		// Create the string to sign
		$string_to_sign = "AWS4-HMAC-SHA256\n";
		$string_to_sign .= $amz_date . "\n";
		$string_to_sign .= $credential_scope . "\n";
		$string_to_sign .= hash( 'sha256', $canonical_request );

		// Debug the string to sign if callback is set
		$this->debug( "Presigned Upload URL String to Sign", $string_to_sign );

		// Calculate the signature
		$signature = $this->calculate_signature( $string_to_sign, $datestamp );

		// Build the final URL
		$url           = 'https://' . $host . $canonical_uri;
		$presigned_url = $url . '?' . $canonical_querystring . '&X-Amz-Signature=' . $signature;

		// Return PresignedUrlResponse
		return new PresignedUrlResponse(
			$presigned_url,
			time() + $expires_seconds
		);
	}

	/**
	 * Delete an object from a bucket
	 *
	 * @param string $bucket     Bucket name
	 * @param string $object_key Object key
	 *
	 * @return ResponseInterface Operation result
	 */
	public function delete_object( string $bucket, string $object_key ): ResponseInterface {
		if ( empty( $bucket ) || empty( $object_key ) ) {
			return new ErrorResponse( 'Bucket and object key are required', 'invalid_parameters', 400 );
		}

		// Generate authorization headers for DELETE request
		$headers = $this->generate_auth_headers(
			'DELETE',
			$bucket,
			$object_key
		);

		// Build the URL
		$url = $this->provider->format_url( $bucket, $object_key );

		// Debug the request if callback is set
		$this->debug( "Delete Object Request URL", $url );
		$this->debug( "Delete Object Request Headers", $headers );

		// Make the request
		$response = wp_remote_request( $url, [
			'method'  => 'DELETE',
			'headers' => $headers,
			'timeout' => 15
		] );

		// Handle errors
		if ( is_wp_error( $response ) ) {
			return ErrorResponse::from_wp_error( $response );
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		$body        = wp_remote_retrieve_body( $response );

		// Debug the response if callback is set
		$this->debug( "Delete Object Response Status", $status_code );
		$this->debug( "Delete Object Response Body", $body );

		// Check for error status code
		if ( $status_code < 200 || $status_code >= 300 ) {
			return $this->handle_error_response( $status_code, $body, 'Failed to delete object' );
		}

		// Create a simple success response
		return new SuccessResponse(
			'Object deleted successfully',
			$status_code,
			[
				'bucket' => $bucket,
				'key'    => $object_key
			]
		);
	}

	/**
	 * List all buckets
	 *
	 * @param int    $max_keys Maximum number of buckets to return
	 * @param string $prefix   Optional prefix to filter buckets
	 * @param string $marker   Optional marker for pagination
	 *
	 * @return ResponseInterface Operation result
	 */
	public function list_buckets( int $max_keys = 1000, string $prefix = '', string $marker = '' ): ResponseInterface {
		// Prepare query parameters
		$query_params = [];

		if ( $max_keys !== 1000 ) {
			$query_params['max-keys'] = $max_keys;
		}

		if ( ! empty( $prefix ) ) {
			$query_params['prefix'] = $prefix;
		}

		if ( ! empty( $marker ) ) {
			$query_params['marker'] = $marker;
		}

		// Generate authorization headers
		$headers = $this->generate_auth_headers(
			'GET',
			'',  // Empty bucket for list_buckets operation
			'',
			$query_params
		);

		// Build the URL
		$endpoint = $this->provider->get_endpoint();
		$url      = 'https://' . $endpoint;

		if ( ! empty( $query_params ) ) {
			$url .= '?' . http_build_query( $query_params );
		}

		// Debug the request if callback is set
		$this->debug( "List Buckets Request URL", $url );
		$this->debug( "List Buckets Request Headers", $headers );

		// Make the request
		$response = wp_remote_get( $url, [
			'headers' => $headers,
			'timeout' => 30
		] );

		// Handle errors
		if ( is_wp_error( $response ) ) {
			return ErrorResponse::from_wp_error( $response );
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		$body        = wp_remote_retrieve_body( $response );

		// Debug the response
		$this->debug( "List Buckets Response Status", $status_code );
		$this->debug( "List Buckets Response Body", $body );

		// Check for error status code
		if ( $status_code < 200 || $status_code >= 300 ) {
			return $this->handle_error_response( $status_code, $body, 'Failed to list buckets' );
		}

		// Parse XML response
		$xml = $this->parse_xml_response( $body );
		if ( is_wp_error( $xml ) ) {
			return ErrorResponse::from_wp_error( $xml, $status_code );
		}

		// Debug the parsed XML structure
		$this->debug( "Parsed XML Structure", $xml );

		// Extract data (compatible with multiple providers)
		$buckets     = [];
		$owner       = null;
		$truncated   = false;
		$next_marker = '';

		// Extract owner - search recursively through the XML structure
		$owner = $this->extract_owner_from_xml( $xml );

		// Extract buckets - search recursively through the XML structure
		$buckets = $this->extract_buckets_from_xml( $xml );

		// Extract truncation info - search recursively
		$truncation_info = $this->extract_truncation_info_from_xml( $xml );
		$truncated       = $truncation_info['truncated'];
		$next_marker     = $truncation_info['next_marker'];

		return new BucketsResponse(
			$buckets,
			$status_code,
			$owner,
			$truncated,
			$next_marker,
			$xml
		);
	}

	/**
	 * Extract owner information from XML structure
	 *
	 * @param array $xml Parsed XML structure
	 *
	 * @return array|null Owner information or null if not found
	 */
	private function extract_owner_from_xml( array $xml ): ?array {
		// Common paths for owner info
		$possible_paths = [
			'Owner',
			'ListAllMyBucketsResult.Owner'
		];

		foreach ( $possible_paths as $path ) {
			$owner_data = $this->get_value_from_path( $xml, $path );
			if ( $owner_data ) {
				return [
					'ID'          => $this->get_value_from_path( $owner_data, 'ID.value' ) ?? '',
					'DisplayName' => $this->get_value_from_path( $owner_data, 'DisplayName.value' ) ?? ''
				];
			}
		}

		return null;
	}

	/**
	 * Extract buckets from XML structure
	 *
	 * @param array $xml Parsed XML structure
	 *
	 * @return array Array of buckets
	 */
	private function extract_buckets_from_xml( array $xml ): array {
		// Common paths for buckets
		$possible_bucket_paths = [
			'Buckets.Bucket',
			'ListAllMyBucketsResult.Buckets.Bucket'
		];

		foreach ( $possible_bucket_paths as $path ) {
			$buckets_data = $this->get_value_from_path( $xml, $path );
			if ( $buckets_data ) {
				return $this->format_buckets_data( $buckets_data );
			}
		}

		// If no buckets found through common paths, search recursively
		return $this->search_for_buckets_recursively( $xml );
	}

	/**
	 * Format buckets data into standard format
	 *
	 * @param array $buckets_data Raw buckets data from XML
	 *
	 * @return array Formatted buckets
	 */
	private function format_buckets_data( $buckets_data ): array {
		$formatted = [];

		// Single bucket case
		if ( isset( $buckets_data['Name'] ) ) {
			$formatted[] = [
				'Name'         => $buckets_data['Name']['value'] ?? '',
				'CreationDate' => $buckets_data['CreationDate']['value'] ?? ''
			];
		} // Multiple buckets case
		elseif ( is_array( $buckets_data ) ) {
			foreach ( $buckets_data as $bucket ) {
				if ( isset( $bucket['Name'] ) ) {
					$formatted[] = [
						'Name'         => $bucket['Name']['value'] ?? '',
						'CreationDate' => $bucket['CreationDate']['value'] ?? ''
					];
				}
			}
		}

		return $formatted;
	}

	/**
	 * Search recursively for buckets in the XML structure
	 *
	 * @param array $data XML data to search
	 *
	 * @return array Found buckets
	 */
	private function search_for_buckets_recursively( array $data ): array {
		$buckets = [];

		// Look for patterns that might represent buckets
		foreach ( $data as $key => $value ) {
			// If we find something that looks like a bucket
			if ( is_array( $value ) && isset( $value['Name'] ) && isset( $value['CreationDate'] ) ) {
				$buckets[] = [
					'Name'         => $value['Name']['value'] ?? '',
					'CreationDate' => $value['CreationDate']['value'] ?? ''
				];
			} // Recursively search deeper
			elseif ( is_array( $value ) ) {
				$found_buckets = $this->search_for_buckets_recursively( $value );
				if ( ! empty( $found_buckets ) ) {
					$buckets = array_merge( $buckets, $found_buckets );
				}
			}
		}

		return $buckets;
	}

	/**
	 * Extract truncation info from XML structure
	 *
	 * @param array $xml Parsed XML structure
	 *
	 * @return array Truncation info
	 */
	private function extract_truncation_info_from_xml( array $xml ): array {
		$result = [
			'truncated'   => false,
			'next_marker' => ''
		];

		// Common paths for truncation info
		$possible_truncated_paths = [
			'IsTruncated',
			'ListAllMyBucketsResult.IsTruncated'
		];

		// Check for truncation
		foreach ( $possible_truncated_paths as $path ) {
			$is_truncated = $this->get_value_from_path( $xml, $path );
			if ( $is_truncated ) {
				$result['truncated'] = ( $is_truncated === 'true' || $is_truncated === true );
				break;
			}
		}

		// If truncated, look for next marker
		if ( $result['truncated'] ) {
			$possible_marker_paths = [
				'NextMarker.value',
				'ListAllMyBucketsResult.NextMarker.value'
			];

			foreach ( $possible_marker_paths as $path ) {
				$marker = $this->get_value_from_path( $xml, $path );
				if ( $marker ) {
					$result['next_marker'] = $marker;
					break;
				}
			}
		}

		return $result;
	}

	/**
	 * Get a value from a dot-notation path in an array
	 *
	 * @param array  $array The array to search
	 * @param string $path  Dot notation path (e.g. "Buckets.Bucket")
	 *
	 * @return mixed|null The value or null if not found
	 */
	private function get_value_from_path( array $array, string $path ) {
		$keys    = explode( '.', $path );
		$current = $array;

		foreach ( $keys as $key ) {
			if ( ! is_array( $current ) || ! isset( $current[ $key ] ) ) {
				return null;
			}
			$current = $current[ $key ];
		}

		return $current;
	}

	/**
	 * List objects in a bucket
	 *
	 * @param string $bucket             Bucket name
	 * @param int    $max_keys           Maximum number of objects to return
	 * @param string $prefix             Optional prefix to filter objects
	 * @param string $delimiter          Optional delimiter for hierarchical listing
	 * @param string $continuation_token Optional continuation token for pagination
	 *
	 * @return ResponseInterface Operation result
	 */
	public function list_objects(
		string $bucket,
		int $max_keys = 1000,
		string $prefix = '',
		string $delimiter = '/',
		string $continuation_token = ''
	): ResponseInterface {
		// Prepare query parameters
		$query_params = [
			'list-type' => '2' // Use ListObjectsV2
		];

		if ( $max_keys !== 1000 ) {
			$query_params['max-keys'] = $max_keys;
		}

		if ( ! empty( $prefix ) ) {
			$query_params['prefix'] = $prefix;
		}

		if ( ! empty( $delimiter ) ) {
			$query_params['delimiter'] = $delimiter;
		}

		if ( ! empty( $continuation_token ) ) {
			$query_params['continuation-token'] = $continuation_token;
		}

		// Generate authorization headers
		$headers = $this->generate_auth_headers(
			'GET',
			$bucket,
			'',
			$query_params
		);

		// Build the URL
		$url = $this->provider->format_url( $bucket );

		if ( ! empty( $query_params ) ) {
			$url .= '?' . http_build_query( $query_params );
		}

		// Debug the request if callback is set
		$this->debug( "List Objects Request URL", $url );
		$this->debug( "List Objects Request Headers", $headers );

		// Make the request
		$response = wp_remote_get( $url, [
			'headers' => $headers,
			'timeout' => 30
		] );

		// Handle errors
		if ( is_wp_error( $response ) ) {
			return ErrorResponse::from_wp_error( $response );
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		$body        = wp_remote_retrieve_body( $response );

		// Debug the response if callback is set
		$this->debug( "List Objects Response Status", $status_code );
		$this->debug( "List Objects Response Body", $body );

		// Check for error status code
		if ( $status_code < 200 || $status_code >= 300 ) {
			return $this->handle_error_response( $status_code, $body, 'Failed to list objects' );
		}

		// Parse XML response
		$xml = $this->parse_xml_response( $body );

		if ( is_wp_error( $xml ) ) {
			return ErrorResponse::from_wp_error( $xml, $status_code );
		}

		$objects            = [];
		$prefixes           = [];
		$truncated          = false;
		$continuation_token = '';

		// Extract from ListObjectsV2Result format
		$result_path = $xml['ListObjectsV2Result'] ?? $xml;

		// Check for truncation
		if ( isset( $result_path['IsTruncated'] ) ) {
			$is_truncated = $result_path['IsTruncated'];
			$truncated    = ( $is_truncated === 'true' || $is_truncated === true );
		}

		// Get continuation token if available
		if ( $truncated && isset( $result_path['NextContinuationToken'] ) ) {
			$continuation_token = $result_path['NextContinuationToken']['value'] ?? '';
		}

		// Extract objects
		if ( isset( $result_path['Contents'] ) ) {
			$contents = $result_path['Contents'];

			// Single object case
			if ( isset( $contents['Key'] ) ) {
				$this->add_formatted_object( $objects, $contents );
			} // Multiple objects case
			elseif ( is_array( $contents ) ) {
				foreach ( $contents as $object ) {
					$this->add_formatted_object( $objects, $object );
				}
			}
		}

		// Extract prefixes (folders)
		if ( isset( $result_path['CommonPrefixes'] ) ) {
			$common_prefixes = $result_path['CommonPrefixes'];

			// Single prefix case
			if ( isset( $common_prefixes['Prefix'] ) ) {
				$prefix_value = $common_prefixes['Prefix']['value'] ?? '';
				if ( ! empty( $prefix_value ) ) {
					$prefixes[] = $prefix_value;
				}
			} // Multiple prefixes case
			elseif ( is_array( $common_prefixes ) ) {
				foreach ( $common_prefixes as $prefix_data ) {
					if ( isset( $prefix_data['Prefix']['value'] ) ) {
						$prefixes[] = $prefix_data['Prefix']['value'];
					} elseif ( isset( $prefix_data['Prefix'] ) ) {
						$prefixes[] = $prefix_data['Prefix'];
					}
				}
			}
		}

		// Pass the current prefix to ObjectsResponse for filtering
		return new ObjectsResponse(
			$objects,
			$prefixes,
			$status_code,
			$truncated,
			$continuation_token,
			$xml,
			$prefix  // Pass the current prefix for filtering
		);
	}

	/**
	 * Download an object
	 *
	 * @param string $bucket     Bucket name
	 * @param string $object_key Object key
	 *
	 * @return ResponseInterface Object data or error result
	 */
	public function get_object( string $bucket, string $object_key ): ResponseInterface {
		if ( empty( $bucket ) || empty( $object_key ) ) {
			return new ErrorResponse( 'Bucket and object key are required', 'invalid_parameters', 400 );
		}

		// Generate authorization headers
		$headers = $this->generate_auth_headers(
			'GET',
			$bucket,
			$object_key
		);

		// Build the URL
		$url = $this->provider->format_url( $bucket, $object_key );

		// Debug the request if callback is set
		$this->debug( "Get Object Request URL", $url );
		$this->debug( "Get Object Request Headers", $headers );

		// Make the request
		$response = wp_remote_get( $url, [
			'headers' => $headers,
			'timeout' => 30
		] );

		// Handle errors
		if ( is_wp_error( $response ) ) {
			return ErrorResponse::from_wp_error( $response );
		}

		$status_code      = wp_remote_retrieve_response_code( $response );
		$body             = wp_remote_retrieve_body( $response );
		$response_headers = wp_remote_retrieve_headers( $response );

		// Debug the response status if callback is set
		$this->debug( "Get Object Response Status", $status_code );
		$this->debug( "Get Object Response Headers", $response_headers );

		// Check for error status code
		if ( $status_code < 200 || $status_code >= 300 ) {
			// Try to parse error message from XML if available
			if ( strpos( $body, '<?xml' ) !== false ) {
				$error_xml = $this->parse_xml_response( $body, false );
				if ( ! is_wp_error( $error_xml ) && isset( $error_xml['Error'] ) ) {
					$error_info    = $error_xml['Error'];
					$error_message = $error_info['Message']['value'] ?? 'Unknown error';
					$error_code    = $error_info['Code']['value'] ?? 'unknown_error';

					return new ErrorResponse( $error_message, $error_code, $status_code );
				}
			}

			return new ErrorResponse( 'Failed to retrieve object', 'request_failed', $status_code );
		}

		// Extract metadata
		$metadata = [
			'content_type'   => wp_remote_retrieve_header( $response, 'content-type' ),
			'content_length' => (int) wp_remote_retrieve_header( $response, 'content-length' ),
			'etag'           => trim( wp_remote_retrieve_header( $response, 'etag' ), '"' ),
			'last_modified'  => wp_remote_retrieve_header( $response, 'last-modified' )
		];

		// Extract custom metadata
		foreach ( $response_headers as $key => $value ) {
			if ( strpos( $key, 'x-amz-meta-' ) === 0 ) {
				$metadata['user_metadata'][ substr( $key, 11 ) ] = $value;
			}
		}

		return new ObjectResponse( $body, $metadata, $status_code, [
			'headers' => $response_headers,
			'bucket'  => $bucket,
			'key'     => $object_key
		] );
	}

	/**
	 * Get object metadata (HEAD request)
	 *
	 * @param string $bucket     Bucket name
	 * @param string $object_key Object key
	 *
	 * @return ResponseInterface Object metadata or error result
	 */
	public function head_object( string $bucket, string $object_key ): ResponseInterface {
		if ( empty( $bucket ) || empty( $object_key ) ) {
			return new ErrorResponse( 'Bucket and object key are required', 'invalid_parameters', 400 );
		}

		// Generate authorization headers
		$headers = $this->generate_auth_headers(
			'HEAD',
			$bucket,
			$object_key
		);

		// Build the URL
		$url = $this->provider->format_url( $bucket, $object_key );

		// Debug the request if callback is set
		$this->debug( "Head Object Request URL", $url );
		$this->debug( "Head Object Request Headers", $headers );

		// Make the request
		$response = wp_remote_head( $url, [
			'headers' => $headers,
			'timeout' => 15
		] );

		// Handle errors
		if ( is_wp_error( $response ) ) {
			return ErrorResponse::from_wp_error( $response );
		}

		$status_code      = wp_remote_retrieve_response_code( $response );
		$response_headers = wp_remote_retrieve_headers( $response );

		// Debug the response status if callback is set
		$this->debug( "Head Object Response Status", $status_code );
		$this->debug( "Head Object Response Headers", $response_headers );

		// Check for error status code
		if ( $status_code < 200 || $status_code >= 300 ) {
			return new ErrorResponse( 'Failed to retrieve object metadata', 'request_failed', $status_code );
		}

		// Extract metadata
		$metadata = [
			'content_type'   => wp_remote_retrieve_header( $response, 'content-type' ),
			'content_length' => (int) wp_remote_retrieve_header( $response, 'content-length' ),
			'etag'           => trim( wp_remote_retrieve_header( $response, 'etag' ), '"' ),
			'last_modified'  => wp_remote_retrieve_header( $response, 'last-modified' )
		];

		// Empty content for HEAD request
		return new ObjectResponse( '', $metadata, $status_code, [
			'headers' => $response_headers,
			'bucket'  => $bucket,
			'key'     => $object_key
		] );
	}

	/**
	 * URL encode S3 object key specially for URLs, avoiding double encoding
	 *
	 * @param string $object_key S3 object key to encode
	 *
	 * @return string Encoded object key
	 */
	protected function encode_object_key_for_url( string $object_key ): string {
		// Remove any leading slash
		$object_key = ltrim( $object_key, '/' );

		// First, make sure any percent-encoded parts are decoded to avoid double encoding
		// This handles cases where the object key was already URL-encoded
		$decoded_key = rawurldecode( $object_key );

		// Now encode spaces (not as plus signs) and special characters, but preserve slashes
		$encoded = '';
		$len     = strlen( $decoded_key );

		for ( $i = 0; $i < $len; $i ++ ) {
			$char = $decoded_key[ $i ];
			if ( $char === '/' ) {
				$encoded .= '/';
			} else if ( $char === ' ' ) {
				$encoded .= '%20';
			} else if ( preg_match( '/[0-9a-zA-Z_.-]/', $char ) ) {
				$encoded .= $char;
			} else {
				$encoded .= rawurlencode( $char );
			}
		}

		return $encoded;
	}

	/**
	 * Format an object from XML data
	 *
	 * @param array $objects Array of objects to add to
	 * @param array $object  Object data to format and add
	 */
	private function add_formatted_object( array &$objects, array $object ): void {
		// Handle different possible formats
		$key_value     = '';
		$last_modified = '';
		$etag          = '';
		$size          = 0;
		$storage_class = 'STANDARD';

		if ( isset( $object['Key']['value'] ) ) {
			$key_value     = $object['Key']['value'];
			$last_modified = $object['LastModified']['value'] ?? '';
			$etag          = isset( $object['ETag']['value'] ) ? trim( $object['ETag']['value'], '"' ) : '';
			$size          = isset( $object['Size']['value'] ) ? (int) $object['Size']['value'] : 0;
			$storage_class = $object['StorageClass']['value'] ?? 'STANDARD';
		} elseif ( isset( $object['Key'] ) ) {
			$key_value     = $object['Key'];
			$last_modified = $object['LastModified'] ?? '';
			$etag          = isset( $object['ETag'] ) ? trim( $object['ETag'], '"' ) : '';
			$size          = isset( $object['Size'] ) ? (int) $object['Size'] : 0;
			$storage_class = $object['StorageClass'] ?? 'STANDARD';
		}

		if ( empty( $key_value ) ) {
			return;
		}

		// Get filename from key
		$filename = File::get_filename( $key_value );

		// Add formatted object
		$objects[] = [
			'Key'           => $key_value,
			'Filename'      => $filename,
			'LastModified'  => $last_modified,
			'ETag'          => $etag,
			'Size'          => $size,
			'StorageClass'  => $storage_class,
			'FormattedSize' => size_format( $size ),
			'Type'          => File::get_file_type( $filename ),
			'MimeType'      => File::get_mime_type( $filename )
		];
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

	/**
	 * Log debug information if callback is set
	 *
	 * @param string $title Debug title
	 * @param mixed  $data  Debug data
	 */
	private function debug( string $title, $data ): void {
		if ( is_callable( $this->debug_callback ) ) {
			call_user_func( $this->debug_callback, $title, $data );
		}
	}

	/**
	 * Handle error responses
	 *
	 * @param int    $status_code HTTP status code
	 * @param string $body        Response body
	 * @param string $default_msg Default error message
	 *
	 * @return ErrorResponse
	 */
	private function handle_error_response( int $status_code, string $body, string $default_msg ): ErrorResponse {
		// Try to parse error message from XML if available
		if ( strpos( $body, '<?xml' ) !== false ) {
			$error_xml = $this->parse_xml_response( $body, false );
			if ( ! is_wp_error( $error_xml ) && isset( $error_xml['Error'] ) ) {
				$error_info    = $error_xml['Error'];
				$error_message = $error_info['Message']['value'] ?? 'Unknown error';
				$error_code    = $error_info['Code']['value'] ?? 'unknown_error';

				return new ErrorResponse( $error_message, $error_code, $status_code );
			}
		}

		return new ErrorResponse( $default_msg, 'request_failed', $status_code );
	}

}