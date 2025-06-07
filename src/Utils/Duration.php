<?php
/**
 * Duration Utility Class
 *
 * Simple duration conversion for S3 presigned URL expiration times.
 *
 * @package     ArrayPress\S3\Utils
 * @copyright   Copyright (c) 2025, ArrayPress Limited
 * @license     GPL2+
 * @version     1.0.0
 * @author      David Sherlock
 */

declare( strict_types=1 );

namespace ArrayPress\S3\Utils;

/**
 * Class Duration
 *
 * Simple duration conversion for S3 operations
 */
class Duration {

	/**
	 * Convert duration to minutes
	 *
	 * @param int    $number Duration number
	 * @param string $unit   Duration unit (minutes, hours, days)
	 *
	 * @return int Duration in minutes, capped at 7 days (10080 minutes)
	 */
	public static function to_minutes( int $number, string $unit ): int {
		$multipliers = [
			'minutes' => 1,
			'hours'   => 60,
			'days'    => 1440,
		];

		$multiplier = $multipliers[ $unit ] ?? 1;
		$minutes    = $number * $multiplier;

		// Cap at 7 days (AWS S3 limit)
		return min( $minutes, 10080 );
	}

	/**
	 * Parse duration from stored value or convert legacy minutes
	 *
	 * @param mixed $value Stored value (array with number/unit or legacy minutes)
	 *
	 * @return array Array with 'number' and 'unit' keys
	 */
	public static function parse( $value ): array {
		// Already an array with required keys
		if ( is_array( $value ) && isset( $value['number'], $value['unit'] ) ) {
			return [
				'number' => max( 1, (int) $value['number'] ),
				'unit'   => in_array( $value['unit'], [ 'minutes', 'hours', 'days' ], true ) ? $value['unit'] : 'minutes'
			];
		}

		// Legacy: convert minutes to best unit
		$minutes = max( 1, (int) $value );

		if ( $minutes >= 1440 && $minutes % 1440 === 0 ) {
			return [ 'number' => $minutes / 1440, 'unit' => 'days' ];
		}

		if ( $minutes >= 60 && $minutes % 60 === 0 ) {
			return [ 'number' => $minutes / 60, 'unit' => 'hours' ];
		}

		return [ 'number' => $minutes, 'unit' => 'minutes' ];
	}

	/**
	 * Get maximum allowed value for a unit
	 *
	 * @param string $unit Duration unit
	 *
	 * @return int Maximum allowed value
	 */
	public static function get_max_for_unit( string $unit ): int {
		$max_values = [
			'minutes' => 10080, // 7 days
			'hours'   => 168,   // 7 days
			'days'    => 7,     // 7 days
		];

		return $max_values[ $unit ] ?? 10080;
	}

}