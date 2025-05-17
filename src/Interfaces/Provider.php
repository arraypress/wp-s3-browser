<?php
/**
 * Provider Interface
 *
 * Defines the contract for S3-compatible storage providers.
 *
 * @package     ArrayPress\S3\Interfaces
 * @copyright   Copyright (c) 2025, ArrayPress Limited
 * @license     GPL2+
 * @version     1.0.0
 * @author      ArrayPress Team
 */

declare( strict_types=1 );

namespace ArrayPress\S3\Interfaces;

/**
 * Interface ProviderInterface
 *
 * Defines the methods that all S3-compatible provider adapters must implement.
 */
interface Provider {

	/**
	 * Get provider ID
	 *
	 * @return string
	 */
	public function get_id(): string;

	/**
	 * Get provider label
	 *
	 * @return string
	 */
	public function get_label(): string;

	/**
	 * Get provider endpoint
	 *
	 * @return string
	 * @throws \InvalidArgumentException If endpoint cannot be determined
	 */
	public function get_endpoint(): string;

	/**
	 * Get signing region
	 *
	 * @return string
	 */
	public function get_region(): string;

	/**
	 * Check if the provider uses path-style URLs
	 *
	 * Path-style: https://endpoint/bucket/object
	 * Virtual-hosted style: https://bucket.endpoint/object
	 *
	 * @return bool
	 */
	public function uses_path_style(): bool;

	/**
	 * Format bucket URL
	 *
	 * @param string $bucket Bucket name
	 * @param string $object Optional object key
	 *
	 * @return string
	 */
	public function format_url( string $bucket, string $object = '' ): string;

	/**
	 * Format object URI for signing
	 *
	 * @param string $bucket     Bucket name
	 * @param string $object_key Object key
	 *
	 * @return string
	 */
	public function format_canonical_uri( string $bucket, string $object_key ): string;

	/**
	 * Get default region
	 *
	 * @return string
	 */
	public function get_default_region(): string;

	/**
	 * Check if account ID is required
	 *
	 * @return bool
	 */
	public function requires_account_id(): bool;

	/**
	 * Get available regions
	 *
	 * @return array Associative array of region code => region label
	 */
	public function get_available_regions(): array;

	/**
	 * Check if a region is valid for this provider
	 *
	 * @param string $region Region code to check
	 *
	 * @return bool True if the region is valid
	 */
	public function is_valid_region( string $region ): bool;

	/**
	 * Check if the provider has integrated CDN
	 *
	 * @return bool
	 */
	public function has_integrated_cdn(): bool;

	/**
	 * Get CDN URL for a bucket (if supported)
	 *
	 * @param string $bucket Bucket name
	 * @param string $object Optional object key
	 *
	 * @return string|null CDN URL or null if not supported
	 */
	public function get_cdn_url( string $bucket, string $object = '' ): ?string;

}