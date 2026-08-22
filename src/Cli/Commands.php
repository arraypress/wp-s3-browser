<?php
/**
 * WP-CLI Commands
 *
 * One implementation for both platforms. Everything here is presentation and
 * flag handling; anything that needs to know how a product stores a file goes
 * through the Files implementation the host plugin supplies.
 *
 * @package     ArrayPress\S3
 * @copyright   Copyright (c) 2025, ArrayPress Limited
 * @license     GPL2+
 * @author      David Sherlock
 */

declare( strict_types=1 );

namespace ArrayPress\S3\Cli;

use ArrayPress\S3\Client;
use InvalidArgumentException;
use RuntimeException;
use WP_CLI;

/**
 * Class Commands
 */
class Commands {

	/**
	 * Work behind the commands.
	 *
	 * @var Runner
	 */
	private Runner $runner;

	/**
	 * Host plugin's file table.
	 *
	 * @var Files
	 */
	private Files $store;

	/**
	 * Build the commands.
	 *
	 * @param Files  $store  Host plugin's file table.
	 * @param Client $client Configured S3 client.
	 */
	public function __construct( Files $store, Client $client ) {
		$this->store  = $store;
		$this->runner = new Runner( $store, $client );
	}

	/**
	 * Check that every file the store references is really in the bucket.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : Output format for the problem list.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - csv
	 *   - json
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp <namespace> verify
	 *     wp <namespace> verify --format=csv
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Flags.
	 *
	 * @return void
	 */
	public function verify( array $args, array $assoc_args ): void {
		$format = (string) ( $assoc_args['format'] ?? 'table' );

		WP_CLI::log( 'Checking every referenced file against the bucket...' );

		$result = $this->runner->verify();

		WP_CLI::log(
			sprintf(
				'Checked %d file%s.',
				$result['checked'],
				1 === $result['checked'] ? '' : 's'
			)
		);

		if ( $result['skipped'] ) {
			WP_CLI::log(
				sprintf(
					'%d file%s left alone: not a bucket path (a local file, a URL, or a shortcode another plugin resolves).',
					count( $result['skipped'] ),
					1 === count( $result['skipped'] ) ? ' was' : 's were'
				)
			);
		}

		// Reported apart from "missing": being unable to look is not the same
		// as having looked and found nothing, and conflating them turns a
		// credentials problem into a false alarm about lost files.
		if ( $result['unreadable'] ) {
			WP_CLI::warning( sprintf( '%d file%s could not be checked.', count( $result['unreadable'] ), 1 === count( $result['unreadable'] ) ? '' : 's' ) );
			$this->rows( $result['unreadable'], [ 'label', 'name', 'path', 'reason' ], $format );
		}

		if ( ! $result['missing'] ) {
			WP_CLI::success( 'Every referenced file is present.' );

			return;
		}

		WP_CLI::warning(
			sprintf(
				'%d file%s missing from the bucket.',
				count( $result['missing'] ),
				1 === count( $result['missing'] ) ? ' is' : 's are'
			)
		);

		$this->rows( $result['missing'], [ 'label', 'name', 'bucket', 'key' ], $format );

		WP_CLI::halt( 1 );
	}

	/**
	 * List every distinct path the store references.
	 *
	 * Feed the result to `rclone --files-from` and you copy exactly what the
	 * store needs, instead of an entire uploads directory.
	 *
	 * ## OPTIONS
	 *
	 * [--all]
	 * : Include paths that are not bucket paths.
	 *
	 * ## EXAMPLES
	 *
	 *     wp <namespace> manifest > files.txt
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Flags.
	 *
	 * @return void
	 */
	public function manifest( array $args, array $assoc_args ): void {
		$manifest = $this->runner->manifest();
		$paths    = isset( $assoc_args['all'] )
			? array_merge( $manifest['s3'], $manifest['other'] )
			: $manifest['s3'];

		sort( $paths );

		foreach ( $paths as $path ) {
			WP_CLI::line( $path );
		}
	}

	/**
	 * Assign files to products from a CSV.
	 *
	 * The CSV takes one row per file. Repeat the reference to give a product
	 * several files:
	 *
	 *     sku,file_name,file_path
	 *     SHIRT-01,Manual,my-bucket/manuals/shirt.pdf
	 *     SHIRT-01,Bonus pack,my-bucket/extras/bonus.zip
	 *
	 * Nothing is written without --execute.
	 *
	 * ## OPTIONS
	 *
	 * <file>
	 * : Path to the CSV.
	 *
	 * [--mode=<mode>]
	 * : How incoming files meet the existing list.
	 * ---
	 * default: append
	 * options:
	 *   - append
	 *   - sync
	 *   - replace
	 * ---
	 *
	 * [--execute]
	 * : Write the changes. Without this the command only reports them.
	 *
	 * [--skip-verify]
	 * : Do not check that each file exists in the bucket first.
	 *
	 * [--yes]
	 * : Skip the confirmation prompt.
	 *
	 * ## EXAMPLES
	 *
	 *     wp <namespace> assign files.csv
	 *     wp <namespace> assign files.csv --execute
	 *     wp <namespace> assign files.csv --mode=sync --execute
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Flags.
	 *
	 * @return void
	 */
	public function assign( array $args, array $assoc_args ): void {
		$path    = (string) ( $args[0] ?? '' );
		$mode    = (string) ( $assoc_args['mode'] ?? Files::APPEND );
		$execute = isset( $assoc_args['execute'] );
		$verify  = ! isset( $assoc_args['skip-verify'] );

		if ( ! in_array( $mode, [ Files::APPEND, Files::SYNC, Files::REPLACE ], true ) ) {
			WP_CLI::error( sprintf( 'Unknown mode "%s".', $mode ) );
		}

		try {
			$grouped = Csv::grouped( $path );
		} catch ( InvalidArgumentException $e ) {
			WP_CLI::error( $e->getMessage() );

			return;
		}

		if ( ! $grouped ) {
			WP_CLI::error( 'The file has no rows.' );

			return;
		}

		$files = array_sum( array_map( 'count', $grouped ) );

		WP_CLI::log(
			sprintf(
				'Read %d file%s for %d %s. Mode: %s.',
				$files,
				1 === $files ? '' : 's',
				count( $grouped ),
				$this->store->noun( 1 !== count( $grouped ) ),
				$mode
			)
		);

		if ( $verify ) {
			WP_CLI::log( 'Checking each file exists in the bucket...' );
		}

		$planned = $this->runner->plan( $grouped, $mode, $verify );

		$this->report( $planned, $mode );

		$changing = array_filter(
			$planned['plans'],
			static fn ( array $entry ): bool => $entry['plan']->changes()
		);

		if ( ! $changing ) {
			WP_CLI::success( 'Nothing to change.' );

			return;
		}

		if ( ! $execute ) {
			WP_CLI::log( '' );
			WP_CLI::success(
				sprintf(
					'Dry run. %d %s would change. Re-run with --execute to write.',
					count( $changing ),
					$this->store->noun( 1 !== count( $changing ) )
				)
			);

			return;
		}

		if ( Files::REPLACE === $mode ) {
			WP_CLI::confirm(
				sprintf(
					'Replace discards the existing rows, which revokes any download already granted against them. Continue for %d %s?',
					count( $changing ),
					$this->store->noun( 1 !== count( $changing ) )
				),
				$assoc_args
			);
		}

		try {
			$written = $this->runner->apply( $changing, $mode );
		} catch ( RuntimeException $e ) {
			WP_CLI::error( $e->getMessage() );

			return;
		}

		WP_CLI::success(
			sprintf(
				'%d %s updated.',
				$written,
				$this->store->noun( 1 !== $written )
			)
		);
	}

	/**
	 * Report what a plan run found.
	 *
	 * @param array  $planned Result of Runner::plan().
	 * @param string $mode    One of the Files mode constants.
	 *
	 * @return void
	 */
	private function report( array $planned, string $mode ): void {
		foreach ( $planned['unmatched'] as $reference ) {
			WP_CLI::warning( sprintf( 'No %s matched "%s".', $this->store->noun(), $reference ) );
		}

		foreach ( $planned['rejected'] as $entry ) {
			// All of a reference's files or none. A product holding two of the
			// three it was meant to get looks finished and is not.
			WP_CLI::warning(
				sprintf(
					'%s skipped entirely -- %d of its files could not be used:',
					$entry['label'],
					count( $entry['problems'] )
				)
			);

			foreach ( $entry['problems'] as $problem ) {
				WP_CLI::log( sprintf( '    %s  (%s)', $problem['path'], $problem['reason'] ) );
			}
		}

		foreach ( $planned['plans'] as $entry ) {
			/** @var Plan $plan */
			$plan = $entry['plan'];

			if ( ! $plan->changes() ) {
				continue;
			}

			WP_CLI::log(
				sprintf(
					'%s: %d file%s -> %d',
					$entry['label'],
					count( $plan->files ) - count( $plan->added ) + count( $plan->removed ),
					1 === count( $plan->files ) ? '' : 's',
					count( $plan->files )
				)
			);

			foreach ( $plan->added as $file ) {
				WP_CLI::log( sprintf( '    + %s  %s', $file['name'], $file['path'] ) );
			}

			foreach ( $plan->repointed as $file ) {
				WP_CLI::log( sprintf( '    ~ %s  %s -> %s', $file['name'], $file['from'], $file['to'] ) );
			}

			foreach ( $plan->removed as $file ) {
				WP_CLI::log( sprintf( '    - %s  %s', $file['name'], $file['path'] ) );
			}
		}
	}

	/**
	 * Print rows in the requested format.
	 *
	 * @param array    $rows    Rows.
	 * @param string[] $columns Columns to show.
	 * @param string   $format  Output format.
	 *
	 * @return void
	 */
	private function rows( array $rows, array $columns, string $format ): void {
		\WP_CLI\Utils\format_items( $format, $rows, $columns );
	}
}
