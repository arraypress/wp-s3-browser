<?php
/**
 * Pagination Trait
 *
 * Provides common methods for extracting pagination data from S3 API responses.
 *
 * @package     ArrayPress\S3\Traits
 * @copyright   Copyright (c) 2025, ArrayPress Limited
 * @license     GPL2+
 * @version     1.0.0
 * @author      ArrayPress Team
 */

declare( strict_types=1 );

namespace ArrayPress\S3\Traits;

/**
 * Trait Pagination
 */
trait Pagination {

	/**
	 * Extract IsTruncated flag from raw XML data
	 *
	 * @param array $raw_data      Raw XML data
	 * @param bool  $default_value Default value if not found
	 *
	 * @return bool Whether the result is truncated
	 */
	protected function extract_is_truncated( array $raw_data, bool $default_value ): bool {
		// Check standard and namespaced versions
		$is_truncated_keys = [ 'IsTruncated', 'ns:IsTruncated' ];

		foreach ( $is_truncated_keys as $key ) {
			if ( isset( $raw_data[ $key ] ) ) {
				$value = $raw_data[ $key ];

				// Handle array structure
				if ( is_array( $value ) && isset( $value['value'] ) ) {
					return strtolower( $value['value'] ) === 'true';
				}

				// Handle direct value
				if ( is_string( $value ) ) {
					return strtolower( $value ) === 'true';
				}

				// Handle boolean value
				if ( is_bool( $value ) ) {
					return $value;
				}
			}
		}

		return $default_value;
	}

	/**
	 * Extract token value from raw XML data
	 *
	 * @param array  $raw_data      Raw XML data
	 * @param array  $token_keys    Possible token keys to check
	 * @param string $default_value Default value if not found
	 *
	 * @return string The token value
	 */
	protected function extract_token( array $raw_data, array $token_keys, string $default_value ): string {
		foreach ( $token_keys as $key ) {
			if ( isset( $raw_data[ $key ] ) ) {
				$value = $raw_data[ $key ];

				// Handle array structure
				if ( is_array( $value ) && isset( $value['value'] ) ) {
					return $value['value'];
				}

				// Handle direct value
				if ( is_string( $value ) ) {
					return $value;
				}
			}
		}

		return $default_value;
	}

	/**
	 * Generate a next page URL based on pagination parameters
	 *
	 * @param string $admin_url    Base admin URL
	 * @param array  $query_params Query parameters to include
	 * @param bool   $is_truncated Whether the result is truncated
	 * @param string $token_name   Name of token parameter (marker/continuation_token)
	 * @param string $token_value  Value of the token
	 *
	 * @return string|null URL for next page or null if not truncated
	 */
	protected function generate_next_page_url(
		string $admin_url,
		array $query_params,
		bool $is_truncated,
		string $token_name,
		string $token_value
	): ?string {
		// If not truncated or no token, no next page
		if ( ! $is_truncated || empty( $token_value ) ) {
			return null;
		}

		if ( empty( $admin_url ) ) {
			return null;
		}

		// Add the token to query params
		$query_params[ $token_name ] = $token_value;

		// Build the URL
		return add_query_arg( $query_params, $admin_url );
	}

}