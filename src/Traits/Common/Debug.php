<?php
/**
 * Debug Trait
 *
 * Provides unified debug functionality for S3 operations.
 *
 * @package     ArrayPress\S3\Traits
 * @copyright   Copyright (c) 2025, ArrayPress Limited
 * @license     GPL2+
 * @version     1.0.0
 * @author      David Sherlock
 */

declare( strict_types=1 );

namespace ArrayPress\S3\Traits\Common;

/**
 * Trait Debug
 *
 * Unified debug functionality for consistent logging across S3 operations
 */
trait Debug {

	/**
	 * Debug mode flag
	 *
	 * @var bool
	 */
	private bool $debug = false;

	/**
	 * Debug callback function
	 *
	 * @var callable|null
	 */
	private $debug_callback = null;

	/**
	 * Log debug information if debug mode is enabled
	 *
	 * @param string $title Debug message title
	 * @param mixed  $data  Optional data to include in debug output
	 */
	protected function debug( string $title, $data = null ): void {
		if ( ! $this->debug ) {
			return;
		}

		if ( is_callable( $this->debug_callback ) ) {
			call_user_func( $this->debug_callback, $title, $data );
			return;
		}

		// Fallback to error_log
		error_log( "[S3 DEBUG] {$title}" );
		if ( $data !== null ) {
			error_log( print_r( $data, true ) );
		}
	}

	/**
	 * Enable or disable debug mode
	 *
	 * @param bool $enable Whether to enable debug mode
	 *
	 * @return self
	 */
	public function set_debug( bool $enable ): self {
		$this->debug = $enable;

		return $this;
	}

	/**
	 * Check if debug mode is enabled
	 *
	 * @return bool
	 */
	public function is_debug_enabled(): bool {
		return $this->debug;
	}

	/**
	 * Set a custom debug callback function
	 *
	 * @param callable $callback Function to call for debug logging
	 *                          Signature: function ( string $title, mixed $data )
	 *
	 * @return self
	 */
	public function set_debug_callback( callable $callback ): self {
		$this->debug_callback = $callback;

		return $this;
	}

	/**
	 * Remove the debug callback (fallback to error_log)
	 *
	 * @return self
	 */
	public function remove_debug_callback(): self {
		$this->debug_callback = null;

		return $this;
	}

}