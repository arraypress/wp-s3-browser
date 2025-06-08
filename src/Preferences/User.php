<?php
/**
 * User Preferences Utility Class
 *
 * Handles user preference management for S3 Browser functionality including
 * favorite buckets, default settings, and other user-specific configurations.
 *
 * @package     ArrayPress\S3\Utils
 * @copyright   Copyright (c) 2025, ArrayPress Limited
 * @license     GPL2+
 * @version     1.0.0
 * @author      David Sherlock
 */

declare( strict_types=1 );

namespace ArrayPress\S3\Preferences;

/**
 * Class UserPreferences
 *
 * Manages user-specific preferences for S3 Browser operations
 */
class User {

	/**
	 * Prefix for all S3 user meta keys
	 */
	const META_PREFIX = 's3_favorite_';

	/**
	 * Default context when none specified
	 */
	const DEFAULT_CONTEXT = 'default';

	/**
	 * Get favorite bucket for a user and context
	 *
	 * @param int    $user_id     User ID (defaults to current user)
	 * @param string $provider_id S3 provider identifier
	 * @param string $context     Context (post type, etc.)
	 *
	 * @return string|null Favorite bucket name or null if none set
	 */
	public static function get_favorite_bucket( int $user_id = 0, string $provider_id = '', string $context = self::DEFAULT_CONTEXT ): ?string {
		$user_id = $user_id ?: get_current_user_id();

		if ( ! $user_id || empty( $provider_id ) ) {
			return null;
		}

		$meta_key = self::build_meta_key( $provider_id, $context );
		$favorite = get_user_meta( $user_id, $meta_key, true );

		return ! empty( $favorite ) ? $favorite : null;
	}

	/**
	 * Set favorite bucket for a user and context
	 *
	 * @param string $bucket      Bucket name to set as favorite
	 * @param int    $user_id     User ID (defaults to current user)
	 * @param string $provider_id S3 provider identifier
	 * @param string $context     Context (post type, etc.)
	 *
	 * @return bool True on success, false on failure
	 */
	public static function set_favorite_bucket( string $bucket, int $user_id = 0, string $provider_id = '', string $context = self::DEFAULT_CONTEXT ): bool {
		$user_id = $user_id ?: get_current_user_id();

		if ( ! $user_id || empty( $provider_id ) || empty( $bucket ) ) {
			return false;
		}

		$meta_key = self::build_meta_key( $provider_id, $context );

		return (bool) update_user_meta( $user_id, $meta_key, $bucket );
	}

	/**
	 * Remove favorite bucket for a user and context
	 *
	 * @param int    $user_id     User ID (defaults to current user)
	 * @param string $provider_id S3 provider identifier
	 * @param string $context     Context (post type, etc.)
	 *
	 * @return bool True on success, false on failure
	 */
	public static function remove_favorite_bucket( int $user_id = 0, string $provider_id = '', string $context = self::DEFAULT_CONTEXT ): bool {
		$user_id = $user_id ?: get_current_user_id();

		if ( ! $user_id || empty( $provider_id ) ) {
			return false;
		}

		$meta_key = self::build_meta_key( $provider_id, $context );

		return delete_user_meta( $user_id, $meta_key );
	}

	/**
	 * Toggle favorite bucket (add if not set, remove if already set to same bucket)
	 *
	 * @param string      $bucket      Bucket name
	 * @param string|null $action      Explicit action: 'add', 'remove', or null for toggle
	 * @param int         $user_id     User ID (defaults to current user)
	 * @param string      $provider_id S3 provider identifier
	 * @param string      $context     Context (post type, etc.)
	 *
	 * @return array Result with 'success', 'action', and 'bucket' keys
	 */
	public static function toggle_favorite_bucket(
		string $bucket,
		?string $action = null,
		int $user_id = 0,
		string $provider_id = '',
		string $context = self::DEFAULT_CONTEXT
	): array {
		$user_id = $user_id ?: get_current_user_id();

		if ( ! $user_id || empty( $provider_id ) || empty( $bucket ) ) {
			return [
				'success' => false,
				'action'  => null,
				'bucket'  => $bucket,
				'error'   => __( 'Invalid parameters provided', 'arraypress' )
			];
		}

		$current_favorite = self::get_favorite_bucket( $user_id, $provider_id, $context );

		// Determine what action to take
		$should_add = false;
		if ( $action === 'add' ) {
			$should_add = true;
		} elseif ( $action === 'remove' ) {
			$should_add = false;
		} else {
			// Toggle logic: add if not current favorite, remove if it is
			$should_add = $current_favorite !== $bucket;
		}

		// Perform the action
		if ( $should_add ) {
			$result           = self::set_favorite_bucket( $bucket, $user_id, $provider_id, $context );
			$performed_action = 'added';
		} else {
			$result           = self::remove_favorite_bucket( $user_id, $provider_id, $context );
			$performed_action = 'removed';
		}

		return [
			'success' => $result,
			'action'  => $performed_action,
			'bucket'  => $bucket
		];
	}

	/**
	 * Check if a bucket is the user's favorite for a context
	 *
	 * @param string $bucket      Bucket name to check
	 * @param int    $user_id     User ID (defaults to current user)
	 * @param string $provider_id S3 provider identifier
	 * @param string $context     Context (post type, etc.)
	 *
	 * @return bool True if the bucket is the favorite
	 */
	public static function is_favorite_bucket( string $bucket, int $user_id = 0, string $provider_id = '', string $context = self::DEFAULT_CONTEXT ): bool {
		$favorite = self::get_favorite_bucket( $user_id, $provider_id, $context );

		return $favorite === $bucket;
	}

	/**
	 * Get all favorite buckets for a user across all contexts
	 *
	 * @param int    $user_id     User ID (defaults to current user)
	 * @param string $provider_id S3 provider identifier
	 *
	 * @return array Array of context => bucket pairs
	 */
	public static function get_all_favorite_buckets( int $user_id = 0, string $provider_id = '' ): array {
		$user_id = $user_id ?: get_current_user_id();

		if ( ! $user_id || empty( $provider_id ) ) {
			return [];
		}

		// Get all user meta with our prefix
		$meta_prefix = self::META_PREFIX . $provider_id . '_';
		$all_meta    = get_user_meta( $user_id );
		$favorites   = [];

		foreach ( $all_meta as $key => $values ) {
			if ( strpos( $key, $meta_prefix ) === 0 ) {
				$context               = substr( $key, strlen( $meta_prefix ) );
				$favorites[ $context ] = $values[0] ?? null;
			}
		}

		return array_filter( $favorites ); // Remove empty values
	}

	/**
	 * Clear all favorite buckets for a user and provider
	 *
	 * @param int    $user_id     User ID (defaults to current user)
	 * @param string $provider_id S3 provider identifier
	 *
	 * @return bool True if any favorites were removed
	 */
	public static function clear_all_favorites( int $user_id = 0, string $provider_id = '' ): bool {
		$favorites   = self::get_all_favorite_buckets( $user_id, $provider_id );
		$removed_any = false;

		foreach ( array_keys( $favorites ) as $context ) {
			if ( self::remove_favorite_bucket( $user_id, $provider_id, $context ) ) {
				$removed_any = true;
			}
		}

		return $removed_any;
	}

	/**
	 * Build user meta key for favorite bucket storage
	 *
	 * @param string $provider_id S3 provider identifier
	 * @param string $context     Context (post type, etc.)
	 *
	 * @return string Meta key
	 */
	private static function build_meta_key( string $provider_id, string $context ): string {
		return self::META_PREFIX . $provider_id . '_' . $context;
	}

	/**
	 * Get formatted message for favorite bucket actions
	 *
	 * @param string $action Action performed ('added' or 'removed')
	 * @param string $bucket Bucket name
	 *
	 * @return string Formatted message
	 */
	public static function get_action_message( string $action, string $bucket ): string {
		switch ( $action ) {
			case 'added':
				return sprintf( __( 'Bucket "%s" set as default', 'arraypress' ), $bucket );
			case 'removed':
				return sprintf( __( 'Default bucket "%s" removed', 'arraypress' ), $bucket );
			default:
				return sprintf( __( 'Favorite bucket action completed for "%s"', 'arraypress' ), $bucket );
		}
	}

}