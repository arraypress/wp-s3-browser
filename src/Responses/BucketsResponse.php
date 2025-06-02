<?php
/**
 * Response for bucket listing operations - Simplified
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
use ArrayPress\S3\Models\S3Bucket;

/**
 * Response for bucket listing operations
 */
class BucketsResponse extends Response {

	/**
	 * List of buckets
	 *
	 * @var array
	 */
	private array $buckets;

	/**
	 * Owner information
	 *
	 * @var array|null
	 */
	private ?array $owner;

	/**
	 * Truncation flag
	 *
	 * @var bool
	 */
	private bool $truncated;

	/**
	 * Next marker for pagination
	 *
	 * @var string
	 */
	private string $next_marker = '';

	/**
	 * Constructor
	 *
	 * @param array      $buckets     List of buckets
	 * @param int        $status_code HTTP status code
	 * @param null|array $owner       Owner information
	 * @param bool       $truncated   Truncation flag
	 * @param string     $next_marker Next marker for pagination
	 * @param mixed      $raw_data    Original raw data
	 */
	public function __construct(
		array $buckets,
		int $status_code = 200,
		?array $owner = null,
		bool $truncated = false,
		string $next_marker = '',
		$raw_data = null
	) {
		parent::__construct( $status_code, $status_code >= 200 && $status_code < 300, $raw_data );

		$this->buckets     = $buckets;
		$this->owner       = $owner;
		$this->truncated   = $truncated;
		$this->next_marker = $next_marker;
	}

	/**
	 * Get buckets
	 *
	 * @return array
	 */
	public function get_buckets(): array {
		return $this->buckets;
	}

	/**
	 * Get bucket count
	 *
	 * @return int
	 */
	public function get_count(): int {
		return count( $this->buckets );
	}

	/**
	 * Get owner information
	 *
	 * @return array|null
	 */
	public function get_owner(): ?array {
		return $this->owner;
	}

	/**
	 * Check if a result is truncated
	 *
	 * @return bool
	 */
	public function is_truncated(): bool {
		return $this->truncated;
	}

	/**
	 * Get next marker for pagination
	 *
	 * @return string
	 */
	public function get_next_marker(): string {
		return $this->next_marker;
	}

	/**
	 * Get buckets as model instances
	 *
	 * @return array Array of S3Bucket models
	 */
	public function to_bucket_models(): array {
		$models = [];
		foreach ( $this->buckets as $bucket_data ) {
			$models[] = new S3Bucket( $bucket_data );
		}

		return $models;
	}

	/**
	 * Get next page URL for admin interface
	 *
	 * @param string $admin_url  Base admin URL (required)
	 * @param array  $query_args Additional query args to add
	 *
	 * @return string|null URL for the next page or null if not truncated
	 */
	public function get_next_page_url( string $admin_url, array $query_args = [] ): ?string {
		// If not truncated or no marker, no next page
		if ( ! $this->is_truncated() || empty( $this->next_marker ) || empty( $admin_url ) ) {
			return null;
		}

		// Add the marker to query params
		$args = array_merge( [
			'marker' => $this->next_marker
		], $query_args );

		// Build the URL
		return add_query_arg( $args, $admin_url );
	}

	/**
	 * Convert to array
	 *
	 * @return array
	 */
	public function to_array(): array {
		$array = parent::to_array();

		$array['buckets'] = $this->buckets;
		$array['count']   = $this->get_count();

		if ( $this->owner !== null ) {
			$array['owner'] = $this->owner;
		}

		if ( $this->truncated ) {
			$array['truncated']   = true;
			$array['next_marker'] = $this->next_marker;
		}

		return $array;
	}

}