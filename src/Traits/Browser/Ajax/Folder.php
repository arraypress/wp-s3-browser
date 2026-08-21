<?php
/**
 * Folder AJAX Trait (legacy)
 *
 * Thin adapters over the REST routes, retained so front-end code written
 * against the admin-ajax actions keeps working. All logic lives in
 * ArrayPress\S3\Traits\Browser\Rest\Handlers.
 *
 * @package     ArrayPress\S3\Traits\Browser\Ajax
 * @copyright   Copyright (c) 2025, ArrayPress Limited
 * @license     GPL2+
 * @version     1.0.0
 * @author      David Sherlock
 * @deprecated  1.2.0 Use the REST API routes instead.
 */

declare( strict_types=1 );

namespace ArrayPress\S3\Traits\Browser\Ajax;

/**
 * Trait Folder
 */
trait Folder {

	/**
	 * Handle AJAX create folder request
	 *
	 * @deprecated 1.2.0 Use POST /<namespace>/<base>/buckets/<bucket>/folders
	 */
	public function handle_ajax_create_folder(): void {
		if ( ! $this->verify_ajax_request() ) {
			return;
		}

		$this->dispatch_to_rest( 'rest_create_folder', [
			'bucket'      => $this->get_sanitized_post( 'bucket' ),
			'prefix'      => $this->get_sanitized_post( 'prefix', true ),
			'folder_name' => $this->get_sanitized_post( 'folder_name', true ),
		] );
	}

	/**
	 * Handle AJAX delete folder request
	 *
	 * @deprecated 1.2.0 Use DELETE /<namespace>/<base>/buckets/<bucket>/folders
	 */
	public function handle_ajax_delete_folder(): void {
		if ( ! $this->verify_ajax_request() ) {
			return;
		}

		$this->dispatch_to_rest( 'rest_delete_folder', [
			'bucket'      => $this->get_sanitized_post( 'bucket' ),
			'folder_path' => $this->get_sanitized_post( 'folder_path', true ),
		] );
	}

}
