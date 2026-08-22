<?php
/**
 * CLI Runner
 *
 * The work behind the commands, with no WP_CLI in it. Deciding what would
 * change is kept apart from writing it, so --dry-run and a real run go through
 * the same code and cannot disagree about what was about to happen.
 *
 * @package     ArrayPress\S3
 * @copyright   Copyright (c) 2025, ArrayPress Limited
 * @license     GPL2+
 * @author      David Sherlock
 */

declare( strict_types=1 );

namespace ArrayPress\S3\Cli;

use ArrayPress\S3\Client;
use ArrayPress\S3\Utils\Parse;

/**
 * Class Runner
 */
class Runner {

	/**
	 * Build a runner.
	 *
	 * @param Files  $store  The host plugin's file table.
	 * @param Client $client Configured S3 client.
	 */
	public function __construct(
		private Files $store,
		private Client $client
	) {
	}

	/**
	 * Every distinct path the site's files reference.
	 *
	 * Feed the R2 paths to `rclone --files-from` and you copy exactly what the
	 * store needs, rather than an entire uploads directory.
	 *
	 * @return array{s3: string[], other: string[]}
	 */
	public function manifest(): array {
		$s3    = [];
		$other = [];

		foreach ( $this->store->all() as $id ) {
			foreach ( $this->store->files( $id ) as $file ) {
				$path = Path::normalize( $file['path'] );

				if ( '' === $path ) {
					continue;
				}

				if ( Parse::path( $file['path'] ) ) {
					$s3[ $path ] = true;
				} else {
					$other[ $path ] = true;
				}
			}
		}

		return [
			's3'    => array_keys( $s3 ),
			'other' => array_keys( $other ),
		];
	}

	/**
	 * Check that every referenced object is actually in the bucket.
	 *
	 * Reads without the cache. A verification that can be satisfied by a
	 * previous run's answer is not one worth running.
	 *
	 * @param callable|null $progress Called with each path as it is checked.
	 *
	 * @return array{checked: int, missing: array, unreadable: array, skipped: array}
	 */
	public function verify( ?callable $progress = null ): array {
		$checked    = 0;
		$missing    = [];
		$unreadable = [];
		$skipped    = [];

		foreach ( $this->store->all() as $id ) {
			$label = $this->store->label( $id );

			foreach ( $this->store->files( $id ) as $file ) {
				$parsed = Parse::path( $file['path'] );

				// A local file, a URL, or a shortcode that another plugin
				// resolves at download time. None of those are ours to judge.
				if ( ! $parsed ) {
					$skipped[] = [
						'id'    => $id,
						'label' => $label,
						'name'  => $file['name'],
						'path'  => $file['path'],
					];

					continue;
				}

				++$checked;

				if ( is_callable( $progress ) ) {
					$progress( $file['path'] );
				}

				$response = $this->client->object_exists( $parsed['bucket'], $parsed['object'], false );

				$row = [
					'id'     => $id,
					'label'  => $label,
					'name'   => $file['name'],
					'path'   => $file['path'],
					'bucket' => $parsed['bucket'],
					'key'    => $parsed['object'],
				];

				if ( ! $response->is_successful() ) {
					// Credentials, network, a bucket that refuses to answer.
					// Reported apart from "missing", because telling someone a
					// file is gone when the truth is you could not look is
					// worse than saying nothing.
					$row['reason'] = $response->get_message();
					$unreadable[]  = $row;

					continue;
				}

				if ( true !== ( $response->get_data()['exists'] ?? false ) ) {
					$missing[] = $row;
				}
			}
		}

		return [
			'checked'    => $checked,
			'missing'    => $missing,
			'unreadable' => $unreadable,
			'skipped'    => $skipped,
		];
	}

	/**
	 * Work out what assigning a CSV would do, writing nothing.
	 *
	 * @param array         $grouped  Files grouped by reference, from Csv.
	 * @param string        $mode     One of the Files mode constants.
	 * @param bool          $verify   Whether to confirm each object exists first.
	 * @param callable|null $progress Called with each reference as it is planned.
	 *
	 * @return array{plans: array, unmatched: array, rejected: array}
	 */
	public function plan( array $grouped, string $mode, bool $verify = true, ?callable $progress = null ): array {
		$plans     = [];
		$unmatched = [];
		$rejected  = [];

		foreach ( $grouped as $reference => $files ) {
			if ( is_callable( $progress ) ) {
				$progress( (string) $reference );
			}

			$id = $this->store->locate( (string) $reference );

			if ( ! $id ) {
				$unmatched[] = (string) $reference;
				continue;
			}

			if ( $verify ) {
				$problems = $this->unusable( $files );

				// All of a reference's files or none of them. A product left
				// holding two of the three it was meant to get looks finished
				// and is not, and nothing downstream would flag it again.
				if ( $problems ) {
					$rejected[] = [
						'reference' => (string) $reference,
						'id'        => $id,
						'label'     => $this->store->label( $id ),
						'problems'  => $problems,
					];

					continue;
				}
			}

			$plan = Plan::build( $this->store->files( $id ), $files, $mode );

			$plans[] = [
				'reference' => (string) $reference,
				'id'        => $id,
				'label'     => $this->store->label( $id ),
				'plan'      => $plan,
			];
		}

		return [
			'plans'     => $plans,
			'unmatched' => $unmatched,
			'rejected'  => $rejected,
		];
	}

	/**
	 * Write the plans that change something.
	 *
	 * @param array  $plans Entries from plan().
	 * @param string $mode  One of the Files mode constants.
	 *
	 * @return int Number of posts written.
	 */
	public function apply( array $plans, string $mode ): int {
		$written = 0;

		foreach ( $plans as $entry ) {
			/** @var Plan $plan */
			$plan = $entry['plan'];

			if ( ! $plan->changes() ) {
				continue;
			}

			$this->store->assign( $entry['id'], $plan->files, $mode );
			++$written;
		}

		return $written;
	}

	/**
	 * Reasons a reference's files cannot be assigned.
	 *
	 * @param array $files Files for one reference.
	 *
	 * @return array<int, array{path: string, reason: string}>
	 */
	private function unusable( array $files ): array {
		$problems = [];

		foreach ( $files as $file ) {
			$parsed = Parse::path( $file['path'] );

			if ( ! $parsed ) {
				$problems[] = [
					'path'   => $file['path'],
					'reason' => 'not a bucket/key path',
				];

				continue;
			}

			$response = $this->client->object_exists( $parsed['bucket'], $parsed['object'], false );

			if ( ! $response->is_successful() ) {
				$problems[] = [
					'path'   => $file['path'],
					'reason' => $response->get_message(),
				];

				continue;
			}

			if ( true !== ( $response->get_data()['exists'] ?? false ) ) {
				$problems[] = [
					'path'   => $file['path'],
					'reason' => 'not found in bucket',
				];
			}
		}

		return $problems;
	}
}
