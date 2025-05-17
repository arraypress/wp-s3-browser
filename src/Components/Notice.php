<?php
/**
 * WordPress Notice Generator
 *
 * @package     ArrayPress\WP\Components
 * @copyright   Copyright (c) 2024, ArrayPress Limited
 * @license     GPL2+
 * @version     1.0.0
 * @author      ArrayPress
 */

declare( strict_types=1 );

namespace ArrayPress\S3\Components;

/**
 * Class Notice
 *
 * Simple utility for generating WordPress admin notices with proper HTML structure.
 */
class Notice {

	/**
	 * Generate an admin notice
	 *
	 * @param string $message     The notice message
	 * @param string $type        Optional. Notice type: 'success', 'info', 'warning', 'error'. Default 'info'.
	 * @param bool   $dismissible Optional. Whether the notice is dismissible. Default false.
	 * @param array  $attributes  Optional. Additional HTML attributes. Default empty array.
	 *
	 * @return string HTML output for the notice
	 */
	public static function create(
		string $message,
		string $type = 'info',
		bool $dismissible = false,
		array $attributes = []
	): string {
		// Validate notice type
		$valid_types = [ 'success', 'info', 'warning', 'error' ];
		if ( ! in_array( $type, $valid_types, true ) ) {
			$type = 'info';
		}

		// Build CSS classes
		$classes = [ 'notice', "notice-{$type}" ];

		if ( $dismissible ) {
			$classes[] = 'is-dismissible';
		}

		// Add custom classes from attributes
		if ( isset( $attributes['class'] ) ) {
			$custom_classes = is_array( $attributes['class'] )
				? $attributes['class']
				: explode( ' ', $attributes['class'] );
			$classes        = array_merge( $classes, $custom_classes );
			unset( $attributes['class'] );
		}

		// Build attributes string
		$attrs = [ 'class="' . esc_attr( implode( ' ', $classes ) ) . '"' ];

		foreach ( $attributes as $key => $value ) {
			$attrs[] = esc_attr( $key ) . '="' . esc_attr( $value ) . '"';
		}

		return sprintf(
			'<div %s><p>%s</p></div>',
			implode( ' ', $attrs ),
			wp_kses_post( $message )
		);
	}

	/**
	 * Echo an admin notice
	 *
	 * @param string $message     The notice message
	 * @param string $type        Optional. Notice type: 'success', 'info', 'warning', 'error'. Default 'info'.
	 * @param bool   $dismissible Optional. Whether the notice is dismissible. Default false.
	 * @param array  $attributes  Optional. Additional HTML attributes. Default empty array.
	 *
	 * @return void
	 */
	public static function echo(
		string $message,
		string $type = 'info',
		bool $dismissible = false,
		array $attributes = []
	): void {
		echo self::create( $message, $type, $dismissible, $attributes );
	}

	/**
	 * Create a success notice
	 *
	 * @param string $message     The notice message
	 * @param bool   $dismissible Optional. Whether the notice is dismissible. Default false.
	 * @param array  $attributes  Optional. Additional HTML attributes. Default empty array.
	 *
	 * @return string HTML output for the notice
	 */
	public static function success( string $message, bool $dismissible = false, array $attributes = [] ): string {
		return self::create( $message, 'success', $dismissible, $attributes );
	}

	/**
	 * Create an info notice
	 *
	 * @param string $message     The notice message
	 * @param bool   $dismissible Optional. Whether the notice is dismissible. Default false.
	 * @param array  $attributes  Optional. Additional HTML attributes. Default empty array.
	 *
	 * @return string HTML output for the notice
	 */
	public static function info( string $message, bool $dismissible = false, array $attributes = [] ): string {
		return self::create( $message, 'info', $dismissible, $attributes );
	}

	/**
	 * Create a warning notice
	 *
	 * @param string $message     The notice message
	 * @param bool   $dismissible Optional. Whether the notice is dismissible. Default false.
	 * @param array  $attributes  Optional. Additional HTML attributes. Default empty array.
	 *
	 * @return string HTML output for the notice
	 */
	public static function warning( string $message, bool $dismissible = false, array $attributes = [] ): string {
		return self::create( $message, 'warning', $dismissible, $attributes );
	}

	/**
	 * Create an error notice
	 *
	 * @param string $message     The notice message
	 * @param bool   $dismissible Optional. Whether the notice is dismissible. Default false.
	 * @param array  $attributes  Optional. Additional HTML attributes. Default empty array.
	 *
	 * @return string HTML output for the notice
	 */
	public static function error( string $message, bool $dismissible = false, array $attributes = [] ): string {
		return self::create( $message, 'error', $dismissible, $attributes );
	}

}