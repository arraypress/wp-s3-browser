<?php
/**
 * Browser REST API Trait
 *
 * Aggregates the REST route registration and handlers, mirroring how
 * AjaxHandlers composes the legacy admin-ajax endpoints.
 *
 * @package     ArrayPress\S3\Traits
 * @copyright   Copyright (c) 2025, ArrayPress Limited
 * @license     GPL2+
 * @version     1.0.0
 * @author      David Sherlock
 */

declare( strict_types=1 );

namespace ArrayPress\S3\Traits\Browser;

/**
 * Trait RestApi
 */
trait RestApi {
	use Rest\Routes;
	use Rest\Handlers;
}
