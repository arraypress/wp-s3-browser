<?php
/**
 * Transport Errors
 *
 * Turns the failures that happen before a request is ever answered -- DNS, TLS,
 * a refused connection -- into something an admin can act on.
 *
 * These arrive as a WP_Error carrying whatever cURL said, and cURL says things
 * like "error:1404B410:SSL routines:ST_CONNECT:sslv3 alert handshake failure".
 * That is a fact about a TLS record layer, not about the store, and it was
 * being shown verbatim in the connection test and the file browser alike.
 *
 * Nothing is discarded: the original text is kept on the response, since it is
 * what makes a genuine network fault diagnosable.
 *
 * @package     ArrayPress\S3
 * @copyright   Copyright (c) 2025, ArrayPress Limited
 * @license     GPL2+
 * @author      David Sherlock
 */

declare( strict_types=1 );

namespace ArrayPress\S3\Utils;

/**
 * Class Transport
 */
class Transport {

	/**
	 * Whether a failure happened below the S3 protocol.
	 *
	 * WordPress reports every cURL failure under one code, so the code alone
	 * says a request never completed -- which is exactly the distinction worth
	 * drawing, because no S3 error can exist yet.
	 *
	 * @param string $code Error code from the WP_Error.
	 *
	 * @return bool
	 */
	public static function is_transport_error( string $code ): bool {
		return in_array( $code, [ 'http_request_failed', 'connect_error' ], true );
	}

	/**
	 * Describe a transport failure in terms of the thing that failed.
	 *
	 * @param string $message  Original error text.
	 * @param string $endpoint Endpoint that was being reached, if known.
	 *
	 * @return string
	 */
	public static function explain( string $message, string $endpoint = '' ): string {
		$host = '' === $endpoint ? '' : (string) wp_parse_url( $endpoint, PHP_URL_HOST );
		$name = '' === $host ? __( 'the storage endpoint', 'arraypress' ) : $host;

		// A wrong account id is the usual cause: on providers that put it in
		// the hostname, it produces an address that exists but cannot present
		// a certificate for the name being asked for.
		if ( self::mentions( $message, [ 'sslv3 alert', 'handshake', 'SSL routines', 'SSL connect error', 'certificate', 'CERTIFICATE_VERIFY' ] ) ) {
			return sprintf(
				/* translators: %s: hostname of the storage endpoint */
				__( 'Could not open a secure connection to %s. The address answered but not as this provider\'s endpoint, which usually means the Account ID or endpoint setting is wrong.', 'arraypress' ),
				$name
			);
		}

		if ( self::mentions( $message, [ 'Could not resolve host', 'name or service not known', 'nodename nor servname', 'Temporary failure in name resolution' ] ) ) {
			return sprintf(
				/* translators: %s: hostname of the storage endpoint */
				__( 'There is no server at %s. Check the Account ID or endpoint setting for a typo.', 'arraypress' ),
				$name
			);
		}

		if ( self::mentions( $message, [ 'timed out', 'Connection refused', 'Failed to connect', 'Empty reply', 'Connection reset' ] ) ) {
			return sprintf(
				/* translators: %s: hostname of the storage endpoint */
				__( '%s did not respond. It may be temporarily unavailable, or blocked by this server\'s outbound firewall.', 'arraypress' ),
				$name
			);
		}

		return sprintf(
			/* translators: %s: hostname of the storage endpoint */
			__( 'Could not reach %s.', 'arraypress' ),
			$name
		);
	}

	/**
	 * Whether any of the needles appear in the message.
	 *
	 * @param string   $message Message.
	 * @param string[] $needles Needles.
	 *
	 * @return bool
	 */
	private static function mentions( string $message, array $needles ): bool {
		foreach ( $needles as $needle ) {
			if ( false !== stripos( $message, $needle ) ) {
				return true;
			}
		}

		return false;
	}
}
