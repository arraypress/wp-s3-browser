<?php
/**
 * Assignment Plan
 *
 * Works out what a post's file list becomes, without writing anything. Keeping
 * this separate is what lets --dry-run show exactly the change that --execute
 * would make: the same code decides both.
 *
 * @package     ArrayPress\S3
 * @copyright   Copyright (c) 2025, ArrayPress Limited
 * @license     GPL2+
 * @author      David Sherlock
 */

declare( strict_types=1 );

namespace ArrayPress\S3\Cli;

/**
 * Class Plan
 */
class Plan {

	/**
	 * The list the post should end up with.
	 *
	 * @var array<int, array{name: string, path: string}>
	 */
	public array $files = [];

	/**
	 * Files not previously on the post.
	 *
	 * @var array<int, array{name: string, path: string}>
	 */
	public array $added = [];

	/**
	 * Files the post already held, skipped rather than duplicated.
	 *
	 * @var array<int, array{name: string, path: string}>
	 */
	public array $skipped = [];

	/**
	 * Files whose path changed while keeping their row.
	 *
	 * @var array<int, array{name: string, from: string, to: string}>
	 */
	public array $repointed = [];

	/**
	 * Files dropped from the post.
	 *
	 * @var array<int, array{name: string, path: string}>
	 */
	public array $removed = [];

	/**
	 * Build a plan.
	 *
	 * @param array<int, array{name: string, path: string}> $existing Files already on the post.
	 * @param array<int, array{name: string, path: string}> $incoming Files from the CSV.
	 * @param string                                        $mode     One of the Files mode constants.
	 *
	 * @return self
	 */
	public static function build( array $existing, array $incoming, string $mode ): self {
		$plan = new self();

		// The same path twice under one reference is a copy-paste in the
		// spreadsheet, not a request for two identical rows.
		$incoming = $plan->dedupe( $incoming );

		switch ( $mode ) {
			case Files::REPLACE:
				$plan->replace( $existing, $incoming );
				break;

			case Files::SYNC:
				$plan->sync( $existing, $incoming );
				break;

			default:
				$plan->append( $existing, $incoming );
		}

		return $plan;
	}

	/**
	 * Whether applying this plan would change anything.
	 *
	 * @return bool
	 */
	public function changes(): bool {
		return $this->added || $this->repointed || $this->removed;
	}

	/**
	 * Add what is not already there, and touch nothing else.
	 *
	 * @param array $existing Existing files.
	 * @param array $incoming Incoming files.
	 *
	 * @return void
	 */
	private function append( array $existing, array $incoming ): void {
		$this->files = $existing;
		$held        = $this->paths( $existing );

		foreach ( $incoming as $file ) {
			if ( in_array( Path::normalize( $file['path'] ), $held, true ) ) {
				$this->skipped[] = $file;
				continue;
			}

			$held[]        = Path::normalize( $file['path'] );
			$this->files[] = $file;
			$this->added[] = $file;
		}
	}

	/**
	 * Make the list match, matching rows by name so paths can be rewritten.
	 *
	 * By name rather than by path because the path is the thing that changes:
	 * a file moved from the server into a bucket has a new path and the same
	 * name, and matching on it in place is what preserves the row -- and, on
	 * WooCommerce, the download id every customer's permission is recorded
	 * against.
	 *
	 * @param array $existing Existing files.
	 * @param array $incoming Incoming files.
	 *
	 * @return void
	 */
	private function sync( array $existing, array $incoming ): void {
		$claimed = [];

		foreach ( $incoming as $file ) {
			$match = null;

			foreach ( $existing as $index => $current ) {
				if ( isset( $claimed[ $index ] ) ) {
					continue;
				}

				if ( self::key( $current['name'] ) === self::key( $file['name'] ) ) {
					$match = $index;
					break;
				}
			}

			if ( null === $match ) {
				$this->files[] = $file;
				$this->added[] = $file;
				continue;
			}

			$claimed[ $match ] = true;
			$this->files[]     = $file;

			if ( Path::normalize( $existing[ $match ]['path'] ) === Path::normalize( $file['path'] ) ) {
				$this->skipped[] = $file;
				continue;
			}

			$this->repointed[] = [
				'name' => $file['name'],
				'from' => $existing[ $match ]['path'],
				'to'   => $file['path'],
			];
		}

		foreach ( $existing as $index => $current ) {
			if ( ! isset( $claimed[ $index ] ) ) {
				$this->removed[] = $current;
			}
		}
	}

	/**
	 * Discard what is there and take the incoming list wholesale.
	 *
	 * @param array $existing Existing files.
	 * @param array $incoming Incoming files.
	 *
	 * @return void
	 */
	private function replace( array $existing, array $incoming ): void {
		$this->files = $incoming;
		$held        = $this->paths( $existing );

		foreach ( $incoming as $file ) {
			if ( in_array( Path::normalize( $file['path'] ), $held, true ) ) {
				$this->skipped[] = $file;
				continue;
			}

			$this->added[] = $file;
		}

		$wanted = $this->paths( $incoming );

		foreach ( $existing as $file ) {
			if ( ! in_array( Path::normalize( $file['path'] ), $wanted, true ) ) {
				$this->removed[] = $file;
			}
		}
	}

	/**
	 * Drop repeated paths from one reference's files.
	 *
	 * @param array $files Files.
	 *
	 * @return array
	 */
	private function dedupe( array $files ): array {
		$seen = [];
		$kept = [];

		foreach ( $files as $file ) {
			$path = Path::normalize( $file['path'] );

			if ( isset( $seen[ $path ] ) ) {
				$this->skipped[] = $file;
				continue;
			}

			$seen[ $path ] = true;
			$kept[]        = $file;
		}

		return $kept;
	}

	/**
	 * Normalized paths of a file list.
	 *
	 * @param array $files Files.
	 *
	 * @return string[]
	 */
	private function paths( array $files ): array {
		return array_map(
			static fn ( array $file ): string => Path::normalize( $file['path'] ),
			$files
		);
	}

	/**
	 * Reduce a name to the form two names can be compared in.
	 *
	 * @param string $name File name.
	 *
	 * @return string
	 */
	private static function key( string $name ): string {
		return strtolower( trim( $name ) );
	}
}
