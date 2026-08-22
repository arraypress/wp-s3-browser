<?php
/**
 * CSV reading.
 *
 * @package ArrayPress\S3
 */

declare( strict_types=1 );

namespace ArrayPress\S3\Tests\Cli;

use ArrayPress\S3\Cli\Csv;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * Class CsvTest
 */
final class CsvTest extends TestCase {

	/**
	 * Files written during a test.
	 *
	 * @var string[]
	 */
	private array $written = [];

	/**
	 * Remove anything written.
	 */
	protected function tearDown(): void {
		foreach ( $this->written as $path ) {
			if ( file_exists( $path ) ) {
				unlink( $path );
			}
		}

		$this->written = [];

		parent::tearDown();
	}

	/**
	 * Write a CSV and return its path.
	 *
	 * @param string $contents File contents.
	 *
	 * @return string
	 */
	private function csv( string $contents ): string {
		$path = tempnam( sys_get_temp_dir(), 's3csv' );

		file_put_contents( $path, $contents );
		$this->written[] = $path;

		return $path;
	}

	public function test_repeated_references_group_into_one_product(): void {
		$grouped = Csv::grouped(
			$this->csv(
				"sku,file_name,file_path\n" .
				"SHIRT-01,Manual,b/manual.pdf\n" .
				"SHIRT-01,Bonus,b/bonus.zip\n" .
				"BOOK-02,EPUB,b/book.epub\n"
			)
		);

		$this->assertSame( [ 'SHIRT-01', 'BOOK-02' ], array_keys( $grouped ) );
		$this->assertCount( 2, $grouped['SHIRT-01'] );
		$this->assertCount( 1, $grouped['BOOK-02'] );
		$this->assertSame( 'Manual', $grouped['SHIRT-01'][0]['name'] );
	}

	public function test_any_accepted_reference_column_works(): void {
		foreach ( [ 'sku', 'id', 'product', 'download', 'reference' ] as $column ) {
			$grouped = Csv::grouped(
				$this->csv( "$column,file_name,file_path\nA,Name,b/f.zip\n" )
			);

			$this->assertArrayHasKey( 'A', $grouped, "column: $column" );
		}
	}

	/**
	 * Excel writes one, and it is invisible in the spreadsheet -- so without
	 * this the first column silently stops being recognised.
	 */
	public function test_a_byte_order_mark_does_not_hide_the_first_column(): void {
		$grouped = Csv::grouped(
			$this->csv( "\xEF\xBB\xBFsku,file_name,file_path\nA,Name,b/f.zip\n" )
		);

		$this->assertArrayHasKey( 'A', $grouped );
	}

	public function test_columns_may_be_in_any_order(): void {
		$grouped = Csv::grouped(
			$this->csv( "file_path,sku,file_name\nb/f.zip,A,Name\n" )
		);

		$this->assertSame( 'b/f.zip', $grouped['A'][0]['path'] );
		$this->assertSame( 'Name', $grouped['A'][0]['name'] );
	}

	public function test_a_missing_name_falls_back_to_the_filename(): void {
		$grouped = Csv::grouped(
			$this->csv( "sku,file_name,file_path\nA,,b/folder/report.pdf\n" )
		);

		$this->assertSame( 'report.pdf', $grouped['A'][0]['name'] );
	}

	public function test_blank_lines_are_ignored(): void {
		$grouped = Csv::grouped(
			$this->csv( "sku,file_name,file_path\nA,Name,b/f.zip\n\n\n" )
		);

		$this->assertCount( 1, $grouped['A'] );
	}

	public function test_the_line_number_is_kept_for_reporting(): void {
		$grouped = Csv::grouped(
			$this->csv( "sku,file_name,file_path\nA,One,b/1.zip\nA,Two,b/2.zip\n" )
		);

		$this->assertSame( 2, $grouped['A'][0]['line'] );
		$this->assertSame( 3, $grouped['A'][1]['line'] );
	}

	public function test_a_missing_required_column_is_refused(): void {
		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( 'file_path' );

		Csv::grouped( $this->csv( "sku,file_name\nA,Name\n" ) );
	}

	public function test_a_missing_reference_column_is_refused(): void {
		$this->expectException( InvalidArgumentException::class );

		Csv::grouped( $this->csv( "name,file_name,file_path\nA,Name,b/f.zip\n" ) );
	}

	public function test_a_row_without_a_path_is_refused_with_its_line(): void {
		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( 'Line 3' );

		Csv::grouped(
			$this->csv( "sku,file_name,file_path\nA,One,b/1.zip\nA,Two,\n" )
		);
	}

	public function test_an_empty_file_is_refused(): void {
		$this->expectException( InvalidArgumentException::class );

		Csv::grouped( $this->csv( '' ) );
	}

	public function test_an_unreadable_file_is_refused(): void {
		$this->expectException( InvalidArgumentException::class );

		Csv::grouped( '/no/such/file.csv' );
	}
}
