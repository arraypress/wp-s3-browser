<?php
/**
 * Admin Screen Questions
 *
 * Whether the browser belongs on the page currently being rendered. Every
 * answer depends on the same two settings, which is why these all used to take
 * them as arguments.
 *
 * @package     ArrayPress\S3\Admin
 * @copyright   Copyright (c) 2025, ArrayPress Limited
 * @license     GPL2+
 * @author      David Sherlock
 */

declare( strict_types=1 );

namespace ArrayPress\S3\Admin;

/**
 * Class Screen
 */
class Screen {

	/*
	 * $_REQUEST['post_id'] is read to decide whether this browser belongs on
	 * the screen being rendered. It gates display only, and the media modal is
	 * the one thing that supplies it -- there is no other signal available
	 * inside the upload iframe.
	 *
	 * phpcs:disable WordPress.Security.NonceVerification.Recommended
	 */

	/**
	 * Admin pages that edit a single post.
	 */
	private const EDIT_HOOKS = [ 'post.php', 'post-new.php' ];

	/**
	 * Build the screen tests for one browser instance.
	 *
	 * @param Config $config Browser configuration.
	 */
	public function __construct( private Config $config ) {
	}

	/**
	 * The post currently being edited, if any.
	 *
	 * @return int Post id, or 0.
	 */
	public function current_post_id(): int {
		// The media modal passes the post it was opened from, which is the
		// only signal available inside the upload iframe.
		if ( isset( $_REQUEST['post_id'] ) ) {
			return (int) $_REQUEST['post_id'];
		}

		if ( is_admin() ) {
			global $post;

			if ( is_object( $post ) ) {
				return (int) $post->ID;
			}
		}

		return 0;
	}

	/**
	 * Whether a post type is one this browser appears for.
	 *
	 * @param string $post_type Post type to test.
	 *
	 * @return bool
	 */
	public function allows_post_type( string $post_type ): bool {
		return ! $this->config->allowed_post_types
			|| in_array( $post_type, $this->config->allowed_post_types, true );
	}

	/**
	 * Whether the post being edited is one this browser appears for.
	 *
	 * @return bool
	 */
	public function allows_current_post(): bool {
		$post_id = $this->current_post_id();

		if ( ! $post_id ) {
			// With no post to check, a restricted browser has no way to know
			// it belongs here, so it stays hidden. An unrestricted one has
			// nothing to check against and appears.
			return ! $this->config->allowed_post_types;
		}

		return $this->allows_post_type( (string) get_post_type( $post_id ) );
	}

	/**
	 * Whether this is the edit screen for a post type, and the user may use it.
	 *
	 * @param string $post_type Post type to test.
	 * @param string $hook      Current admin page hook suffix.
	 *
	 * @return bool
	 */
	public function is_editing( string $post_type, string $hook ): bool {
		return in_array( $hook, self::EDIT_HOOKS, true ) && $this->is_showing( $post_type );
	}

	/**
	 * Whether the screen being rendered is for a post type, and the user may
	 * use it.
	 *
	 * @param string $post_type Post type to test.
	 *
	 * @return bool
	 */
	public function is_showing( string $post_type ): bool {
		if ( ! $this->config->user_is_allowed() ) {
			return false;
		}

		$screen = get_current_screen();

		return $screen && $screen->post_type === $post_type && $this->allows_post_type( $post_type );
	}
}
