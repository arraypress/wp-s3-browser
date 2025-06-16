<?php
/**
 * S3 Admin Utility Trait
 *
 * Essential static methods for S3-based plugin admin interfaces.
 *
 * @package     ArrayPress\S3\Traits
 * @copyright   Copyright (c) 2025, ArrayPress Limited
 * @license     GPL2+
 * @version     1.0.0
 * @author      David Sherlock
 */

declare( strict_types=1 );

namespace ArrayPress\S3\Utils;

/**
 * Class Admin
 */
class Admin {

	/**
	 * Get default path formats
	 *
	 * @return array Default path format examples
	 */
	public static function get_default_path_formats(): array {
		return [
			'simple'      => [
				'example'     => 'my-bucket/file.zip',
				'description' => __( 'Simple bucket/file', 'arraypress' )
			],
			'nested'      => [
				'example'     => 'my-bucket/downloads/file.zip',
				'description' => __( 'With folder structure', 'arraypress' )
			],
			's3_protocol' => [
				'example'     => 's3://my-bucket/downloads/file.zip',
				'description' => __( 'With S3 protocol', 'arraypress' )
			],
		];
	}

	/**
	 * Render path format guide section
	 *
	 * @param array $additional_formats Additional formats to merge with defaults
	 */
	public static function render_path_format_guide( array $additional_formats = [] ): void {
		$formats = array_merge( self::get_default_path_formats(), $additional_formats );
		?>
        <div class="s3-path-guide">
            <h4><?php esc_html_e( 'Supported Path Formats:', 'arraypress' ); ?></h4>
            <ul class="s3-path-formats">
				<?php foreach ( $formats as $format_data ) : ?>
                    <li>
                        <code><?php echo esc_html( $format_data['example'] ); ?></code>
                        <span class="path-description"><?php echo esc_html( $format_data['description'] ); ?></span>
                    </li>
				<?php endforeach; ?>
            </ul>
        </div>
		<?php
	}

	/**
	 * Render connection test section
	 *
	 * @param string $button_id Button ID
	 * @param string $result_id Result span ID
	 */
	public static function render_connection_test( string $button_id, string $result_id ): void {
		?>
        <div class="s3-connection-test">
            <h4><?php esc_html_e( 'Connection Test', 'arraypress' ); ?></h4>
            <p>
                <button type="button" id="<?php echo esc_attr( $button_id ); ?>" class="button s3-test-connection">
					<?php esc_html_e( 'Test Connection', 'arraypress' ); ?>
                </button>
                <span id="<?php echo esc_attr( $result_id ); ?>" class="s3-test-result"></span>
            </p>
        </div>
		<?php
	}

	/**
	 * Render complete validation field (path guide + connection test)
	 *
	 * @param string $title Field title
	 * @param string $button_id Connection test button ID
	 * @param string $result_id Connection test result ID
	 * @param bool $show_test Whether to show connection test
	 */
	public static function render_validation_field( string $title, string $button_id, string $result_id, bool $show_test = true ): void {
		?>
        <tr valign="top">
            <th scope="row" class="titledesc">
				<?php echo esc_html( $title ); ?>
            </th>
            <td class="forminp">
                <div class="s3-validation-field">
					<?php self::render_path_format_guide(); ?>
					<?php if ( $show_test ): ?>
						<?php self::render_connection_test( $button_id, $result_id ); ?>
					<?php endif; ?>
                </div>
            </td>
        </tr>
		<?php
	}

}