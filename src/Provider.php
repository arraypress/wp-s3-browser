<?php
/**
 * Storage Provider
 *
 * @package     ArrayPress\S3
 * @copyright   Copyright (c) 2025, ArrayPress Limited
 * @license     GPL2+
 * @version     2.0.0
 * @author      David Sherlock
 */

declare( strict_types=1 );

namespace ArrayPress\S3;

use ArrayPress\S3\Utils\Encode;
use ArrayPress\S3Signer\AddressingStyle;
use ArrayPress\S3Signer\Provider as ProviderType;

/**
 * Class Provider
 *
 * Identifies which storage account requests go to, and builds the URLs the
 * transport layer sends them to.
 *
 * This replaces an interface, an abstract base and nine concrete subclasses.
 * Once the unreachable code was removed, what remained of each subclass was
 * data — an id, a label, an endpoint pattern, an addressing style and a region
 * — and all of it already existed in arraypress/wp-s3-signer's Provider enum,
 * which is where signing needs it anyway. Two copies of that table could
 * disagree; one cannot.
 *
 * URL building stays here rather than in the signer, because it is transport's
 * concern: the signer produces signatures and presigned URLs, and never sends
 * anything.
 */
final class Provider {

	/**
	 * Which provider this is.
	 *
	 * @var ProviderType
	 */
	private ProviderType $type;

	/**
	 * Resolved region.
	 *
	 * @var string
	 */
	private string $region;

	/**
	 * Resolved endpoint host, without scheme.
	 *
	 * @var string
	 */
	private string $endpoint;

	/**
	 * Build a provider.
	 *
	 * @param ProviderType $type       Which provider.
	 * @param string       $region     Region. Empty uses the provider default.
	 * @param string       $account_id Account id — Cloudflare R2 only.
	 * @param string       $endpoint   Explicit host — MinIO and Other only.
	 *
	 * @throws \InvalidArgumentException When a required part is missing.
	 */
	public function __construct(
		ProviderType $type,
		string $region = '',
		string $account_id = '',
		string $endpoint = ''
	) {
		$this->type     = $type;
		$this->region   = '' !== trim( $region ) ? trim( $region ) : $type->default_region();
		$this->endpoint = $type->endpoint( $region, $account_id, $endpoint );
	}

	/**
	 * Cloudflare R2.
	 *
	 * @param string $account_id Cloudflare account id.
	 *
	 * @return self
	 */
	public static function r2( string $account_id ): self {
		return new self( ProviderType::R2, '', $account_id );
	}

	/**
	 * Amazon S3.
	 *
	 * @param string $region AWS region.
	 *
	 * @return self
	 */
	public static function aws( string $region = '' ): self {
		return new self( ProviderType::Aws, $region );
	}

	/**
	 * Any provider identified only by region.
	 *
	 * @param ProviderType $type   Which provider.
	 * @param string       $region Region.
	 *
	 * @return self
	 */
	public static function regional( ProviderType $type, string $region = '' ): self {
		return new self( $type, $region );
	}

	/**
	 * MinIO, Ceph, or any other S3-compatible endpoint.
	 *
	 * @param string $endpoint Host, optionally with a port.
	 * @param string $region   Region label.
	 *
	 * @return self
	 */
	public static function custom( string $endpoint, string $region = '' ): self {
		return new self( ProviderType::Other, $region, '', $endpoint );
	}

	/**
	 * Provider identifier, e.g. 'r2'.
	 *
	 * @return string
	 */
	public function get_id(): string {
		return $this->type->value;
	}

	/**
	 * Human label, for admin output.
	 *
	 * @return string
	 */
	public function get_label(): string {
		return $this->type->label();
	}

	/**
	 * Region this provider signs with.
	 *
	 * @return string
	 */
	public function get_region(): string {
		return $this->region;
	}

	/**
	 * Endpoint host, without scheme.
	 *
	 * @return string
	 */
	public function get_endpoint(): string {
		return $this->endpoint;
	}

	/**
	 * Whether the bucket goes in the path rather than the host.
	 *
	 * @return bool
	 */
	public function uses_path_style(): bool {
		return AddressingStyle::Path === $this->type->addressing();
	}

	/**
	 * Build a request URL for a bucket and optional object.
	 *
	 * The object key is encoded here; the signature is computed over the same
	 * encoding, so the two cannot drift.
	 *
	 * @param string $bucket Bucket name.
	 * @param string $object Optional object key.
	 *
	 * @return string
	 */
	public function format_url( string $bucket, string $object = '' ): string {
		return $this->build_url_with_encoded_key(
			$bucket,
			'' === $object ? '' : Encode::object_key( $object )
		);
	}

	/**
	 * Build a request URL from an already-encoded object key.
	 *
	 * @param string $bucket      Bucket name.
	 * @param string $encoded_key Encoded object key, or '' for the bucket itself.
	 *
	 * @return string
	 */
	public function build_url_with_encoded_key( string $bucket, string $encoded_key ): string {
		$host = $this->uses_path_style()
			? $this->endpoint . '/' . rawurlencode( $bucket )
			: $bucket . '.' . $this->endpoint;

		return 'https://' . $host . ( '' === $encoded_key ? '' : '/' . $encoded_key );
	}

	/**
	 * Build a request URL with query parameters.
	 *
	 * PHP_QUERY_RFC3986 is required, not cosmetic: the signature is computed
	 * over RFC 3986 encoding, and http_build_query()'s default renders a space
	 * as '+' and a tilde as '%7E'. A prefix or continuation token containing
	 * either would be signed one way and sent another.
	 *
	 * @param string $bucket       Bucket name, or '' for service-level requests.
	 * @param string $object       Optional object key.
	 * @param array  $query_params Query parameters.
	 *
	 * @return string
	 */
	public function build_url_with_query( string $bucket = '', string $object = '', array $query_params = [] ): string {
		$url = '' === $bucket
			? 'https://' . $this->endpoint
			: $this->format_url( $bucket, $object );

		if ( ! empty( $query_params ) ) {
			$url .= '?' . http_build_query( $query_params, '', '&', PHP_QUERY_RFC3986 );
		}

		return $url;
	}
}
