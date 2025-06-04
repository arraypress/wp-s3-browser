<?php
/**
 * Client Context Trait
 *
 * Provides context functionality for conditional filtering and operations.
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
 * Trait Context
 */
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
	 * Check if a specific context is set
	 *
	 * @param string $context Context to check
	 *
	 * @return bool Whether the specified context matches the current context
	 */
	public function is_context( string $context ): bool {
		return $this->context === $context;
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
	 * Apply a filter with context support
	 *
	 * @param string $filter_name Base filter name
	 * @param mixed  $value       Value to filter
	 * @param mixed  ...$args     Additional arguments for the filter
	 *
	 * @return mixed Filtered value
	 */
	protected function apply_context_filter( string $filter_name, $value, ...$args ) {
		// Add context as the last parameter
		$args[] = $this->context;

		// Apply the filter
		return apply_filters( $filter_name, $value, ...$args );
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

}