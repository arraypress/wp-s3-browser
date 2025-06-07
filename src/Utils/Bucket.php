<?php
/**
 * Bucket Utility Class
 *
 * Handles bucket information gathering and metadata operations.
 *
 * @package     ArrayPress\S3\Utils
 * @copyright   Copyright (c) 2025, ArrayPress Limited
 * @license     GPL2+
 * @version     1.0.0
 * @author      David Sherlock
 */

declare( strict_types=1 );

namespace ArrayPress\S3\Utils;

use ArrayPress\S3\Client;

/**
 * Class Bucket
 *
 * Bucket information utilities
 */
class Bucket {

	/**
	 * Get basic bucket information using client methods
	 *
	 * @param Client $client S3 client instance
	 * @param string $bucket Bucket name
	 *
	 * @return array Basic bucket info
	 */
	public static function get_basic_info( Client $client, string $bucket ): array {
		$info = [
			'name'    => $bucket,
			'region'  => null,
			'created' => null,
		];

		// Get bucket location
		$location_result = $client->get_bucket_location( $bucket );
		if ( $location_result->is_successful() ) {
			$location_data  = $location_result->get_data();
			$info['region'] = $location_data['location'] ?? null;
		}

		// Get creation date from bucket list
		$buckets_result = $client->get_bucket_models();
		if ( $buckets_result->is_successful() ) {
			$buckets_data = $buckets_result->get_data();
			$buckets      = $buckets_data['buckets'] ?? [];

			foreach ( $buckets as $bucket_model ) {
				if ( $bucket_model->get_name() === $bucket ) {
					$info['created'] = $bucket_model->get_creation_date( true );
					break;
				}
			}
		}

		return $info;
	}

	/**
	 * Get bucket permissions summary
	 *
	 * @param Client $client S3 client instance
	 * @param string $bucket Bucket name
	 *
	 * @return array|null Permissions info or null if check failed
	 */
	public static function get_permissions( Client $client, string $bucket ): ?array {
		try {
			$permissions = $client->check_key_permissions( $bucket, true );

			return [
				'read'   => $permissions['read'] ?? false,
				'write'  => $permissions['write'] ?? false,
				'delete' => $permissions['delete'] ?? false,
			];
		} catch ( \Exception $e ) {
			return null;
		}
	}

	/**
	 * Get CORS analysis for a bucket
	 *
	 * @param Client $client         S3 client instance
	 * @param string $bucket         Bucket name
	 * @param string $current_origin Current origin for upload checking
	 *
	 * @return array CORS analysis data
	 */
	public static function get_cors_analysis( Client $client, string $bucket, string $current_origin ): array {
		$cors_data = [
			'analysis'       => null,
			'upload_ready'   => false,
			'current_origin' => $current_origin,
			'details'        => 'CORS not configured'
		];

		// Get CORS configuration analysis
		$cors_result = $client->analyze_cors_configuration( $bucket );
		if ( $cors_result->is_successful() ) {
			$cors_data['analysis'] = $cors_result->get_data();

			// Check upload capability
			$upload_check = $client->cors_allows_upload( $bucket, $current_origin );
			if ( $upload_check->is_successful() ) {
				$upload_data = $upload_check->get_data();

				$cors_data['upload_ready']    = $upload_data['allows_upload'];
				$cors_data['allowed_methods'] = $upload_data['allowed_methods'] ?? [];
				$cors_data['details']         = $upload_data['allows_upload']
					? __( 'Upload allowed from current domain', 'arraypress' )
					: __( 'Upload not allowed from current domain', 'arraypress' );
			}
		}

		return $cors_data;
	}

	/**
	 * Get comprehensive bucket details
	 *
	 * @param Client $client         S3 client instance
	 * @param string $bucket         Bucket name
	 * @param string $current_origin Current origin for CORS checking
	 *
	 * @return array Complete bucket details
	 */
	public static function get_details( Client $client, string $bucket, string $current_origin ): array {
		return [
			'bucket'      => $bucket,
			'basic'       => self::get_basic_info( $client, $bucket ),
			'cors'        => self::get_cors_analysis( $client, $bucket, $current_origin ),
			'permissions' => self::get_permissions( $client, $bucket ),
		];
	}

}