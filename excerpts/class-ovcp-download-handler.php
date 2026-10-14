<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class OVCP_Download_Handler {

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'init', array( $this, 'maybe_handle_download' ) );
	}

	public function get_download_url( $document_post_id ) {
		$url = add_query_arg(
			array(
				'ovcp_download' => $document_post_id,
			),
			home_url( '/' )
		);
		return wp_nonce_url( $url, 'ovcp_download_' . $document_post_id );
	}

	/**
	 * Runs on every front-end request; only does work when ?ovcp_download is present.
	 * Access rule: the logged-in user must either be the document's assigned client,
	 * or have manage_options (admin). Anyone else — including logged-out visitors
	 * and OTHER clients — gets a 403, even if they have a valid-looking URL.
	 */
	public function maybe_handle_download() {
		if ( empty( $_GET['ovcp_download'] ) ) {
			return;
		}

		$document_id = absint( $_GET['ovcp_download'] );

		if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( $_GET['_wpnonce'], 'ovcp_download_' . $document_id ) ) {
			wp_die( 'This download link is invalid or has expired.', 'Invalid link', array( 'response' => 403 ) );
		}

		if ( ! is_user_logged_in() ) {
			auth_redirect();
			exit;
		}

		// Same license gate as the portal dashboard — admins previewing the
		// site can still pull files, but a lapsed license blocks direct
		// download links too, not just the dashboard view.
		if ( ! current_user_can( 'manage_options' ) && ! OVCP_License::is_active() ) {
			wp_die( 'This portal is temporarily unavailable. Please check back shortly.', 'Unavailable', array( 'response' => 503 ) );
		}

		$document = get_post( $document_id );
		if ( ! $document || 'ov_document' !== $document->post_type ) {
			wp_die( 'Document not found.', 'Not found', array( 'response' => 404 ) );
		}

		$assigned_client_id = (int) get_post_meta( $document_id, '_ovcp_client_id', true );
		$current_user_id    = get_current_user_id();

		if ( $assigned_client_id !== $current_user_id && ! current_user_can( 'manage_options' ) ) {
			wp_die( 'You do not have permission to access this file.', 'Forbidden', array( 'response' => 403 ) );
		}

		$attachment_id = (int) get_post_meta( $document_id, '_ovcp_attachment_id', true );
		$file_path     = get_attached_file( $attachment_id );

		if ( ! $attachment_id || ! $file_path || ! file_exists( $file_path ) ) {
			wp_die( 'File is missing on the server.', 'Not found', array( 'response' => 404 ) );
		}

		$this->stream_file( $file_path );
		exit;
	}

	private function stream_file( $file_path ) {
		nocache_headers();
		header( 'Content-Description: File Transfer' );
		header( 'Content-Type: ' . ( wp_check_filetype( $file_path )['type'] ?: 'application/octet-stream' ) );
		header( 'Content-Disposition: attachment; filename="' . basename( $file_path ) . '"' );
		header( 'Content-Length: ' . filesize( $file_path ) );
		header( 'X-Content-Type-Options: nosniff' );

		// Use WP_Filesystem where available; fall back to readfile for simplicity/performance on large files.
		readfile( $file_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_readfile
	}
}
