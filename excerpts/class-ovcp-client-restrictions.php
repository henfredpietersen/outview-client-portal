<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class OVCP_Client_Restrictions {

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'admin_init', array( $this, 'block_wp_admin_for_clients' ) );
		add_filter( 'show_admin_bar', array( $this, 'hide_admin_bar_for_clients' ) );
		add_filter( 'login_redirect', array( $this, 'redirect_clients_to_portal' ), 10, 3 );
	}

	private function is_client( $user = null ) {
		$user = $user ? $user : wp_get_current_user();
		return $user && in_array( 'ov_client', (array) $user->roles, true );
	}

	/**
	 * Clients have no business in wp-admin at all (they have no capabilities
	 * there anyway, but we redirect proactively so they don't hit a confusing
	 * "you don't have permission" screen).
	 */
	public function block_wp_admin_for_clients() {
		if ( wp_doing_ajax() ) {
			return;
		}
		if ( $this->is_client() ) {
			wp_safe_redirect( $this->portal_url() );
			exit;
		}
	}

	public function hide_admin_bar_for_clients( $show ) {
		if ( $this->is_client() ) {
			return false;
		}
		return $show;
	}

	public function redirect_clients_to_portal( $redirect_to, $requested_redirect_to, $user ) {
		if ( is_a( $user, 'WP_User' ) && $this->is_client( $user ) ) {
			return $this->portal_url();
		}
		return $redirect_to;
	}

	private function portal_url() {
		$page_id = get_option( 'ovcp_portal_page_id' );
		if ( $page_id && get_post( $page_id ) ) {
			return get_permalink( $page_id );
		}
		return home_url( '/' );
	}
}
