<?php
/**
 * Duration Utility Class
 *
 * Simple duration management for S3 presigned URL expiration times.
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
 * Simple duration management for S3 operations
 */
class Duration {

	/**
	 * Convert duration to minutes
	 *
	 * @param int $number  Duration number
	 * @param string $unit Duration unit (minutes, hours, days)
	 *
	 * @return int Duration in minutes
	 */
	public static function to_minutes( int $number, string $unit ): int {
		$multipliers = [
			'minutes' => 1,
			'hours'   => 60,
			'days'    => 1440,
		];

		$multiplier = $multipliers[ $unit ] ?? 1;

		return $number * $multiplier;
	}

	/**
	 * Convert duration array to minutes
	 *
	 * @param array $duration Array with 'number' and 'unit' keys
	 *
	 * @return int Duration in minutes
	 */
	public static function array_to_minutes( array $duration ): int {
		$number = (int) ( $duration['number'] ?? 1 );
		$unit   = $duration['unit'] ?? 'minutes';

		return self::to_minutes( $number, $unit );
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
				'unit'   => in_array( $value['unit'], [
					'minutes',
					'hours',
					'days'
				], true ) ? $value['unit'] : 'minutes'
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

}