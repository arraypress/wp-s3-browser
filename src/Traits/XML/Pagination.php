<?php
/**
 * XML Pagination Trait
 *
 * Provides pagination-specific utilities for S3 API responses.
 *
 * @package     ArrayPress\S3\Traits\XML
 * @copyright   Copyright (c) 2025, ArrayPress Limited
 * @license     GPL2+
 * @version     1.0.0
 * @author      David Sherlock
 */

declare( strict_types=1 );

namespace ArrayPress\S3\Traits\XML;

use ArrayPress\S3\Utils\Xml;

/**
 * Trait Pagination
 */
trait Pagination {

	/**
	 * Check if XML array represents truncated results (S3-specific)
	 *
	 * @param array $xml XML array
	 *
	 * @return bool True if truncated
	 */
	protected function is_truncated_response( array $xml ): bool {
		$truncated_value = Xml::find_value( $xml, 'IsTruncated' );
		if ( $truncated_value !== null ) {
			$text_value = $this->extract_text_value( $truncated_value );

			return $text_value === 'true' || $text_value === '1';
		}

		return false;
	}

	/**
	 * Get next continuation token from XML (S3-specific)
	 *
	 * @param array $xml XML array
	 *
	 * @return string|null Next continuation token or null
	 */
	protected function get_continuation_token( array $xml ): ?string {
		$token = Xml::find_value( $xml, 'NextContinuationToken' );
		if ( $token !== null ) {
			return $this->extract_text_value( $token );
		}

		return null;
	}

	/**
	 * Get next marker from XML (S3-specific)
	 *
	 * @param array $xml XML array
	 *
	 * @return string|null Next marker or null
	 */
	protected function get_next_marker( array $xml ): ?string {
		$marker = Xml::find_value( $xml, 'NextMarker' );
		if ( $marker !== null ) {
			return $this->extract_text_value( $marker );
		}

		return null;
	}

}