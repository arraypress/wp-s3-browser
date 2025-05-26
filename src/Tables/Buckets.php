<?php
/**
 * Response for bucket listing operations
 *
 * @package     ArrayPress\S3\Responses
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
			'actions' => __( 'Actions', 'arraypress' ),
		];
	}

	/**
	 * Prepare items
	 */
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

		$data = $result->get_data();
		$buckets = $data['buckets'];
		$items   = [];

		foreach ( $buckets as $bucket ) {
			$items[] = [
				'name'    => $bucket->get_name(),
				'created' => $bucket->get_formatted_date(),
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
		return sprintf(
			'<span class="dashicons dashicons-database"></span> <a href="#" class="bucket-name" data-bucket="%s">%s</a>',
			esc_attr( $item['name'] ),
			esc_html( $item['name'] )
		);
	}

	/**
	 * Column actions - Fixed to use JS navigation
	 */
	public function column_actions( $item ) {
		$bucket    = $item['name'];
		$post_id   = isset( $_REQUEST['post_id'] ) ? intval( $_REQUEST['post_id'] ) : 0;
		$post_type = $post_id ? get_post_type( $post_id ) : 'default';

		// Get current user ID
		$user_id = get_current_user_id();

		// Create a browse button instance
		$output = sprintf(
			'<a href="#" class="button s3-icon-button browse-bucket-button" data-bucket="%s"><span class="dashicons dashicons-visibility"></span>%s</a>',
			esc_attr( $bucket ),
			esc_html__( 'Browse', 'arraypress' )
		);

		// Check if this bucket is a favorite using straightforward meta-check
		$meta_key        = "s3_favorite_{$this->provider_id}_{$post_type}";
		$favorite_bucket = get_user_meta( $user_id, $meta_key, true );
		$is_favorite     = ( $favorite_bucket === $bucket );

		// Create a favorite button with the appropriate star icon and text
		$favorite_class  = $is_favorite ? 'dashicons-star-filled s3-favorite-active' : 'dashicons-star-empty';
		$favorite_text   = $is_favorite ? __( 'Default', 'arraypress' ) : __( 'Set Default', 'arraypress' );
		$favorite_action = $is_favorite ? 'remove' : 'add';

		$output .= sprintf(
			' <a href="#" class="button s3-icon-button s3-favorite-bucket" data-bucket="%s" data-provider="%s" data-action="%s" data-post-type="%s">' .
			'<span class="dashicons %s"></span>%s</a>',
			esc_attr( $bucket ),
			esc_attr( $this->provider_id ),
			esc_attr( $favorite_action ),
			esc_attr( $post_type ),
			esc_attr( $favorite_class ),
			esc_html( $favorite_text )
		);

		return $output;
	}

	/**
	 * Display table navigation for BucketsTable.php
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
						printf(
							'<button type="button" class="button s3-icon-button s3-refresh-button" data-type="buckets" data-provider="%s">
							<span class="dashicons dashicons-update"></span>%s
						</button>',
							esc_attr( $this->provider_id ),
							esc_html__( 'Refresh', 'arraypress' )
						);
						?>
                    </div>
                </div>
			<?php else: ?>
                <!-- Bottom navigation matching WordPress standard structure -->
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
							<a class="next-page button s3-icon-button" href="<?php echo esc_url( $url ); ?>">
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
	 * Display custom pagination for S3
	 */
	public function pagination( $which ) {
		if ( $which !== 'top' && isset( $this->_pagination_args['marker'] ) && ! empty( $this->_pagination_args['marker'] ) ) {
			$marker = $this->_pagination_args['marker'];

			// This URL should stay in the iframe
			$post_id = isset( $_GET['post_id'] ) ? intval( $_GET['post_id'] ) : 0;
			$url     = add_query_arg( [
				'chromeless' => 1,
				'post_id'    => $post_id,
				'tab'        => 's3_' . $this->provider_id,
				'view'       => 'buckets',
				'marker'     => urlencode( $marker )
			] );

			echo '<div class="tablenav-pages">';
			echo '<span class="displaying-num">' . esc_html( sprintf(
					_n( '%s bucket', '%s buckets', count( $this->items ), 'arraypress' ),
					number_format_i18n( count( $this->items ) )
				) ) . '</span>';

			echo '<span class="pagination-links">';
			echo '<a class="next-page button s3-icon-button" href="' . esc_url( $url ) . '">' .
			     esc_html__( 'Next Page', 'arraypress' ) . ' &raquo;</a>';
			echo '</span>';
			echo '</div>';
		} else {
			echo '<div class="tablenav-pages one-page">';
			echo '<span class="displaying-num">' . esc_html( sprintf(
					_n( '%s bucket', '%s buckets', count( $this->items ), 'arraypress' ),
					number_format_i18n( count( $this->items ) )
				) ) . '</span>';
			echo '</div>';
		}
	}

	/**
	 * No items found text
	 */
	public function no_items() {
		echo esc_html__( 'No buckets found.', 'arraypress' );
	}

}