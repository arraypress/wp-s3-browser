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

namespace ArrayPress\S3\Responses;

use ArrayPress\S3\Abstracts\Response;

/**
 * Response for object retrieval operations
 */
class ObjectResponse extends Response {

	/**
	 * Object content
	 *
	 * @var string
	 */
	private string $content;

	/**
	 * Object metadata
	 *
	 * @var array
	 */
	private array $metadata;

	/**
	 * Constructor
	 *
	 * @param string $content     Object content
	 * @param array  $metadata    Object metadata
	 * @param int    $status_code HTTP status code
	 * @param mixed  $raw_data    Original raw data
	 */
	public function __construct(
		string $content,
		array $metadata = [],
		int $status_code = 200,
		$raw_data = null
	) {
		parent::__construct( $status_code, $status_code >= 200 && $status_code < 300, $raw_data );
		$this->content  = $content;
		$this->metadata = $metadata;
	}

	/**
	 * Get object content
	 *
	 * @return string
	 */
	public function get_content(): string {
		return $this->content;
	}

	/**
	 * Get object metadata
	 *
	 * @return array
	 */
	public function get_metadata(): array {
		return $this->metadata;
	}

	/**
	 * Get specific metadata value
	 *
	 * @param string $key     Metadata key
	 * @param mixed  $default Default value if key not found
	 *
	 * @return mixed
	 */
	public function get_metadata_value( string $key, $default = null ) {
		return $this->metadata[ $key ] ?? $default;
	}

	/**
	 * Get content type
	 *
	 * @return string
	 */
	public function get_content_type(): string {
		return $this->get_metadata_value( 'content_type', 'application/octet-stream' );
	}

	/**
	 * Get content length
	 *
	 * @return int
	 */
	public function get_content_length(): int {
		return $this->get_metadata_value( 'content_length', strlen( $this->content ) );
	}

	/**
	 * Convert to array
	 *
	 * @return array
	 */
	public function to_array(): array {
		$array = parent::to_array();

		$array['metadata']       = $this->metadata;
		$array['content_length'] = $this->get_content_length();
		$array['content_type']   = $this->get_content_type();
		$array['has_content']    = ! empty( $this->content );

		return $array;
	}

}