<?php


/**
 * Case-insensitive version of str_contains()
 *
 * @param string $haystack The string to search in
 * @param string $needle   The substring to search for
 *
 * @return bool Whether the haystack contains the needle
 */
function str_contains_lower( string $haystack, string $needle ): bool {
	return $needle === '' || str_contains( strtolower( $haystack ), strtolower( $needle ) );
}