<?php
/**
 * The plugin vary class to manage X-LiteSpeed-Vary
 *
 * @since      	1.1.3
 */
namespace LiteSpeed;
defined( 'WPINC' ) || exit;

class Vary extends Root {
	const X_HEADER = 'X-LiteSpeed-Vary';

	private static $_vary_name = '_lscache_vary'; // this default vary cookie is used for logged in status check
	private static $_can_change_vary = false; // Currently only AJAX used this

	/**
	 * Adds the actions used for setting up cookies on log in/out.
	 *
	 * Also checks if the database matches the rewrite rule.
	 *
	 * @since 1.0.4
	 */
	public function init() {
		$this->_update_vary_name();
	}

	/**
	 * Update the default vary name if changed
	 *
	 * @since  4.0
	 */
	private function _update_vary_name() {
		$db_cookie = $this->conf( Base::O_CACHE_LOGIN_COOKIE ); // [3.0] todo: check if works in network's sites

		// If no vary set in rewrite rule
		if ( ! isset( $_SERVER[ 'LSCACHE_VARY_COOKIE' ] ) ) {
			if ( $db_cookie ) {
				// Display cookie error msg to admin
				if ( is_multisite() ? is_network_admin() : is_admin() ) {
					Admin_Display::show_error_cookie();
				}
				Control::set_nocache( 'vary cookie setting error' );
				return;
			}
			return;
		}
		// If db setting does not exist, skip checking db value
		if ( ! $db_cookie ) {
			return;
		}

		// beyond this point, need to make sure db vary setting is in $_SERVER env.
		$vary_arr = explode( ',', $_SERVER[ 'LSCACHE_VARY_COOKIE' ] );

		if ( in_array( $db_cookie, $vary_arr ) ) {
			self::$_vary_name = $db_cookie;
			return;
		}

		if ( is_multisite() ? is_network_admin() : is_admin() ) {
			Admin_Display::show_error_cookie();
		}
		Control::set_nocache('vary cookie setting lost error');

	}

	/**
	 * Hooks after user init
	 *
	 * @since  4.0
	 */
	public function after_user_init() {
		// logged in user
		if ( Router::is_logged_in() ) {
			// If not esi, check cache logged-in user setting
			if ( ! $this->cls( 'Router' )->esi_enabled() ) {
				// If cache logged-in, then init cacheable to private
				if ( $this->conf( Base::O_CACHE_PRIV ) ) {
					add_action( 'wp_logout', __NAMESPACE__ . '\Purge::purge_on_logout' );

					$this->cls( 'Control' )->init_cacheable();
					Control::set_private( 'logged in user' );
				}
				// No cache for logged-in user
				else {
					Control::set_nocache( 'logged in user' );
				}
			}
			// ESI is on, can be public cache
			else {
				// Need to make sure vary is using group id
				$this->cls( 'Control' )->init_cacheable();
			}

			// register logout hook to clear login status
			add_action( 'clear_auth_cookie', array( $this, 'remove_logged_in' ) );

		}
		else {
			// Only after vary init, can detect if is Guest mode or not
			$this->_maybe_guest_mode();

			// Set vary cookie for logging in user, otherwise the user will hit public with vary=0 (guest version)
			add_action( 'set_logged_in_cookie', array( $this, 'add_logged_in' ), 10, 4 );
			add_action( 'wp_login', __NAMESPACE__ . '\Purge::purge_on_logout' );

			$this->cls( 'Control' )->init_cacheable();

			// Check `login page` cacheable setting because they don't go through main WP logic
			add_action( 'login_init', array( $this->cls( 'Tag' ), 'check_login_cacheable' ), 5 );

			if ( ! empty( $_GET[ 'litespeed_guest' ] ) ) {
				add_action( 'wp_loaded', array( $this, 'update_guest_vary' ), 20 );
			}
		}

		// Add comment list ESI
		add_filter( 'comments_array', array( $this, 'check_commenter' ) );

		// Set vary cookie for commenter.
		add_action( 'set_comment_cookies', array( $this, 'append_commenter' ) );

		/**
		 * Don't change for REST call because they don't carry on user info usually
		 * @since 1.6.7
		 */
		add_action( 'rest_api_init', function(){ // this hook is fired in `init` hook
			Debug2::debug( '[Vary] Rest API init disabled vary change' );
			add_filter( 'litespeed_can_change_vary', '__return_false' );
		} );
	}

	/**
	 * Check if is Guest mode or not
	 *
	 * @since  4.0
	 */
	private function _maybe_guest_mode() {
		if ( ! $this->conf( Base::O_GUEST ) ) {
			return;
		}

		// If vary is set, then not a guest
		if ( self::has_vary() ) {
			return;
		}

		// If has admin QS, then no guest
		if ( ! empty( $_GET[ Router::ACTION ] ) ) {
			return;
		}

		if ( defined( 'DOING_AJAX' ) ) {
			return;
		}

		if ( defined( 'DOING_CRON' ) ) {
			return;
		}

		// If is the request to update vary, then no guest
		if ( ! empty( $_GET[ 'litespeed_guest' ] ) ) {
			return;
		}

		Debug2::debug( '[Vary] 👒👒 Guest mode' );

		! defined( 'LITESPEED_GUEST' ) && define( 'LITESPEED_GUEST', true );

		if ( $this->conf( Base::O_GUEST_OPTM ) ) {
			! defined( 'LITESPEED_GUEST_OPTM' ) && define( 'LITESPEED_GUEST_OPTM', true );
		}
	}

	/**
	 * Update Guest vary
	 *
	 * @since  4.0
	 */
	public function update_guest_vary() {
		if ( $this->_always_guest() ) {
			! defined( 'LITESPEED_GUEST' ) && define( 'LITESPEED_GUEST', true );
			Debug2::debug( '[Vary] 🤠🤠 Guest' );
			echo '[]';
			exit;
		}

		$vary = $this->finalize_default_vary();

		// save it
		$expire = time() + 2 * DAY_IN_SECONDS;

		$this->_cookie( $vary, $expire );
		Debug2::debug( "[Vary] update guest vary set_cookie ---> $vary" );

		// return json
		echo json_encode( array( 'reload' => 'yes' ) );
		exit;
	}

	/**
	 * Detect if is a guest visitor
	 *
	 * @since  4.0
	 */
	private function _always_guest() {
		if ( empty( $_SERVER[ 'HTTP_USER_AGENT' ] ) ) {
			return false;
		}

		$match = preg_match( '#Page Speed|Lighthouse|GTmetrix|Google|Pingdom|bot#i', $_SERVER[ 'HTTP_USER_AGENT' ] );
		if ( $match ) {
			return true;
		}

		$ips = [
			'208.70.247.157',
			'172.255.48.130',
			'172.255.48.131',
			'172.255.48.132',
			'172.255.48.133',
			'172.255.48.134',
			'172.255.48.135',
			'172.255.48.136',
			'172.255.48.137',
			'172.255.48.138',
			'172.255.48.139',
			'172.255.48.140',
			'172.255.48.141',
			'172.255.48.142',
			'172.255.48.143',
			'172.255.48.144',
			'172.255.48.145',
			'172.255.48.146',
			'172.255.48.147',
			'52.229.122.240',
			'104.214.72.101',
			'13.66.7.11',
			'13.85.24.83',
			'13.85.24.90',
			'13.85.82.26',
			'40.74.242.253',
			'40.74.243.13',
			'40.74.243.176',
			'104.214.48.247',
			'157.55.189.189',
			'104.214.110.135',
			'70.37.83.240',
			'65.52.36.250',
			'13.78.216.56',
			'52.162.212.163',
			'23.96.34.105',
			'65.52.113.236',
			'172.255.61.34',
			'172.255.61.35',
			'172.255.61.36',
			'172.255.61.37',
			'172.255.61.38',
			'172.255.61.39',
			'172.255.61.40',
			'104.41.2.19',
			'191.235.98.164',
			'191.235.99.221',
			'191.232.194.51',
			'52.237.235.185',
			'52.237.250.73',
			'52.237.236.145',
			'104.211.143.8',
			'104.211.165.53',
			'52.172.14.87',
			'40.83.89.214',
			'52.175.57.81',
			'20.188.63.151',
			'20.52.36.49',
			'52.246.165.153',
			'51.144.102.233',
			'13.76.97.224',
			'102.133.169.66',
			'52.231.199.170',
			'13.53.162.7',
			'40.123.218.94',
		];

		if ( $this->cls( 'Router' )->ip_access( $ips ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Hooked to the comments_array filter.
	 *
	 * Check if the user accessing the page has the commenter cookie.
	 *
	 * If the user does not want to cache commenters, just check if user is commenter.
	 * Otherwise if the vary cookie is set, unset it. This is so that when the page is cached, the page will appear as if the user was a normal user.
	 * Normal user is defined as not a logged in user and not a commenter.
	 *
	 * @since 1.0.4
	 * @access public
	 * @global type $post
	 * @param array $comments The current comments to output
	 * @return array The comments to output.
	 */
	public function check_commenter( $comments ) {
		/**
		 * Hook to bypass pending comment check for comment related plugins compatibility
		 * @since 2.9.5
		 */
		if ( apply_filters( 'litespeed_vary_check_commenter_pending', true ) ) {
			$pending = false;
			foreach ( $comments as $comment ) {
				if ( ! $comment->comment_approved ) { // current user has pending comment
					$pending = true;
					break;
				}
			}

			// No pending comments, don't need to add private cache
			if ( ! $pending ) {
				Debug2::debug( '[Vary] No pending comment' );
				$this->remove_commenter();

				// Remove commenter prefilled info if exists, for public cache
				foreach( $_COOKIE as $cookie_name => $cookie_value ) {
					if ( strlen( $cookie_name ) >= 15 && strpos( $cookie_name, 'comment_author_' ) === 0 ) {
						unset( $_COOKIE[ $cookie_name ] );
					}
				}

				return $comments;
			}
		}

		// Current user/visitor has pending comments
		// set vary=2 for next time vary lookup
		$this->add_commenter();

		if ( $this->conf( Base::O_CACHE_COMMENTER ) ) {
			Control::set_private( 'existing commenter' );
		}
		else {
			Control::set_nocache( 'existing commenter' );
		}

		return $comments;
	}

	/**
	 * Check if default vary has a value
	 *
	 * @since 1.1.3
	 * @access public
	 */
	public static function has_vary() {
		if ( empty( $_COOKIE[ self::$_vary_name ] ) ) {
			return false;
		}
		return $_COOKIE[ self::$_vary_name ];
	}

	/**
	 * Append user status with logged in
	 *
	 * @since 1.1.3
	 * @since 1.6.2 Removed static referral
	 * @access public
	 */
	public function add_logged_in( $logged_in_cookie = false, $expire = false, $expiration = false, $uid = false ) {
		Debug2::debug( '[Vary] add_logged_in' );

		/**
		 * NOTE: Run before `$this->_update_default_vary()` to make vary changeable
		 * @since  2.2.2
		 */
		self::can_ajax_vary();

		// If the cookie is lost somehow, set it
		$this->_update_default_vary( $uid, $expire );
	}

	/**
	 * Remove user logged in status
	 *
	 * @since 1.1.3
	 * @since 1.6.2 Removed static referral
	 * @access public
	 */
	public function remove_logged_in() {
		Debug2::debug( '[Vary] remove_logged_in' );

		/**
		 * NOTE: Run before `$this->_update_default_vary()` to make vary changeable
		 * @since  2.2.2
		 */
		self::can_ajax_vary();

		// Force update vary to remove login status
		$this->_update_default_vary( -1 );
	}

	/**
	 * Allow vary can be changed for ajax calls
	 *
	 * @since 2.2.2
	 * @since 2.6 Changed to static
	 * @access public
	 */
	public static function can_ajax_vary() {
		Debug2::debug( '[Vary] _can_change_vary -> true' );
		self::$_can_change_vary = true;
	}

	/**
	 * Check if can change default vary
	 *
	 * @since 1.6.2
	 * @access private
	 */
	private function can_change_vary() {
		// Don't change for ajax due to ajax not sending webp header
		if ( Router::is_ajax() ) {
			if ( ! self::$_can_change_vary ) {
				Debug2::debug( '[Vary] can_change_vary bypassed due to ajax call' );
				return false;
			}
		}

		/**
		 * POST request can set vary to fix #820789 login "loop" guest cache issue
		 * @since 1.6.5
		 */
		if ( $_SERVER["REQUEST_METHOD"] !== 'GET' && $_SERVER["REQUEST_METHOD"] !== 'POST' ) {
			Debug2::debug( '[Vary] can_change_vary bypassed due to method not get/post' );
			return false;
		}

		/**
		 * Disable vary change if is from crawler
		 * @since  2.9.8 To enable woocommerce cart not empty warm up (@Taba)
		 */
		if ( ! empty( $_SERVER[ 'HTTP_USER_AGENT' ] ) && strpos( $_SERVER[ 'HTTP_USER_AGENT' ], Crawler::FAST_USER_AGENT ) === 0 ) {
			Debug2::debug( '[Vary] can_change_vary bypassed due to crawler' );
			return false;
		}

		if ( ! apply_filters( 'litespeed_can_change_vary', true ) ) {
			Debug2::debug( '[Vary] can_change_vary bypassed due to litespeed_can_change_vary hook' );
			return false;
		}

		return true;
	}

	/**
	 * Update default vary
	 *
	 * @since 1.6.2
	 * @since  1.6.6.1 Add ran check to make it only run once ( No run multiple times due to login process doesn't have valid uid )
	 * @access private
	 */
	private function _update_default_vary( $uid = false, $expire = false ) {
		// Make sure header output only run once
		if ( ! defined( 'LITESPEED_DID_' . __FUNCTION__ ) ) {
			define( 'LITESPEED_DID_' . __FUNCTION__, true );
		}
		else {
			Debug2::debug2( "[Vary] _update_default_vary bypassed due to run already" );
			return;
		}

		// If the cookie is lost somehow, set it
		$vary = $this->finalize_default_vary( $uid );
		$current_vary = self::has_vary();
		if ( $current_vary !== $vary && $current_vary !== 'commenter' && $this->can_change_vary() ) {
			// $_COOKIE[ self::$_vary_name ] = $vary; // not needed

			// save it
			if ( ! $expire ) {
				$expire = time() + 2 * DAY_IN_SECONDS;
			}
			$this->_cookie( $vary, $expire );
			Debug2::debug( "[Vary] set_cookie ---> $vary" );
			// Control::set_nocache( 'changing default vary' . " $current_vary => $vary" );
		}
	}

	/**
	 * Get vary name
	 *
	 * @since 1.9.1
	 * @access public
	 */
	public function get_vary_name() {
		return self::$_vary_name;
	}

	/**
	 * Check if one user role is in vary group settings
	 *
	 * @since 1.2.0
	 * @since  3.0 Moved here from conf.cls
	 * @access public
	 * @param  string $role The user role
	 * @return int       The set value if already set
	 */
	public function in_vary_group( $role ) {
		$group = 0;
		$vary_groups = $this->conf( Base::O_CACHE_VARY_GROUP );
		if ( array_key_exists( $role, $vary_groups ) ) {
			$group = $vary_groups[ $role ];
		}
		elseif ( $role === 'administrator' ) {
			$group = 99;
		}

		if ( $group ) {
			Debug2::debug2( '[Vary] role in vary_group [group] ' . $group );
		}

		return $group;
	}

	/**
	 * Finalize default Vary Cookie
	 *
	 *  Get user vary tag based on admin_bar & role
	 *
	 * NOTE: Login process will also call this because it does not call wp hook as normal page loading
	 *
	 * @since 1.6.2
	 * @access public
	 */
	public function finalize_default_vary( $uid = false ) {
		if ( defined( 'LITESPEED_GUEST' ) ) {
			return false;
		}

		$vary = array();

		if ( $this->conf( Base::O_GUEST ) ) {
			$vary[ 'guest_mode' ] = 1;
		}

		if ( ! $uid ) {
			$uid = get_current_user_id();
		}
		else {
			Debug2::debug( '[Vary] uid: ' . $uid );
		}

		// get user's group id
		$role = Router::get_role( $uid );

		if ( $uid > 0 && $role ) {
			$vary[ 'logged-in' ] = 1;

			// parse role group from settings
			if ( $role_group = $this->in_vary_group( $role ) ) {
				$vary[ 'role' ] = $role_group;
			}

			// Get admin bar set
			// see @_get_admin_bar_pref()
			$pref = get_user_option( 'show_admin_bar_front', $uid );
			Debug2::debug2( '[Vary] show_admin_bar_front: ' . $pref );
			$admin_bar = $pref === false || $pref === 'true';

			if ( $admin_bar ) {
				$vary[ 'admin_bar' ] = 1;
				Debug2::debug2( '[Vary] admin bar : true' );
			}

		}
		else {
			// Guest user
			Debug2::debug( '[Vary] role id: failed, guest' );

		}

		/**
		 * Add filter
		 * @since 1.6 Added for Role Excludes for optimization cls
		 * @since 1.6.2 Hooked to webp (checked in v4, no webp anymore)
		 * @since 3.0 Used by 3rd hooks too
		 */
		$vary = apply_filters( 'litespeed_vary', $vary );

		if ( ! $vary ) {
			return false;
		}

		ksort( $vary );
		$res = array();
		foreach ( $vary as $key => $val ) {
			$res[] = $key . ':' . $val;
		}

		$res = implode( ';', $res );
		if ( defined( 'LSCWP_LOG' ) ) {
			return $res;
		}
		// Encrypt in production
		return md5( $this->conf( Base::HASH ) . $res );
	}

	/**
	 * Get the hash of all vary related values
	 *
	 * @since  4.0
	 */
	public function finalize_full_varies() {
		$vary = $this->_finalize_curr_vary_cookies( true );
		$vary .= $this->finalize_default_vary( get_current_user_id() );
		$vary .= $this->get_env_vary();
		return $vary;
	}

	/**
	 * Get request environment Vary
	 *
	 * @since  4.0
	 */
	public function get_env_vary() {
		$env_vary = isset( $_SERVER[ 'LSCACHE_VARY_VALUE' ] ) ? $_SERVER[ 'LSCACHE_VARY_VALUE' ] : false;
		if ( ! $env_vary ) {
			$env_vary = isset( $_SERVER[ 'HTTP_X_LSCACHE_VARY_VALUE' ] ) ? $_SERVER[ 'HTTP_X_LSCACHE_VARY_VALUE' ] : false;
		}
		return $env_vary;
	}

	/**
	 * Append user status with commenter
	 *
	 * This is ONLY used when submit a comment
	 *
	 * @since 1.1.6
	 * @access public
	 */
	public function append_commenter() {
		$this->add_commenter( true );
	}

	/**
	 * Correct user status with commenter
	 *
	 * @since 1.1.3
	 * @access private
	 * @param  boolean $from_redirect If the request is from redirect page or not
	 */
	private function add_commenter( $from_redirect = false ) {
		// If the cookie is lost somehow, set it
		if ( self::has_vary() !== 'commenter' ) {
			Debug2::debug( '[Vary] Add commenter' );
			// $_COOKIE[ self::$_vary_name ] = 'commenter'; // not needed

			// save it
			// only set commenter status for current domain path
			$this->_cookie( 'commenter', time() + apply_filters( 'comment_cookie_lifetime', 30000000 ), self::_relative_path( $from_redirect ) );
			// Control::set_nocache( 'adding commenter status' );
		}
	}

	/**
	 * Remove user commenter status
	 *
	 * @since 1.1.3
	 * @access private
	 */
	private function remove_commenter() {
		if ( self::has_vary() === 'commenter' ) {
			Debug2::debug( '[Vary] Remove commenter' );
			// remove logged in status from global var
			// unset( $_COOKIE[ self::$_vary_name ] ); // not needed

			// save it
			$this->_cookie( false, false, self::_relative_path() );
			// Control::set_nocache( 'removing commenter status' );
		}
	}

	/**
	 * Generate relative path for cookie
	 *
	 * @since 1.1.3
	 * @access private
	 * @param  boolean $from_redirect If the request is from redirect page or not
	 */
	private static function _relative_path( $from_redirect = false ) {
		$path = false;
		$tag = $from_redirect ? 'HTTP_REFERER' : 'SCRIPT_URL';
		if ( ! empty( $_SERVER[ $tag ] ) ) {
			$path = parse_url( $_SERVER[ $tag ] );
			$path = ! empty( $path[ 'path' ] ) ? $path[ 'path' ] : false;
			Debug2::debug( '[Vary] Cookie Vary path: ' . $path );
		}
		return $path;
	}

	/**
	 * Builds the vary header.
	 *
	 * NOTE: Non caccheable page can still set vary ( for logged in process )
	 *
	 * Currently, this only checks post passwords and 3rd party.
	 *
	 * @since 1.0.13
	 * @access public
	 * @global $post
	 * @return mixed false if the user has the postpass cookie. Empty string if the post is not password protected. Vary header otherwise.
	 */
	public function finalize() {
		// Finalize default vary
		$this->_update_default_vary();

		$tp_cookies = $this->_finalize_curr_vary_cookies();

		if ( ! $tp_cookies ) {
			Debug2::debug2( '[Vary] no custimzed vary' );
			return;
		}

		return self::X_HEADER . ': ' . implode( ',', $tp_cookies );
	}

	/**
	 * Gets vary cookies or their values unique hash that are already added for the current page.
	 *
	 * @since 1.0.13
	 * @access private
	 * @return array List of all vary cookies currently added.
	 */
	private function _finalize_curr_vary_cookies( $values_json = false ) {
		global $post;

		$cookies = array(); // No need to append default vary cookie name

		if ( ! empty( $post->post_password ) ) {
			$postpass_key = 'wp-postpass_' . COOKIEHASH;
			if ( $this->_get_cookie_val( $postpass_key ) ) {
				Debug2::debug( '[Vary] finalize bypassed due to password protected vary ' );
				// If user has password cookie, do not cache & ignore existing vary cookies
				Control::set_nocache( 'password protected vary' );
				return false;
			}

			$cookies[] = $values_json ? $this->_get_cookie_val( $postpass_key ) : $postpass_key;
		}

		$cookies = apply_filters( 'litespeed_vary_curr_cookies', $cookies );
		if ( $cookies ) {
			$cookies = array_filter( array_unique( $cookies ) );
			Debug2::debug( '[Vary] vary cookies changed by filter litespeed_vary_curr_cookies', $cookies );
		}

		if ( ! $cookies ) {
			return false;
		}
		// Format cookie name data or value data
		sort( $cookies ); // This is to maintain the cookie val orders for $values_json=true case.
		foreach ( $cookies as $k => $v ) {
			$cookies[ $k ] = $values_json ? $this->_get_cookie_val( $v ) : 'cookie=' . $v;
		}

		return $values_json ? json_encode( $cookies ) : $cookies;
	}

	/**
	 * Get one vary cookie value
	 *
	 * @since  4.0
	 */
	private function _get_cookie_val( $key ) {
		if ( ! empty( $_COOKIE[ $key ] ) ) {
			return $_COOKIE[ $key ];
		}

		return false;
	}

	/**
	 * Set the vary cookie.
	 *
	 * If vary cookie changed, must set non cacheable.
	 *
	 * @since 1.0.4
	 * @access private
	 * @param integer $val The value to update.
	 * @param integer $expire Expire time.
	 * @param boolean $path False if use wp root path as cookie path
	 */
	private function _cookie($val = false, $expire = false, $path = false) {
		if ( ! $val ) {
			$expire = 1;
		}

		/**
		 * Add HTTPS bypass in case clients use both HTTP and HTTPS version of site
		 * @since 1.7
		 */
		$is_ssl = $this->conf( Base::O_UTIL_NO_HTTPS_VARY ) ? false : is_ssl();

		setcookie( self::$_vary_name, $val, $expire, $path?: COOKIEPATH, COOKIE_DOMAIN, $is_ssl, true );
	}

}
