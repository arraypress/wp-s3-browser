<?php
/**
 * CSV Reader
 *
 * Reads the file-assignment format: one row per file, repeated in the first
 * column to give a product several files.
 *
 *     sku,file_name,file_path
 *     SHIRT-01,Manual,my-bucket/manuals/shirt.pdf
 *     SHIRT-01,Bonus pack,my-bucket/extras/bonus.zip
 *
 * One row per *file* rather than a delimited list of files per product: every
 * file gets its own name, and no separator has to be chosen that a path might
 * contain.
 *
 * @package     ArrayPress\S3
 * @copyright   Copyright (c) 2025, ArrayPress Limited
 * @license     GPL2+
 * @author      David Sherlock
 */

declare( strict_types=1 );

namespace ArrayPress\S3\Cli;

use InvalidArgumentException;

/**
 * Class Csv
 */
class Csv {

	/**
	 * Columns every file must supply.
	 */
	private const REQUIRED = [ 'file_name', 'file_path' ];

	/**
	 * Accepted names for the first column, which references the post.
	 *
	 * Spreadsheets exported from either platform tend to lead with one of
	 * these, and insisting on a single spelling only invites a rename step.
	 */
	private const REFERENCE = [ 'sku', 'id', 'product', 'download', 'reference' ];

	/**
	 * Read and group a CSV.
	 *
	 * Grouping happens here rather than at the point of writing so that each
	 * post is written once, however many files it takes. Writing per row would
	 * save a four-file product four times, and report a diff nobody could read.
	 *
	 * @param string $path Path to the CSV file.
	 *
	 * @return array<string, array<int, array{name: string, path: string, line: int}>>
	 *         Files grouped by reference, in the order the file listed them.
	 *
	 * @throws InvalidArgumentException When the file cannot be read or lacks required columns.
	 */
	public static function grouped( string $path ): array {
		if ( ! is_readable( $path ) ) {
			throw new InvalidArgumentException( sprintf( 'Cannot read %s.', $path ) );
		}

		$handle = fopen( $path, 'r' );

		if ( false === $handle ) {
			throw new InvalidArgumentException( sprintf( 'Cannot open %s.', $path ) );
		}

		try {
			// The empty escape is deliberate, and is the default PHP is moving
			// to: the backslash escape is a PHP quirk rather than part of CSV,
			// and it mangles any path that happens to contain one.
			$header = fgetcsv( $handle, 0, ',', '"', '' );

			if ( ! is_array( $header ) ) {
				throw new InvalidArgumentException( 'The file is empty.' );
			}

			$columns = self::columns( $header );
			$grouped = [];
			$line    = 1;

			while ( true ) {
				$row = fgetcsv( $handle, 0, ',', '"', '' );

				if ( ! is_array( $row ) ) {
					break;
				}

				++$line;

				// A trailing newline reads as a row of one empty field.
				if ( [ null ] === $row || '' === trim( implode( '', array_map( 'strval', $row ) ) ) ) {
					continue;
				}

				$reference = self::field( $row, $columns['reference'] );
				$name      = self::field( $row, $columns['file_name'] );
				$file      = self::field( $row, $columns['file_path'] );

				if ( '' === $reference ) {
					throw new InvalidArgumentException( sprintf( 'Line %d has no %s.', $line, $columns['reference_name'] ) );
				}

				if ( '' === $file ) {
					throw new InvalidArgumentException( sprintf( 'Line %d has no file_path.', $line ) );
				}

				$grouped[ $reference ][] = [
					'name' => '' === $name ? basename( $file ) : $name,
					'path' => $file,
					'line' => $line,
				];
			}

			return $grouped;
		} finally {
			fclose( $handle );
		}
	}

	/**
	 * Map the header row to column positions.
	 *
	 * @param array $header Header row.
	 *
	 * @return array{reference: int, reference_name: string, file_name: int, file_path: int}
	 *
	 * @throws InvalidArgumentException When a required column is absent.
	 */
	private static function columns( array $header ): array {
		$found = [];

		foreach ( $header as $index => $label ) {
			// A UTF-8 BOM on the first cell is invisible in a spreadsheet and
			// would otherwise make "sku" not equal "sku".
			$label = trim( (string) $label );
			$label = ltrim( $label, "\xEF\xBB\xBF" );

			$found[ strtolower( $label ) ] = (int) $index;
		}

		$reference = null;

		foreach ( self::REFERENCE as $candidate ) {
			if ( isset( $found[ $candidate ] ) ) {
				$reference = [ $candidate, $found[ $candidate ] ];
				break;
			}
		}

		if ( null === $reference ) {
			throw new InvalidArgumentException(
				sprintf(
					'The first column must be one of: %s.',
					implode( ', ', self::REFERENCE )
				)
			);
		}

		foreach ( self::REQUIRED as $column ) {
			if ( ! isset( $found[ $column ] ) ) {
				throw new InvalidArgumentException( sprintf( 'Missing required column: %s.', $column ) );
			}
		}

		return [
			'reference'      => $reference[1],
			'reference_name' => $reference[0],
			'file_name'      => $found['file_name'],
			'file_path'      => $found['file_path'],
		];
	}

	/**
	 * Read one field from a row.
	 *
	 * @param array $row   Row.
	 * @param int   $index Column index.
	 *
	 * @return string
	 */
	private static function field( array $row, int $index ): string {
		return trim( (string) ( $row[ $index ] ?? '' ) );
	}
}
