<?php
/**
 * S3 Bucket Model
 *
 * Represents an S3 bucket with enhanced functionality.
 *
 * @package     ArrayPress\S3\Models
 * @copyright   Copyright (c) 2025, ArrayPress Limited
 * @license     GPL2+
 * @version     1.0.0
 * @author      David Sherlock
 */

declare( strict_types=1 );

namespace ArrayPress\S3\Models;

/**
 * Class S3Bucket
 */
class S3Bucket {

	/**
	 * Bucket name
	 *
	 * @var string
	 */
	private string $name;

	/**
	 * Creation date
	 *
	 * @var string
	 */
	private string $creation_date;

	/**
	 * Region (optional)
	 *
	 * @var string|null
	 */
	private ?string $region;

	/**
	 * Constructor
	 *
	 * @param array $data Bucket data from S3 API
	 */
	public function __construct( array $data ) {
		$this->name          = $data['Name'] ?? '';
		$this->creation_date = $data['CreationDate'] ?? '';
		$this->region        = $data['Region'] ?? null;
	}

	/**
	 * Get bucket name
	 *
	 * @return string
	 */
	public function get_name(): string {
		return $this->name;
	}

	/**
	 * Get creation date
	 *
	 * @param bool   $formatted Whether to return formatted date or raw timestamp
	 * @param string $format    PHP date format for formatted output
	 *
	 * @return string Raw timestamp or formatted date
	 */
	public function get_creation_date( bool $formatted = false, string $format = 'Y-m-d H:i:s' ): string {
		if ( ! $formatted ) {
			return $this->creation_date;
		}

		return empty( $this->creation_date ) ? '' : date( $format, strtotime( $this->creation_date ) );
	}

	/**
	 * Get region
	 *
	 * @return string|null
	 */
	public function get_region(): ?string {
		return $this->region;
	}

	/**
	 * Convert to array
	 *
	 * @return array
	 */
	public function to_array(): array {
		return [
			'Name'          => $this->name,
			'CreationDate'  => $this->creation_date,
			'Region'        => $this->region,
			'FormattedDate' => $this->get_creation_date( true )
		];
	}

}