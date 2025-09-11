<?php
/**
 * S3 Buckets List Table - Simplified Row Actions
 *
 * @package     ArrayPress\S3\Tables
 * @copyright   Copyright (c) 2025, ArrayPress Limited
 * @license     GPL2+
 * @version     1.0.0
 * @author      David Sherlock
 */

declare( strict_types=1 );

namespace ArrayPress\S3\Tables;

use WP_List_Table;

/**
 * S3 Buckets List Table
 */
class Buckets extends WP_List_Table {

	/**
	 * Client instance
	 */
	private $client;

	/**
	 * Provider ID
	 */
	private $provider_id;

	/**
	 * Constructor
	 *
	 * @param array $args Additional args
	 */
	public function __construct( $args = array() ) {
		parent::__construct( [
			'singular' => 'bucket',
			'plural'   => 'buckets',
			'ajax'     => false,
		] );

		$this->client      = $args['client'];
		$this->provider_id = $args['provider_id'];
	}

	/**
	 * Get columns
	 */
	public function get_columns() {
		return [
			'name'    => __( 'Bucket Name', 'arraypress' ),
			'created' => __( 'Creation Date', 'arraypress' ),
			'actions' => __( 'Favorite', 'arraypress' ),
		];
	}

	/**
	 * Prepare items
	 */
	public function prepare_items() {
		$this->_column_headers = [ $this->get_columns(), [], [] ];

		$marker = isset( $_GET['marker'] ) ? sanitize_text_field( $_GET['marker'] ) : '';

		// Get buckets (1000 is the maximum)
		$result = $this->client->get_bucket_models( 1000, '', $marker, true );

		if ( ! $result->is_successful() ) {
			echo '<div class="notice notice-error"><p>' . esc_html( $result->get_error_message() ) . '</p></div>';
			$this->items = [];

			return;
		}

		$data    = $result->get_data();
		$buckets = $data['buckets'];
		$items   = [];

		foreach ( $buckets as $bucket ) {
			$items[] = [
				'name'    => $bucket->get_name(),
				'created' => $bucket->get_creation_date( true, 'M j, Y g:i A' ),
				'raw'     => $bucket,
			];
		}

		$this->items = $items;

		// Store pagination info
		$this->set_pagination_args( [
			'total_items' => count( $items ),
			'per_page'    => 1000,
			'total_pages' => $data['truncated'] ? 2 : 1,
		] );

		// Store the marker for next page link
		if ( $data['truncated'] && ! empty( $data['next_marker'] ) ) {
			$this->_pagination_args['marker'] = $data['next_marker'];
		}
	}

	/**
	 * Column default
	 */
	public function column_default( $item, $column_name ) {
		return $item[ $column_name ] ?? '';
	}

	/**
	 * Column name - Now clickable with proper attributes
	 */
	public function column_name( $item ) {
		$bucket  = $item['name'];
		$actions = $this->get_row_actions( $item );

		$primary_content = sprintf(
			'<span class="dashicons dashicons-database"></span> <a href="#" class="bucket-name" data-bucket="%s"><strong>%s</strong></a>',
			esc_attr( $bucket ),
			esc_html( $bucket )
		);

		return $primary_content . $this->row_actions( $actions );
	}

	/**
	 * Column created - Display formatted creation date with relative time
	 */
	public function column_created( $item ) {
		$bucket         = $item['raw']; // Get the S3Bucket model object
		$formatted_date = $item['created']; // Already formatted in prepare_items
		$raw_date       = $bucket->get_creation_date(); // Get raw timestamp

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
	 * Column actions - Keep existing star favoriting system
	 */
	public function column_actions( $item ) {
		$bucket    = $item['name'];
		$post_id   = isset( $_REQUEST['post_id'] ) ? intval( $_REQUEST['post_id'] ) : 0;
		$post_type = $post_id ? get_post_type( $post_id ) : 'default';

		// Get current user ID
		$user_id = get_current_user_id();

		// Check if this bucket is a favorite
		$meta_key        = "s3_favorite_{$this->provider_id}_{$post_type}";
		$favorite_bucket = get_user_meta( $user_id, $meta_key, true );
		$is_favorite     = ( $favorite_bucket === $bucket );

		// Create a favorite star with the appropriate icon and tooltip
		$favorite_class  = $is_favorite ? 'dashicons-star-filled s3-favorite-active' : 'dashicons-star-empty';
		$favorite_title  = $is_favorite ? __( 'Remove as default bucket', 'arraypress' ) : __( 'Set as default bucket', 'arraypress' );
		$favorite_action = $is_favorite ? 'remove' : 'add';

		return sprintf(
			'<span class="s3-favorite-star dashicons %s s3-favorite-bucket" data-bucket="%s" data-provider="%s" data-action="%s" data-post-type="%s" title="%s"></span>',
			esc_attr( $favorite_class ),
			esc_attr( $bucket ),
			esc_attr( $this->provider_id ),
			esc_attr( $favorite_action ),
			esc_attr( $post_type ),
			esc_attr( $favorite_title )
		);
	}

	/**
	 * Generate row actions - SIMPLIFIED to just Browse and Details
	 *
	 * @param array $item Item data
	 *
	 * @return array Array of action links
	 */
	protected function get_row_actions( array $item ): array {
		$bucket  = $item['name'];
		$actions = [];

		// Browse action
		$actions['browse'] = sprintf(
			'<a href="#" class="browse-bucket-button" data-bucket="%s">%s</a>',
			esc_attr( $bucket ),
			esc_html__( 'Browse', 'arraypress' )
		);

		// Details action - comprehensive modal
		$actions['details'] = sprintf(
			'<a href="#" class="s3-bucket-details" data-bucket="%s" data-provider="%s">%s</a>',
			esc_attr( $bucket ),
			esc_attr( $this->provider_id ),
			esc_html__( 'Details', 'arraypress' )
		);

		return $actions;
	}

	/**
	 * Display table navigation
	 *
	 * @param string $which Which tablenav ('top' or 'bottom')
	 */
	public function display_tablenav( $which ) {
		?>
        <div class="tablenav <?php echo esc_attr( $which ); ?>">
			<?php if ( $which === 'top' ): ?>
                <div class="s3-top-nav">
                    <div class="s3-actions-container">
						<?php
						// Use WordPress native button classes
						printf(
							'<button type="button" class="button button-secondary s3-refresh-button" data-type="buckets" data-provider="%s">
                        <span class="dashicons dashicons-update"></span> %s
                    </button>',
							esc_attr( $this->provider_id ),
							esc_html__( 'Refresh', 'arraypress' )
						);
						?>
                    </div>
                </div>
			<?php else: ?>
                <!-- Bottom navigation with WordPress styling -->
                <div class="tablenav-pages">
                <span class="displaying-num">
                    <?php
                    $count = count( $this->items );
                    echo esc_html( sprintf(
	                    _n( '%s bucket', '%s buckets', $count, 'arraypress' ),
	                    number_format_i18n( $count )
                    ) );
                    ?>
                </span>
					<?php if ( isset( $this->_pagination_args['marker'] ) && ! empty( $this->_pagination_args['marker'] ) ): ?>
                        <span class="pagination-links">
                        <?php
                        $marker  = $this->_pagination_args['marker'];
                        $post_id = isset( $_GET['post_id'] ) ? intval( $_GET['post_id'] ) : 0;
                        $url     = add_query_arg( [
	                        'chromeless' => 1,
	                        'post_id'    => $post_id,
	                        'tab'        => 's3_' . $this->provider_id,
	                        'view'       => 'buckets',
	                        'marker'     => urlencode( $marker )
                        ] );
                        ?>
                        <!-- WordPress native pagination button -->
                        <a class="button button-secondary" href="<?php echo esc_url( $url ); ?>">
                            <?php esc_html_e( 'Next Page', 'arraypress' ); ?> &raquo;
                        </a>
                    </span>
					<?php endif; ?>
                </div>
			<?php endif; ?>
            <br class="clear"/>
        </div>
		<?php
	}

	/**
	 * No items found text
	 */
	public function no_items() {
		echo esc_html__( 'No buckets found.', 'arraypress' );
	}

}