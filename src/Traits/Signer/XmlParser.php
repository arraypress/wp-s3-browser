<?php
/**
 * XML Parser Trait - Main Composition
 *
 * Combines all XML parsing functionality into a single trait for convenience.
 *
 * @package     ArrayPress\S3\Traits\Signer
 * @copyright   Copyright (c) 2025, ArrayPress Limited
 * @license     GPL2+
 * @version     1.0.0
 * @author      David Sherlock
 */

declare( strict_types=1 );

namespace ArrayPress\S3\Traits\Signer;

use ArrayPress\S3\Traits\XML\{Core, Extract, Parser, Pagination, Builder, ErrorHandler};

/**
 * Trait XmlParser
 */
trait XmlParser {
	use Core, Extract, Parser, Pagination, Builder, ErrorHandler;
}