<?php
/**
 * S3 Objects List Table
 *
 * Displays S3 objects and folders in a WordPress-style table with pagination,
 * search functionality, and file operations.
 *
 * @package     ArrayPress\S3\Tables
 * @copyright   Copyright (c) 2025, ArrayPress Limited
 * @license     GPL2+
 * @version     1.0.0
 * @author      David Sherlock
 */

declare( strict_types=1 );

namespace ArrayPress\S3\Tables;

use Exception;
use WP_Error;
use WP_List_Table;
use ArrayPress\S3\Client;

/**
 * Class Objects
 *
 * Extends WP_List_Table to display S3 objects and prefixes with proper pagination
 * and file operations support.
 */
class Objects extends WP_List_Table {

	/**
	 * S3 Client instance
	 *
	 * @var Client
	 */
	private Client $client;

	/**
	 * Current S3 bucket name
	 *
	 * @var string
	 */
	private string $bucket;

	/**
	 * Current prefix (folder path)
	 *
	 * @var string
	 */
	private string $prefix;

	/**
	 * Storage provider ID
	 *
	 * @var string
	 */
	private string $provider_id;

	/**
	 * Cached API result to avoid multiple requests
	 *
	 * @var array|WP_Error|null
	 */
	private $api_result = null;

	/**
	 * Number of items per page
	 *
	 * @var int
	 */
	private int $per_page;

	/**
	 * Constructor
	 *
	 * @param array $args        {
	 *                           Configuration arguments.
	 *
	 * @type Client $client      S3 client instance.
	 * @type string $bucket      Bucket name.
	 * @type string $prefix      Optional. Object prefix/folder path. Default empty.
	 * @type string $provider_id Provider identifier.
	 * @type int    $per_page    Optional. Items per page. Default 1000.
	 *                           }
	 */
	public function __construct( array $args = [] ) {
		parent::__construct( [
			'singular' => 'object',
			'plural'   => 'objects',
			'ajax'     => true,
			'screen'   => null
		] );

		$this->client      = $args['client'];
		$this->bucket      = $args['bucket'];
		$this->prefix      = $args['prefix'] ?? '';
		$this->provider_id = $args['provider_id'];
		$this->per_page    = $args['per_page'] ?? 1000;
	}

	/**
	 * Get table columns
	 *
	 * @return array Column definitions
	 */
	public function get_columns(): array {
		return [
			'name'     => __( 'Name', 'arraypress' ),
			'size'     => __( 'Size', 'arraypress' ),
			'modified' => __( 'Last Modified', 'arraypress' ),
			'actions'  => __( 'Actions', 'arraypress' ),
		];
	}

	/**
	 * Get API results from S3 with caching
	 *
	 * @return array|WP_Error API results or error object
	 */
	private function get_api_results() {
		if ( $this->api_result !== null ) {
			return $this->api_result;
		}

		$continuation_token = isset( $_REQUEST['continuation_token'] )
			? sanitize_text_field( $_REQUEST['continuation_token'] )
			: '';

		$result = $this->client->get_object_models(
			$this->bucket,
			$this->per_page,
			$this->prefix,
			'/',
			$continuation_token
		);

		$this->api_result = $result->is_successful()
			? $result->get_data()
			: new WP_Error( $result->get_error_code(), $result->get_error_message() );

		return $this->api_result;
	}

	/**
	 * Prepare table items for display
	 *
	 * Fetches data from S3 API and formats it for table display.
	 *
	 * @return void
	 */
	public function prepare_items(): void {
		$this->_column_headers = [
			$this->get_columns(),
			[],
			[]
		];

		$result = $this->get_api_results();

		if ( is_wp_error( $result ) ) {
			$this->items = [];

			return;
		}

		$objects  = $result['objects'] ?? [];
		$prefixes = $result['prefixes'] ?? [];
		$items    = [];

		// Add folders first
		foreach ( $prefixes as $folder ) {
			$items[] = [
				'type'     => 'folder',
				'name'     => $folder->get_folder_name(),
				'prefix'   => $folder->get_prefix(),
				'size'     => '-',
				'modified' => '-',
			];
		}

		// Add files
		foreach ( $objects as $object ) {
			$items[] = [
				'type'     => 'file',
				'name'     => $object->get_filename(),
				'key'      => $object->get_key(),
				'size'     => $object->get_formatted_size(),
				'modified' => $object->get_formatted_date(),
				'mime'     => $object->get_mime_type(),
				'object'   => $object,
			];
		}

		$this->items = $items;

		$this->set_pagination_args( [
			'total_items' => count( $items ),
			'per_page'    => $this->per_page,
			'total_pages' => isset( $result['truncated'] ) && $result['truncated'] ? 2 : 1,
		] );

		// Store continuation token for AJAX loading
		if ( isset( $result['truncated'] ) && $result['truncated'] && ! empty( $result['continuation_token'] ) ) {
			$this->_pagination_args['continuation_token'] = $result['continuation_token'];
		}
	}

	/**
	 * Display table navigation (search, pagination)
	 *
	 * @param string $which Position: 'top' or 'bottom'
	 *
	 * @return void
	 */
	protected function display_tablenav( $which ): void {
		?>
        <div class="tablenav <?php echo esc_attr( $which ); ?>">
			<?php if ( $which === 'top' ): ?>
                <div class="s3-top-nav">
                    <div class="s3-search-container">
                        <input type="search" id="s3-js-search"
                               placeholder="<?php esc_attr_e( 'Search files and folders...', 'arraypress' ); ?>"
                               autocomplete="off"/>
                        <button type="button" id="s3-js-search-clear" class="button" style="display: none;">
							<?php esc_html_e( 'Clear', 'arraypress' ); ?>
                        </button>
                        <span class="s3-search-stats"></span>
                    </div>
                    <div class="s3-actions-container">
						<?php
						printf(
							'<button type="button" class="button s3-icon-button s3-refresh-button" data-type="objects" data-bucket="%s" data-prefix="%s" data-provider="%s">
                            <span class="dashicons dashicons-update"></span> %s
                        </button>',
							esc_attr( $this->bucket ),
							esc_attr( $this->prefix ),
							esc_attr( $this->provider_id ),
							esc_html__( 'Refresh', 'arraypress' )
						);
						?>
                    </div>
                </div>
			<?php else: ?>
                <div class="tablenav-pages">
                    <span class="displaying-num" id="s3-total-count">
                        <?php
                        $count    = count( $this->items );
                        $has_more = isset( $this->_pagination_args['continuation_token'] ) && $this->_pagination_args['continuation_token'];

                        echo esc_html( sprintf(
	                        _n( '%s item', '%s items', $count, 'arraypress' ),
	                        number_format_i18n( $count )
                        ) );

                        if ( $has_more ) {
	                        echo esc_html__( ' (more available)', 'arraypress' );
                        }
                        ?>
                    </span>
					<?php if ( isset( $this->_pagination_args['continuation_token'] ) && $this->_pagination_args['continuation_token'] ): ?>
                        <span class="pagination-links">
                            <button type="button" id="s3-load-more" class="button button-secondary s3-icon-button"
                                    data-token="<?php echo esc_attr( $this->_pagination_args['continuation_token'] ); ?>"
                                    data-bucket="<?php echo esc_attr( $this->bucket ); ?>"
                                    data-prefix="<?php echo esc_attr( $this->prefix ); ?>"
                                    data-provider="<?php echo esc_attr( $this->provider_id ); ?>">
                                <span class="dashicons dashicons-update"></span>
                                <span class="s3-button-text"><?php esc_html_e( 'Load More Items', 'arraypress' ); ?></span>
                                <span class="spinner" style="display: none;"></span>
                            </button>
                            <span class="s3-load-status"></span>
                        </span>
					<?php endif; ?>
                </div>
			<?php endif; ?>
            <br class="clear"/>
        </div>
		<?php
	}

	/**
	 * Handle AJAX load more request
	 *
	 * @param Client $client      S3 client instance
	 * @param string $provider_id Provider identifier
	 *
	 * @return void
	 */
	public static function ajax_load_more( Client $client, string $provider_id ): void {
		if ( ! check_ajax_referer( 's3_browser_nonce_' . $provider_id, 'nonce', false ) ) {
			wp_die( 'Security check failed', 'Error', [ 'response' => 403 ] );
		}

		$bucket             = sanitize_text_field( $_POST['bucket'] ?? '' );
		$prefix             = sanitize_text_field( $_POST['prefix'] ?? '' );
		$continuation_token = sanitize_text_field( $_POST['continuation_token'] ?? '' );

		if ( empty( $bucket ) ) {
			wp_send_json_error( [ 'message' => 'Bucket parameter is required' ] );
		}

		$table = new self( [
			'client'      => $client,
			'bucket'      => $bucket,
			'prefix'      => $prefix,
			'provider_id' => $provider_id,
			'per_page'    => 1000
		] );

		$_REQUEST['continuation_token'] = $continuation_token;

		try {
			$table->prepare_items();

			$html = '';
			foreach ( $table->items as $item ) {
				$html .= $table->render_row( $item );
			}

			wp_send_json_success( [
				'items'              => $table->items,
				'has_more'           => isset( $table->_pagination_args['continuation_token'] ),
				'continuation_token' => $table->_pagination_args['continuation_token'] ?? '',
				'html'               => $html,
				'count'              => count( $table->items )
			] );

		} catch ( Exception $e ) {
			wp_send_json_error( [
				'message' => 'Error loading more items: ' . $e->getMessage()
			] );
		}
	}

	/**
	 * Render a single table row
	 *
	 * @param array $item Item data
	 *
	 * @return string HTML for table row
	 */
	public function render_row( array $item ): string {
		$output = '<tr>';
		$output .= '<td class="column-name">' . $this->column_name( $item ) . '</td>';
		$output .= '<td class="column-size">' . esc_html( $item['size'] ) . '</td>';
		$output .= '<td class="column-modified">' . esc_html( $item['modified'] ) . '</td>';
		$output .= '<td class="column-actions">' . $this->column_actions( $item ) . '</td>';
		$output .= '</tr>';

		return $output;
	}

	/**
	 * Default column renderer
	 *
	 * @param array  $item        Item data
	 * @param string $column_name Column identifier
	 *
	 * @return string Column content
	 */
	public function column_default( $item, $column_name ): string {
		return $item[ $column_name ] ?? '';
	}

	/**
	 * Render the name column with icon and link
	 *
	 * @param array $item Item data
	 *
	 * @return string Column HTML
	 */
	public function column_name( array $item ): string {
		if ( $item['type'] === 'folder' ) {
			return sprintf(
				'<span class="dashicons dashicons-category s3-folder-icon"></span> <a href="#" class="s3-folder-link" data-prefix="%s" data-bucket="%s">%s</a>',
				esc_attr( $item['prefix'] ),
				esc_attr( $this->bucket ),
				esc_html( $item['name'] )
			);
		}

		$icon_class = $item['object']->get_dashicon_class();

		return sprintf(
			'<span class="dashicons %s"></span> %s',
			esc_attr( $icon_class ),
			esc_html( $item['name'] )
		);
	}

	/**
	 * Render the actions column with buttons
	 *
	 * @param array $item Item data
	 *
	 * @return string Actions HTML
	 */
	public function column_actions( array $item ): string {
		if ( $item['type'] === 'folder' ) {
			$url = add_query_arg( [
				'chromeless' => 1,
				'post_id'    => isset( $_REQUEST['post_id'] ) ? intval( $_REQUEST['post_id'] ) : 0,
				'tab'        => 's3_' . $this->provider_id,
				'bucket'     => $this->bucket,
				'prefix'     => $item['prefix']
			], remove_query_arg( [ 'continuation_token' ] ) );

			return sprintf(
				'<a href="%s" class="button s3-icon-button"><span class="dashicons dashicons-external"></span>%s</a>',
				esc_url( $url ),
				esc_html__( 'Open', 'arraypress' )
			);
		}

		// File actions
		$actions = sprintf(
			'<a href="#" class="button s3-icon-button s3-select-file" data-filename="%s" data-bucket="%s" data-key="%s"><span class="dashicons dashicons-insert"></span>%s</a>',
			esc_attr( $item['name'] ),
			esc_attr( $this->bucket ),
			esc_attr( $item['key'] ),
			esc_html__( 'Select', 'arraypress' )
		);

		// Download link
		if ( isset( $item['object'] ) ) {
			$presigned_url = $item['object']->get_presigned_url( $this->client, $this->bucket, 60 );
			if ( ! is_wp_error( $presigned_url ) ) {
				$actions .= sprintf(
					' <a href="#" class="button s3-icon-button s3-download-file" data-url="%s"><span class="dashicons dashicons-download"></span>%s</a>',
					esc_attr( $presigned_url ),
					esc_html__( 'Download', 'arraypress' )
				);
			}
		}

		// Delete button
		$actions .= sprintf(
			' <a href="#" class="button s3-icon-button s3-delete-file button-delete" data-filename="%s" data-bucket="%s" data-key="%s"><span class="dashicons dashicons-trash"></span>%s</a>',
			esc_attr( $item['name'] ),
			esc_attr( $this->bucket ),
			esc_attr( $item['key'] ),
			esc_html__( 'Delete', 'arraypress' )
		);

		return $actions;
	}

	/**
	 * Message displayed when no items are found
	 *
	 * @return void
	 */
	public function no_items(): void {
		echo esc_html__( 'No files or folders found.', 'arraypress' );
	}

}