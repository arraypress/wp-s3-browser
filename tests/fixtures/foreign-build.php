<?php
/**
 * A response class as it would exist in another plugin's Strauss-prefixed copy
 * of this library: same relative namespace, different root.
 */

declare( strict_types=1 );

namespace OtherPlugin\ArrayPress\S3\Responses;

class ObjectsResponse {

	public function is_successful(): bool {
		return true;
	}
}
