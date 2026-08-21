<?php
/**
 * Authentication Trait
 *
 * Adapts arraypress/wp-s3-signer to this library's signing interface.
 *
 * @package     ArrayPress\S3\Traits
 * @copyright   Copyright (c) 2025, ArrayPress Limited
 * @license     GPL2+
 * @version     2.0.0
 * @author      David Sherlock
 */

declare( strict_types=1 );

namespace ArrayPress\S3\Traits\Signer;

use ArrayPress\S3Signer\AddressingStyle;
use ArrayPress\S3Signer\Method;
use ArrayPress\S3Signer\Signer as SigV4;

/**
 * Trait Authentication
 *
 * SigV4 itself now lives in arraypress/wp-s3-signer, which has its own test
 * suite and is shared with other products. This trait is the seam: it maps
 * this library's provider objects onto that signer and returns headers in the
 * shape the transport traits already expect.
 *
 * Keeping the signing rules in one package matters because the failure mode is
 * silent — a canonical request that does not describe the request actually
 * sent produces SignatureDoesNotMatch with no indication of which part is
 * wrong.
 */
trait Authentication {

	/**
	 * Memoised signer, keyed by addressing host.
	 *
	 * @var array<string, SigV4>
	 */
	private array $sigv4 = [];

	/**
	 * Get a signer configured for this provider.
	 *
	 * @return SigV4
	 */
	private function sigv4(): SigV4 {
		$endpoint = $this->provider->get_endpoint();

		if ( ! isset( $this->sigv4[ $endpoint ] ) ) {
			$this->sigv4[ $endpoint ] = new SigV4(
				$this->access_key,
				$this->secret_key,
				$endpoint,
				$this->provider->get_region(),
				null,
				$this->provider->uses_path_style()
					? AddressingStyle::Path
					: AddressingStyle::VirtualHosted
			);
		}

		return $this->sigv4[ $endpoint ];
	}

	/**
	 * Generate authorization headers for an S3 request
	 *
	 * @param string $method        HTTP method (GET, PUT, etc.)
	 * @param string $bucket        Bucket name
	 * @param string $object_key    Object key (if applicable)
	 * @param array  $query_params  Query parameters
	 * @param string $payload       Request payload (or empty string)
	 * @param array  $extra_headers Additional headers to include *in the
	 *                              signature*. S3 requires every x-amz-* header
	 *                              sent to appear in SignedHeaders, so anything
	 *                              like x-amz-copy-source belongs here rather
	 *                              than being bolted on afterwards.
	 *
	 * @return array Headers with AWS signature
	 */
	public function generate_auth_headers(
		string $method,
		string $bucket,
		string $object_key = '',
		array $query_params = [],
		string $payload = '',
		array $extra_headers = []
	): array {
		$query = array_map( 'strval', $query_params );

		$request = $this->sigv4()->sign(
			Method::from( strtoupper( $method ) ),
			$bucket,
			$object_key,
			$query,
			$payload,
			$extra_headers
		);

		$this->debug( 'Signed request', $request->url );

		// Ask for XML explicitly. Not signed, and not required to be: S3 only
		// verifies the headers named in SignedHeaders.
		return $request->headers + [ 'Accept' => 'application/xml' ];
	}

}
