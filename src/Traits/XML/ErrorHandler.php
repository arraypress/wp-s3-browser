<?php
/**
 * XML Error Handler Trait
 *
 * Provides error handling and debugging functionality for XML operations.
 *
 * @package     ArrayPress\S3\Traits\XML
 * @copyright   Copyright (c) 2025, ArrayPress Limited
 * @license     GPL2+
 * @version     1.0.0
 * @author      David Sherlock
 */

declare( strict_types=1 );

namespace ArrayPress\S3\Traits\XML;

/**
 * Trait ErrorHandler
 */
trait ErrorHandler {

	/**
	 * Debug log XML parsing errors
	 *
	 * @param array  $errors     Array of LibXMLError objects
	 * @param string $xml_string The XML string that failed to parse
	 */
	protected function debug_log_errors( array $errors, string $xml_string ): void {
		$error_messages = [];

		foreach ( $errors as $error ) {
			$error_type    = $this->get_error_type( $error->level );
			$error_line    = $error->line;
			$error_column  = $error->column;
			$error_message = trim( $error->message );

			$error_messages[] = sprintf(
				"%s (Line %d, Column %d): %s",
				$error_type,
				$error_line,
				$error_column,
				$error_message
			);

			// Try to extract the problematic XML fragment
			if ( $error_line > 0 ) {
				$lines      = explode( "\n", $xml_string );
				$line_index = $error_line - 1;

				if ( isset( $lines[ $line_index ] ) ) {
					$context_start = max( 0, $line_index - 2 );
					$context_end   = min( count( $lines ) - 1, $line_index + 2 );
					$context       = [];

					for ( $i = $context_start; $i <= $context_end; $i ++ ) {
						$line_number = $i + 1;
						$prefix      = ( $i === $line_index ) ? ">> " : "   ";
						$context[]   = sprintf( "%s%d: %s", $prefix, $line_number, $lines[ $i ] );
					}

					$error_messages[] = "XML Context:\n" . implode( "\n", $context );
				}
			}
		}

		// Log the combined error information
		$this->debug( 'XML Parsing Errors', implode( "\n", $error_messages ) );
	}

	/**
	 * Get XML error type string
	 *
	 * @param int $level LibXML error level
	 *
	 * @return string Error type description
	 */
	private function get_error_type( int $level ): string {
		switch ( $level ) {
			case LIBXML_ERR_WARNING:
				return 'Warning';
			case LIBXML_ERR_ERROR:
				return 'Error';
			case LIBXML_ERR_FATAL:
				return 'Fatal Error';
			default:
				return 'Unknown';
		}
	}

}