<?php
declare( strict_types=1 );

namespace ArrayPress\S3\Tests\Responses;

use ArrayPress\S3\Responses\ErrorResponse;
use PHPUnit\Framework\TestCase;

/**
 * Relaying a provider error over REST.
 *
 * The REST layer used to invent a code and force 502 for every upstream
 * failure, which is how "this token cannot list buckets" -- a working,
 * recommended R2 setup -- became indistinguishable from wrong credentials.
 */
final class ErrorResponseTest extends TestCase {

	public function test_provider_code_survives_the_conversion(): void {
		$error = ( new ErrorResponse( 'Access Denied', 'AccessDenied', 403 ) )->to_wp_error();

		$this->assertSame( 'AccessDenied', $error->get_error_code() );
		$this->assertSame( 'Access Denied', $error->get_error_message() );
	}

	/**
	 * A status the browser can act on is worth relaying: 404 says the bucket
	 * or key is gone, 403 that the token lacks the permission.
	 */
	public function test_actionable_statuses_are_relayed(): void {
		foreach ( [ 400, 403, 404, 409, 429 ] as $status ) {
			$error = ( new ErrorResponse( 'x', 'SomeCode', $status ) )->to_wp_error();

			$this->assertSame( $status, $error->get_error_data()['status'] );
		}
	}

	/**
	 * Anything else is a fault upstream of WordPress, not in the request that
	 * arrived here.
	 */
	public function test_other_statuses_become_bad_gateway(): void {
		foreach ( [ 0, 200, 500, 503 ] as $status ) {
			$error = ( new ErrorResponse( 'x', 'SomeCode', $status ) )->to_wp_error();

			$this->assertSame( 502, $error->get_error_data()['status'] );
		}
	}

	/**
	 * 401 is WordPress's own signal that the *user* is unauthenticated.
	 * Returning it for a storage credential problem sends the admin to a login
	 * screen that cannot help.
	 */
	public function test_unauthorized_is_not_relayed_as_unauthorized(): void {
		$error = ( new ErrorResponse( 'x', 'InvalidAccessKeyId', 401 ) )->to_wp_error();

		$this->assertSame( 502, $error->get_error_data()['status'] );
		$this->assertSame( 'InvalidAccessKeyId', $error->get_error_code() );
	}

	public function test_error_data_is_carried_through(): void {
		$error = ( new ErrorResponse( 'x', 'NoSuchBucket', 404, [ 'bucket' => 'gone' ] ) )->to_wp_error();

		$data = $error->get_error_data();

		$this->assertSame( 'gone', $data['bucket'] );
		$this->assertSame( 404, $data['status'] );
	}

	/**
	 * A provider that sends its own 'status' field must not be able to
	 * overwrite the HTTP status WordPress will use.
	 */
	public function test_provider_data_cannot_override_the_status(): void {
		$error = ( new ErrorResponse( 'x', 'SomeCode', 404, [ 'status' => 200 ] ) )->to_wp_error();

		$this->assertSame( 404, $error->get_error_data()['status'] );
	}

	public function test_round_trip_through_wp_error_preserves_the_code(): void {
		$original = new ErrorResponse( 'Access Denied', 'AccessDenied', 403 );
		$restored = ErrorResponse::from_wp_error( $original->to_wp_error(), 403 );

		$this->assertSame( 'AccessDenied', $restored->get_error_code() );
		$this->assertSame( 'Access Denied', $restored->get_error_message() );
	}
}
