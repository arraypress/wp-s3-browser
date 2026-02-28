<?php
/**
 * Assets Utility
 *
 * Provides standalone asset loading for S3 Browser components
 * without requiring full Browser initialization.
 *
 * @package     ArrayPress\S3\Traits
 * @copyright   Copyright (c) 2025, ArrayPress Limited
 * @license     GPL2+
 * @version     1.0.0
 * @author      David Sherlock
 */
declare( strict_types=1 );

namespace ArrayPress\S3\Utils;

/**
 * Class Assets
 *
 * Utility for loading S3 Browser assets independently
 */
class Assets {

	/**
	 * Force load admin CSS without Browser initialization
	 *
	 * Use this when you need the admin styles (e.g., for validation sections)
	 * but don't have credentials configured yet.
	 *
	 * @param string $handle Optional. Style handle. Default 's3-admin-components'.
	 *
	 * @return bool True if enqueued successfully, false otherwise
	 */
	public static function load_admin_css( string $handle = 's3-admin-components' ): bool {
		// Check if already enqueued
		if ( wp_style_is( $handle ) ) {
			return true;
		}

		// Use this file's location to find assets
		if ( function_exists( 'wp_enqueue_composer_style' ) ) {
			return wp_enqueue_composer_style(
				$handle,
				__FILE__,
				'css/admin.css'
			);
		}

		return false;
	}

}