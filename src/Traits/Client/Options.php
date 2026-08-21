<?php
/**
 * Client Options Trait
 *
 * Provides methods for getting formatted options for form fields.
 *
 * @package     ArrayPress\S3\Traits\Client
 * @copyright   Copyright (c) 2025, ArrayPress Limited
 * @license     GPL2+
 * @version     1.0.0
 * @author      David Sherlock
 */

declare( strict_types=1 );

namespace ArrayPress\S3\Traits\Client;

use ArrayPress\S3\Models\S3Bucket;

/**
 * Trait Options
 */
trait Options {

	/**
	 * Get bucket options for select fields
	 *
	 * Returns an associative array suitable for use in select/dropdown fields.
	 * Keys and values are both the bucket name.
	 *
	 * @param bool   $use_cache    Whether to use cached results
	 *
	 * @return array Associative array of bucket names
	 */
	public function get_bucket_options(
		bool $use_cache = true
	): array {
		$options = [];

		// Get bucket models
		$result = $this->get_bucket_models( 1000, '', '', $use_cache );

		if ( ! $result->is_successful() ) {
			return $options;
		}

		$data    = $result->get_data();
		$buckets = $data['buckets'] ?? [];

		// Convert S3Bucket models to options array
		foreach ( $buckets as $bucket ) {
			if ( $bucket instanceof S3Bucket ) {
				$name             = $bucket->get_name();
				$options[ $name ] = $name;
			}
		}

		return $options;
	}
}
