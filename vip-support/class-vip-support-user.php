<?php

/**
 * Manages VIP Support users.
 *
 * @package WPCOM_VIP_Support_User
 **/
class WPCOM_VIP_Support_User {

	/**
	 * GET parameter for a message: We blocked this user from the
	 * support role because they're not an A12n.
	 */
	const MSG_BLOCK_UPGRADE_NON_A11N     = 'vip_support_msg_1';

	/**
	 * GET parameter for a message: We blocked this user from the
	 * support role because they have not verified their
	 * email address.
	 */
	const MSG_BLOCK_UPGRADE_VERIFY_EMAIL = 'vip_support_msg_2';

	/**
	 * GET parameter for a message: We blocked this NEW user from
	 * the support role because they're not an A12n.
	 */
	const MSG_BLOCK_NEW_NON_VIP_USER     = 'vip_support_msg_3';

	/**
	 * GET parameter for a message: We blocked this user from
	 * LEAVING the support role because they have not verified
	 * their email address.
	 */
	const MSG_BLOCK_DOWNGRADE            = 'vip_support_msg_4';

	/**
	 * GET parameter for a message: This user was added to the
	 * VIP Support role.
	 */
	const MSG_MADE_VIP                   = 'vip_support_msg_5';

	/**
	 * GET parameter for a message: We downgraded this user from
	 * the support role because their email address is no longer
	 * verified.
	 */
	const MSG_DOWNGRADE_VIP_USER = 'vip_support_msg_6';

	/**
	 * Meta key for the email verification data.
	 */
	const META_VERIFICATION_DATA = '_vip_email_verification_data';

	/**
	 * Meta key flag that this user needs verification
	 */
	const META_EMAIL_NEEDS_VERIFICATION = '_vip_email_needs_verification';

	/**
	 * Meta key for the email which HAS been verified.
	 */
	const META_EMAIL_VERIFIED    = '_vip_verified_email';

	/**
	 * GET parameter for the code in the verification link.
	 */
	const GET_EMAIL_VERIFY                = 'vip_verify_code';

	/**
	 * GET parameter for the user ID for the user being verified.
	 */
	const GET_EMAIL_USER_LOGIN               = 'vip_user_login';

	/**
	 * GET parameter to indicate to trigger a resend if true.
	 */
	const GET_TRIGGER_RESEND_VERIFICATION = 'vip_trigger_resend';

	/**
	 * A flag to indicate reversion and then to prevent recursion.
	 *
	 * @var bool True if the role is being reverted
	 */
	protected $reverting_role;

	/**
	 * Set to a string to indicate a message to replace, but
	 * defaults to false.
	 *
	 * @var bool|string
	 */
	protected $message_replace;

	/**
	 * A flag to indicate the user being registered is an
	 * A12n (i.e. VIP).
	 *
	 * @var bool
	 */
	protected $registering_a11n;

	/**
	 * Initiate an instance of this class if one doesn't
	 * exist already. Return the WPCOM_VIP_Support_User instance.
	 *
	 * @access @static
	 *
	 * @return WPCOM_VIP_Support_User object The instance of WPCOM_VIP_Support_User
	 */
	static public function init() {
		static $instance = false;

		if ( ! $instance ) {
			$instance = new WPCOM_VIP_Support_User;
		}

		return $instance;

	}

	/**
	 * Class constructor. Handles hooking actions and filters,
	 * and sets some properties.
	 */
	public function __construct() {
		add_action( 'admin_notices',      array( $this, 'action_admin_notices' ) );
		add_action( 'set_user_role',      array( $this, 'action_set_user_role' ), 10, 3 );
		add_action( 'user_register',      array( $this, 'action_user_register' ) );
		add_action( 'parse_request',      array( $this, 'action_parse_request' ) );
		add_action( 'personal_options',   array( $this, 'action_personal_options' ) );
		add_action( 'load-user-edit.php', array( $this, 'action_load_user_edit' ) );
		add_action( 'load-profile.php',   array( $this, 'action_load_profile' ) );
		add_action( 'profile_update',     array( $this, 'action_profile_update' ) );
		add_action( 'admin_head',         array( $this, 'action_admin_head' ) );
		add_action( 'password_reset',     array( $this, 'action_password_reset' ) );

		add_filter( 'wp_redirect',          array( $this, 'filter_wp_redirect' ) );
		add_filter( 'removable_query_args', array( $this, 'filter_removable_query_args' ) );

		$this->reverting_role   = false;
		$this->message_replace  = false;
		$this->registering_a11n  = false;
	}

	// HOOKS
	// =====

	/**
	 * Hooks the admin_head action to add some CSS into the
	 * user edit and profile screens.
	 */
	public function action_admin_head() {
		if ( in_array( get_current_screen()->base, array( 'user-edit', 'profile' ) ) ) {
			?>
			<style type="text/css">
				.vip-support-email-status {
					padding-left: 1em;
				}
				.vip-support-email-status .dashicons {
					line-height: 1.6;
				}
				.email-not-verified {
					color: #dd3d36;
				}
				.email-verified {
					color: #7ad03a;
				}
			</style>
			<?php
		}
	}

	/**
	 * Hooks the load action on the user edit screen to
	 * send verification email if required.
	 */
	public function action_load_user_edit() {
		if ( isset( $_GET[self::GET_TRIGGER_RESEND_VERIFICATION] ) && $_GET[self::GET_TRIGGER_RESEND_VERIFICATION] ) {
			$user_id = absint( $_GET['user_id'] );
			$this->send_verification_email( $user_id );
		}
	}

	/**
	 * Hooks the load action on the profile screen to
	 * send verification email if required.
	 */
	public function action_load_profile() {
		if ( isset( $_GET[self::GET_TRIGGER_RESEND_VERIFICATION] ) && $_GET[self::GET_TRIGGER_RESEND_VERIFICATION] ) {
			$user_id = get_current_user_id();
			$this->send_verification_email( $user_id );
		}
	}

	/**
	 * Hooks the personal_options action on the user edit
	 * and profile screens to add verification status for
	 * the user's email.
	 *
	 * @param object $user The WP_User object representing the user being edited
	 */
	public function action_personal_options( $user ) {
		if ( ! $this->is_a8c_email( $user->user_email ) ) {
			return;
		}

		if ( $this->user_has_verified_email( $user->ID ) ) {
			?>
			<em id="vip-support-email-status" class="vip-support-email-status email-verified"><span class="dashicons dashicons-yes"></span>
				<?php esc_html_e( 'email is verified', 'vip-support' ); ?>
			</em>
			<?php
		} else {
			?>
			<em id="vip-support-email-status" class="vip-support-email-status email-not-verified"><span class="dashicons dashicons-no"></span>
				<?php esc_html_e( 'email is not verified', 'vip-support' ); ?>
			</em>
			<?php
		}
?>
		<script type="text/javascript">
			jQuery( 'document').ready( function( $ ) {
				$( '#email' ).after( $( '#vip-support-email-status' ) );
			} );
		</script>
<?php
	}

	/**
	 * Hooks the admin_notices action to add some admin notices,
	 * also resends verification emails when required.
	 */
	public function action_admin_notices() {
		$error_html   = false;
		$message_html = false;
		$screen       = get_current_screen();

		// Messages on the users list screen
		if ( in_array( $screen->base, array( 'users', 'user-edit', 'profile' ) ) ) {

			$update = false;
			if ( isset( $_GET['update'] ) ) {
				$update = $_GET['update'];
			}

			switch ( $update ) {
				case self::MSG_BLOCK_UPGRADE_NON_A11N :
					$error_html = __( 'Only users with a recognised Automattic email address can be assigned the VIP Support role.', 'vip-support' );
					break;
				case    self::MSG_BLOCK_UPGRADE_VERIFY_EMAIL :
				case self::MSG_MADE_VIP :
					$error_html = __( 'This user’s Automattic email address must be verified before they can be assigned the VIP Support role.', 'vip-support' );
					break;
				case self::MSG_BLOCK_NEW_NON_VIP_USER :
					$error_html = __( 'Only Automattic staff can be assigned the VIP Support role, the new user has been made a "subscriber".', 'vip-support' );
					break;
				case self::MSG_BLOCK_DOWNGRADE :
					$error_html = __( 'VIP Support users can only be assigned the VIP Support role, or deleted.', 'vip-support' );
					break;
				case self::MSG_DOWNGRADE_VIP_USER :
					$error_html = __( 'This user’s email address has changed, and as a result they are no longer in the VIP Support role. Once the user has verified their new email address they will have the VIP Support role restored.', 'vip-support' );
					break;
				default:
					break;
			}
		}

		// Messages on the user's own profile edit screen
		if ( 'profile' == $screen->base ) {
			if ( isset( $_GET[self::GET_TRIGGER_RESEND_VERIFICATION] ) && $_GET[self::GET_TRIGGER_RESEND_VERIFICATION] ) {
				$message_html = __( 'The verification email has been sent, please check your inbox. Delivery may take a few minutes.', 'vip-support' );
			} else {
				$user_id = get_current_user_id();
				$user    = get_user_by( 'id', $user_id );
				$resend_link = $this->get_trigger_resend_verification_url();
				if ( $this->is_a8c_email( $user->user_email ) && ! $this->user_has_verified_email( $user->ID ) ) {
					$error_html = sprintf( __( 'Your Automattic email address is not verified, <a href="%s">re-send verification email</a>.', 'vip-support' ), esc_url( $resend_link ) );
				}
			}
		}

		// Messages on the user edit screen for another user
		if ( 'user-edit' == $screen->base ) {
			if ( isset( $_GET[self::GET_TRIGGER_RESEND_VERIFICATION] ) && $_GET[self::GET_TRIGGER_RESEND_VERIFICATION] ) {
				$message_html = __( 'The verification email has been sent, please ask the user to check their inbox. Delivery may take a few minutes.', 'vip-support' );
			} else {
				$user_id = absint( $_GET['user_id'] );
				$user = get_user_by( 'id', $user_id );
				$resend_link = $this->get_trigger_resend_verification_url();
				if ( $this->is_a8c_email( $user->user_email ) && ! $this->user_has_verified_email( $user->ID ) && $this->user_has_vip_support_role( $user->ID ) ) {
					$error_html = sprintf( __( 'This user’s Automattic email address is not verified, <a href="%s">re-send verification email</a>.', 'vip-support' ), esc_url( $resend_link ) );
				}
			}
		}

		// For is-dismissible see https://make.wordpress.org/core/2015/04/23/spinners-and-dismissible-admin-notices-in-4-2/
		if ( $error_html ) {
			echo '<div id="message" class="notice is-dismissible error"><p>' . $error_html . '</p></div>';

		}
		if ( $message_html ) {
			echo '<div id="message" class="notice is-dismissible updated"><p>' . $message_html . '</p></div>';

		}
	}

	/**
	 * Hooks the set_user_role action to check if we're setting the user to the
	 * VIP Support role. If we are setting to the VIP Support role, various checks
	 * are run, and the transition may be reverted.
	 *
	 * @param int $user_id The ID of the user having their role changed
	 * @param string $role The name of the new role
	 * @param array $old_roles Any roles the user was assigned to previously
	 */
	public function action_set_user_role( $user_id, $role, $old_roles ) {
		// Avoid recursing, while we're reverting
		if ( $this->reverting_role ) {
			return;
		}
		$user = new WP_User( $user_id );

		// Try to make the conditional checks clearer
		$becoming_support         = ( WPCOM_VIP_Support_Role::VIP_SUPPORT_ROLE == $role );
		$valid_and_verified_email = ( $this->is_a8c_email( $user->user_email ) && $this->user_has_verified_email( $user_id ) );

		if ( $becoming_support && ! $valid_and_verified_email ) {
			$this->reverting_role = true;
			// @FIXME This could be expressed more simply, probably :|
			if ( ! is_array( $old_roles ) || ! isset( $old_roles[0] ) ) {
				if ( $this->is_a8c_email( $user->user_email ) ) {
					$revert_role_to = WPCOM_VIP_Support_Role::VIP_SUPPORT_INACTIVE_ROLE;
				} else {
					$revert_role_to = 'subscriber';
				}
			} else {
				$revert_role_to = $old_roles[0];
			}
			$user->set_role( $revert_role_to );
			if ( $this->is_a8c_email( $user->user_email ) && ! $this->user_has_verified_email( $user_id ) ) {
				$this->message_replace = self::MSG_BLOCK_UPGRADE_VERIFY_EMAIL;
				$this->send_verification_email( $user_id );
			} else {
				$this->message_replace = self::MSG_BLOCK_UPGRADE_NON_A11N;
			}
			$this->reverting_role = false;
		}

	}

	/**
	 * Filters wp_redirect so we can replace the query string arguments
	 * and manipulate the admin notice shown to the user to reflect what
	 * has happened (e.g. role setting has been rejected as the user is
	 * not an A12n).
	 *
	 * @param $location
	 *
	 * @return string
	 */
	public function filter_wp_redirect( $location ) {
		if ( ! $this->message_replace && ! $this->registering_a11n ) {
			return $location;
		}
		if ( $this->message_replace ) {
			$location = add_query_arg( array( 'update' => rawurlencode( $this->message_replace ) ), $location );
			$location = esc_url_raw( $location );
		}
		if ( $this->registering_a11n ) {
			$location = add_query_arg( array( 'update' => rawurlencode( self::MSG_MADE_VIP ) ), $location );
			$location = esc_url_raw( $location );
		}
		return $location;
	}

	/**
	 * Hooks the user_register action to determine if we're registering an
	 * A12n, and so need an email verification. Also checks if the registered
	 * user cannot be set to VIP Support role (as not an A12n).
	 *
	 * When a user is registered we reset VIP Support role to inactive, then
	 * wait until they recover their password to mark their role as active.
	 *
	 * If they do not go through password recovery then we send the verification
	 * email when they first log in.
	 *
	 * @param int $user_id The ID of the user which has been registered.
	 */
	public function action_user_register( $user_id ) {
		$user = new WP_User( $user_id );
		if ( $this->is_a8c_email( $user->user_email ) && $this->user_has_vip_support_role( $user->ID ) ) {
			$user->set_role( WPCOM_VIP_Support_Role::VIP_SUPPORT_INACTIVE_ROLE );
			$this->registering_a11n = true;
			// @TODO Abstract this into an UNVERIFY method
			$this->mark_user_email_unverified( $user_id );
			$this->send_verification_email( $user_id );
		} else {
			if ( self::MSG_BLOCK_UPGRADE_NON_A11N == $this->message_replace ) {
				$this->message_replace = self::MSG_BLOCK_NEW_NON_VIP_USER;
			}
		}
	}

	/**
	 * Hooks the profile_update action to delete the email verification meta
	 * when the user's email address changes.
	 *
	 * @param int $user_id The ID of the user whose profile was just updated
	 */
	public function action_profile_update( $user_id ) {
		$user = new WP_User( $user_id );
		$verified_email = get_user_meta( $user_id, self::META_EMAIL_VERIFIED, true );
		if ( $user->user_email !== $verified_email && $this->user_has_vip_support_role( $user_id ) ) {
			$user->set_role( WPCOM_VIP_Support_Role::VIP_SUPPORT_INACTIVE_ROLE );
			$this->message_replace = self::MSG_DOWNGRADE_VIP_USER;
			delete_user_meta( $user_id, self::META_EMAIL_VERIFIED );
			delete_user_meta( $user_id, self::META_VERIFICATION_DATA );
			if ( $this->user_has_vip_support_role( $user_id ) || $this->user_has_vip_support_role( $user_id, false ) ) {
				$this->send_verification_email( $user_id );
			}
		}
	}

	/**
	 * @param object $user A WP_User object
	 */
	public function action_password_reset( $user ) {
		if ( '/wp-login.php' !== $_SERVER['PHP_SELF'] ) {
			return;
		}
		if ( ! $user->has_cap( WPCOM_VIP_Support_Role::VIP_SUPPORT_INACTIVE_ROLE ) ) {
			return;
		}
		if ( ! get_user_meta( $user->ID, self::META_EMAIL_NEEDS_VERIFICATION ) ) {
			return;
		}

		$this->mark_user_email_verified( $user->ID, $user->user_email );
		$user->set_role( WPCOM_VIP_Support_Role::VIP_SUPPORT_ROLE );
	}

	/**
	 * Hooks the parse_request action to do any email verification.
	 *
	 * @TODO Abstract all the verification stuff into a utility method for clarity
	 *
	 */
	public function action_parse_request() {
		if ( ! isset( $_GET[self::GET_EMAIL_VERIFY] ) ) {
			return;
		}

		$rebuffal_title   = __( 'Verification failed', 'vip-support' );
		$rebuffal_message = __( 'This email verification link is not for your account, was not recognised, has been invalidated, or has already been used.', 'vip-support' );

		$user_login = $_GET[self::GET_EMAIL_USER_LOGIN];
		$user = get_user_by( 'login', $user_login );
		if ( ! $user ) {
			// 403 Forbidden – The server understood the request, but is refusing to fulfill it.
			// Authorization will not help and the request SHOULD NOT be repeated.
			wp_die( $rebuffal_message, $rebuffal_title, array( 'response' => 403 ) );
		}

		// We only want the user who was sent the email to be able to verify their email
		// (i.e. not another logged in or anonymous user clicking the link).
		// @FIXME: Should we expire the link at this point, so an attacker cannot iterate the IDs?
		if ( get_current_user_id() != $user->ID ) {
			wp_die( $rebuffal_message, $rebuffal_title, array( 'response' => 403 ) );
		}

		if ( ! $this->is_a8c_email( $user->user_email ) ) {
			wp_die( $rebuffal_message, $rebuffal_title, array( 'response' => 403 ) );
		}

		$stored_verification_code = $this->get_user_email_verification_code( $user->ID );
		$hash_sent                = (string) sanitize_text_field( $_GET[self::GET_EMAIL_VERIFY] );

		$check_hash = $this->create_check_hash( get_current_user_id(), $stored_verification_code, $user->user_email );

		if ( $check_hash !== $hash_sent ) {
			wp_die( $rebuffal_message, $rebuffal_title, array( 'response' => 403 ) );
		}

		// It's all looking good. Verify the email.
		$this->mark_user_email_verified( $user->ID, $user->user_email );

		// If the user is an A12n, add them to the support role
		if ( $this->is_a8c_email( $user->user_email ) ) {
			$user->set_role( WPCOM_VIP_Support_Role::VIP_SUPPORT_ROLE );
		}

		$message = sprintf( __( 'Your email has been verified as %s', 'vip-support' ), $user->user_email );
		$title = __( 'Verification succeeded', 'vip-support' );
		wp_die( $message, $title, array( 'response' => 200 ) );
	}

	/**
	 * Hooks the removable_query_args filter to add our arguments to those
	 * tidied up by Javascript so the user sees nicer URLs.
	 *
	 * @param array $args An array of URL parameter names which are tidied away
	 *
	 * @return array An array of URL parameter names which are tidied away
	 */
	public function filter_removable_query_args( $args ) {
		$args[] = self::GET_TRIGGER_RESEND_VERIFICATION;
		return $args;
	}

	// UTILITIES
	// =========

	/**
	 * Send a user an email with a verification link for their current email address.
	 *
	 * See the action_parse_request for information about the hash
	 * @see VipSupportUser::action_parse_request
	 *
	 * @param int $user_id The ID of the user to send the email to
	 */
	protected function send_verification_email( $user_id ) {
		// @FIXME: Should the verification code expire?


		$verification_code = $this->get_user_email_verification_code( $user_id );

		$user = new WP_User( $user_id );
		$hash = $this->create_check_hash( $user_id, $verification_code, $user->user_email );

		$hash              = urlencode( $hash );
		$user_id           = absint( $user_id );
		$verification_link = add_query_arg( array( self::GET_EMAIL_VERIFY => urlencode( $hash ), self::GET_EMAIL_USER_LOGIN => urlencode( $user->user_login ) ), home_url() );

		$user = new WP_User( $user_id );

		$message  = __( 'Dear Automattician,', 'vip-support' );
		$message .= PHP_EOL . PHP_EOL;
		$message .= sprintf( __( 'You need to verify your Automattic email address for your user on %1$s (%2$s). If you are expecting this, please click the link below to verify your email address:', 'vip-support' ), get_bloginfo( 'name' ), home_url() );
		$message .= PHP_EOL;
		$message .= esc_url_raw( $verification_link );
		$message .= PHP_EOL . PHP_EOL;
		$message .= __( 'If you have any questions, please contact the WordPress.com VIP Support Team.' );

		$subject = sprintf( __( 'Email verification for %s', 'vip-support' ), get_bloginfo( 'name' ) );

		wp_mail( $user->user_email, $subject, $message );
	}

	/**
	 * Check if a user has verified their email address.
	 *
	 * @param int $user_id The ID of the user to check
	 *
	 * @return bool True if the user has a verified email address, otherwise false
	 */
	protected function user_has_verified_email( $user_id ) {
		$user = new WP_User( $user_id );
		$verified_email = get_user_meta( $user_id, self::META_EMAIL_VERIFIED, true );
		return ( $user->user_email == $verified_email );
	}

	/**
	 * Create and return a URL with a parameter which will trigger the
	 * resending of a verification email.
	 *
	 * @return string A URL with a parameter to trigger a verification email
	 */
	protected function get_trigger_resend_verification_url() {
		return add_query_arg( array( self::GET_TRIGGER_RESEND_VERIFICATION => '1' ) );
	}

	/**
	 * Is a provided string an email address using an A8c domain.
	 *
	 * @param string $email An email address to check
	 *
	 * @return bool True if the string is an email with an A8c domain
	 */
	public function is_a8c_email( $email ) {
		if ( ! is_email( $email ) ) {
			return false;
		}
		list( $local, $domain ) = explode( '@', $email, 2 );
		$a8c_domains = array(
			'a8c.com',
			'automattic.com',
			'matticspace.com',
		);
		if ( in_array( $domain, $a8c_domains ) ) {
			return true;
		}
		return false;
	}

	/**
	 * Determine if a given user has been validated as an Automattician
	 *
	 * Checks their email address as well as their email address verification status
	 *
	 * @TODO Check the A11n is also proxxied
	 *
	 * @param int The WP User id to check
	 * @return bool Boolean indicating if the account is a valid Automattician
	 */
	public static function is_verified_automattician( $user_id ) {
		$user = new WP_User( $user_id );

		if ( ! $user || ! $user->ID ) {
			return false;
		}

		$instance = self::init();

		$is_a8c_email 	= $instance->is_a8c_email( $user->user_email );
		$email_verified = $instance->user_has_verified_email( $user->ID );

		return ( $is_a8c_email && $email_verified );
	}

	public function user_has_vip_support_role( $user_id, $active_role = true ) {
		$user = new WP_User( $user_id );

		if ( ! $user || ! $user->ID ) {
			return false;
		}

		$wp_roles = wp_roles();

		// Filter out caps that are not role names and assign to $user_roles
		if ( is_array( $user->caps ) )
			$user_roles = array_filter( array_keys( $user->caps ), array( $wp_roles, 'is_role' ) );
		
		if ( false === $active_role) {
			return in_array( WPCOM_VIP_Support_Role::VIP_SUPPORT_INACTIVE_ROLE, $user_roles, true );
		}

		return in_array( WPCOM_VIP_Support_Role::VIP_SUPPORT_ROLE, $user_roles, true );
	}

	/**
	 * Provide a randomly generated verification code to share via
	 * the email verification link.
	 *
	 * Stored in the same serialised user meta value:
	 * * The verification code
	 * * The email the verification code was generated against
	 * * The last time this method was touched, so we can calculate expiry
	 *   in the future if we want to
	 *
	 * @param int $user_id The ID of the user to get the verification code for
	 *
	 * @return string A random hex string
	 */
	protected function get_user_email_verification_code( $user_id ) {
		$generate_new_code = false;
		$user = get_user_by( 'id', $user_id );

		$verification_data = get_user_meta( $user_id, self::META_VERIFICATION_DATA, true );
		if ( ! $verification_data ) {
			$verification_data = array(
				'touch' => current_time( 'timestamp', true ), // GPL timestamp
				'email' => $user->user_email,
			);
			$generate_new_code = true;
		}

		if ( $verification_data['email'] != $user->user_email ) {
			$generate_new_code = true;
		}

		if ( $generate_new_code ) {
			$verification_data['code']  = bin2hex( openssl_random_pseudo_bytes( 16 ) );
			$verification_data['touch'] = current_time( 'timestamp', true );
		}

		update_user_meta( $user_id, self::META_VERIFICATION_DATA, $verification_data );

		return $verification_data['code'];
	}

	/**
	 * The hash sent in the email verification link is composed of the user ID, a verification code
	 * generated and stored when the email was sent (a random string), and the user email. The idea
	 * being that each verification link is tied to a user AND a particular email address, so a link
	 * does not work if the user has subsequently changed their email and does not work for another
	 * logged in or anonymous user.
	 *
	 * @param int $user_id The ID of the user to generate the hash for
	 * @param string $verification_code A string of random characters
	 * @param string $user_email The email of the user to generate the hash for
	 *
	 * @return string The check hash for the values passed
	 */
	protected function create_check_hash( $user_id, $verification_code, $user_email ) {
		return wp_hash( $user_id . $verification_code . $user_email );
	}

	/**
	 * @TODO Write a method description
	 *
	 * @param int $user_id The ID of the user to mark as having a verified email
	 * @param string $user_email The email which has been verified
	 */
	public function mark_user_email_verified( $user_id, $user_email ) {
		update_user_meta( $user_id, self::META_EMAIL_VERIFIED, $user_email );
		delete_user_meta( $user_id, self::META_VERIFICATION_DATA );
		delete_user_meta( $user_id, self::META_EMAIL_NEEDS_VERIFICATION );
	}

	/**
	 * @param int $user_id The ID of the user to mark as NOT (any longer) having a verified email
	 */
	protected function mark_user_email_unverified( $user_id ) {
		update_user_meta( $user_id, self::META_EMAIL_VERIFIED, false );
		update_user_meta( $user_id, self::META_EMAIL_NEEDS_VERIFICATION, false );
	}

}

WPCOM_VIP_Support_User::init();
