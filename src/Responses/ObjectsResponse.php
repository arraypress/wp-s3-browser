<?php
/**
 * Response for object listing operations - Simplified
 *
 * Handles the response data from S3-compatible storage listing operations including
 * pagination support without the separate Pagination trait.
 *
 * @package     ArrayPress\S3\Responses
 * @copyright   Copyright (c) 2025, ArrayPress Limited
 * @license     GPL2+
 * @version     1.0.0
 * @author      David Sherlock
 */

declare( strict_types=1 );

namespace ArrayPress\S3\Responses;

use ArrayPress\S3\Abstracts\Response;
use ArrayPress\S3\Models\S3Object;
use ArrayPress\S3\Models\S3Prefix;

/**
 * Response class for S3 object listing operations
 */
class ObjectsResponse extends Response {

	/**
	 * List of objects
	 *
	 * @var array
	 */
	private array $objects = [];

	/**
	 * List of prefixes (folders)
	 *
	 * @var array
	 */
	private array $prefixes = [];

	/**
	 * Truncation flag
	 *
	 * @var bool
	 */
	private bool $truncated = false;

	/**
	 * Continuation token for pagination
	 *
	 * @var string
	 */
	private string $continuation_token = '';

	/**
	 * Current prefix (for filtering)
	 *
	 * @var string
	 */
	private string $current_prefix = '';

	/**
	 * Constructor
	 *
	 * @param array  $objects            List of objects
	 * @param array  $prefixes           List of prefixes (folders)
	 * @param int    $status_code        HTTP status code
	 * @param bool   $truncated          Truncation flag
	 * @param string $continuation_token Continuation token for pagination
	 * @param mixed  $raw_data           Original raw data
	 * @param string $current_prefix     Current prefix for filtering
	 */
	public function __construct(
		array $objects,
		array $prefixes = [],
		int $status_code = 200,
		bool $truncated = false,
		string $continuation_token = '',
		$raw_data = null,
		string $current_prefix = ''
	) {
		parent::__construct( $status_code, $status_code >= 200 && $status_code < 300, $raw_data );

		$this->objects            = $objects;
		$this->prefixes           = $prefixes;
		$this->truncated          = $truncated;
		$this->continuation_token = $continuation_token;
		$this->current_prefix     = $current_prefix;
	}

	/**
	 * Get objects (raw data)
	 *
	 * @return array List of objects
	 */
	public function get_objects(): array {
		return $this->objects;
	}

	/**
	 * Get prefixes (folders)
	 *
	 * @return array List of prefixes
	 */
	public function get_prefixes(): array {
		return $this->prefixes;
	}

	/**
	 * Get total count (objects + prefixes)
	 *
	 * @return int Total count
	 */
	public function get_count(): int {
		return count( $this->objects ) + count( $this->prefixes );
	}

	/**
	 * Check if result is truncated
	 *
	 * @return bool Whether more objects are available
	 */
	public function is_truncated(): bool {
		return $this->truncated;
	}

	/**
	 * Get a continuation token for pagination
	 *
	 * @return string Token for retrieving next page
	 */
	public function get_continuation_token(): string {
		return $this->continuation_token;
	}

	/**
	 * Get objects as model instances with filtering applied
	 *
	 * @return array Array of S3Object models
	 */
	public function to_object_models(): array {
		$models = [];
		foreach ( $this->objects as $object_data ) {
			$object = new S3Object( $object_data );

			// Apply filtering here - exclude objects that should be hidden
			if ( ! $object->should_be_excluded( $this->current_prefix ) ) {
				$models[] = $object;
			}
		}

		return $models;
	}

	/**
	 * Get prefixes as model instances
	 *
	 * @return array Array of S3Prefix models
	 */
	public function to_prefix_models(): array {
		$models = [];
		foreach ( $this->prefixes as $prefix_str ) {
			$models[] = new S3Prefix( $prefix_str );
		}

		return $models;
	}

	/**
	 * Get next page URL for admin interface
	 *
	 * @param string $bucket     Bucket name
	 * @param string $prefix     Current prefix
	 * @param string $admin_url  Base admin URL (required)
	 * @param array  $query_args Additional query args to add
	 *
	 * @return string|null URL for the next page or null if not truncated
	 */
	public function get_next_page_url( string $bucket, string $prefix, string $admin_url, array $query_args = [] ): ?string {
		// If not truncated or no token, no next page
		if ( ! $this->is_truncated() || empty( $this->continuation_token ) || empty( $admin_url ) ) {
			return null;
		}

		// Add bucket, prefix, and continuation token to query args
		$args = array_merge( [
			'bucket'             => $bucket,
			'prefix'             => $prefix,
			'continuation_token' => urlencode( $this->continuation_token )
		], $query_args );

		// Build the URL
		return add_query_arg( $args, $admin_url );
	}

	/**
	 * Convert response to array
	 *
	 * @return array Response as array
	 */
	public function to_array(): array {
		$array                       = parent::to_array();
		$array['objects']            = $this->objects;
		$array['prefixes']           = $this->prefixes;
		$array['count']              = $this->get_count();
		$array['truncated']          = $this->truncated;
		$array['continuation_token'] = $this->continuation_token;

		return $array;
	}

}