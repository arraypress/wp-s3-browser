<?php
/**
 * Browser AJAX Handlers Trait - Complete Fixed Version
 *
 * Handles AJAX operations for the S3 Browser including proper character handling
 * and comprehensive error management for all S3 operations.
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
 * Trait AjaxHandlers
 *
 * Provides AJAX endpoint handlers for S3 Browser operations including file management,
 * folder operations, uploads, and cache management. All handlers include proper
 * character encoding handling to prevent issues with special characters like apostrophes.
 */
trait AjaxHandlers {
	use Ajax\Helpers;
	use Ajax\File;
	use Ajax\Folder;
	use Ajax\Upload;
	use Ajax\Bucket;
	use Ajax\System;
	use Ajax\Connection;
}