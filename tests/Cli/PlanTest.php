<?php
/**
 * Assignment planning.
 *
 * @package ArrayPress\S3
 */

declare( strict_types=1 );

namespace ArrayPress\S3\Tests\Cli;

use ArrayPress\S3\Cli\Files;
use ArrayPress\S3\Cli\Plan;
use PHPUnit\Framework\TestCase;

/**
 * Class PlanTest
 */
final class PlanTest extends TestCase {

	/**
	 * Build a file row.
	 *
	 * @param string $name Name.
	 * @param string $path Path.
	 *
	 * @return array
	 */
	private function file( string $name, string $path ): array {
		return [
			'name' => $name,
			'path' => $path,
		];
	}

	public function test_append_adds_what_is_missing(): void {
		$plan = Plan::build(
			[ $this->file( 'Manual', 'b/manual.pdf' ) ],
			[ $this->file( 'Bonus', 'b/bonus.zip' ) ],
			Files::APPEND
		);

		$this->assertCount( 2, $plan->files );
		$this->assertCount( 1, $plan->added );
		$this->assertSame( 'b/manual.pdf', $plan->files[0]['path'] );
		$this->assertSame( 'b/bonus.zip', $plan->files[1]['path'] );
	}

	public function test_append_never_removes_or_reorders(): void {
		$existing = [
			$this->file( 'One', 'b/one.zip' ),
			$this->file( 'Two', 'b/two.zip' ),
		];

		$plan = Plan::build( $existing, [ $this->file( 'Three', 'b/three.zip' ) ], Files::APPEND );

		$this->assertSame( 'b/one.zip', $plan->files[0]['path'] );
		$this->assertSame( 'b/two.zip', $plan->files[1]['path'] );
		$this->assertEmpty( $plan->removed );
	}

	public function test_append_skips_a_file_already_held(): void {
		$plan = Plan::build(
			[ $this->file( 'Manual', 'b/manual.pdf' ) ],
			[ $this->file( 'Manual again', 'b/manual.pdf' ) ],
			Files::APPEND
		);

		$this->assertCount( 1, $plan->files );
		$this->assertCount( 1, $plan->skipped );
		$this->assertFalse( $plan->changes() );
	}

	/**
	 * The host plugins treat these as the same stored file, so a CSV written
	 * in either shape must not add a second row for it.
	 */
	public function test_an_s3_prefix_does_not_make_a_second_file(): void {
		$plan = Plan::build(
			[ $this->file( 'Manual', 's3://b/manual.pdf' ) ],
			[ $this->file( 'Manual', 'b/manual.pdf' ) ],
			Files::APPEND
		);

		$this->assertCount( 1, $plan->files );
		$this->assertFalse( $plan->changes() );
	}

	public function test_a_path_repeated_in_the_csv_is_added_once(): void {
		$plan = Plan::build(
			[],
			[
				$this->file( 'One', 'b/one.zip' ),
				$this->file( 'One again', 'b/one.zip' ),
			],
			Files::APPEND
		);

		$this->assertCount( 1, $plan->files );
		$this->assertCount( 1, $plan->skipped );
	}

	/**
	 * The point of sync: the file moved, the name did not. Matching on the
	 * name is what lets the row -- and on WooCommerce the download id every
	 * customer permission hangs off -- survive the move.
	 */
	public function test_sync_repoints_a_matching_name(): void {
		$plan = Plan::build(
			[ $this->file( 'Manual', 'old/manual.pdf' ) ],
			[ $this->file( 'Manual', 'bucket/manual.pdf' ) ],
			Files::SYNC
		);

		$this->assertCount( 1, $plan->files );
		$this->assertCount( 1, $plan->repointed );
		$this->assertSame( 'old/manual.pdf', $plan->repointed[0]['from'] );
		$this->assertSame( 'bucket/manual.pdf', $plan->repointed[0]['to'] );
		$this->assertEmpty( $plan->removed );
		$this->assertTrue( $plan->changes() );
	}

	public function test_sync_matches_names_case_insensitively(): void {
		$plan = Plan::build(
			[ $this->file( 'manual', 'old/manual.pdf' ) ],
			[ $this->file( 'Manual', 'bucket/manual.pdf' ) ],
			Files::SYNC
		);

		$this->assertCount( 1, $plan->repointed );
		$this->assertEmpty( $plan->added );
	}

	public function test_sync_removes_what_the_csv_does_not_name(): void {
		$plan = Plan::build(
			[
				$this->file( 'Keep', 'b/keep.zip' ),
				$this->file( 'Drop', 'b/drop.zip' ),
			],
			[ $this->file( 'Keep', 'b/keep.zip' ) ],
			Files::SYNC
		);

		$this->assertCount( 1, $plan->files );
		$this->assertCount( 1, $plan->removed );
		$this->assertSame( 'b/drop.zip', $plan->removed[0]['path'] );
	}

	public function test_sync_leaves_an_identical_list_alone(): void {
		$files = [
			$this->file( 'One', 'b/one.zip' ),
			$this->file( 'Two', 'b/two.zip' ),
		];

		$plan = Plan::build( $files, $files, Files::SYNC );

		$this->assertFalse( $plan->changes() );
		$this->assertCount( 2, $plan->files );
	}

	/**
	 * Two rows sharing a name must not both claim the same existing row, or
	 * the second silently becomes an addition and the first is lost.
	 */
	public function test_sync_does_not_let_two_rows_claim_one_match(): void {
		$plan = Plan::build(
			[
				$this->file( 'Manual', 'old/a.pdf' ),
				$this->file( 'Manual', 'old/b.pdf' ),
			],
			[
				$this->file( 'Manual', 'new/a.pdf' ),
				$this->file( 'Manual', 'new/b.pdf' ),
			],
			Files::SYNC
		);

		$this->assertCount( 2, $plan->files );
		$this->assertCount( 2, $plan->repointed );
		$this->assertEmpty( $plan->removed );
	}

	public function test_replace_takes_the_incoming_list_wholesale(): void {
		$plan = Plan::build(
			[ $this->file( 'Old', 'b/old.zip' ) ],
			[ $this->file( 'New', 'b/new.zip' ) ],
			Files::REPLACE
		);

		$this->assertCount( 1, $plan->files );
		$this->assertSame( 'b/new.zip', $plan->files[0]['path'] );
		$this->assertCount( 1, $plan->removed );
		$this->assertCount( 1, $plan->added );
	}

	public function test_an_unknown_mode_appends_rather_than_destroying(): void {
		$plan = Plan::build(
			[ $this->file( 'Keep', 'b/keep.zip' ) ],
			[ $this->file( 'Add', 'b/add.zip' ) ],
			'nonsense'
		);

		$this->assertCount( 2, $plan->files );
		$this->assertEmpty( $plan->removed );
	}
}
