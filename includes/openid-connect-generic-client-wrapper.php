<?php

class OpenID_Connect_Generic_Client_Wrapper {
	
	private $client;
	
	// settings object
	private $settings;
	
	// logger object
	private $logger;

	// token refresh info cookie key
	private $cookie_token_refresh_key = 'openid-connect-generic-refresh';

	// user redirect cookie key
	public $cookie_redirect_key = 'openid-connect-generic-redirect';

	// WP_Error if there was a problem, or false if no error
	private $error = false;

	
	/**
	 * Inject necessary objects and services into the client
	 * 
	 * @param \WP_Option_Settings $settings
	 * @param \WP_Option_Logger $logger
	 */
	function __construct( OpenID_Connect_Generic_Client $client, WP_Option_Settings $settings, WP_Option_Logger $logger ){
		$this->client = $client;
		$this->settings = $settings;
		$this->logger = $logger;
	}

	/**
	 * Hook the client into WP
	 *
	 * @param \OpenID_Connect_Generic_Client $client
	 * @param \WP_Option_Settings $settings
	 * @param \WP_Option_Logger $logger
	 *
	 * @return \OpenID_Connect_Generic_Client_Wrapper
	 */
	static public function register( OpenID_Connect_Generic_Client $client, WP_Option_Settings $settings, WP_Option_Logger $logger ){
		$client_wrapper  = new self( $client, $settings, $logger );
		
		// remove cookies on logout
		add_action( 'wp_logout', array( $client_wrapper, 'wp_logout' ) );

		// integrated logout
		if ( $settings->endpoint_end_session ) {
			add_filter( 'allowed_redirect_hosts', array( $client_wrapper, 'update_allowed_redirect_hosts' ), 99, 1 );
			add_filter( 'logout_redirect', array( $client_wrapper, 'get_end_session_logout_redirect_url' ), 99, 1 );
		}

		// alter the requests according to settings
		add_filter( 'openid-connect-generic-alter-request', array( $client_wrapper, 'alter_request' ), 10, 3 );
		add_filter( 'http_request_timeout', array( $client_wrapper, 'alter_http_request_timeout' ) );

		if ( is_admin() ) {
			// use the ajax url to handle processing authorization without any html output
			// this callback will occur when then IDP returns with an authenticated value
			add_action( 'wp_ajax_openid-connect-authorize', array( $client_wrapper, 'authentication_request_callback' ) );
			add_action( 'wp_ajax_nopriv_openid-connect-authorize', array( $client_wrapper, 'authentication_request_callback' ) );
		}

		if ( $settings->alternate_redirect_uri ){
			// provide an alternate route for authentication_request_callback
			add_rewrite_rule( '^openid-connect-authorize/?', 'index.php?openid-connect-authorize=1', 'top' );
			add_rewrite_tag( '%openid-connect-authorize%', '1' );
			add_action( 'parse_request', array( $client_wrapper, 'alternate_redirect_uri_parse_request' ) );
		}

		// verify token for any logged in user
		if ( is_user_logged_in() ) {
			$client_wrapper->ensure_tokens_still_fresh();
		}
		
		return $client_wrapper;
	}

	/**
	 * Implements WP action - parse_request
	 * 
	 * @param $query
	 *
	 * @return mixed
	 */
	function alternate_redirect_uri_parse_request( $query ){
		if ( isset( $query->query_vars['openid-connect-authorize'] ) &&
		     $query->query_vars['openid-connect-authorize'] === '1' )
		{
			$this->authentication_request_callback();
			exit;
		}

		return $query;
	}

	/**
	 * WP Hook for altering remote request timeout
	 * 
	 * @param $timeout
	 * 
	 * @return int
	 */
	function alter_http_request_timeout( $timeout ){
		if ( is_numeric( $this->settings->http_request_timeout ) ){
			return absint( $this->settings->http_request_timeout );
		}
		
		return $timeout;
	}
	
	/**
	 * Get the authentication url from the client
	 * 
	 * @return string
	 */
	function get_authentication_url(){
		return $this->client->make_authentication_url();
	}

	/**
	 * Handle retrieval and validation of refresh_token
	 */
	function ensure_tokens_still_fresh() {
		if ( ! is_user_logged_in() ) {
			return;
		}

		$is_openid_connect_user = get_user_meta( wp_get_current_user()->ID, 'openid-connect-generic-user', TRUE );
		if ( empty( $is_openid_connect_user ) ) {
			return;
		}

		if ( ! isset( $_COOKIE[ $this->cookie_token_refresh_key] ) ) {
			wp_logout();
			$this->error_redirect( new WP_Error( 'token-refresh-cookie-missing', __( 'Single sign-on cookie missing. Please login again.' ), $_COOKIE ) );
			exit;
		}

		$user_id = wp_get_current_user()->ID;
		$current_time = current_time( 'timestamp', TRUE );
		$refresh_token_info = $this->read_token_refresh_info_from_cookie( $user_id );

		if ( ! $refresh_token_info ) {
			wp_logout();
			$this->error_redirect( new WP_Error( 'token-refresh-cookie-missing', __( 'Single sign-on cookie invalid. Please login again.' ), $_COOKIE ) );
		}

		$next_access_token_refresh_time = $refresh_token_info[ 'next_access_token_refresh_time' ];
		$refresh_token = $refresh_token_info[ 'refresh_token' ];

		if ( $current_time < $next_access_token_refresh_time ) {
			return;
		}

		if ( ! $refresh_token ) {
			wp_logout();
			$this->error_redirect( new WP_Error( 'access-token-expired', __( 'Session expired. Please login again.' ) ) );
		}

		$token_result = $this->client->request_new_tokens( $refresh_token );
		
		if ( is_wp_error( $token_result ) ) {
			wp_logout();
			$this->error_redirect( $token_result );
		}

		$token_response = $this->client->get_token_response( $token_result );

		if ( is_wp_error( $token_response ) ) {
			wp_logout();
			$this->error_redirect( $token_response );
		}

		$this->issue_token_refresh_info_cookie( $user_id, $token_response );
	}

	/**
	 * Handle errors by redirecting the user to the login form
	 *  along with an error code
	 *
	 * @param $error WP_Error
	 */
	function error_redirect( $error ) {
		$this->logger->log( $error );
		
		// redirect user back to login page
		wp_redirect(  
			wp_login_url() . 
			'?login-error=' . $error->get_error_code() .
		    '&message=' . urlencode( $error->get_error_message() )
		);
		exit;
	}

	/**
	 * Get the current error state
	 *
	 * @return bool | WP_Error
	 */
	function get_error(){
		return $this->error;
	}
	
	/**
	 * Implements hook wp_logout
	 *
	 * Remove cookies
	 */
	function wp_logout() {
		// set OpenID Connect user flag to false on logout to allow users to log into the same account without OpenID Connect
		if( $this->settings->link_existing_users ) {
			if( get_user_meta( wp_get_current_user()->ID, 'openid-connect-generic-user', TRUE ) )
				update_user_meta( wp_get_current_user()->ID, 'openid-connect-generic-user', FALSE );
		}
		
		setcookie( $this->cookie_token_refresh_key, false, 1, COOKIEPATH, COOKIE_DOMAIN, is_ssl() );
	}

	/**
	 * Add the end_session endpoint to WP core's whitelist of redirect hosts
	 *
	 * @param array $allowed
	 *
	 * @return array
	 */
	function update_allowed_redirect_hosts( array $allowed ) {
		$host = parse_url( $this->settings->endpoint_end_session, PHP_URL_HOST );
		if ( ! $host ) {
			return false;
		}

		$allowed[] = $host;
		return $allowed;
	}

	/**
	 * Handle the logout redirect for end_session endpoint
	 *
	 * @param $redirect_url
	 *
	 * @return string
	 */
	function get_end_session_logout_redirect_url( $redirect_url ) {
		$url = $this->settings->endpoint_end_session;
		$query = parse_url( $url, PHP_URL_QUERY );
		$url .= $query ? '&' : '?';

		// prevent redirect back to the IdP when logging out in auto mode
		if ( $this->settings->login_type === 'auto' && $redirect_url === 'wp-login.php?loggedout=true' ) {
			$redirect_url = '';
		}

		// convert to absolute url if needed
		if ( ! parse_url( $redirect_url, PHP_URL_HOST ) ) {
			$redirect_url = home_url( $redirect_url );
		}

		$url .= 'post_logout_redirect_uri=' . urlencode( $redirect_url );
		return $url;
	}

	/**
	 * Modify outgoing requests according to settings
	 *
	 * @param $request
	 * @param $op
	 *
	 * @return mixed
	 */
	function alter_request( $request, $op ) {
		if ( $this->settings->no_sslverify ) {
			$request['sslverify'] = FALSE;
		}

		return $request;
	}
	
	/**
	 * Control the authentication and subsequent authorization of the user when
	 *  returning from the IDP.
	 */
	function authentication_request_callback() {
		$client = $this->client;
		
		// start the authentication flow
		$authentication_request = $client->validate_authentication_request( $_GET );
		
		if ( is_wp_error( $authentication_request ) ){
			$this->error_redirect( $authentication_request );
		}
		
		// retrieve the authentication code from the authentication request
		$code = $client->get_authentication_code( $authentication_request );
		
		if ( is_wp_error( $code ) ){
			$this->error_redirect( $code );
		}

		// attempting to exchange an authorization code for an authentication token
		$token_result = $client->request_authentication_token( $code );
		
		if ( is_wp_error( $token_result ) ) {
			$this->error_redirect( $token_result );
		}

		// get the decoded response from the authentication request result
		$token_response = $client->get_token_response( $token_result );

		if ( is_wp_error( $token_response ) ){
			$this->error_redirect( $token_response );
		}

		// ensure the that response contains required information
		$valid = $client->validate_token_response( $token_response );
		
		if ( is_wp_error( $valid ) ) {
			$this->error_redirect( $valid );
		}

		/**
		 * End authentication
		 * -
		 * Start Authorization
		 */
		// The id_token is used to identify the authenticated user, e.g. for SSO.
		// The access_token must be used to prove access rights to protected resources
		// e.g. for the userinfo endpoint
		$id_token_claim = $client->get_id_token_claim( $token_response );
		
		if ( is_wp_error( $id_token_claim ) ){
			$this->error_redirect( $id_token_claim );
		}
		
		// validate our id_token has required values
		$valid = $client->validate_id_token_claim( $id_token_claim );
		
		if ( is_wp_error( $valid ) ){
			$this->error_redirect( $valid );
		}
		
		// exchange the token_response for a user_claim
		$user_claim = $client->get_user_claim( $token_response );
		
		if ( is_wp_error( $user_claim ) ){
			$this->error_redirect( $user_claim );
		}
		
		// validate our user_claim has required values
		$valid = $client->validate_user_claim( $user_claim, $id_token_claim );
		
		if ( is_wp_error( $valid ) ){
			$this->error_redirect( $valid );
		}

		/**
		 * End authorization
		 * -
		 * Request is authenticated and authorized - start user handling
		 */
		$subject_identity = $client->get_subject_identity( $id_token_claim );
		$user = $this->get_user_by_identity( $subject_identity );

		// if we didn't find an existing user, we'll need to create it
		if ( ! $user ) {
			$user = $this->create_new_user( $subject_identity, $user_claim );
		}
		else {
			// allow plugins / themes to take action using current claims on existing user (e.g. update role)
			do_action( 'openid-connect-generic-update-user-using-current-claim', $user, $user_claim );
		}

		// validate the found / created user
		$valid = $this->validate_user( $user );
		
		if ( is_wp_error( $valid ) ){
			$this->error_redirect( $valid );
		}

		// login the found / created user
		$this->login_user( $user, $token_response, $id_token_claim, $user_claim, $subject_identity  );
		
		// log our success
		$this->logger->log( "Successful login for: {$user->user_login} ({$user->ID})", 'login-success' );

		// redirect back to the origin page if enabled
		$redirect_url = esc_url( $_COOKIE[ $this->cookie_redirect_key ] );

		if( $this->settings->redirect_user_back && !empty( $redirect_url ) ) {
			do_action( 'openid-connect-generic-redirect-user-back', $redirect_url, $user );
			setcookie( $this->cookie_redirect_key, $redirect_url, 1, COOKIEPATH, COOKIE_DOMAIN, is_ssl() );
			wp_redirect( $redirect_url );
		}
		// otherwise, go home!
		else {
			wp_redirect( home_url() );
		}
	}

	/**
	 * Validate the potential WP_User 
	 * 
	 * @param $user
	 *
	 * @return \WP_Error
	 */
	function validate_user( $user ){
		// ensure our found user is a real WP_User
		if ( ! is_a( $user, 'WP_User' ) || ! $user->exists() ) {
			return new WP_Error( 'invalid-user', __( 'Invalid user' ), $user );
		}
		
		return true;
	}

	/**
	 * Record user meta data, and provide an authorization cookie
	 * 
	 * @param $user
	 */
	function login_user( $user, $token_response, $id_token_claim, $user_claim, $subject_identity ){
		// hey, we made it!
		// let's remember the tokens for future reference
		update_user_meta( $user->ID, 'openid-connect-generic-last-token-response', $token_response );
		update_user_meta( $user->ID, 'openid-connect-generic-last-id-token-claim', $id_token_claim );
		update_user_meta( $user->ID, 'openid-connect-generic-last-user-claim', $user_claim );

		// if we're allowing users to use WordPress and OpenID Connect, we need to set this to true at every login
		if( $this->settings->link_existing_users ) {
			update_user_meta( $user->ID, 'openid-connect-generic-user', TRUE );
		}

		// you did great, have a cookie!
		$this->issue_token_refresh_info_cookie( $user->ID, $token_response );
		wp_set_auth_cookie( $user->ID, FALSE );
	}

	/**
	 * Create encrypted refresh_token cookie
	 *
	 * @param $user_id
	 * @param $token_response
	 */
	function issue_token_refresh_info_cookie( $user_id, $token_response ) {
		$cookie_value = serialize( array(
			'next_access_token_refresh_time' => $token_response['expires_in'] + current_time( 'timestamp' , TRUE ),
			'refresh_token' => isset( $token_response[ 'refresh_token' ] ) ? $token_response[ 'refresh_token' ] : false
		) );
		$key = $this->get_refresh_cookie_encryption_key( $user_id );
		$encrypted_cookie_value = \Defuse\Crypto\Crypto::encrypt( $cookie_value, $key );
		setcookie( $this->cookie_token_refresh_key, $encrypted_cookie_value, 0, COOKIEPATH, COOKIE_DOMAIN, is_ssl() );
	}

	/**
	 * Retrieve and decrypt refresh_token contents from user cookie
	 * @param $user_id
	 *
	 * @return bool|mixed
	 */
	function read_token_refresh_info_from_cookie( $user_id ) {
		if ( ! isset( $_COOKIE[ $this->cookie_token_refresh_key ] ) ) {
			return false;
		}

		try {
			$encrypted_cookie_value = $_COOKIE[$this->cookie_token_refresh_key];
			$key = $this->get_refresh_cookie_encryption_key( $user_id );
			$cookie_value = unserialize( \Defuse\Crypto\Crypto::decrypt($encrypted_cookie_value, $key) );

			if ( ! isset( $cookie_value[ 'next_access_token_refresh_time' ] )
				|| ! $cookie_value[ 'next_access_token_refresh_time' ]
				|| ! isset( $cookie_value[ 'refresh_token' ] ) )
			{
				return false;
			}

			return $cookie_value;
		}
		catch ( Exception $e ) {
			$this->logger->log( $e->getMessage() );
			return false;
		}
	}

	/**
	 * Retrieve or regenerate a user's unique encryption key
	 *
	 * @param $user_id
	 *
	 * @return \Defuse\Crypto\Key
	 */
	function get_refresh_cookie_encryption_key( $user_id ) {
		$meta_key = 'openid-connect-generic-refresh-cookie-key';
		$existing_key_string = get_user_meta( $user_id, $meta_key, true );

		try {
			$user_encryption_key = \Defuse\Crypto\Key::loadFromAsciiSafeString( $existing_key_string );
		}
		catch ( Exception $e ) {
			$this->logger->log( "Error loading user {$user_id} refresh token cookie key, generating new: " . $e->getMessage() );
			$user_encryption_key = \Defuse\Crypto\Key::createNewRandomKey();
			update_user_meta( $user_id, $meta_key, $user_encryption_key->saveToAsciiSafeString() );
		}

		return $user_encryption_key;
	}
	
	/**
	 * Get the user that has meta data matching a 
	 * 
	 * @param $subject_identity
	 *
	 * @return false|\WP_User
	 */
	function get_user_by_identity( $subject_identity ){
		// look for user by their openid-connect-generic-subject-identity value
		$user_query = new WP_User_Query( array(
			'meta_query' => array(
				array(
					'key'   => 'openid-connect-generic-subject-identity',
					'value' => $subject_identity,
				)
			)
		) );

		// if we found an existing users, grab the first one returned
		if ( $user_query->get_total() > 0 ) {
			$users = $user_query->get_results();
			return $users[0];
		}
		
		return false;
	}

	/**
	 * Avoid user_login collisions by incrementing
	 *
	 * @param $user_claim array
	 *
	 * @return string
	 */
	private function get_username_from_claim( $user_claim ) {
		// allow settings to take first stab at username
		if ( !empty( $this->settings->identity_key ) && isset( $user_claim[ $this->settings->identity_key ] ) ) {
			$desired_username =  $user_claim[ $this->settings->identity_key ];
		}
		else if ( isset( $user_claim['preferred_username'] ) && ! empty( $user_claim['preferred_username'] ) ) {
			$desired_username = $user_claim['preferred_username'];
		}
		else if ( isset( $user_claim['name'] ) && ! empty( $user_claim['name'] ) ) {
			$desired_username = $user_claim['name'];
		}
		else if ( isset( $user_claim['email'] ) && ! empty( $user_claim['email'] ) ) {
			$tmp = explode( '@', $user_claim['email'] );
			$desired_username = $tmp[0];
		}
		else {
			// nothing to build a name from
			return new WP_Error( 'no-username', __( 'No appropriate username found' ), $user_claim );
		}

		// normalize the data a bit
		$desired_username = strtolower( preg_replace( '/[^a-zA-Z\_0-9]/', '', $desired_username ) );

		// copy the username for incrementing
		$username = $desired_username;

		// original user gets "name"
		// second user gets "name2"
		// etc
		$count = 1;
		while ( username_exists( $username ) ) {
			$count ++;
			$username = $desired_username . $count;
		}

		return $username;
	}
	
	/**
	 * Create a new user from details in a user_claim
	 * 
	 * @param $subject_identity
	 * @param $user_claim
	 *
	 * @return \WP_Error | \WP_User
	 */
	function create_new_user( $subject_identity, $user_claim){
		// default username & email to the subject identity
		$username = $subject_identity;
		$email    = $subject_identity;

		// allow claim details to determine username
		if ( isset( $user_claim['email'] ) ) {
			$email    = $user_claim['email'];
			$username = $this->get_username_from_claim( $user_claim );
			
			if ( is_wp_error( $username ) ){
				return $username;
			}
		}
		// if no email exists, attempt another request for userinfo
		else if ( isset( $token_response['access_token'] ) ) {
			$user_claim_result = $this->client->request_userinfo( $token_response['access_token'] );

			// make sure we didn't get an error
			if ( is_wp_error( $user_claim_result ) ) {
				return new WP_Error( 'bad-user-claim-result', __( 'Bad user claim result' ), $user_claim_result );
			}

			$user_claim = json_decode( $user_claim_result['body'], TRUE );

			// check for email in claim
			if ( ! isset( $user_claim['email'] ) ) {
				return new WP_Error( 'incomplete-user-claim', __( 'User claim incomplete' ), $user_claim );
			}
			
			$email    = $user_claim['email'];
			$username = $this->get_username_from_claim( $user_claim );
		}

		// before trying to create the user, first check if a user with the same email already exists
		if( $this->settings->link_existing_users ) {
			if( $uid = email_exists( $email ) ) {
				return $this->update_existing_user( $uid, $subject_identity );
			}
		}
		
		// allow other plugins / themes to determine authorization 
		// of new accounts based on the returned user claim
		$create_user = apply_filters( 'openid-connect-generic-user-creation-test', TRUE, $user_claim );

		if ( ! $create_user ) {
			return new WP_Error( 'cannot-authorize', __( 'Can not authorize.' ), $create_user );
		}

		// create the new user
		$uid = wp_create_user( $username, wp_generate_password( 32, TRUE, TRUE ), $email );

		// make sure we didn't fail in creating the user
		if ( is_wp_error( $uid ) ) {
			return new WP_Error( 'failed-user-creation', __( 'Failed user creation.' ), $uid );
		}

		// retrieve our new user
		$user = get_user_by( 'id', $uid );

		// save some meta data about this new user for the future
		add_user_meta( $user->ID, 'openid-connect-generic-user', TRUE, TRUE );
		add_user_meta( $user->ID, 'openid-connect-generic-subject-identity', (string) $subject_identity, TRUE );

		// log the results
		$this->logger->log( "New user created: {$user->user_login} ($uid)", 'success' );

		// allow plugins / themes to take action on new user creation
		do_action( 'openid-connect-generic-user-create', $user, $user_claim );
		
		return $user;
	}
	
	
	/**
	 * Update an existing user with OpenID Connect meta data
	 * 
	 * @param $uid
	 * @param $subject_identity
	 *
	 * @return \WP_Error | \WP_User
	 */
	function update_existing_user( $uid, $subject_identity ) {
		// add the OpenID Connect meta data 
		add_user_meta( $uid, 'openid-connect-generic-user', TRUE, TRUE );
		add_user_meta( $uid, 'openid-connect-generic-subject-identity', (string) $subject_identity, TRUE );
		
		// allow plugins / themes to take action on user update
		do_action( 'openid-connect-generic-user-update', $uid );
		
		// return our updated user
		return get_user_by( 'id', $uid );
	}
}
