<?php
/**
 * CORS Analysis
 *
 * Reads a bucket's CORS rules and reports what they permit. Pure inspection
 * of the rule arrays -- no requests, no state.
 *
 * @package     ArrayPress\S3\Cors
 * @copyright   Copyright (c) 2025, ArrayPress Limited
 * @license     GPL2+
 * @author      David Sherlock
 */

declare( strict_types=1 );

namespace ArrayPress\S3\Cors;

/**
 * Class Analysis
 */
class Analysis {

	/**
	 * Methods a browser uses to upload an object.
	 */
	private const UPLOAD_METHODS = [ 'PUT', 'POST' ];

	/**
	 * Methods that change the bucket, and so are worth warning about when
	 * any origin may call them.
	 */
	private const WRITE_METHODS = [ 'PUT', 'POST', 'DELETE' ];

	/**
	 * A preflight cached beyond this is slow to correct after a rule change.
	 */
	private const LONG_CACHE = 86400;

	/**
	 * Describe what a rule set permits.
	 *
	 * @param array $rules CORS rules.
	 *
	 * @return array Analysis.
	 */
	public static function describe( array $rules ): array {
		$analysis = [
			'has_cors'             => (bool) $rules,
			'rules_count'          => count( $rules ),
			'supports_public_read' => false,
			'supports_upload'      => false,
			'supports_delete'      => false,
			'allows_all_origins'   => false,
			'max_cache_time'       => 0,
			'capabilities'         => [],
			'security_warnings'    => [],
		];

		foreach ( $rules as $rule ) {
			$methods = $rule['AllowedMethods'] ?? [];
			$origins = $rule['AllowedOrigins'] ?? [];
			$headers = $rule['AllowedHeaders'] ?? [];

			if ( in_array( 'GET', $methods, true ) ) {
				$analysis['supports_public_read'] = true;
				$analysis['capabilities'][]       = 'read';
			}

			if ( array_intersect( self::UPLOAD_METHODS, $methods ) ) {
				$analysis['supports_upload'] = true;
				$analysis['capabilities'][]  = 'upload';
			}

			if ( in_array( 'DELETE', $methods, true ) ) {
				$analysis['supports_delete'] = true;
				$analysis['capabilities'][]  = 'delete';
			}

			if ( in_array( '*', $origins, true ) ) {
				$analysis['allows_all_origins'] = true;

				if ( array_intersect( self::WRITE_METHODS, $methods ) ) {
					$analysis['security_warnings'][] = __( 'Allows write operations from any origin (*)', 'arraypress' );
				}
			}

			if ( in_array( '*', $headers, true ) ) {
				$analysis['security_warnings'][] = __( 'Allows all headers (*)', 'arraypress' );
			}

			$analysis['max_cache_time'] = max( $analysis['max_cache_time'], (int) ( $rule['MaxAgeSeconds'] ?? 0 ) );
		}

		$analysis['capabilities']      = array_values( array_unique( $analysis['capabilities'] ) );
		$analysis['security_warnings'] = array_values( array_unique( $analysis['security_warnings'] ) );
		$analysis['recommendations']   = self::recommend( $analysis );

		return $analysis;
	}

	/**
	 * Check whether a rule set permits uploads from an origin.
	 *
	 * @param array  $rules  CORS rules.
	 * @param string $origin Origin to test.
	 *
	 * @return array Whether uploads are allowed, and the rules that say so.
	 */
	public static function allows_upload( array $rules, string $origin = '*' ): array {
		$methods  = [];
		$matching = [];

		foreach ( $rules as $rule ) {
			$origins = $rule['AllowedOrigins'] ?? [];

			// A wildcard rule covers this origin; otherwise it has to be
			// named. S3 does not do subdomain matching.
			if ( ! in_array( '*', $origins, true ) && ! in_array( $origin, $origins, true ) ) {
				continue;
			}

			$allowed = array_intersect( self::UPLOAD_METHODS, $rule['AllowedMethods'] ?? [] );

			if ( ! $allowed ) {
				continue;
			}

			$methods    = array_merge( $methods, $allowed );
			$matching[] = $rule;
		}

		return [
			'origin'          => $origin,
			'allows_upload'   => (bool) $matching,
			'allowed_methods' => array_values( array_unique( $methods ) ),
			'matching_rules'  => $matching,
			'rules_checked'   => count( $rules ),
		];
	}

	/**
	 * List every origin any rule allows.
	 *
	 * @param array $rules CORS rules.
	 *
	 * @return array Origins.
	 */
	public static function origins( array $rules ): array {
		return self::collect( $rules, 'AllowedOrigins' );
	}

	/**
	 * List every method any rule allows.
	 *
	 * @param array $rules CORS rules.
	 *
	 * @return array Methods.
	 */
	public static function methods( array $rules ): array {
		return self::collect( $rules, 'AllowedMethods' );
	}

	/**
	 * Gather one field across every rule.
	 *
	 * @param array  $rules CORS rules.
	 * @param string $field Field to collect.
	 *
	 * @return array Unique values.
	 */
	private static function collect( array $rules, string $field ): array {
		$values = [];

		foreach ( $rules as $rule ) {
			$values = array_merge( $values, $rule[ $field ] ?? [] );
		}

		return array_values( array_unique( $values ) );
	}

	/**
	 * Suggest changes based on an analysis.
	 *
	 * @param array $analysis Output of describe().
	 *
	 * @return array Recommendations.
	 */
	private static function recommend( array $analysis ): array {
		$recommendations = [];

		if ( $analysis['allows_all_origins'] && $analysis['supports_upload'] ) {
			$recommendations[] = __( 'Consider naming the origins allowed to upload instead of using "*".', 'arraypress' );
		}

		if ( $analysis['allows_all_origins'] && $analysis['supports_delete'] ) {
			$recommendations[] = __( 'Allowing DELETE from any origin lets any site remove your objects.', 'arraypress' );
		}

		if ( $analysis['max_cache_time'] > self::LONG_CACHE ) {
			$recommendations[] = __( 'A long preflight cache delays how quickly a CORS change takes effect.', 'arraypress' );
		}

		if ( ! $analysis['has_cors'] ) {
			$recommendations[] = __( 'Add a CORS configuration if this bucket is read or written from a browser.', 'arraypress' );
		}

		if ( $analysis['has_cors'] && ! $analysis['security_warnings'] ) {
			$recommendations[] = __( 'This CORS configuration looks sound.', 'arraypress' );
		}

		return $recommendations;
	}

}
