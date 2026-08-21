<?php
/**
 * XML request bodies.
 *
 * @package     ArrayPress\S3\Xml
 * @copyright   Copyright (c) 2025, ArrayPress Limited
 * @license     GPL2+
 * @version     3.1.0
 * @author      David Sherlock
 */

declare( strict_types=1 );

namespace ArrayPress\S3\Xml;

/**
 * Class Builder
 *
 * Builds the XML bodies S3 expects for the handful of operations that take
 * one. These were a trait composed into the API client, but neither method
 * touches any state: given the same array they return the same string. As a
 * trait they could only be exercised by constructing a client and issuing a
 * request; as static functions they can be tested directly, which matters for
 * output that has to match a provider's parser exactly.
 */
final class Builder {

	/**
	 * Build CORS configuration XML from rules array
	 *
	 * Creates S3-compatible CORS configuration XML from an array of CORS rules.
	 *
	 * @param array $cors_rules Array of CORS rules
	 *
	 * @return string XML string for CORS configuration
	 */
	public static function cors_configuration( array $cors_rules ): string {
		$xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
		$xml .= '<CORSConfiguration xmlns="http://s3.amazonaws.com/doc/2006-03-01/">' . "\n";

		foreach ( $cors_rules as $rule ) {
			$xml .= '  <CORSRule>' . "\n";

			// ID (optional)
			if ( ! empty( $rule['ID'] ) ) {
				$xml .= '    <ID>' . htmlspecialchars( $rule['ID'], ENT_XML1 | ENT_COMPAT, 'UTF-8' ) . '</ID>' . "\n";
			}

			// AllowedMethods (required)
			if ( ! empty( $rule['AllowedMethods'] ) ) {
				foreach ( $rule['AllowedMethods'] as $method ) {
					$xml .= '    <AllowedMethod>' . htmlspecialchars( $method, ENT_XML1 | ENT_COMPAT, 'UTF-8' ) . '</AllowedMethod>' . "\n";
				}
			}

			// AllowedOrigins (required)
			if ( ! empty( $rule['AllowedOrigins'] ) ) {
				foreach ( $rule['AllowedOrigins'] as $origin ) {
					$xml .= '    <AllowedOrigin>' . htmlspecialchars( $origin, ENT_XML1 | ENT_COMPAT, 'UTF-8' ) . '</AllowedOrigin>' . "\n";
				}
			}

			// AllowedHeaders (optional)
			if ( ! empty( $rule['AllowedHeaders'] ) ) {
				foreach ( $rule['AllowedHeaders'] as $header ) {
					$xml .= '    <AllowedHeader>' . htmlspecialchars( $header, ENT_XML1 | ENT_COMPAT, 'UTF-8' ) . '</AllowedHeader>' . "\n";
				}
			}

			// ExposeHeaders (optional)
			if ( ! empty( $rule['ExposeHeaders'] ) ) {
				foreach ( $rule['ExposeHeaders'] as $header ) {
					$xml .= '    <ExposeHeader>' . htmlspecialchars( $header, ENT_XML1 | ENT_COMPAT, 'UTF-8' ) . '</ExposeHeader>' . "\n";
				}
			}

			// MaxAgeSeconds (optional)
			if ( isset( $rule['MaxAgeSeconds'] ) && $rule['MaxAgeSeconds'] > 0 ) {
				$xml .= '    <MaxAgeSeconds>' . (int) $rule['MaxAgeSeconds'] . '</MaxAgeSeconds>' . "\n";
			}

			$xml .= '  </CORSRule>' . "\n";
		}

		$xml .= '</CORSConfiguration>';

		return $xml;
	}

	/**
	 * Build XML for batch delete request with R2 compatibility
	 *
	 * @param array $object_keys Array of object keys
	 *
	 * @return string XML string
	 */
	public static function batch_delete( array $object_keys ): string {
		$xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";

		// Try without namespace first for R2 compatibility
		$xml .= '<Delete>' . "\n";
		$xml .= '  <Quiet>false</Quiet>' . "\n";

		foreach ( $object_keys as $key ) {
			$clean_key = rawurldecode( $key ); // Decode first to avoid double encoding
			$xml       .= '  <Object>' . "\n";
			$xml       .= '    <Key>' . htmlspecialchars( $clean_key, ENT_XML1 | ENT_COMPAT, 'UTF-8' ) . '</Key>' . "\n";
			$xml       .= '  </Object>' . "\n";
		}

		$xml .= '</Delete>';

		return $xml;
	}
}
