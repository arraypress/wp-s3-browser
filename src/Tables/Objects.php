<?php
/**
 * S3 Objects List Table - Updated with Details Modal Support
 *
 * Displays S3 objects and folders in a WordPress-style table with pagination,
 * search functionality, file operations, and details modal support.
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
 * and file operations support including renaming, folder deletion, and details modal.
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
			'type'     => __( 'Type', 'arraypress' ),
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
				'mime'     => 'folder',
			];
		}

		// Add files with consolidated method calls
		foreach ( $objects as $object ) {
			$items[] = [
				'type'     => 'file',
				'name'     => $object->get_filename(),
				'key'      => $object->get_key(),
				'size'     => $object->get_size( true ), // Use consolidated method with formatting
				'modified' => $object->get_last_modified( true, 'M j, Y g:i A' ), // Use consolidated method with custom format
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
	 * Render the type column with WordPress-style tooltip
	 *
	 * @param array $item Item data
	 *
	 * @return string Column HTML
	 */
	public function column_type( array $item ): string {
		if ( $item['type'] === 'folder' ) {
			return '<span class="s3-type-folder">' . esc_html__( 'Folder', 'arraypress' ) . '</span>';
		}

		// For files, show the category with MIME type in tooltip
		$category = $item['object']->get_category();
		$mime_type = $item['mime'];
		$category_display = ucfirst( $category ); // image -> Image, video -> Video, etc.

		return sprintf(
			'<span class="s3-type-file s3-category-%s s3-has-tooltip" data-tooltip="%s">%s</span>',
			esc_attr( $category ),
			esc_attr( $mime_type ), // MIME type in tooltip
			esc_html( $category_display ) // Category as main text
		);
	}

	/**
	 * Render the modified column with relative time
	 *
	 * @param array $item Item data
	 *
	 * @return string Column HTML
	 */
	public function column_modified( array $item ): string {
		if ( $item['type'] === 'folder' || $item['modified'] === '-' ) {
			return '<span class="s3-no-date">-</span>';
		}

		// Get the object and add relative time
		$object = $item['object'];
		$formatted_date = $item['modified']; // Already formatted in prepare_items
		$raw_date = $object->get_last_modified(); // Get raw timestamp

		// Add relative time if we have a valid date
		$relative_time = '';
		if ( ! empty( $raw_date ) ) {
			$timestamp = strtotime( $raw_date );
			if ( $timestamp ) {
				$relative_time = sprintf(
					'<br><small class="description">%s</small>',
					esc_html( human_time_diff( $timestamp ) . ' ago' )
				);
			}
		}

		return $formatted_date . $relative_time;
	}

	/**
	 * Generate row actions for WordPress-style hover actions
	 *
	 * @param array $item Item data
	 *
	 * @return array Array of action links
	 */
	protected function get_row_actions( array $item ): array {
		$actions = [];

		if ( $item['type'] === 'folder' ) {
			// Folder actions
			$actions['delete'] = sprintf(
				'<a href="#" class="s3-delete-folder" data-folder-name="%s" data-bucket="%s" data-prefix="%s">%s</a>',
				esc_attr( $item['name'] ),
				esc_attr( $this->bucket ),
				esc_attr( $item['prefix'] ),
				esc_html__( 'Delete', 'arraypress' )
			);

		} else {
			// File actions
			$actions['details'] = sprintf(
				'<a href="#" class="s3-show-details" data-key="%s">%s</a>',
				esc_attr( $item['key'] ),
				esc_html__( 'Details', 'arraypress' )
			);

			$actions['rename'] = sprintf(
				'<a href="#" class="s3-rename-file" data-filename="%s" data-bucket="%s" data-key="%s">%s</a>',
				esc_attr( $item['name'] ),
				esc_attr( $this->bucket ),
				esc_attr( $item['key'] ),
				esc_html__( 'Rename', 'arraypress' )
			);

			// Copy Link action
			$actions['copy_link'] = sprintf(
				'<a href="#" class="s3-copy-link" data-filename="%s" data-bucket="%s" data-key="%s">%s</a>',
				esc_attr( $item['name'] ),
				esc_attr( $this->bucket ),
				esc_attr( $item['key'] ),
				esc_html__( 'Copy Link', 'arraypress' )
			);

			// Download link
			if ( isset( $item['object'] ) ) {
				$presigned_url = $item['object']->get_presigned_url( $this->client, $this->bucket, 60 );
				if ( ! is_wp_error( $presigned_url ) ) {
					$actions['download'] = sprintf(
						'<a href="#" class="s3-download-file" data-url="%s">%s</a>',
						esc_attr( $presigned_url ),
						esc_html__( 'Download', 'arraypress' )
					);
				}
			}

			$actions['delete'] = sprintf(
				'<a href="#" class="s3-delete-file button-delete" data-filename="%s" data-bucket="%s" data-key="%s">%s</a>',
				esc_attr( $item['name'] ),
				esc_attr( $this->bucket ),
				esc_attr( $item['key'] ),
				esc_html__( 'Delete', 'arraypress' )
			);
		}

		return $actions;
	}

	/**
	 * Render the actions column with Insert File button for files and Open button for folders
	 *
	 * @param array $item Item data
	 *
	 * @return string Column HTML
	 */
	public function column_actions( array $item ): string {
		if ( $item['type'] === 'file' ) {
			return sprintf(
				'<button type="button" class="button button-primary s3-insert-file" data-filename="%s" data-bucket="%s" data-key="%s" title="%s">
                <span class="dashicons dashicons-insert"></span> %s
            </button>',
				esc_attr( $item['name'] ),
				esc_attr( $this->bucket ),
				esc_attr( $item['key'] ),
				esc_attr__( 'Insert this file', 'arraypress' ),
				esc_html__( 'Insert File', 'arraypress' )
			);
		} elseif ( $item['type'] === 'folder' ) {
			return sprintf(
				'<button type="button" class="button button-secondary s3-open-folder" data-prefix="%s" data-bucket="%s" data-folder-name="%s" title="%s">
                <span class="dashicons dashicons-portfolio"></span> %s
            </button>',
				esc_attr( $item['prefix'] ),
				esc_attr( $this->bucket ),
				esc_attr( $item['name'] ),
				esc_attr__( 'Open this folder', 'arraypress' ),
				esc_html__( 'Open', 'arraypress' )
			);
		}

		return '';
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
	 * Render the name column with icon, link, and row actions
	 *
	 * @param array $item Item data
	 *
	 * @return string Column HTML
	 */
	public function column_name( array $item ): string {
		$actions = $this->get_row_actions( $item );

		if ( $item['type'] === 'folder' ) {
			$primary_content = sprintf(
				'<span class="dashicons dashicons-category s3-folder-icon"></span> <a href="#" class="s3-folder-link" data-prefix="%s" data-bucket="%s"><strong>%s</strong></a>',
				esc_attr( $item['prefix'] ),
				esc_attr( $this->bucket ),
				esc_html( $item['name'] )
			);
		} else {
			$icon_class = $item['object']->get_dashicon_class();

			$primary_content = sprintf(
				'<span class="dashicons %s"></span> <span class="s3-filename" data-original-name="%s" %s><strong>%s</strong></span>',
				esc_attr( $icon_class ),
				esc_attr( $item['name'] ),
				$item['object']->get_data_attributes(),
				esc_html( $item['name'] )
			);
		}

		return $primary_content . $this->row_actions( $actions );
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
                        <!-- WordPress native search input styling -->
                        <input type="search" id="s3-js-search" class="wp-filter-search"
                               placeholder="<?php esc_attr_e( 'Search files and folders...', 'arraypress' ); ?>"
                               autocomplete="off"/>
                        <button type="button" id="s3-js-search-clear" class="button button-secondary" style="display: none;">
							<?php esc_html_e( 'Clear', 'arraypress' ); ?>
                        </button>
                        <span class="s3-search-stats"></span>
                    </div>
                    <div class="s3-actions-container">
						<?php
						// WordPress native button styling
						printf(
							'<button type="button" class="button button-secondary s3-refresh-button" data-type="objects" data-bucket="%s" data-prefix="%s" data-provider="%s">
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
                        <!-- WordPress native load more button -->
                        <button type="button" id="s3-load-more" class="button button-secondary"
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
		$output .= '<td class="column-name has-row-actions">' . $this->column_name( $item ) . '</td>';
		$output .= '<td class="column-type">' . $this->column_type( $item ) . '</td>';
		$output .= '<td class="column-size">' . esc_html( $item['size'] ) . '</td>';
		$output .= '<td class="column-modified">' . $this->column_modified( $item ) . '</td>';
		$output .= '<td class="column-actions">' . $this->column_actions( $item ) . '</td>';
		$output .= '</tr>';

		return $output;
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