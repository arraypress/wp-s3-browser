<?php
/**
 * Client Configuration Trait
 *
 * Handles configuration and setup for the S3 Client.
 *
 * @package     ArrayPress\S3\Traits
 * @copyright   Copyright (c) 2025, ArrayPress Limited
 * @license     GPL2+
 * @version     1.0.0
 * @author      ArrayPress Team
 */

declare( strict_types=1 );

namespace ArrayPress\S3\Traits\Client;

use ArrayPress\S3\Abstracts\Provider;

/**
 * Trait ClientConfiguration
 */
trait Configuration {

	/**
	 * Get the provider instance
	 *
	 * @return Provider
	 */
	public function get_provider(): Provider {
		return $this->provider;
	}

	/**
	 * Set a custom debug logger callback
	 *
	 * @param callable $callback Function to call for debug logging
	 *
	 * @return self
	 */
	public function set_debug_logger( callable $callback ): self {
		$this->debug_logger = $callback;

		// Also set the debug callback for the signer
		$this->signer->set_debug_callback( $callback );

		return $this;
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
	 * Log debug information
	 *
	 * @param string $message Message to log
	 * @param mixed  $data    Optional data to include
	 */
	private function log_debug( string $message, $data = null ): void {
		if ( ! $this->debug ) {
			return;
		}

		// Use custom logger if set
		if ( is_callable( $this->debug_logger ) ) {
			call_user_func( $this->debug_logger, $message, $data );

			return;
		}

		// Default to error_log
		error_log( $message );
		if ( $data !== null ) {
			error_log( print_r( $data, true ) );
		}
	}

}