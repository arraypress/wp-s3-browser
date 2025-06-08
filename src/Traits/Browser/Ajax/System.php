<?php
/**
 * Browser System Operations AJAX Handlers Trait
 *
 * Handles AJAX operations for system-level functionality in the S3 Browser.
 *
 * @package     ArrayPress\S3\Traits\Browser\Ajax
 * @copyright   Copyright (c) 2025, ArrayPress Limited
 * @license     GPL2+
 * @version     1.0.0
 * @author      David Sherlock
 */

declare( strict_types=1 );

namespace ArrayPress\S3\Traits\Browser\Ajax;

use ArrayPress\S3\Tables\Objects;

/**
 * Trait System
 *
 * Provides AJAX endpoint handlers for system-level operations including
 * cache management, pagination, and user preferences.
 */
trait System {

	/**
	 * Handle AJAX load more request
	 *
	 * Processes pagination requests for object listings. Delegates to the
	 * Objects table class for handling pagination logic and returning
	 * additional object data.
	 *
	 * Expected POST parameters:
	 * - Various pagination parameters handled by Objects::ajax_load_more()
	 * - nonce: Security nonce
	 *
	 * @since 1.0.0
	 */
	public function handle_ajax_load_more(): void {
		if ( ! $this->verify_ajax_request() ) {
			return;
		}

		Objects::ajax_load_more( $this->client, $this->provider_id );
	}

	/**
	 * Handle AJAX cache clear request
	 *
	 * Processes requests to clear all cached S3 data. This includes object
	 * listings, bucket information, and any other cached S3 responses.
	 * Useful for forcing fresh data retrieval from S3.
	 *
	 * Expected POST parameters:
	 * - nonce: Security nonce
	 *
	 * @since 1.0.0
	 */
	public function handle_ajax_clear_cache(): void {
		if ( ! $this->verify_ajax_request() ) {
			return;
		}

		$success = $this->client->clear_all_cache();

		if ( $success ) {
			wp_send_json_success( [
				'message' => __( 'Cache cleared successfully', 'arraypress' ),
				'status'  => 'success'
			] );
		} else {
			wp_send_json_error( [
				'message' => __( 'Failed to clear cache', 'arraypress' ),
				'status'  => 'error'
			] );
		}
	}

	/**
	 * Handle AJAX toggle favorite request
	 *
	 * Processes requests to set or remove a bucket as the user's default/favorite
	 * bucket for a specific post type context. This allows users to have different
	 * default buckets for different contexts (e.g., media library vs. custom post types).
	 *
	 * Expected POST parameters:
	 * - bucket: Bucket name to set as favorite
	 * - favorite_action: 'add' or 'remove' (optional, toggles if not specified)
	 * - post_type: Context for the favorite (defaults to 'default')
	 * - nonce: Security nonce
	 *
	 * @since 1.0.0
	 */
	public function handle_ajax_toggle_favorite(): void {
		if ( ! $this->verify_ajax_request() ) {
			return;
		}

		$bucket = $this->get_sanitized_post( 'bucket' );
		if ( empty( $bucket ) ) {
			wp_send_json_error( [ 'message' => __( 'Bucket name is required', 'arraypress' ) ] );
			return;
		}

		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			wp_send_json_error( [ 'message' => __( 'User not logged in', 'arraypress' ) ] );
			return;
		}

		$action           = $this->get_sanitized_post( 'favorite_action' );
		$post_type        = $this->get_sanitized_post( 'post_type' ) ?: 'default';
		$meta_key         = "s3_favorite_{$this->provider_id}_{$post_type}";
		$current_favorite = get_user_meta( $user_id, $meta_key, true );

		$should_add = $action === 'add' || ( $action !== 'remove' && $current_favorite !== $bucket );

		delete_user_meta( $user_id, $meta_key );

		$result = true;
		if ( $should_add ) {
			$result = update_user_meta( $user_id, $meta_key, $bucket );
			$status = 'added';
		} else {
			$status = 'removed';
		}

		if ( $result ) {
			wp_send_json_success( [
				'message' => $status === 'added'
					? __( 'Bucket set as default', 'arraypress' )
					: __( 'Default bucket removed', 'arraypress' ),
				'status'  => $status,
				'bucket'  => $bucket
			] );
		} else {
			wp_send_json_error( [ 'message' => __( 'Failed to update default bucket', 'arraypress' ) ] );
		}
	}

}