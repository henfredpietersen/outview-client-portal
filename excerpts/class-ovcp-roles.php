<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class OVCP_Roles {

	const ROLE = 'ov_client';

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'init', array( $this, 'maybe_add_role' ) );
	}

	public function maybe_add_role() {
		if ( ! get_role( self::ROLE ) ) {
			$this->add_role();
		}
	}

	/**
	 * A deliberately minimal role: clients can read the front end and their own
	 * profile, nothing else. All actual content access is gated in the portal
	 * shortcode + download handler by matching post meta to the user ID —
	 * this role just keeps them out of wp-admin and off the standard dashboard.
	 */
	public function add_role() {
		add_role(
			self::ROLE,
			'Client',
			array(
				'read' => true,
			)
		);
	}
}
