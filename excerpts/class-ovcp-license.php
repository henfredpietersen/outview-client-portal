<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Licensing: domain-lock (local, no network required) + periodic phone-home
 * check-in against Outview's licensing server. Kept deliberately parallel
 * with the license handling in Outview's other plugins so they all behave the
 * same way for site owners.
 *
 * Two independent layers:
 *  1. Domain-lock — bound to the domain it was first activated on. Copying
 *     this plugin's files/database to a second site won't work there unless
 *     deactivated on the original site first. Never leaves the server.
 *  2. Phone-home check-in — confirms the subscription is still paid and not
 *     revoked. Only the license key, this site's domain, and plugin version
 *     are sent — never any client/project/invoice data.
 *
 * If either layer fails, client-facing portal access is locked down to a
 * simple "temporarily unavailable" message — but wp-admin is NEVER locked,
 * so the site owner can always get back in to fix/renew.
 */
class OVCP_License {

	const OPTION_KEY       = 'ovcp_license';
	const CRON_HOOK        = 'ovcp_license_check_event';
	const API_BASE         = 'https://outview.co.za/wp-json/outview-licensing/v1';
	const PRODUCT_SLUG     = 'portal';
	const GRACE_PERIOD_SEC = 14 * DAY_IN_SECONDS;

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( self::CRON_HOOK, array( __CLASS__, 'check_in' ) );
		add_action( 'admin_notices', array( __CLASS__, 'admin_notice' ) );
		add_action( 'admin_menu', array( $this, 'register_menu' ), 20 ); // after OVCP_Settings registers the parent menu
		add_action( 'admin_init', array( $this, 'handle_form' ) );

		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + ( 5 * MINUTE_IN_SECONDS ), 'twicedaily', self::CRON_HOOK );
		}
	}

	public function register_menu() {
		add_submenu_page(
			'ovcp-portal',
			__( 'License', 'ov-client-portal' ),
			__( 'License', 'ov-client-portal' ),
			'manage_options',
			'ovcp-license',
			array( $this, 'render_license_page' )
		);
	}

	public function handle_form() {
		if ( ! isset( $_POST['ovcp_license_action'] ) || ! current_user_can( 'manage_options' ) ) return;

		$action = sanitize_text_field( wp_unslash( $_POST['ovcp_license_action'] ) );

		if ( 'activate' === $action && check_admin_referer( 'ovcp_license_action', 'ovcp_license_nonce' ) ) {
			$result = self::activate_key( sanitize_text_field( wp_unslash( $_POST['license_key'] ?? '' ) ) );
			$notice = is_wp_error( $result ) ? 'error' : 'ok';
			$msg    = is_wp_error( $result ) ? rawurlencode( $result->get_error_message() ) : '';
			wp_safe_redirect( add_query_arg( array( 'ovcp_license_notice' => $notice, 'ovcp_msg' => $msg ), admin_url( 'admin.php?page=ovcp-license' ) ) );
			exit;
		}

		if ( 'deactivate' === $action && check_admin_referer( 'ovcp_license_action', 'ovcp_license_nonce' ) ) {
			self::deactivate();
			wp_safe_redirect( add_query_arg( 'ovcp_license_notice', 'deactivated', admin_url( 'admin.php?page=ovcp-license' ) ) );
			exit;
		}
	}

	public function render_license_page() {
		if ( ! current_user_can( 'manage_options' ) ) return;
		$license = self::get_all();

		if ( isset( $_GET['ovcp_license_notice'] ) ) {
			$notice = sanitize_text_field( wp_unslash( $_GET['ovcp_license_notice'] ) );
			$map = array(
				'ok'           => array( 'success', self::is_active() ? __( 'License activated.', 'ov-client-portal' ) : self::status_label() ),
				'error'        => array( 'error', isset( $_GET['ovcp_msg'] ) ? sanitize_text_field( wp_unslash( urldecode( $_GET['ovcp_msg'] ) ) ) : __( 'Could not activate license.', 'ov-client-portal' ) ),
				'deactivated'  => array( 'info', __( 'License deactivated on this site.', 'ov-client-portal' ) ),
			);
			if ( isset( $map[ $notice ] ) ) {
				printf( '<div class="notice notice-%s is-dismissible"><p>%s</p></div>', esc_attr( $map[ $notice ][0] ), esc_html( $map[ $notice ][1] ) );
			}
		}
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Client Portal — License', 'ov-client-portal' ); ?></h1>

			<table class="form-table">
				<tr>
					<th><?php esc_html_e( 'Status', 'ov-client-portal' ); ?></th>
					<td>
						<strong style="color: <?php echo self::is_active() ? '#1a7d34' : '#b32d2e'; ?>;">
							<?php echo self::is_active() ? esc_html__( 'Active', 'ov-client-portal' ) : esc_html__( 'Inactive', 'ov-client-portal' ); ?>
						</strong>
						<p class="description"><?php echo esc_html( self::status_label() ); ?></p>
					</td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Domain', 'ov-client-portal' ); ?></th>
					<td>
						<code><?php echo esc_html( self::current_domain() ); ?></code>
						<?php if ( ! empty( $license['domain'] ) ) : ?>
							<p class="description"><?php printf( esc_html__( 'This license is bound to: %s', 'ov-client-portal' ), esc_html( $license['domain'] ) ); ?></p>
						<?php endif; ?>
					</td>
				</tr>
				<?php if ( ! empty( $license['last_check'] ) ) : ?>
				<tr>
					<th><?php esc_html_e( 'Last checked', 'ov-client-portal' ); ?></th>
					<td><?php echo esc_html( human_time_diff( $license['last_check'] ) . ' ' . __( 'ago', 'ov-client-portal' ) ); ?></td>
				</tr>
				<?php endif; ?>
			</table>

			<form method="post" action="">
				<?php wp_nonce_field( 'ovcp_license_action', 'ovcp_license_nonce' ); ?>
				<input type="hidden" name="ovcp_license_action" value="activate" />
				<table class="form-table">
					<tr>
						<th><label for="ovcp_license_key"><?php esc_html_e( 'License Key', 'ov-client-portal' ); ?></label></th>
						<td>
							<input type="text" class="regular-text" id="ovcp_license_key" name="license_key" value="<?php echo esc_attr( $license['key'] ); ?>" placeholder="OV-XXXX-XXXX-XXXX-XXXX" />
							<p class="description"><?php esc_html_e( 'You received this key by email when you purchased Outview CRM.', 'ov-client-portal' ); ?></p>
						</td>
					</tr>
				</table>
				<?php submit_button( __( 'Activate / Verify Now', 'ov-client-portal' ) ); ?>
			</form>

			<?php if ( ! empty( $license['key'] ) ) : ?>
				<form method="post" action="" onsubmit="return confirm('<?php echo esc_js( __( 'Deactivate this license on this site? You will need to re-activate it here (or on another site) to use it again.', 'ov-client-portal' ) ); ?>');">
					<?php wp_nonce_field( 'ovcp_license_action', 'ovcp_license_nonce' ); ?>
					<input type="hidden" name="ovcp_license_action" value="deactivate" />
					<?php submit_button( __( 'Deactivate License on This Site', 'ov-client-portal' ), 'secondary' ); ?>
				</form>
			<?php endif; ?>
		</div>
		<?php
	}

	public static function unschedule() {
		$timestamp = wp_next_scheduled( self::CRON_HOOK );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, self::CRON_HOOK );
		}
	}

	/** ------------------------------------------------------------------
	 * Storage
	 * -------------------------------------------------------------------*/

	public static function get_defaults() {
		return array(
			'key'                => '',
			'domain'             => '',
			'status'             => 'unlicensed', // unlicensed | active | grace | domain_mismatch | invalid | expired
			'expires'            => '',
			'last_check'         => 0,
			'last_check_message' => '',
			'activated_at'       => 0,
		);
	}

	public static function get_all() {
		$saved = get_option( self::OPTION_KEY, array() );
		return wp_parse_args( is_array( $saved ) ? $saved : array(), self::get_defaults() );
	}

	private static function update( array $new_values ) {
		$all = array_merge( self::get_all(), $new_values );
		update_option( self::OPTION_KEY, $all );
		return $all;
	}

	public static function current_domain() {
		$host = wp_parse_url( home_url(), PHP_URL_HOST );
		return $host ? strtolower( preg_replace( '/^www\./', '', $host ) ) : '';
	}

	/** ------------------------------------------------------------------
	 * Public state helpers
	 * -------------------------------------------------------------------*/

	public static function is_active() {
		$license = self::get_all();

		if ( empty( $license['key'] ) ) {
			return false;
		}

		if ( ! empty( $license['domain'] ) && $license['domain'] !== self::current_domain() ) {
			return false;
		}

		return in_array( $license['status'], array( 'active', 'grace' ), true );
	}

	public static function status_label() {
		$license = self::get_all();

		if ( empty( $license['key'] ) ) {
			return __( 'No license key entered', 'ov-client-portal' );
		}
		if ( ! empty( $license['domain'] ) && $license['domain'] !== self::current_domain() ) {
			return sprintf(
				/* translators: 1: bound domain, 2: current domain */
				__( 'License is bound to %1$s but this site is running on %2$s. Contact Outview to transfer it.', 'ov-client-portal' ),
				$license['domain'],
				self::current_domain()
			);
		}

		switch ( $license['status'] ) {
			case 'active':
				return $license['expires']
					? sprintf( __( 'Active — renews %s', 'ov-client-portal' ), $license['expires'] )
					: __( 'Active', 'ov-client-portal' );
			case 'grace':
				return __( 'Could not reach the license server recently, but you\'re still within the grace period.', 'ov-client-portal' );
			case 'expired':
				return __( 'Subscription has expired. Renew to restore client access to the portal.', 'ov-client-portal' );
			case 'invalid':
				return __( 'License key not recognized.', 'ov-client-portal' );
			default:
				return __( 'Unlicensed', 'ov-client-portal' );
		}
	}

	/** ------------------------------------------------------------------
	 * Activation / deactivation
	 * -------------------------------------------------------------------*/

	public static function activate_key( $key ) {
		$key = sanitize_text_field( $key );
		if ( '' === $key ) {
			return new WP_Error( 'ovcp_license_empty', __( 'Please enter a license key.', 'ov-client-portal' ) );
		}

		$existing = self::get_all();
		$domain   = self::current_domain();

		if ( ! empty( $existing['domain'] ) && $existing['domain'] !== $domain && $existing['key'] === $key ) {
			return new WP_Error( 'ovcp_license_domain_locked', __( 'This license is already bound to a different domain. Deactivate it there first, or contact Outview to transfer it.', 'ov-client-portal' ) );
		}

		self::update( array(
			'key'          => $key,
			'domain'       => $domain,
			'status'       => 'unlicensed',
			'activated_at' => time(),
		) );

		return self::check_in( true );
	}

	public static function deactivate() {
		self::update( self::get_defaults() );
	}

	/** ------------------------------------------------------------------
	 * Phone-home check-in
	 * -------------------------------------------------------------------*/

	public static function check_in( $return_result = false ) {
		$license = self::get_all();

		if ( empty( $license['key'] ) ) {
			return $return_result ? new WP_Error( 'ovcp_license_empty', __( 'No license key set.', 'ov-client-portal' ) ) : null;
		}

		$response = wp_remote_post( self::API_BASE . '/validate', array(
			'timeout' => 10,
			'body'    => array(
				'license_key'    => $license['key'],
				'domain'         => self::current_domain(),
				'product'        => self::PRODUCT_SLUG,
				'site_url'       => home_url(),
				'plugin_version' => defined( 'OVCP_VERSION' ) ? OVCP_VERSION : '',
			),
		) );

		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			$grace_active = $license['last_check'] && ( time() - $license['last_check'] ) < self::GRACE_PERIOD_SEC;
			self::update( array(
				'status'             => $grace_active ? 'grace' : ( in_array( $license['status'], array( 'active', 'grace' ), true ) ? 'grace' : $license['status'] ),
				'last_check_message' => is_wp_error( $response ) ? $response->get_error_message() : __( 'License server unreachable.', 'ov-client-portal' ),
			) );
			return $return_result ? new WP_Error( 'ovcp_license_unreachable', __( 'Could not reach the license server. Will retry automatically.', 'ov-client-portal' ) ) : null;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( ! is_array( $body ) || ! isset( $body['valid'] ) ) {
			self::update( array( 'last_check_message' => __( 'Unexpected response from license server.', 'ov-client-portal' ) ) );
			return $return_result ? new WP_Error( 'ovcp_license_bad_response', __( 'Unexpected response from license server.', 'ov-client-portal' ) ) : null;
		}

		$status = 'invalid';
		if ( ! empty( $body['valid'] ) ) {
			$status = 'active';
		} elseif ( 'expired' === ( $body['status'] ?? '' ) ) {
			$status = 'expired';
		} elseif ( 'domain_mismatch' === ( $body['status'] ?? '' ) ) {
			$status = 'invalid';
		}

		self::update( array(
			'status'             => $status,
			'expires'            => sanitize_text_field( $body['expires'] ?? '' ),
			'last_check'         => time(),
			'last_check_message' => sanitize_text_field( $body['message'] ?? '' ),
		) );

		if ( ! $return_result ) {
			return null;
		}

		return 'active' === $status
			? true
			: new WP_Error( 'ovcp_license_' . $status, $body['message'] ?? self::status_label() );
	}

	/** ------------------------------------------------------------------
	 * Admin notice
	 * -------------------------------------------------------------------*/

	public static function admin_notice() {
		if ( ! current_user_can( 'manage_options' ) ) return;
		if ( self::is_active() ) return;

		$screen = get_current_screen();
		if ( $screen && strpos( $screen->id, 'ovcp-' ) === false && 'dashboard' !== $screen->id ) return;

		printf(
			'<div class="notice notice-warning"><p><strong>%s</strong> — %s <a href="%s">%s</a></p></div>',
			esc_html__( 'Client Portal is not currently active.', 'ov-client-portal' ),
			esc_html( self::status_label() ),
			esc_url( admin_url( 'admin.php?page=ovcp-license' ) ),
			esc_html__( 'Manage license', 'ov-client-portal' )
		);
	}
}
