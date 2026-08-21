<?php
/**
 * Client/Signer Config Trait
 *
 * @package     ArrayPress\S3\Traits
 * @copyright   Copyright (c) 2025, ArrayPress Limited
 * @license     GPL2+
 * @version     1.0.0
 * @author      David Sherlock
 */

declare( strict_types=1 );

namespace ArrayPress\S3\Traits\Shared;

/**
 * Trait Config
 */
trait Config {

	/**
	 * User agent for HTTP requests
	 *
	 * @var string
	 */
	private string $user_agent = 'ArrayPress-S3-Client/1.0';

	/**
	 * Admin hook(s) for enqueuing assets on specific admin pages
	 * Stored internally as array for consistency
	 *
	 * @var array
	 */
	private array $admin_hook = [];

	/**
	 * Get user agent with WordPress info (for better compatibility)
	 *
	 * @return string Enhanced user agent
	 */
	public function get_enhanced_user_agent(): string {
		$wp_version  = get_bloginfo( 'version' );
		$php_version = PHP_VERSION;

		return sprintf(
			'%s WordPress/%s PHP/%s',
			$this->user_agent,
			$wp_version,
			$php_version
		);
	}

	/**
	 * Get common request headers with user agent
	 *
	 * @param array $additional_headers Additional headers to merge
	 *
	 * @return array Complete headers array
	 */
	public function get_base_request_headers( array $additional_headers = [] ): array {
		$base_headers = [
			'User-Agent' => $this->get_enhanced_user_agent(),
		];

		return array_merge( $base_headers, $additional_headers );
	}

	/**
	 * Set the admin hook(s) for this browser instance
	 *
	 * @param string|array $hook Admin hook suffix or array of hook suffixes
	 */
	public function set_admin_hook( $hook ): void {
		if ( is_string( $hook ) && ! empty( $hook ) ) {
			$this->admin_hook = [ $hook ];
		} elseif ( is_array( $hook ) ) {
			$this->admin_hook = array_filter( $hook ); // Remove empty values
		} else {
			$this->admin_hook = [];
		}
	}

	/**
	 * Check if current hook matches any registered admin hooks
	 *
	 * @param string $hook Hook suffix to check
	 *
	 * @return bool
	 */
	public function matches_admin_hook( string $hook ): bool {
		return ! empty( $this->admin_hook ) && in_array( $hook, $this->admin_hook, true );
	}

}