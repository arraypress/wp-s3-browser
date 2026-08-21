<?php
/**
 * Current Origin
 *
 * @package     ArrayPress\S3\Cors
 * @copyright   Copyright (c) 2025, ArrayPress Limited
 * @license     GPL2+
 * @author      David Sherlock
 */

declare( strict_types=1 );

namespace ArrayPress\S3\Cors;

/**
 * Class Origin
 */
class Origin {

	/**
	 * The origin this site is served from, as CORS spells it.
	 *
	 * Read from the site's configured URL rather than from the request.
	 * $_SERVER['HTTP_HOST'] is the Host header, which the client supplies and
	 * can set to anything, and this value does not merely get displayed: it is
	 * written into the bucket's CORS configuration as an allowed origin, and
	 * stays there. A forged Host header on the CORS-setup request would
	 * persist someone else's origin as one the bucket accepts browser uploads
	 * from.
	 *
	 * home_url() comes from the database, so it is whatever the site owner
	 * configured and nothing a request can influence.
	 *
	 * @return string Scheme, host and port -- no path, no trailing slash.
	 */
	public static function current(): string {
		$parts = wp_parse_url( home_url() );

		if ( empty( $parts['host'] ) ) {
			return '';
		}

		$scheme = $parts['scheme'] ?? ( is_ssl() ? 'https' : 'http' );
		$origin = $scheme . '://' . $parts['host'];

		// A non-default port is part of the origin: https://example.com and
		// https://example.com:8443 are distinct to a browser, and a rule
		// naming one does not cover the other.
		if ( ! empty( $parts['port'] ) ) {
			$origin .= ':' . $parts['port'];
		}

		return $origin;
	}

}
