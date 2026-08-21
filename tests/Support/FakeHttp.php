<?php
/**
 * Canned HTTP responses for the transport layer.
 */

declare( strict_types=1 );

namespace ArrayPress\S3\Tests\Support;

/**
 * Class FakeHttp
 *
 * Stands in for the WordPress HTTP API so a request can be driven all the way
 * through Client, Api, the XML parsers and the response objects against a real
 * provider payload, with no network.
 *
 * This is the layer that had no tests, and it is where the defects were: an
 * error parser that never matched a real S3 error document, a scoped-token 403
 * reported as bad credentials, query strings signed one way and sent another.
 * All of those are visible here and nowhere else.
 */
final class FakeHttp {

	/** @var array<int, array{status:int, body:string, headers:array}> */
	private static array $queue = [];

	/** @var array<int, array{url:string, args:array}> */
	private static array $requests = [];

	/**
	 * Queue the next response.
	 *
	 * @param int    $status  HTTP status.
	 * @param string $body    Response body.
	 * @param array  $headers Response headers.
	 *
	 * @return void
	 */
	public static function queue( int $status, string $body = '', array $headers = [] ): void {
		self::$queue[] = [ 'status' => $status, 'body' => $body, 'headers' => $headers ];
	}

	/**
	 * Queue a response body loaded from tests/fixtures.
	 *
	 * @param string $name   Fixture filename.
	 * @param int    $status HTTP status.
	 *
	 * @return void
	 */
	public static function queue_fixture( string $name, int $status = 200 ): void {
		self::queue( $status, file_get_contents( __DIR__ . '/../fixtures/' . $name ) );
	}

	/**
	 * Take the next queued response, or a 200 with an empty body.
	 *
	 * @param string $url  Requested URL.
	 * @param array  $args Request arguments.
	 *
	 * @return array WordPress-shaped response array.
	 */
	public static function respond( string $url, array $args ): array {
		self::$requests[] = [ 'url' => $url, 'args' => $args ];

		$next = array_shift( self::$queue ) ?? [ 'status' => 200, 'body' => '', 'headers' => [] ];

		return [
			'response' => [ 'code' => $next['status'], 'message' => '' ],
			'body'     => $next['body'],
			'headers'  => $next['headers'],
		];
	}

	/**
	 * Every request made since the last reset.
	 *
	 * @return array<int, array{url:string, args:array}>
	 */
	public static function requests(): array {
		return self::$requests;
	}

	/**
	 * The most recent request.
	 *
	 * @return array{url:string, args:array}|null
	 */
	public static function last(): ?array {
		return self::$requests ? self::$requests[ count( self::$requests ) - 1 ] : null;
	}

	/**
	 * Clear queued responses and recorded requests.
	 *
	 * @return void
	 */
	public static function reset(): void {
		self::$queue    = [];
		self::$requests = [];
	}
}
