<?php
///**
// * WordPress Breadcrumb Generator
// *
// * @package     ArrayPress\WP\Components
// * @copyright   Copyright (c) 2024, ArrayPress Limited
// * @license     GPL2+
// * @version     1.0.0
// * @author      ArrayPress
// */
//
//declare( strict_types=1 );
//
//namespace ArrayPress\S3\Components;
//
///**
// * Class Breadcrumb
// *
// * Simple utility for generating breadcrumb navigation with WordPress dashicons.
// */
//class Breadcrumb {
//
//	/**
//	 * Breadcrumb separator
//	 *
//	 * @var string
//	 */
//	private string $separator = '›';
//
//	/**
//	 * CSS classes for the breadcrumb container
//	 *
//	 * @var array
//	 */
//	private array $container_classes = [];
//
//	/**
//	 * Links in the breadcrumb
//	 *
//	 * @var array
//	 */
//	private array $links = [];
//
//	/**
//	 * Current item (non-linked)
//	 *
//	 * @var array|null
//	 */
//	private ?array $current = null;
//
//	/**
//	 * Constructor
//	 *
//	 * @param string $separator Optional. Separator between breadcrumb items. Default '›'.
//	 * @param array  $classes   Optional. CSS classes for container. Default empty array.
//	 */
//	public function __construct( string $separator = '›', array $classes = [] ) {
//		$this->separator         = $separator;
//		$this->container_classes = $classes;
//	}
//
//	/**
//	 * Add a link to the breadcrumb
//	 *
//	 * @param string      $url     The URL for the link
//	 * @param string      $label   The link text
//	 * @param string|null $icon    Optional. Dashicon class (without 'dashicons-' prefix). Default null.
//	 * @param array       $classes Optional. CSS classes for the link. Default empty array.
//	 *
//	 * @return self
//	 */
//	public function add_link( string $url, string $label, ?string $icon = null, array $classes = [] ): self {
//		$this->links[] = [
//			'url'     => $url,
//			'label'   => $label,
//			'icon'    => $icon,
//			'classes' => $classes,
//		];
//
//		return $this;
//	}
//
//	/**
//	 * Set the current item (non-linked)
//	 *
//	 * @param string      $label   The current item text
//	 * @param string|null $icon    Optional. Dashicon class (without 'dashicons-' prefix). Default null.
//	 * @param array       $classes Optional. CSS classes for the current item. Default empty array.
//	 *
//	 * @return self
//	 */
//	public function set_current( string $label, ?string $icon = null, array $classes = [] ): self {
//		$this->current = [
//			'label'   => $label,
//			'icon'    => $icon,
//			'classes' => $classes,
//		];
//
//		return $this;
//	}
//
//	/**
//	 * Generate the breadcrumb HTML
//	 *
//	 * @return string HTML output for the breadcrumb
//	 */
//	public function render(): string {
//		if ( empty( $this->links ) && ! $this->current ) {
//			return '';
//		}
//
//		$classes = array_merge( [ 'breadcrumb-container' ], $this->container_classes );
//		$output  = '<div class="' . esc_attr( implode( ' ', $classes ) ) . '">';
//
//		// Render links
//		foreach ( $this->links as $index => $link ) {
//			if ( $index > 0 ) {
//				$output .= '<span class="breadcrumb-separator">' . esc_html( $this->separator ) . '</span>';
//			}
//
//			$link_classes = array_merge( [ 'breadcrumb-link' ], $link['classes'] );
//			$output       .= '<a href="' . esc_url( $link['url'] ) . '" class="' . esc_attr( implode( ' ', $link_classes ) ) . '">';
//
//			if ( $link['icon'] ) {
//				$output .= '<span class="dashicons dashicons-' . esc_attr( $link['icon'] ) . '"></span>';
//			}
//
//			$output .= esc_html( $link['label'] );
//			$output .= '</a>';
//		}
//
//		// Render current item
//		if ( $this->current ) {
//			if ( ! empty( $this->links ) ) {
//				$output .= '<span class="breadcrumb-separator">' . esc_html( $this->separator ) . '</span>';
//			}
//
//			$current_classes = array_merge( [ 'breadcrumb-current' ], $this->current['classes'] );
//			$output          .= '<span class="' . esc_attr( implode( ' ', $current_classes ) ) . '">';
//
//			if ( $this->current['icon'] ) {
//				$output .= '<span class="dashicons dashicons-' . esc_attr( $this->current['icon'] ) . '"></span>';
//			}
//
//			$output .= esc_html( $this->current['label'] );
//			$output .= '</span>';
//		}
//
//		$output .= '</div>';
//
//		return $output;
//	}
//
//	/**
//	 * Echo the breadcrumb HTML
//	 *
//	 * @return void
//	 */
//	public function echo(): void {
//		echo $this->render();
//	}
//
//	/**
//	 * Create a breadcrumb from a path string
//	 *
//	 * @param string      $base_url   Base URL for the breadcrumb links
//	 * @param string      $base_label Label for the base URL
//	 * @param string|null $base_icon  Optional. Dashicon for the base. Default null.
//	 * @param string      $path       Forward slash separated path string
//	 * @param string      $separator  Optional. Separator between items. Default '›'.
//	 * @param array       $classes    Optional. CSS classes for container. Default empty array.
//	 *
//	 * @return self
//	 */
//	public static function from_path(
//		string $base_url,
//		string $base_label,
//		?string $base_icon = null,
//		string $path = '',
//		string $separator = '›',
//		array $classes = []
//	): self {
//		$breadcrumb = new self( $separator, $classes );
//
//		// Add base link
//		$breadcrumb->add_link( $base_url, $base_label, $base_icon );
//
//		// Add path segments
//		if ( ! empty( $path ) ) {
//			$parts        = explode( '/', trim( $path, '/' ) );
//			$current_path = '';
//
//			foreach ( $parts as $i => $part ) {
//				if ( empty( $part ) ) {
//					continue;
//				}
//
//				$current_path .= $part . '/';
//				$url          = add_query_arg( 'path', rtrim( $current_path, '/' ), $base_url );
//
//				// Last part is current, others are links
//				if ( $i === count( $parts ) - 1 ) {
//					$breadcrumb->set_current( $part, 'category' );
//				} else {
//					$breadcrumb->add_link( $url, $part, 'category' );
//				}
//			}
//		}
//
//		return $breadcrumb;
//	}
//
//	/**
//	 * Add a back button or link
//	 *
//	 * @param string      $url     The URL for the back link
//	 * @param string      $label   Optional. Label for the back button. Default 'Back'.
//	 * @param string|null $icon    Optional. Dashicon class. Default 'arrow-left-alt'.
//	 * @param array       $classes Optional. CSS classes. Default empty array.
//	 *
//	 * @return string HTML for the back button
//	 */
//	public static function back_button(
//		string $url,
//		string $label = 'Back',
//		?string $icon = 'arrow-left-alt',
//		array $classes = []
//	): string {
//		$button_classes = array_merge( [ 'button' ], $classes );
//		$output         = '<div class="breadcrumb-back-button">';
//		$output         .= '<a href="' . esc_url( $url ) . '" class="' . esc_attr( implode( ' ', $button_classes ) ) . '">';
//
//		if ( $icon ) {
//			$output .= '<span class="dashicons dashicons-' . esc_attr( $icon ) . '" style="margin-top: 3px;"></span> ';
//		}
//
//		$output .= esc_html( $label );
//		$output .= '</a>';
//		$output .= '</div>';
//
//		return $output;
//	}
//
//}