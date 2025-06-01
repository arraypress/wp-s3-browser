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

namespace ArrayPress\S3\Traits\Common;

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
	 * Set custom user agent for HTTP requests
	 *
	 * @param string $user_agent User agent string
	 *
	 * @return self
	 */
	public function set_user_agent( string $user_agent ): self {
		$this->user_agent = $user_agent;

		return $this;
	}

	/**
	 * Get current user agent
	 *
	 * @return string Current user agent
	 */
	public function get_user_agent(): string {
		return $this->user_agent;
	}

}