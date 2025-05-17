<?php
/**
 * Response for bucket listing operations
 *
 * @package     ArrayPress\S3\Responses
 * @copyright   Copyright (c) 2025, ArrayPress Limited
 * @license     GPL2+
 * @version     1.0.0
 * @author      ArrayPress Team
 */

declare( strict_types=1 );

namespace ArrayPress\S3\Tables;

use WP_List_Table;

/**
 * S3 Buckets List Table
 */
class BucketsTable extends WP_List_Table {

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
			'name'    => __( 'Bucket Name', 'arraypress-s3' ),
			'created' => __( 'Creation Date', 'arraypress-s3' ),
			'actions' => __( 'Actions', 'arraypress-s3' ),
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

		if ( is_wp_error( $result ) ) {
			echo '<div class="notice notice-error"><p>' . esc_html( $result->get_error_message() ) . '</p></div>';
			$this->items = [];

			return;
		}

		$buckets = $result['buckets'];
		$items   = [];

		foreach ( $buckets as $bucket ) {
			$items[] = [
				'name'    => $bucket->get_name(),
				'created' => $bucket->get_formatted_date(),
				'raw'     => $bucket, // Store the raw bucket object for access later
			];
		}

		$this->items = $items;

		// Store pagination info
		$this->set_pagination_args( [
			'total_items' => count( $items ), // We don't know the real count from S3
			'per_page'    => 1000,
			'total_pages' => $result['truncated'] ? 2 : 1, // Just indicate if there's more
		] );

		// Store the marker for next page link
		if ( $result['truncated'] && ! empty( $result['next_marker'] ) ) {
			$this->_pagination_args['marker'] = $result['next_marker'];
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
		// Create a link that opens the bucket
		// Note: We're using # for href and relying on JS to handle the click
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
		// Add a dashicon to the browse button
		return sprintf(
			'<a href="#" class="button browse-bucket-button" data-bucket="%s"><span class="dashicons dashicons-search"></span>%s</a>',
			esc_attr( $item['name'] ),
			esc_html__( 'Browse', 'arraypress-s3' )
		);
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
					_n( '%s bucket', '%s buckets', count( $this->items ), 'arraypress-s3' ),
					number_format_i18n( count( $this->items ) )
				) ) . '</span>';

			echo '<span class="pagination-links">';
			echo '<a class="next-page button" href="' . esc_url( $url ) . '">' .
			     esc_html__( 'Next Page', 'arraypress-s3' ) . ' &raquo;</a>';
			echo '</span>';
			echo '</div>';
		} else {
			echo '<div class="tablenav-pages one-page">';
			echo '<span class="displaying-num">' . esc_html( sprintf(
					_n( '%s bucket', '%s buckets', count( $this->items ), 'arraypress-s3' ),
					number_format_i18n( count( $this->items ) )
				) ) . '</span>';
			echo '</div>';
		}
	}

	/**
	 * No items found text
	 */
	public function no_items() {
		echo esc_html__( 'No buckets found.', 'arraypress-s3' );
	}

}