<?php
/**
 * Stored Path
 *
 * Comparison of file paths as the host plugins keep them: "bucket/key",
 * optionally with an s3:// prefix, and tolerant of stray slashes at either
 * end.
 *
 * Splitting a path into bucket and object is Utils\Parse's job, not this
 * one's -- it already rejects URLs and shortcodes, which is the difference
 * between a bucket and a local file that merely has a slash in it.
 *
 * @package     ArrayPress\S3
 * @copyright   Copyright (c) 2025, ArrayPress Limited
 * @license     GPL2+
 * @author      David Sherlock
 */

declare( strict_types=1 );

namespace ArrayPress\S3\Cli;

/**
 * Class Path
 */
class Path {

	/**
	 * Reduce a path to the form two references can be compared in.
	 *
	 * Matches how the host plugins recognise their own stored paths, so
	 * "s3://bucket/a.zip" and "bucket/a.zip" are one file, not two.
	 *
	 * @param string $path Stored or incoming path.
	 *
	 * @return string
	 */
	public static function normalize( string $path ): string {
		$path = trim( $path );

		if ( str_starts_with( $path, 's3://' ) ) {
			$path = substr( $path, 5 );
		}

		return trim( $path, '/' );
	}
}
