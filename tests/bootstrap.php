<?php
/**
 * Test bootstrap.
 *
 * These are unit tests for the pure, WordPress-independent parts of the
 * library — signing, encoding, URL provenance. WordPress functions the code
 * under test touches incidentally are stubbed rather than loaded, so the suite
 * runs in milliseconds with no database and no WordPress install.
 */

declare( strict_types=1 );

/*
 * A throwaway WordPress root.
 *
 * Browser.php does `require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php'`
 * at file scope, so the class cannot be loaded — and therefore its traits
 * cannot be linked — without that file existing. Providing a stub lets the
 * suite assert that every trait a class composes actually resolves, which
 * neither `php -l` nor an autoloader findFile() check can tell you: both
 * confirm a file exists without ever executing the class declaration.
 */
if ( ! defined( 'ABSPATH' ) ) {
	$wp_stub_root = sys_get_temp_dir() . '/wp-s3-browser-tests/';

	if ( ! is_dir( $wp_stub_root . 'wp-admin/includes' ) ) {
		mkdir( $wp_stub_root . 'wp-admin/includes', 0777, true );
	}

	file_put_contents(
		$wp_stub_root . 'wp-admin/includes/class-wp-list-table.php',
		"<?php\nif ( ! class_exists( 'WP_List_Table' ) ) {\n"
		. "\tclass WP_List_Table {\n"
		. "\t\tpublic \$items = [];\n"
		. "\t\tprotected \$_pagination_args = [];\n"
		. "\t\tpublic function __construct( \$args = [] ) {}\n"
		. "\t}\n}\n"
	);

	define( 'ABSPATH', $wp_stub_root );
}

if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
	define( 'HOUR_IN_SECONDS', 3600 );
}

if ( ! function_exists( '__' ) ) {
	function __( string $text, string $domain = 'default' ): string {
		return $text;
	}
}

if ( ! function_exists( '_n' ) ) {
	function _n( string $single, string $plural, int $number, string $domain = 'default' ): string {
		return 1 === $number ? $single : $plural;
	}
}

if ( ! function_exists( 'esc_html' ) ) {
	function esc_html( string $text ): string {
		return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );
	}
}

if ( ! function_exists( 'esc_attr' ) ) {
	function esc_attr( string $text ): string {
		return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );
	}
}

if ( ! function_exists( 'sanitize_key' ) ) {
	function sanitize_key( string $key ): string {
		return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $key ) );
	}
}

if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( string $hook, $value, ...$args ) {
		return $value;
	}
}

require_once __DIR__ . '/../vendor/autoload.php';
