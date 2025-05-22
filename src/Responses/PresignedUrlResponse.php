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
 * @author      David Sherlock
 */

declare( strict_types=1 );

namespace ArrayPress\S3\Responses;

use ArrayPress\S3\Abstracts\Response;

/**
 * Response for presigned URL operations
 */
class PresignedUrlResponse extends Response {

	/**
	 * The presigned URL
	 *
	 * @var string
	 */
	private string $url;

	/**
	 * URL expiration timestamp
	 *
	 * @var int|null
	 */
	private ?int $expires_at;

	/**
	 * Constructor
	 *
	 * @param string   $url         Presigned URL
	 * @param null|int $expires_at  Expiration timestamp
	 * @param int      $status_code HTTP status code
	 * @param mixed    $raw_data    Original raw data
	 */
	public function __construct(
		string $url,
		?int $expires_at = null,
		int $status_code = 200,
		$raw_data = null
	) {
		parent::__construct( $status_code, true, $raw_data );
		$this->url        = $url;
		$this->expires_at = $expires_at;
	}

	/**
	 * Get the presigned URL
	 *
	 * @return string
	 */
	public function get_url(): string {
		return $this->url;
	}

	/**
	 * Get expiration timestamp
	 *
	 * @return int|null
	 */
	public function get_expires_at(): ?int {
		return $this->expires_at;
	}

	/**
	 * Check if URL has expired
	 *
	 * @return bool
	 */
	public function has_expired(): bool {
		return $this->expires_at !== null && $this->expires_at < time();
	}

	/**
	 * Get seconds until expiration
	 *
	 * @return int|null Seconds until expiration or null if no expiration
	 */
	public function get_seconds_until_expiration(): ?int {
		if ( $this->expires_at === null ) {
			return null;
		}

		$remaining = $this->expires_at - time();

		return $remaining > 0 ? $remaining : 0;
	}

	/**
	 * Convert to array
	 *
	 * @return array
	 */
	public function to_array(): array {
		$array = parent::to_array();

		$array['url'] = $this->url;

		if ( $this->expires_at !== null ) {
			$array['expires_at']         = $this->expires_at;
			$array['expires_in_seconds'] = $this->get_seconds_until_expiration();
			$array['has_expired']        = $this->has_expired();
		}

		return $array;
	}

}