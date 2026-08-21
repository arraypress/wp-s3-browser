<?php
declare( strict_types=1 );

namespace ArrayPress\S3\Tests\Admin;

use ArrayPress\S3\Admin\Templates;
use PHPUnit\Framework\TestCase;

/**
 * Underscore templates for wp.template().
 *
 * The point of moving this markup out of JavaScript was to make escaping the
 * default rather than a per-interpolation decision, so that is what these
 * tests check — not the exact markup, which is free to change.
 */
final class TemplatesTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['test_user_can'] = true;
	}

	private function render(): string {
		$templates = new Templates( 'upload_files' );

		ob_start();
		$templates->print_templates();

		return (string) ob_get_clean();
	}

	public function test_templates_are_printed_with_expected_ids(): void {
		$output = $this->render();

		$this->assertStringContainsString( 'id="tmpl-s3-bucket-details"', $output );
		$this->assertStringContainsString( 'id="tmpl-s3-file-details"', $output );
		$this->assertStringContainsString( 'type="text/html"', $output );
	}

	/**
	 * Underscore escapes `{{ }}` and emits `{{{ }}}` raw. Nothing here should
	 * need the raw form: every interpolated value is provider- or user-derived.
	 */
	public function test_no_interpolation_bypasses_escaping(): void {
		$output = $this->render();

		$this->assertSame(
			0,
			preg_match_all( '/\{\{\{/', $output ),
			'Templates must not use the unescaped {{{ }}} form.'
		);
	}

	public function test_values_are_interpolated_through_the_escaping_form(): void {
		$output = $this->render();

		foreach ( [ 'data.bucket', 'data.cors.details', 'data.filename', 'data.key', 'data.etag' ] as $field ) {
			$this->assertMatchesRegularExpression(
				'/\{\{\s*' . preg_quote( $field, '/' ) . '\s*\}\}/',
				$output,
				$field . ' should be interpolated with the escaping form'
			);
		}
	}

	public function test_printing_twice_emits_one_copy(): void {
		$templates = new Templates( 'upload_files' );

		ob_start();
		$templates->print_templates();
		$templates->print_templates();
		$output = (string) ob_get_clean();

		$this->assertSame(
			1,
			substr_count( $output, 'id="tmpl-s3-bucket-details"' ),
			'Both admin_footer and admin_print_footer_scripts fire on a normal admin page; '
			. 'printing twice would give two elements the same id.'
		);
	}

	public function test_nothing_is_printed_without_the_capability(): void {
		$GLOBALS['test_user_can'] = false;

		$output = $this->render();

		$GLOBALS['test_user_can'] = true;

		$this->assertSame( '', $output );
	}
}
