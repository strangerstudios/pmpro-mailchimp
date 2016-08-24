<?php

class PMProMailChimp
{
    private static $api_key;
    private static $api_url;
    private static $dc;
    private static $class;
    private static $user_agent;
    private static $options;

    private $url_args;
    private $all_lists;
    private $merge_fields;

    private $subscriber_id;

    /**
     * API constructor - Configure the settings, if the API key gets passed on instantiation.
     *
     * @param null $api_key - Key for Mailchimp API.
     * @since 2.0.0
     */
    public function __construct($api_key = null)
    {
        if (isset(self::$class)) {
            return self::$class;
        }

        self::$class = $this;

        if (!is_null($api_key)) {
            // Save the API key
            self::$api_key = $api_key;

            $this->url_args = array(
                'timeout' => apply_filters('pmpro_addon_mc_api_timeout', 10),
                'headers' => array(
                    'Authorization' => 'Basic ' . self::$api_key
                ),
            );

            // the datacenter that the key belongs to.
            list(, self::$dc) = explode('-', $api_key);

            // Build the URL based on the datacenter
            self::$api_url = "https://" . self::$dc . ".api.mailchimp.com/3.0";
            self::$user_agent = 'WordPress/pmpro_addon_mc;http://paidmembershipspro.com';
        }

        add_filter('get_mailchimpapi_class_instance', array($this, 'get_instance'));

        return self::$class;
    }

    /**
     * Returns the instance of the current class.
     *
     * @return PMProMailChimp object (active)
     * @since 2.0.0
     */
    public function get_instance()
    {

        return self::$class;
    }

    /**
     * Set the API key for Mailchimp & configure headers for requests.
     * @since 2.0.0
     */
    public function set_key()
    {
        self::$options = get_option("pmpromc_options");

        // Save the API key
        if (!isset(self::$options['api_key']) || empty(self::$options['api_key'])) {
            return;
        }

        self::$api_key = self::$options['api_key'];

        $this->url_args = array(
            'timeout' => apply_filters('pmpromc_api_timeout', 10),
            'headers' => array(
                'Authorization' => 'Basic ' . self::$api_key
            ),
        );

        // the datacenter that the key belongs to.
        list(, self::$dc) = explode('-', self::$api_key);

        // Build the URL based on the datacenter
        self::$api_url = "https://" . self::$dc . ".api.mailchimp.com/3.0";
        self::$user_agent = 'WordPress/pmpro_addon_mc;http://paidmembershipspro.com';
    }

    /**
     * Connect to Mailchimp API services, test the API key & fetch any existing lists.
     *
     * @return bool - True if able to conenct to MailChimp API services.
     * @since 2.0.0
     */
    public function connect()
    {
        // test connectivity by fetching all lists
	    /**
	     * Set the number of lists to return from the MailChimp server.
	     *
	     * @since 2.0.0
	     *
	     * @param   int     $max_lists      - Max number of lists to return
	     */
        $max_lists = apply_filters('pmpro_addon_mc_api_fetch_list_limit', 15);

        $url = self::$api_url . "/lists/?count={$max_lists}";
        $response = wp_remote_get($url, $this->url_args);

	    // Fix: is_wp_error() appears to be unreliable since WordPress v4.5
        if (200 != wp_remote_retrieve_response_code($response)) {

            switch (wp_remote_retrieve_response_code($response)) {
                case 401:
                    $this->set_error_msg(
                        sprintf(
                            __(
                                'Sorry, but MailChimp was unable to verify your API key. MailChimp gave this response: <p><em>%s</em></p> Please try entering your API key again.',
                                'pmpro-mailchimp'
                            ),
                            $response->get_error_message()
                        )
                    );
                    return false;
                    break;

                default:
                    $this->set_error_msg(
                        sprintf(
                            __(
                                'Error while communicating with the Mailchimp servers: <p><em>%s</em></p>',
                                'pmpro-mailchimp'
                            ),
                            $response->get_error_message()
                        )
                    );
                    return false;
            }
        } else {

            $body = $this->decode_response($response['body']);
            $this->all_lists = isset($body->lists) ? $body->lists : array();
        }

        return true;
    }

    /**
     * Subscribe user's email address to the specified list.
     *
     * @param string $list -- MC specific list ID
     * @param WP_User|null $user_obj - The WP_User object
     * @param array $merge_fields - Merge fields (see Mailchimp API docs).
     * @param string $email_type - The type of message to send (text or html)
     * @param bool $dbl_opt_in - Whether the list should use double opt-in or not
     * @return bool -- True if successful, false otherwise.
     *
     * @since 2.0.0
     */
    public function subscribe($list = '', WP_User $user_obj = null, $merge_fields = array(), $email_type = 'html', $dbl_opt_in = false)
    {
        // Can't be empty
        $test = (array)($user_obj);

        if (empty($list) || empty($test)) {

            global $msg;
            global $msgt;

            $msgt = "error";

            if (empty($list)) {
                $msg = __("No list ID specified for subscribe operation", "pmpromc");
            }

            if (empty($test)) {
                $msg = __("No user specified for subscribe operation", "pmpromc");
            }

            return false;
        }

	    $interests = $this->populate_interest_groups( $list, $user_obj );
	    $merge_fields = $this->populate_merge_fields( $list, $user_obj );

	    //build request
        $request = array(
            'email_type' => $email_type,
            'email_address' => $user_obj->user_email,
            'status' => (1 == $dbl_opt_in ? 'pending' : 'subscribed'),
        );

	    // add populated merge fields (if applicable)
	    if (!empty( $merge_fields )) {
		    $request['merge_fields'] = $merge_fields;
	    }

	    // add populated interests, (if applicable)
	    if ( !empty( $interests ) ) {
	    	$request['interests'] = $interests;
	    }

        $args = array(
            'method' => 'PUT', // Allows us to add or update a user ID
            'user-agent' => self::$user_agent,
            'timeout' => $this->url_args['timeout'],
            'headers' => $this->url_args['headers'],
            'body' => $this->encode($request),
        );

        //hit api
        $url = self::$api_url . "/lists/{$list}/members/" . $this->subscriber_id($user_obj->user_email);
        $resp = wp_remote_request($url, $args);

	    if (WP_DEBUG) {
	    	error_log("Subscribe: Response object: " . print_r($resp, true));
	    }

        //handle response
        if ( 200 !== wp_remote_retrieve_response_code( $resp ) ) {

        	// try updating the Merge Fields & Interest Groups on the MailChimp Server(s).
        	if ( true === $this->update_server_settings( $list ) ) {

        		// retry the update with updated interest & merge groups
		        $resp = wp_remote_request($url, $args);

		        if ( 200 !== wp_remote_retrieve_response_code( $resp ) ) {
			        $this->set_error_msg( $resp );

			        return false;
		        }

	        } else {

		        $this->set_error_msg( $resp );

		        return false;
	        }
        }

        return true;
    }


	/**
	 * Update interest groups & merge fields on the remote MailChimp server (if possible)
	 *
	 * @param   string      $list_id        ID of MailChimp list to attempt to update
	 *
	 * @return  bool
	 */
    private function update_server_settings( $list_id ) {

    	$retVal = true;

    	// configure & update interest groups both locally & on MC server
    	$retVal = $retVal && $this->update_interest_groups( $list_id );

	    // configure & update merge fields bot locally and on MC server
		$retVal = $retVal && $this->update_merge_fields( $list_id );

    	return $retVal;
    }

    /**
     * Unsubscribe user from the specified distribution list (MC)
     *
     * @param string $list - MC distribution list ID
     * @param \WP_User|null $user_objs - The User's WP_User object
     * @return bool - True/False depending on whether the operation is successful.
     *
     * @since 2.0.0
     */
    public function unsubscribe($list = '', WP_User $user_objs = null)
    {
        // Can't be empty
        if (empty($list) || empty($user_objs)) {
            return false;
        }

        // Force the emails into an array
        if (!is_array($user_objs)) {
            $user_objs = array($user_objs);
        }

        $url = self::$api_url . "/lists/{$list}/members";

        $args = array(
            'method' => 'DELETE', // Allows us remove a user ID
            'user-agent' => self::$user_agent,
            'timeout' => $this->url_args['timeout'],
            'headers' => $this->url_args['headers'],
            'body' => null,
        );

        foreach ($user_objs as $user) {
            $user_id = $this->subscriber_id($user->user_email);
            $user_url = $url . "/{$user_id}";

            $resp = wp_remote_request($user_url, $args);

	        if ( 200 !== wp_remote_retrieve_response_code( $resp ) ) {
		        if ( is_wp_error( $resp ) ) {
			        $this->set_error_msg( $resp->get_error_message() );
		        } else {
			        $this->set_error_msg( "Unsubscribe Error: " . wp_remote_retrieve_response_message( $resp ) );
		        }

		        return false;
	        }
        }

        return true;
    }

    /**
     * @param null $list_id - Mailchimp list ID
     * @param \WP_User|null $user_data - User to get info for
     * @return array|bool|mixed|object - Member information for the specified MC list, or on error false.
     *
     * @since 2.0.0
     */
    public function get_listinfo_for_member($list_id = null, WP_User $user_data = null)
    {
        if (empty($list_id)) {
            $this->set_error_msg(__("Error: Need to specify the list ID to receive member info", "pmpromc"));
            return false;
        }

        $url = self::$api_url . "/lists/{$list_id}/members/" . $this->subscriber_id($user_data->user_email);

        $resp = wp_remote_get($url, $this->url_args);

	    if ( 200 !== wp_remote_retrieve_response_code( $resp ) ) {
		    $this->set_error_msg($resp);
		    return false;
	    }

        $member_info = $this->decode_response($resp['body']);
        return $member_info;
    }

    /**
     * Update the users information on the Mailchimp servers
     *
     * NOTE: if email address gets updated, the user will get unsubscribed and resubscribed!!!
     *
     * @param null $list_id - The MC list ID
     * @param \WP_User|null $old_user - Pre-update WP_User info
     * @param \WP_User|null $new_user - post-update WP_User Info
     * @return bool - Success/failure during update operation
     *
     * @since 2.0.0
     */
    public function update_list_member($list_id = null, WP_User $old_user = null, WP_User $new_user = null)
    {
        $url = self::$api_url . "/lists/{$list_id}/members/" . $this->subscriber_id($old_user->user_email);

	    // configure merge fields & interests
        $merge_fields = $this->populate_merge_fields( $list_id, $new_user );
	    $interest_groups = $this->populate_interest_groups( $list_id, $new_user );

        if ($old_user->user_email != $new_user->user_email) {

            $retval = $this->unsubscribe($list_id, $old_user);

            // Don't use double opt-in since the user is already subscribed.
            $retval = $retval && $this->subscribe($list_id, $new_user, $merge_fields, 'html', false);

            if (false === $retval) {

                $this->set_error_msg(__("Error while updating email address for user!", "pmpromc"));
            }

            return $retval;
        }

        // Not trying to change the email address of the user, so we'll attempt to update.
        $request = array(
            'email_type' => 'html',
            'merge_fields' => $merge_fields,
        );

	    // Add any configured interest groups/groupings.
	    if ( !empty( $interest_groups ) ) {
	    	$request['interests'] = $interest_groups;
	    }

        $args = array(
            'method' => 'PATCH', // Allows us to add or update a user ID
            'user-agent' => self::$user_agent,
            'timeout' => $this->url_args['timeout'],
            'headers' => $this->url_args['headers'],
            'body' => $this->encode($request),
        );

        $resp = wp_remote_request($url, $args);

	    if ( 200 !== wp_remote_retrieve_response_code( $resp ) ) {
		    $this->set_error_msg($resp);
		    return false;
	    }

        return true;
    }

	/**
	 * Add/configure merge fields for the specified list ID (and the user object)
	 *
	 * @param   string      $list_id        -- The ID of the MC mailing list
	 * @param   WP_User     $user           - User object
	 *
	 * @return array  {
	 *      Merge field array w/field name & data value.
	 *      @type    string      $name       Merge Field name
	 *      @type    string      $value      Merge Field value
	 * }
	 */
    public function populate_merge_fields( $list_id, $user ) {

    	// get local configuration for merge fields
    	$mc_list_settings = get_option('pmcapi_list_settings', null);

	    /**
	     * Populate merge fields for specified distribution list
	     *
	     * @since   2.1     Added $list_id (ID of MC list) to allow list specific merge fields
	     */
	    $this->merge_fields = apply_filters(
		    "pmpro_mailchimp_listsubscribe_fields",
		    array(
			    "FNAME" => $user->first_name,
			    "LNAME" => $user->last_name
		    ),
		    $user, $list_id
	    );

	    if (WP_DEBUG) {
	    	error_log("MCAPI: Defined merge fields for {$list_id} and user {$user->ID}: " . print_r($this->merge_fields));
	    }

	    $configured_fields = array();

	    // identify any configured merge fields that are stored locally
	    if ( isset( $mc_list_settings[$list_id]->mf_config )) {

		    foreach ( $mc_list_settings[ $list_id ]->mf_config as $k => $settings ) {
			    $configured_fields[ $settings['name'] ] = null;
		    }
	    }

	    // check if there's a difference between what we have stored & what the user has specified in filters, etc.
	    if ( empty( $mc_list_settings[$list_id]->mf_config) ) {

	    	$config_changed = $this->merge_fields;

	    } else {

		    $config_changed = array_diff_key( $configured_fields, $this->merge_fields );
	    }

	    // update the server configuration for the merge fields
	    if ( ! empty( $config_changed ) ) {

	    	$this->configure_merge_fields( $list_id, $config_changed );
	    }

	    // need to convert groupings (v2 feature) to interest categories?
	    foreach( $this->merge_fields as $key => $value ) {

	    	if ( 'groupings' === strtolower($key) ) {

                // clear from merge field list
			    unset($this->merge_fields[$key]);
		    }
	    }

	    return $this->merge_fields;
    }

	/**
	 * Configure interests for the user in MailChimp list
	 *
	 * @param   string      $list_id        ID of MC list
	 * @param   WP_User     $user           User object
	 *
	 * @return  array       $interests {
	 *      Array of interests to assign the user to
	 *
	 *      @type   string      $interest_id        ID of the interest for the list ($list_id)
	 *      @type   boolean     $assign_to_user     Whether to assign the interest to the user for the $list_id
	 * }
	 */
    public function populate_interest_groups( $list_id, $user ) {

    	$pmpro_active = false;

    	if ( function_exists('pmpro_getAllLevels') ) {
		    global $pmpro_level;
		    $pmpro_active = false;
	    }

    	$mc_list_settings = get_option( 'pmcapi_list_settings', null);

	    $interests = array();

	    foreach( $mc_list_settings[$list_id]->interest_categories as $category ) {

	    	foreach( $category as $interest_id => $name ) {

	    		// assign the interest to this user Id(filtered, but set to true by default).

			    if ( true === $pmpro_active ) {
				    $interests[ $interest_id ] = apply_filters( 'pmpro_addon_mc_api_assign_interest_to_user', true, $user, $list_id, $interest_id, $name, $pmpro_level );
			    } else {
				    $interests[ $interest_id ] = apply_filters( 'pmpro_addon_mc_api_assign_interest_to_user', true, $user, $list_id, $interest_id, $name, null );
			    }
		    }
	    }

	    return $interests;
    }

	/**
	 * Returns the interest groups for the specified MailChimp distribution list
	 *
	 * @since 2.1
	 *
	 * @param       string      $list_id
	 *
	 * @return      array       List of Interest Groups
	 *
	 */
    public function get_local_interest_categories( $list_id ) {

    	$mc_list_settings = get_option('pmcapi_list_settings');

	    return empty( $mc_list_settings[$list_id]->interest_categories ) ? false : $mc_list_settings[$list_id]->interest_categories;
    }

	/**
	 * Updates server side interest categories for a mailing list (id)
	 *
	 * @since 2.1
	 *
	 * @param   string      $list_id          - ID for the MC mailing list
	 *
	 * @return  boolean
	 */
    public function update_interest_groups( $list_id ) {

	    /**
	     * Local definition for list settings (merge fields & interest categories)
	     * @since 2.1
	     *
	     * 	{@internal Format of $mcapi_list_settings configuration:
	     *  array(
	     *      $list_id => stdClass(),
	     *                  ->name = string
	     *                  ->merge_fields = array()
	     *                  ->mf_config = array()
	     *                  ->add_interests = array( $interest_id => boolean, $interest_id => boolean ),
	     *                  ->interest_categories = array(
	     *                          $category_name =>   stdClass(),
	     *                                              ->id
	     *                                              ->interests = array(
	     *                                                      $interest_id => $interest_name,
	     *                                                      $interest_id => $interest_name,
	     *                                              )
	     *                          $category_name =>   [...],
	     *                 )
	     *      $list_id => [...],
	     *  )}}
	     */
	    $mcapi_list_settings = get_option( "pmcapi_list_settings", null );

	    if (WP_DEBUG) {
	    	error_log("MCAPI: Local settings for {$list_id}: ", print_r( $mcapi_list_settings[$list_id], true) );
	    }

	    // if there are no stored list settings
	    if (empty( $mcapi_list_settings ) ) {

		    $mcapi_list_settings = array();
		    $mcapi_list_settings[$list_id] = new stdClass();
		    $mcapi_list_settings[$list_id]->interest_categories = array();
		    $mcapi_list_settings[$list_id]->merge_fields = array();
	    }

	    $filtered_mf_config = apply_filters('pmpro_mailchimp_merge_fields',
		    array(
			    array('name' => 'PMPLEVELID', 'type' => 'number'),
			    array('name' => 'PMPLEVEL', 'type' => 'text'),
		    ),
		    $list_id
	    );

	    $v2_category_def = array();

	    // look for categories
	    foreach( $filtered_mf_config as $key => $settings ) {

		    // do we have an old-style interest group definition?
		    if ( 'groupings' == strtolower($key)) {

			    if (WP_DEBUG) {
				    error_log("MCAPI: Found v2 style interest category definition");
			    }

			    $v2_category_def = $settings;
			    break;
		    }
	    }

	    $category_type = apply_filters('pmpro_mailchimp_list_interest_category_type', 'checkboxes', $list_id );
	    $server_ic = $this->get_interest_categories( $list_id );

	    // process & convert any MCAPI-v2-style interest groups (groupings) aka interest categories.
	    if (!empty($v2_category_def)) {

		    foreach($v2_category_def as $key => $grouping_def ) {

			    if ( empty( $mcapi_list_settings[$list_id]->interest_categories[$grouping_def['name']] ) ) {

				    $mcapi_list_settings[$list_id]->interest_categories[$grouping_def['name']] = new stdClass();
				    $mcapi_list_settings[$list_id]->interest_categories[$grouping_def['name']]->id = null;
				    $mcapi_list_settings[$list_id]->interest_categories[$grouping_def['name']]->interests = array();

				    foreach( $grouping_def['groups'] as $key => $group_name ) {

					    $mcapi_list_settings[$list_id]->interest_categories[$grouping_def['name']]->interests["add_new_{$key}"] = $group_name;
				    }
			    }
		    }
	    }

	    // Update server if more interest categories found locally
	    if ( count($server_ic) < count( $mcapi_list_settings[$list_id]->interest_categories ) ) {

		    // patch all existing interest categories to MC servers
		    $url = self::$api_url . "/lists/{$list_id}/interest-categories";

		    $args = array(
			    'user-agent' => self::$user_agent,
			    'timeout' => $this->url_args['timeout'],
			    'headers' => $this->url_args['headers'],
		    );

		    /**
		     * Update the on-server (MailChimp server) interest category definition(s) for the system
		     */
		    foreach( $mcapi_list_settings[$list_id]->interest_categories as $key => $category ) {

		    	// Do we have a new or (likely) existing category
		    	if ( !empty( $category->id ) ) {

				    $ic_url = "{$url}/{$category->id}";
		    		$args['method'] = 'PATCH'; // Allows us to add or update

			    } else {

			    	$ic_url = $url;
				    $args['method'] = 'POST'; // Allows us to add
			    }

			    $request = array(
				    'title' => $category->title,
			        'type'  => ($category->type != $category_type ? $category_type : $category->type),
			    );

			    $args['body'] = $this->encode($request);

			    if (WP_DEBUG) {
				    error_log("MCAPI: Updating interest category {$category->id} on the MailChimp servers");
			    }

			    $resp = wp_remote_request($ic_url, $args);

			    if ( 200 !== wp_remote_retrieve_response_code( $resp ) ) {

				    if (WP_DEBUG) {
					    error_log("MCAPI: Error updating interest category {$category->id}: " . print_r($resp, true));
				    }

				    $this->set_error_msg($resp);
				    return false;
			    }

			    $upstream = array_diff_key($server_ic[$category->id]->interests, $category->interests);
			    $local = array_diff_key($category->interests, $server_ic[$category->id]->interests);

			    if (WP_DEBUG) {
				    error_log("MCAPI: Local interests not available upstream: " . print_r($local, true));
				    error_log("--------");
				    error_log("MCAPI: Upstream interests not available in local config: " . print_r($upstream, true));
			    }

			    /**
			     * Update any interests belonging to the interest category
			     */
			    $local_icount = count($category->interests);
			    $upstream_icount = count($server_ic[$category->id]->interests);

			    if (WP_DEBUG) {
				    error_log("MCAPI: Comparing local vs upstream interest counts. Local ({$local_icount}) vs remote ({$upstream_icount})");
			    }

			    // push interests for interest category to MailChimp server?
			    if ( $local_icount > $upstream_icount || !empty( $local )) {

			    	if ( false == $this->edit_interests_on_server( $list_id, $category->id, $category->interests) )
				    {
					    if (WP_DEBUG) {
						    error_log("MCAPI: Updating interests for {$category->id} on the MailChimp servers");
					    }

					    $this->set_error_msg(
				    		sprintf( __("Error: Unable to update interests for the %s interest category", "pmpromc"),
							    $category->title)
					    );
				    }
			    }

			    // merge upstream interests from MailChimp server
			    if ( $local_icount < $upstream_icount || ! empty( $upstream ) ) {

			    	if (WP_DEBUG) {
					    error_log("MCAPI: Setting local interest for {$category->id} so they can be saved");
				    }

				    $mcapi_list_settings[$list_id]->interest_categories[$category->id]->interests = $server_ic[$category->id]->interests;
			    }
		    }

	    } else {

		    if (WP_DEBUG) {
			    error_log("MCAPI: Only updating local interest category settings for {$list_id}");
		    }
	    	// update the local interest group settings to match upstream server
		    $mcapi_list_settings[$list_id]->interest_categories = $server_ic;
	    }


	    if (WP_DEBUG) {
		    error_log("MCAPI: Updating local list settings for {$list_id}");
	    }

	    // update the PMPro MailChimp API settings for all lists (no autoload)
	    if ( true !== update_option('pmcapi_list_settings', $mcapi_list_settings, false ) ) {

		    if (WP_DEBUG) {
			    error_log("MCAPI: Error updating pmcapi_list_settings option");
		    }

		    // configure the option update error message
		    $this->set_error_msg(
		    	sprintf(
		    		__("Error: Unable to update the list specific settings for the '%s' MailChimp list", "pmpromc"),
				    $mcapi_list_settings[$list_id]->name
			    )
		    );
	    }

	    return true;
    }

	/**
	 * Update the list of interests belonging to the $list_id mailing list for the $cat_id interest category on the
	 * MailChimp server.
	 *
	 * @since       2.1
	 *
	 * @param       string      $list_id        - ID of the MailChimp distribution list
	 * @param       string      $cat_id         - ID of the Interest Cateogry belonging to $list_id
	 * @param       array       $interests      - array( $interest_id => $interest_name )
	 *
	 * @return      bool
	 */
    private function edit_interests_on_server( $list_id, $cat_id, $interests ) {

	    // patch all existing interest categories to MC servers
	    $url = self::$api_url . "/lists/{$list_id}/interest-categories/{$cat_id}/interests";

	    $args = array(
		    'method' => 'PATCH', // Allows us to add or update
		    'user-agent' => self::$user_agent,
		    'timeout' => $this->url_args['timeout'],
		    'headers' => $this->url_args['headers'],
	    );

	    foreach( $interests as $id => $name ) {

		    $args['body'] = $this->encode( array(
				    'name'    => $name,
		        )
		    );

		    // handle v2 conversion
		    if ( false !== stripos( $id, 'add_new_') ) {
		    	$args['method'] = "POST";
			    $i_url = "{$url}";
		    } else {
			    $args['method'] = "PATCH";
			    $i_url = "{$url}/{$id}";
		    }

		    if (WP_DEBUG) {
			    error_log("MCAPI: Updating interest '{$name}' (id: {$id}) for category {$cat_id} in list {$list_id} on the MailChimp server");
		    }

		    $resp = wp_remote_request($i_url, $args);

		    if ( 200 !== wp_remote_retrieve_response_code( $resp ) ) {
			    $this->set_error_msg($resp);
			    return false;
		    }
	    }

	    return true;
    }

	/**
	 * Return all interest categories for the specified list ID
	 *
	 * @since 2.1
	 *
	 * @param       string      $list_id        MailChimp List ID
	 *
	 * @return      mixed           False = error | array( interest-category-id => object[1], )
	 *
	 * @see http://developer.mailchimp.com/documentation/mailchimp/reference/lists/interest-categories/ - Docs for Interest Categories on MailChimp
	 */
    public function get_interest_categories( $list_id ) {

	    // get all existing interest categories from MC servers
	    $url = self::$api_url . "/lists/{$list_id}/interest-categories/";

	    $args = array(
		    'method' => 'GET', // Fetch data (read)
		    'user-agent' => self::$user_agent,
		    'timeout' => $this->url_args['timeout'],
		    'headers' => $this->url_args['headers'],
		    'body' => null
	    );

	    if (WP_DEBUG) {
		    error_log("MCAPI: Fetching interest categories for {$list_id} from the MailChimp servers");
	    }

	    $resp = wp_remote_request($url, $args);

	    if ( 200 !== wp_remote_retrieve_response_code( $resp ) ) {
		    $this->set_error_msg($resp);
		    return false;
	    }

	    $ic = array();

	    // Save the interest category information we (may) need
	    foreach( $resp->categories as $cat ) {

	    	$ic[$cat->id] = new stdClass();
		    $ic[$cat->id]->type = $cat->type;
		    $ic[$cat->id]->name = $cat->title;
		    $ic[$cat->id]->interests = $this->get_interests_for_category( $list_id, $cat->id );
	    }

	    return $ic;
    }

	/**
	 * Read all interests for an interest category from the MailChimp server
	 *
	 * @since 2.1
	 *
	 * @param   string      $list_id    ID of the Distribution List on MailChimp server
	 * @param   string      $cat_id     ID of the Interest Category on MailChimp server
	 *
	 * @return  array|bool      Array of interest names & IDs
	 */
	public function get_interests_for_category(  $list_id, $cat_id ) {

	    $url = self::$api_url . "/lists/{$list_id}/interest-categories/{$cat_id}/interests";

	    $args = array(
		    'method' => 'GET', // Allows us to add or update a user ID
		    'user-agent' => self::$user_agent,
		    'timeout' => $this->url_args['timeout'],
		    'headers' => $this->url_args['headers'],
		    'body' => null
	    );

	    if (WP_DEBUG) {
		    error_log("MCAPI: Fetching interests for category {$cat_id} in list {$list_id} from the MailChimp servers");
	    }

	    $resp = wp_remote_request($url, $args);

	    if ( 200 !== wp_remote_retrieve_response_code( $resp ) ) {
		    $this->set_error_msg($resp);
		    return false;
	    }

	    $interests = array();

	    foreach( $resp->interests as $interest ) {
	    	$interests[$interest->id] = $interest->name;
	    }

	    return $interests;
    }

    /**
     * Check if a merge field is in an array of merge fields
     *
     * @param   string      $field_name
     * @param   array      $fields
     *
     * @return  boolean
     */
    public function in_merge_fields($field_name, $fields)
    {
        if (empty($fields)) {
	        return false;
        }

        foreach ($fields as $field) {
	        if ( $field->tag == $field_name ) {
		        return true;
	        }
        }

        return false;
    }

	/**
	 * Configure merge fields for Mailchimp (uses filter)
	 *
	 * @param       string      $list_id    - The MC list ID
	 * @param       array       $delta  {
	 * Optional. Array of merge fields we don't think are defined on the MailChimp server
	 *      @type    array      $field_definition  {
	 *
	 *          Optional. Field definition for the merge field (name, type)
	 *          @type   string      $key        name | type
	 *          @type   string      $value      string or field type
	 *          @type   boolean     $public     Whether the field is to be hidden (false)
	 *      }
	 * }
	 *
	 * @return      array                  - Merge field list
	 *
	 * @since 2.1
	 */
	public function configure_merge_fields( $list_id, $delta = array() ) {

		// nothing to be done.
		if ( empty( $delta ) ) {
			return;
		}

		$mcapi_list_settings = get_option('pmcapi_list_settings', null );

		$mf_config = apply_filters('pmpro_mailchimp_merge_fields',
			array(
				array('name' => 'PMPLEVELID', 'type' => 'number'),
				array('name' => 'PMPLEVEL', 'type' => 'text'),
			),
			$list_id
		);

		// check if the local settings are roughly the same as the filtered values
		if (count($mf_config[0]) == count($mcapi_list_settings[$list_id]->mf_config)) {

			if (WP_DEBUG) {
				error_log("MCAPI: No difference in count between locally stored option & supplied filter value");
			}

			return;
		}

		foreach( $mf_config as $key => $settings ) {

			// new field that needs to be configured on server
			if ( !empty( $delta[$settings['name']] ) ) {

				if (WP_DEBUG) {
					error_log("MCAPI Processing merge field: {$delta[$settings['name']]}");
				}

				// include field in MailChimp profile for user (on MailChimp server)
				$visibility = isset($settings['visible']) ? $settings['visible'] : false;

				// Add the field to the
				$mcapi_list_settings[$list_id]->mf_config[] = $this->add_merge_field( $settings['name'], $settings['type'], $visibility , $list_id );
			}
		}

		// update the PMPro MailChimp API settings for all lists (no autoload)
		if ( true !== update_option('pmcapi_list_settings', $mcapi_list_settings, false ) ) {

			if (WP_DEBUG) {
				error_log("MCAPI: Error updating pmcapi_list_settings option");
			}

			// configure the option update error message
			$this->set_error_msg(
				sprintf(
					__("Error: Unable to update the list specific settings for the '%s' MailChimp list", "pmpromc"),
					$mcapi_list_settings[$list_id]->name
				)
			);
		}
    }

    /**
     * Get previously defined merge fields for a list (via MC API)
     *
     * @param string $list_id - The MC list ID
     * @param bool $force - Whether to force a read/write
     *
     * @return mixed - False if error | Merge fields for the list_id
     * @since 2.0.0
     */
    public function get_existing_remote_merge_fields($list_id, $force = false)
    {
        //get from cache
        if (isset($this->merge_fields[$list_id]) && !$force) {
            return $this->merge_fields[$list_id];
        }

        //hit the API
        $url = self::$api_url . "/lists/" . $list_id . "/merge-fields";
        $response = wp_remote_get($url, $this->url_args);

        //check response
	    if ( 200 !== wp_remote_retrieve_response_code( $response ) ) {
		    $this->set_error_msg($response);
		    return false;
	    }  else {

            $body = $this->decode_response($response['body']);
            $this->merge_fields[$list_id] = isset($body->merge_fields) ? $body->merge_fields : array();

            return $this->merge_fields[$list_id];
        }
    }

    /**
     * Add a merge field to a list (very basic)
     *
     * @param string $merge_field - The Merge Field Name
     * @param string $type - The Merge Field Type (text, number, date, birthday, address, zip code, phone, website)
     * @param mixed $public - Whether the field should show on the subscribers MailChimp profile. Defaults to false.
     * @param string $list_id - The MC list ID
     *
     * @return mixed - Merge field or false
     * @since 2.0.0
     */
    public function add_merge_field($merge_field, $type = NULL, $public = false, $list_id)
    {
        //default type to text
        if (empty($type)) {
            $type = 'text';
        }

        //prepare request
        $request = array(
            'tag' => $merge_field,
            'name' => $merge_field,
            'type' => $type,
            'public' => $public,
        );

        $args = array(
            'method' => 'PATCH', // PATCH method lets us add/edit merge field definition
            'user-agent' => self::$user_agent,
            'timeout' => $this->url_args['timeout'],
            'headers' => $this->url_args['headers'],
            'body' => $this->encode($request),
        );

        //hit the API
        $url = self::$api_url . "/lists/" . $list_id . "/merge-fields";
        $response = wp_remote_request($url, $args);

        //check response
	    if ( 200 !== wp_remote_retrieve_response_code( $response ) ) {
		    $this->set_error_msg($response);
		    return false;
	    } else {
            $body = $this->decode_response($response['body']);
            $merge_field = isset($body->merge_field) ? $body->merge_field : array();
        }

        return $merge_field;
    }

    /**
     * Returns an array of all lists created for the the API key owner
     *
     * @return mixed - Array of all lists, array of lists the user email belongs to, null (no lists defined).
     *
     * @since 2.0.0
     */
    public function get_all_lists()
    {
        if (empty($this->all_lists)) {
            $this->connect();
        }

        return $this->all_lists;
    }

    /**
     * Decode the JSON object we received
     * @param $response
     * @return array|mixed|object
     *
     * @since 2.0.0
     */
    private function decode_response($response)
    {
        if (null !== $obj = json_decode($response)) {
            return $obj;
        }

        return false;
    }

    /**
     * @param $data
     * @return bool|mixed|string|void
     *
     * @since 2.0.0
     */
    private function encode($data)
    {
        if (false !== ($json = json_encode($data))) {
            return $json;
        }

        return false;
    }

    /**
     * @param $user_email
     * @return string
     *
     * @since 2.0.0
     */
    private function subscriber_id($user_email)
    {
        $this->subscriber_id = md5(strtolower($user_email));
        
        return $this->subscriber_id;
    }

    /**
     * Set visible error message (WordPress dashboard and/or PMPro error field).
     *
     * @param   WP_Http|string      HTML object with error status, or text message to display
     *
     * @since 2.0.0
     * @since 2.1   Added to the pmpro_msg[t] error messaging system
     */
    private function set_error_msg($obj)
    {
        global $msgt, $pmpro_msgt;
        global $msg, $pmpro_msg;

        $msgt = 'error';

	    if ( !is_string($obj) && !is_array($obj) && ( 200 !== wp_remote_retrieve_response_code( $obj )) ) {
		    $msg = $obj->get_error_message();
        } elseif ( is_string($obj) ) {
		    $msg = $obj;
	    } elseif (is_array( $obj )) {
	    	foreach( $obj as $o ) {
			    $msg = '';
			    $msg .= $o->get_error_message();
		    }
        } else {
        	$msg = __("Unable to identify error message", "pmpromc");
	    }

	    $pmpro_msg = $msg;
	    $pmpro_msgt = $msgt;
    }
}