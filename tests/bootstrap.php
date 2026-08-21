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
		. "\t\tpublic function set_pagination_args( \$args ) { \$this->_pagination_args = \$args; }\n"
		. "\t\tpublic function row_actions( \$actions, \$always_visible = false ) { return ''; }\n"
		. "\t\tpublic function get_pagenum() { return 1; }\n"
		. "\t\tpublic function display() {}\n"
		. "\t\tpublic function get_columns() { return []; }\n"
		. "\t\tpublic function prepare_items() {}\n"
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

/*
 * REST plumbing stubs. register_rest_route() records what it was asked to
 * register so tests can assert route shape and — critically — that every
 * route carries a permission_callback.
 */
$GLOBALS['registered_rest_routes'] = [];

if ( ! function_exists( 'register_rest_route' ) ) {
	function register_rest_route( string $namespace, string $route, array $args = [], bool $override = false ): bool {
		$GLOBALS['registered_rest_routes'][] = [
			'namespace' => $namespace,
			'route'     => $route,
			'args'      => $args,
		];

		return true;
	}
}

if ( ! function_exists( 'rest_authorization_required_code' ) ) {
	function rest_authorization_required_code(): int {
		return 401;
	}
}

if ( ! function_exists( '_doing_it_wrong' ) ) {
	function _doing_it_wrong( string $function, string $message, string $version ): void {
		$GLOBALS['doing_it_wrong'][] = $function;
	}
}

if ( ! function_exists( 'esc_html__' ) ) {
	function esc_html__( string $text, string $domain = 'default' ): string {
		return $text;
	}
}

if ( ! function_exists( 'current_user_can' ) ) {
	function current_user_can( string $capability ): bool {
		return $GLOBALS['test_user_can'] ?? true;
	}
}

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		public string $code;
		public string $message;
		public array $data;

		public function __construct( string $code = '', string $message = '', array $data = [] ) {
			$this->code    = $code;
			$this->message = $message;
			$this->data    = $data;
		}

		public function get_error_code(): string {
			return $this->code;
		}

		public function get_error_message(): string {
			return $this->message;
		}

		public function get_error_data(): array {
			return $this->data;
		}
	}
}

if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( $thing ): bool {
		return $thing instanceof WP_Error;
	}
}

if ( ! class_exists( 'WP_REST_Request' ) ) {
	/**
	 * Minimal stand-in. The routes read parameters via ArrayAccess, which is
	 * how WP_REST_Request exposes them.
	 */
	class WP_REST_Request implements ArrayAccess {
		private array $params = [];

		public function set_param( string $key, $value ): void {
			$this->params[ $key ] = $value;
		}

		public function get_param( string $key ) {
			return $this->params[ $key ] ?? null;
		}

		public function offsetExists( $offset ): bool {
			return isset( $this->params[ $offset ] );
		}

		#[\ReturnTypeWillChange]
		public function offsetGet( $offset ) {
			return $this->params[ $offset ] ?? null;
		}

		public function offsetSet( $offset, $value ): void {
			$this->params[ $offset ] = $value;
		}

		public function offsetUnset( $offset ): void {
			unset( $this->params[ $offset ] );
		}
	}
}

if ( ! function_exists( 'esc_html_e' ) ) {
	function esc_html_e( string $text, string $domain = 'default' ): void {
		echo htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );
	}
}

if ( ! function_exists( 'esc_attr_e' ) ) {
	function esc_attr_e( string $text, string $domain = 'default' ): void {
		echo htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );
	}
}

if ( ! function_exists( '_e' ) ) {
	function _e( string $text, string $domain = 'default' ): void {
		echo $text;
	}
}

if ( ! function_exists( 'esc_js' ) ) {
	function esc_js( string $text ): string {
		return addcslashes( $text, "'\"\\\n\r" );
	}
}

/*
 * WordPress HTTP API, backed by FakeHttp so a request can be driven through
 * the whole stack against a recorded provider payload.
 */
require_once __DIR__ . '/Support/FakeHttp.php';

use ArrayPress\S3\Tests\Support\FakeHttp;

if ( ! function_exists( 'wp_remote_request' ) ) {
	function wp_remote_request( string $url, array $args = [] ) {
		return FakeHttp::respond( $url, $args );
	}
}

if ( ! function_exists( 'wp_remote_get' ) ) {
	function wp_remote_get( string $url, array $args = [] ) {
		return FakeHttp::respond( $url, $args + [ 'method' => 'GET' ] );
	}
}

if ( ! function_exists( 'wp_remote_head' ) ) {
	function wp_remote_head( string $url, array $args = [] ) {
		return FakeHttp::respond( $url, $args + [ 'method' => 'HEAD' ] );
	}
}

if ( ! function_exists( 'wp_remote_post' ) ) {
	function wp_remote_post( string $url, array $args = [] ) {
		return FakeHttp::respond( $url, $args + [ 'method' => 'POST' ] );
	}
}

if ( ! function_exists( 'wp_remote_retrieve_response_code' ) ) {
	function wp_remote_retrieve_response_code( $response ) {
		return $response['response']['code'] ?? 0;
	}
}

if ( ! function_exists( 'wp_remote_retrieve_body' ) ) {
	function wp_remote_retrieve_body( $response ): string {
		return (string) ( $response['body'] ?? '' );
	}
}

if ( ! function_exists( 'wp_remote_retrieve_headers' ) ) {
	function wp_remote_retrieve_headers( $response ) {
		return $response['headers'] ?? [];
	}
}

if ( ! function_exists( 'wp_remote_retrieve_header' ) ) {
	function wp_remote_retrieve_header( $response, string $header ) {
		foreach ( $response['headers'] ?? [] as $name => $value ) {
			if ( 0 === strcasecmp( $name, $header ) ) {
				return $value;
			}
		}

		return '';
	}
}

/* Transients, backed by the same in-memory option store. */
if ( ! function_exists( 'get_transient' ) ) {
	function get_transient( string $key ) {
		return $GLOBALS['wp_test_options'][ 'transient_' . $key ] ?? false;
	}
}

if ( ! function_exists( 'set_transient' ) ) {
	function set_transient( string $key, $value, int $ttl = 0 ): bool {
		$GLOBALS['wp_test_options'][ 'transient_' . $key ] = $value;

		return true;
	}
}

if ( ! function_exists( 'delete_transient' ) ) {
	function delete_transient( string $key ): bool {
		unset( $GLOBALS['wp_test_options'][ 'transient_' . $key ] );

		return true;
	}
}

if ( ! function_exists( 'get_option' ) ) {
	function get_option( string $key, $default = false ) {
		return $GLOBALS['wp_test_options'][ $key ] ?? $default;
	}
}

if ( ! function_exists( 'update_option' ) ) {
	function update_option( string $key, $value, $autoload = null ): bool {
		$GLOBALS['wp_test_options'][ $key ] = $value;

		return true;
	}
}

if ( ! function_exists( 'wp_using_ext_object_cache' ) ) {
	function wp_using_ext_object_cache(): bool {
		return false;
	}
}

if ( ! function_exists( 'number_format_i18n' ) ) {
	function number_format_i18n( $number, int $decimals = 0 ): string {
		return number_format( (float) $number, $decimals );
	}
}

if ( ! isset( $GLOBALS['wp_test_options'] ) ) {
	$GLOBALS['wp_test_options'] = [];
}

if ( ! function_exists( 'get_bloginfo' ) ) {
	function get_bloginfo( string $show = '' ): string {
		return 'version' === $show ? '7.1' : 'Test Site';
	}
}

if ( ! function_exists( 'home_url' ) ) {
	function home_url( string $path = '' ): string {
		return 'https://example.test' . $path;
	}
}

if ( ! function_exists( 'size_format' ) ) {
	function size_format( $bytes, int $decimals = 0 ) {
		$units = [ 'B', 'KB', 'MB', 'GB', 'TB' ];
		$bytes = (float) $bytes;
		$i     = $bytes > 0 ? (int) floor( log( $bytes, 1024 ) ) : 0;
		$i     = min( $i, count( $units ) - 1 );

		return round( $bytes / ( 1024 ** $i ), $decimals ) . ' ' . $units[ $i ];
	}
}

if ( ! function_exists( 'human_time_diff' ) ) {
	function human_time_diff( int $from, int $to = 0 ): string {
		return '2 days';
	}
}

if ( ! function_exists( 'current_time' ) ) {
	function current_time( string $type = 'timestamp', $gmt = 0 ) {
		return 'timestamp' === $type ? time() : gmdate( 'Y-m-d H:i:s' );
	}
}

if ( ! function_exists( 'date_i18n' ) ) {
	function date_i18n( string $format, $timestamp = false ): string {
		return gmdate( $format, $timestamp ?: time() );
	}
}

if ( ! function_exists( 'wp_check_filetype' ) ) {
	function wp_check_filetype( string $filename, $mimes = null ): array {
		$map = [
			'mp3' => 'audio/mpeg', 'wav' => 'audio/wav', 'zip' => 'application/zip',
			'jpg' => 'image/jpeg', 'png' => 'image/png', 'pdf' => 'application/pdf',
			'txt' => 'text/plain', 'mp4' => 'video/mp4',
		];
		$ext = strtolower( pathinfo( $filename, PATHINFO_EXTENSION ) );

		return [ 'ext' => $ext ?: false, 'type' => $map[ $ext ] ?? false ];
	}
}

if ( ! function_exists( 'wp_get_mime_types' ) ) {
	function wp_get_mime_types(): array {
		return [ 'mp3' => 'audio/mpeg', 'wav' => 'audio/wav', 'zip' => 'application/zip' ];
	}
}

if ( ! function_exists( 'sanitize_file_name' ) ) {
	function sanitize_file_name( string $name ): string {
		return preg_replace( '/[^A-Za-z0-9._-]/', '-', $name );
	}
}

require_once __DIR__ . '/../vendor/autoload.php';

if ( ! function_exists( 'wp_generate_password' ) ) {
	function wp_generate_password( int $length = 12, bool $special_chars = true ): string {
		$alphabet = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
		$password = '';

		for ( $i = 0; $i < $length; $i ++ ) {
			$password .= $alphabet[ random_int( 0, strlen( $alphabet ) - 1 ) ];
		}

		return $password;
	}
}

if ( ! defined( 'DAY_IN_SECONDS' ) ) {
	define( 'DAY_IN_SECONDS', 86400 );
}

if ( ! function_exists( 'get_allowed_mime_types' ) ) {
	function get_allowed_mime_types( $user = null ): array {
		return [
			'jpg|jpeg|jpe' => 'image/jpeg',
			'png'          => 'image/png',
			'gif'          => 'image/gif',
			'pdf'          => 'application/pdf',
			'txt'          => 'text/plain',
			'zip'          => 'application/zip',
			'mp4'          => 'video/mp4',
			'mp3'          => 'audio/mpeg',
		];
	}
}
