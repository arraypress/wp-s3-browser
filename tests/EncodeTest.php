<?php
declare( strict_types=1 );

namespace ArrayPress\S3\Tests;

use ArrayPress\S3\Utils\Encode;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * Object key encoding.
 *
 * Encoding is shared by the request URL and the SigV4 canonical URI, so a
 * change here silently breaks every signature.
 */
final class EncodeTest extends TestCase {

	public function test_encodes_spaces_as_percent_twenty(): void {
		$this->assertSame( 'folder/my%20file.zip', Encode::object_key( 'folder/my file.zip' ) );
	}

	public function test_preserves_path_separators(): void {
		$this->assertSame( 'a/b/c/d.txt', Encode::object_key( 'a/b/c/d.txt' ) );
	}

	public function test_strips_leading_slash(): void {
		$this->assertSame( 'a/b.txt', Encode::object_key( '/a/b.txt' ) );
	}

	public function test_empty_key_returns_empty(): void {
		$this->assertSame( '', Encode::object_key( '' ) );
	}

	/**
	 * Dots are legal anywhere in an S3 key. A substring test for '..' rejected
	 * real filenames, so only whole segments count as traversal.
	 */
	public function test_allows_dots_inside_a_segment(): void {
		$this->assertSame( 'mixes/v1..2.wav', Encode::object_key( 'mixes/v1..2.wav' ) );
		$this->assertSame( 'report..final.pdf', Encode::object_key( 'report..final.pdf' ) );
	}

	public function test_rejects_traversal_segment(): void {
		$this->expectException( InvalidArgumentException::class );
		Encode::object_key( 'a/../../etc/passwd' );
	}

	public function test_rejects_single_dot_segment(): void {
		$this->expectException( InvalidArgumentException::class );
		Encode::object_key( './x' );
	}

	public function test_rejects_nul_byte(): void {
		$this->expectException( InvalidArgumentException::class );
		Encode::object_key( "a\0b.txt" );
	}

	/**
	 * Rejection must not fall through to the bucket root: returning '' here is
	 * what turned a blocked traversal into an operation on the whole bucket.
	 */
	public function test_try_variant_returns_null_rather_than_empty(): void {
		$this->assertNull( Encode::try_object_key( 'a/../b' ) );
		$this->assertSame( 'a/b.txt', Encode::try_object_key( 'a/b.txt' ) );
	}

	public function test_encodes_unicode(): void {
		$this->assertSame(
			'Kits/D%C3%A9j%C3%A0%20vu.zip',
			Encode::object_key( 'Kits/Déjà vu.zip' )
		);
	}
}
