<?php
/**
 * Form Utility Class
 *
 * Handles form processing and sanitization for S3 settings.
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
 * Class Save
 *
 * Simple form processing utilities
 */
class Save {

	/**
	 * Process and save minutes field with validation
	 *
	 * @param string $post_key    POST field key
	 * @param string $option_name WordPress option name
	 * @param int    $min_minutes Minimum allowed minutes (default: 1)
	 * @param int    $max_minutes Maximum allowed minutes (default: 10080 = 7 days)
	 * @param bool   $unset_post  Whether to unset the POST field after processing
	 *
	 * @return bool True if field was processed and saved
	 */
	public static function minutes_option(
		string $post_key,
		string $option_name,
		int $min_minutes = 1,
		int $max_minutes = 10080,
		bool $unset_post = true
	): bool {
		if ( ! isset( $_POST[ $post_key ] ) ) {
			return false;
		}

		$minutes = max( $min_minutes, min( $max_minutes, (int) $_POST[ $post_key ] ) );
		update_option( $option_name, $minutes );

		if ( $unset_post ) {
			unset( $_POST[ $post_key ] );
		}

		return true;
	}

	/**
	 * Process and save encrypted POST field
	 *
	 * @param string   $post_key   POST field key
	 * @param string   $option_key Encryption option key
	 * @param object   $encryption Encryption instance
	 * @param callable $sanitizer  Sanitization function
	 * @param bool     $unset_post Whether to unset the POST field after processing
	 *
	 * @return bool True if field was processed and saved
	 */
	public static function encrypted_option(
		string $post_key,
		string $option_key,
		object $encryption,
		callable $sanitizer,
		bool $unset_post = true
	): bool {
		if ( ! isset( $_POST[ $post_key ] ) ) {
			return false;
		}

		// Trim the value first
		$trimmed_value = trim( $_POST[ $post_key ] );

		// If the field is empty after trimming, delete the option
		if ( empty( $trimmed_value ) ) {
			$encryption->delete_option( $option_key );
		} else {
			// Otherwise sanitize and save the trimmed value
			$sanitized_value = call_user_func( $sanitizer, $trimmed_value );
			$encryption->update_option( $option_key, $sanitized_value );
		}

		if ( $unset_post ) {
			unset( $_POST[ $post_key ] );
		}

		return true;
	}

}