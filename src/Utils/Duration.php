<?php
/**
 * Duration Utility Class
 *
 * Enhanced duration management for S3 presigned URL expiration times and other
 * time-based operations with improved validation and timestamp handling.
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
 * Enhanced duration management for S3 operations with timestamp utilities
 */
class Duration {

	/**
	 * Maximum allowed duration in minutes (7 days - AWS S3 limit)
	 */
	const MAX_MINUTES = 10080;

	/**
	 * Minimum allowed duration in minutes
	 */
	const MIN_MINUTES = 1;

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
		return min( $minutes, self::MAX_MINUTES );
	}

	/**
	 * Validate and normalize minutes value
	 *
	 * Ensures the value is within acceptable bounds for S3 presigned URLs.
	 *
	 * @param int $minutes Minutes to validate
	 *
	 * @return int Validated minutes value
	 */
	public static function validate_minutes( int $minutes ): int {
		if ( $minutes < self::MIN_MINUTES ) {
			return self::MIN_MINUTES;
		}

		if ( $minutes > self::MAX_MINUTES ) {
			return self::MAX_MINUTES;
		}

		return $minutes;
	}

	/**
	 * Add minutes to a timestamp
	 *
	 * Clean utility method for calculating expiration times.
	 *
	 * @param int $timestamp Base timestamp
	 * @param int $minutes   Minutes to add
	 *
	 * @return int New timestamp
	 */
	public static function add_minutes_to_timestamp( int $timestamp, int $minutes ): int {
		return $timestamp + ( $minutes * 60 );
	}

	/**
	 * Add seconds to a timestamp
	 *
	 * Clean utility method for calculating short-term expiration times.
	 *
	 * @param int $timestamp Base timestamp
	 * @param int $seconds   Seconds to add
	 *
	 * @return int New timestamp
	 */
	public static function add_seconds_to_timestamp( int $timestamp, int $seconds ): int {
		return $timestamp + $seconds;
	}

	/**
	 * Get expiration timestamp for upload URLs (15 minutes from now)
	 *
	 * @param int|null $base_timestamp Base timestamp (defaults to current time)
	 *
	 * @return int Expiration timestamp
	 */
	public static function get_upload_expiration( ?int $base_timestamp = null ): int {
		$base_timestamp = $base_timestamp ?? time();

		return self::add_minutes_to_timestamp( $base_timestamp, 15 );
	}

	/**
	 * Get expiration timestamp for download URLs (60 minutes from now)
	 *
	 * @param int|null $base_timestamp Base timestamp (defaults to current time)
	 *
	 * @return int Expiration timestamp
	 */
	public static function get_download_expiration( ?int $base_timestamp = null ): int {
		$base_timestamp = $base_timestamp ?? time();

		return self::add_minutes_to_timestamp( $base_timestamp, 60 );
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

	/**
	 * Get maximum allowed value for a unit
	 *
	 * @param string $unit Duration unit
	 *
	 * @return int Maximum allowed value
	 */
	public static function get_max_for_unit( string $unit ): int {
		$max_values = [
			'minutes' => self::MAX_MINUTES, // 7 days
			'hours'   => 168,              // 7 days
			'days'    => 7,                // 7 days
		];

		return $max_values[ $unit ] ?? self::MAX_MINUTES;
	}

	/**
	 * Format duration for display
	 *
	 * @param int $minutes Duration in minutes
	 *
	 * @return string Formatted duration string
	 */
	public static function format_display( int $minutes ): string {
		if ( $minutes >= 1440 ) {
			$days              = floor( $minutes / 1440 );
			$remaining_minutes = $minutes % 1440;

			if ( $remaining_minutes === 0 ) {
				return sprintf( _n( '%d day', '%d days', $days, 'arraypress' ), $days );
			}

			$hours = floor( $remaining_minutes / 60 );
			if ( $hours > 0 ) {
				return sprintf(
					__( '%d days, %d hours', 'arraypress' ),
					$days,
					$hours
				);
			}

			return sprintf(
				__( '%d days, %d minutes', 'arraypress' ),
				$days,
				$remaining_minutes
			);
		}

		if ( $minutes >= 60 ) {
			$hours             = floor( $minutes / 60 );
			$remaining_minutes = $minutes % 60;

			if ( $remaining_minutes === 0 ) {
				return sprintf( _n( '%d hour', '%d hours', $hours, 'arraypress' ), $hours );
			}

			return sprintf(
				__( '%d hours, %d minutes', 'arraypress' ),
				$hours,
				$remaining_minutes
			);
		}

		return sprintf( _n( '%d minute', '%d minutes', $minutes, 'arraypress' ), $minutes );
	}

}