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
 * @author      ArrayPress Team
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
	 * @return string
	 */
	public function get_creation_date(): string {
		return $this->creation_date;
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
	 * Get a formatted creation date
	 *
	 * @param string $format PHP date format
	 *
	 * @return string
	 */
	public function get_formatted_date( string $format = 'Y-m-d H:i:s' ): string {
		return empty( $this->creation_date ) ? '' : date( $format, strtotime( $this->creation_date ) );
	}

	/**
	 * Get admin URL for viewing this bucket
	 *
	 * @param string $admin_url  Base admin URL (required)
	 * @param array  $query_args Additional query args to add
	 *
	 * @return string URL for viewing this bucket
	 */
	public function get_admin_url( string $admin_url, array $query_args = [] ): string {
		if ( empty( $admin_url ) ) {
			return '';
		}

		// Merge provided query args with required ones
		$args = array_merge( [
			'bucket' => $this->name
		], $query_args );

		// Add query parameters
		return add_query_arg( $args, $admin_url );
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
			'FormattedDate' => $this->get_formatted_date()
		];
	}

	/**
	 * Create from array
	 *
	 * @param array $data Bucket data
	 *
	 * @return self
	 */
	public static function from_array( array $data ): self {
		return new self( $data );
	}

}