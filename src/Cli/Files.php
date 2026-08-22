<?php
/**
 * Product File Store
 *
 * What a host plugin must be able to do with its own file table for the CLI
 * commands to work against it.
 *
 * The library deliberately knows nothing about how either platform stores a
 * file. WooCommerce keys its rows by download id and records customer
 * permissions against that id; Easy Digital Downloads resolves by position in
 * the array. Those are not the same shape, and an abstraction that pretended
 * they were would be one that silently reorders EDD rows -- changing what
 * every past purchase resolves to -- while looking correct.
 *
 * @package     ArrayPress\S3
 * @copyright   Copyright (c) 2025, ArrayPress Limited
 * @license     GPL2+
 * @author      David Sherlock
 */

declare( strict_types=1 );

namespace ArrayPress\S3\Cli;

/**
 * Interface Files
 */
interface Files {

	/**
	 * Add incoming files, leaving every existing row untouched.
	 *
	 * Files already on the product are skipped rather than duplicated.
	 */
	public const APPEND = 'append';

	/**
	 * Make the product's list match the incoming one.
	 *
	 * Rows are matched by name so a path can be rewritten in place, which is
	 * what keeps a WooCommerce download id -- and every permission recorded
	 * against it -- pointing at the file the customer bought. Rows the
	 * incoming list does not name are removed.
	 */
	public const SYNC = 'sync';

	/**
	 * Discard the existing list and write the incoming one.
	 *
	 * Identities are not preserved. On WooCommerce that revokes access for
	 * anyone holding a permission against the old rows.
	 */
	public const REPLACE = 'replace';

	/**
	 * What this platform calls the thing files belong to.
	 *
	 * Used in output, so it should read naturally in a sentence: "3 products
	 * updated", "no download matched SKU-1".
	 *
	 * @param bool $plural Whether the plural form is wanted.
	 *
	 * @return string
	 */
	public function noun( bool $plural = false ): string;

	/**
	 * Resolve a reference from a CSV row to a post ID.
	 *
	 * What counts as a reference is the platform's business: a SKU on
	 * WooCommerce, a post ID or slug elsewhere.
	 *
	 * @param string $reference Value from the CSV's first column.
	 *
	 * @return int Post ID, or 0 when nothing matched.
	 */
	public function locate( string $reference ): int;

	/**
	 * Name a post for display.
	 *
	 * @param int $id Post ID.
	 *
	 * @return string
	 */
	public function label( int $id ): string;

	/**
	 * Read the files currently on a post, in the order they are stored.
	 *
	 * @param int $id Post ID.
	 *
	 * @return array<int, array{name: string, path: string}>
	 */
	public function files( int $id ): array;

	/**
	 * Write a file list to a post.
	 *
	 * Implementations own their identity rules -- which rows may be reused,
	 * which must keep their key, what order is safe to disturb.
	 *
	 * @param int                                          $id    Post ID.
	 * @param array<int, array{name: string, path: string}> $files Files to write.
	 * @param string                                       $mode  One of APPEND, SYNC, REPLACE.
	 *
	 * @return void
	 */
	public function assign( int $id, array $files, string $mode ): void;

	/**
	 * Every post on the site that holds at least one file.
	 *
	 * @return int[] Post IDs.
	 */
	public function all(): array;
}
