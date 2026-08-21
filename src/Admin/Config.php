<?php
/**
 * Browser Configuration
 *
 * What one browser instance is: which provider, which bucket, who may use it,
 * and which context it belongs to. Every admin-side collaborator needs some of
 * this, and passing it around as six arguments is what kept them all attached
 * to the browser.
 *
 * @package     ArrayPress\S3\Admin
 * @copyright   Copyright (c) 2025, ArrayPress Limited
 * @license     GPL2+
 * @author      David Sherlock
 */

declare( strict_types=1 );

namespace ArrayPress\S3\Admin;

/**
 * Class Config
 */
class Config {

	/**
	 * Describe a browser instance.
	 *
	 * @param string      $provider_id        Provider identifier.
	 * @param string      $provider_name      Human label for the provider.
	 * @param string      $capability         Capability required to use it.
	 * @param string      $default_bucket     Bucket it opens on.
	 * @param array       $allowed_post_types Post types it appears for; empty means all.
	 * @param string|null $context            Which integration this instance serves.
	 */
	public function __construct(
		public readonly string $provider_id,
		public readonly string $provider_name,
		public readonly string $capability,
		public readonly string $default_bucket,
		public readonly array $allowed_post_types = [],
		public readonly ?string $context = null
	) {
	}

	/**
	 * Whether this instance belongs to a particular integration.
	 *
	 * @return bool
	 */
	public function has_context(): bool {
		return null !== $this->context;
	}

	/**
	 * A suffix distinguishing this instance from another.
	 *
	 * An EDD browser and a WooCommerce browser on the same site must not share
	 * REST route bases, asset handles or media tab ids.
	 *
	 * @return string
	 */
	public function hook_suffix(): string {
		return $this->has_context() ? $this->provider_id . '_' . $this->context : $this->provider_id;
	}

	/**
	 * The media uploader tab id for this instance.
	 *
	 * @return string
	 */
	public function tab_id(): string {
		return 's3_' . $this->hook_suffix();
	}

	/**
	 * Whether the current user may use this browser.
	 *
	 * @return bool
	 */
	public function user_is_allowed(): bool {
		return current_user_can( $this->capability );
	}

	/**
	 * Apply a filter, and its context-specific variant.
	 *
	 * An integration that only wants to change its own browser hooks the
	 * suffixed name; one that wants to change every browser hooks the base.
	 *
	 * @param string $name     Base filter name.
	 * @param mixed  $value    Value to filter.
	 * @param mixed  ...$args  Extra arguments for the filter.
	 *
	 * @return mixed Filtered value.
	 */
	public function filter( string $name, $value, ...$args ) {
		$args[] = $this->context;

		$value = apply_filters( $name, $value, ...$args );

		if ( $this->has_context() ) {
			$value = apply_filters( $name . '_' . $this->context, $value, ...$args );
		}

		return $value;
	}

}
