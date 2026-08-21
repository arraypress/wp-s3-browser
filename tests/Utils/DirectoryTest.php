<?php
declare( strict_types=1 );

namespace ArrayPress\S3\Tests\Utils;

use ArrayPress\S3\Utils\Directory;
use PHPUnit\Framework\TestCase;

/**
 * Building the key an object gets when it moves.
 *
 * S3 has no move: the object is copied to a new key and the old one deleted.
 * So this function decides where a file ends up, and a mistake here does not
 * fail loudly -- it relocates the file somewhere the admin did not ask for,
 * and the original is deleted afterwards.
 */
final class DirectoryTest extends TestCase {

	public function test_a_file_keeps_its_name_in_the_new_folder(): void {
		$this->assertSame(
			'archive/basslines.zip',
			Directory::build_move_key( 'testing/basslines.zip', 'archive' )
		);
	}

	public function test_a_trailing_slash_on_the_target_makes_no_difference(): void {
		$this->assertSame(
			'archive/basslines.zip',
			Directory::build_move_key( 'testing/basslines.zip', 'archive/' )
		);
	}

	public function test_a_nested_target_is_kept_whole(): void {
		$this->assertSame(
			'2026/q1/archive/basslines.zip',
			Directory::build_move_key( 'testing/basslines.zip', '2026/q1/archive' )
		);
	}

	/**
	 * An empty target is the bucket root, not a key beginning with a slash --
	 * "/basslines.zip" and "basslines.zip" are different objects in S3.
	 */
	public function test_an_empty_target_moves_to_the_root(): void {
		$this->assertSame( 'basslines.zip', Directory::build_move_key( 'testing/basslines.zip', '' ) );
		$this->assertSame( 'basslines.zip', Directory::build_move_key( 'testing/basslines.zip', '/' ) );
	}

	public function test_a_file_already_at_the_root_can_be_filed_away(): void {
		$this->assertSame( 'archive/loose.zip', Directory::build_move_key( 'loose.zip', 'archive' ) );
	}

	/**
	 * Moving into the folder a file is already in produces the same key, which
	 * is what lets the caller refuse it rather than copy an object over itself
	 * and then delete it.
	 */
	public function test_moving_into_the_same_folder_yields_the_same_key(): void {
		$this->assertSame(
			'testing/basslines.zip',
			Directory::build_move_key( 'testing/basslines.zip', 'testing' )
		);
	}

	public function test_only_the_last_segment_is_treated_as_the_filename(): void {
		$this->assertSame(
			'flat/deep.zip',
			Directory::build_move_key( 'a/b/c/deep.zip', 'flat' )
		);
	}

	public function test_a_name_containing_dots_survives(): void {
		$this->assertSame(
			'archive/v1..2.final.zip',
			Directory::build_move_key( 'mixes/v1..2.final.zip', 'archive' )
		);
	}

	public function test_a_keyless_input_is_returned_unchanged(): void {
		$this->assertSame( '', Directory::build_move_key( '', 'archive' ) );
	}
}
