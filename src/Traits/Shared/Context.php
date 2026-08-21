<?php
/**
 * Context Trait - Enhanced with Tab ID Methods
 *
 * Provides context management and consistent ID generation for tabs, hooks, and actions.
 *
 * @package     ArrayPress\S3\Traits\Shared
 * @copyright   Copyright (c) 2025, ArrayPress Limited
 * @license     GPL2+
 * @version     1.0.0
 * @author      David Sherlock
 */

declare( strict_types=1 );

namespace ArrayPress\S3\Traits\Shared;

trait Context {

	/**
	 * Context identifier for this client instance
	 *
	 * @var string|null
	 */
	private ?string $context = null;

	/**
	 * Set the context for this client
	 *
	 * @param string|null $context Context identifier (e.g., 'edd_plugin', 'woocommerce', etc.)
	 *
	 * @return self
	 */
	public function set_context( ?string $context ): self {
		$this->context = $context;

		return $this;
	}

	/**
	 * Get the current context
	 *
	 * @return string|null Current context or null if not set
	 */
	public function get_context(): ?string {
		return $this->context;
	}

	/**
	 * Check if any context is set
	 *
	 * @return bool Whether a context is currently set
	 */
	public function has_context(): bool {
		return $this->context !== null;
	}

	/**
	 * Apply multiple filters with context support
	 *
	 * This allows applying both a general filter and a context-specific filter
	 *
	 * @param string $base_filter_name Base filter name (without context)
	 * @param mixed  $value            Value to filter
	 * @param mixed  ...$args          Additional arguments for the filter
	 *
	 * @return mixed Filtered value
	 */
	protected function apply_contextual_filters( string $base_filter_name, $value, ...$args ) {
		// Add context as the last parameter
		$args[] = $this->context;

		// Apply the general filter first
		$value = apply_filters( $base_filter_name, $value, ...$args );

		// If we have a context, also apply the context-specific filter
		if ( $this->has_context() ) {
			$contextual_filter_name = $base_filter_name . '_' . $this->context;
			$value                  = apply_filters( $contextual_filter_name, $value, ...$args );
		}

		return $value;
	}

	/**
	 * Get a unique suffix based on provider and context
	 *
	 * Distinguishes one browser instance from another — an EDD one and a
	 * WooCommerce one on the same site — in REST route bases and asset
	 * handles.
	 *
	 * @return string Hook suffix for AJAX actions
	 */
	private function get_hook_suffix(): string {
		if ( $this->has_context() ) {
			return $this->provider_id . '_' . $this->get_context();
		}

		// Fallback to just provider ID
		return $this->provider_id;
	}

	/**
	 * Get the tab ID for media uploader tabs
	 *
	 * This consistently returns the tab ID with 's3_' prefix
	 *
	 * @return string Tab ID for media uploader
	 */
	protected function get_tab_id(): string {
		return 's3_' . $this->get_hook_suffix();
	}



}