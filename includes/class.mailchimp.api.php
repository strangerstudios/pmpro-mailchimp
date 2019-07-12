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
     * @return MailChimp\API object (active)
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
    public function connect() {
        // test connectivity by fetching all lists
        $max_lists = apply_filters('pmpro_addon_mc_api_fetch_list_limit', 15);

        $url = self::$api_url . "/lists/?count={$max_lists}";
        $response = wp_remote_get($url, $this->url_args);		
		$resp_code = wp_remote_retrieve_response_code( $response );

        if ( is_numeric( $resp_code ) && 200 !== $resp_code ) {
			
            switch ($resp_code) {
                case 401:
                    $this->set_error_msg(                        
                            $response,
							__('Sorry, but MailChimp was unable to verify your API key. MailChimp gave this response: <p><em>%s</em></p> Please try entering your API key again.', 'pmpro-mailchimp')
                    );
                    return false;
                    break;

                default:
                    $this->set_error_msg(                        
                            $response,
							__('Error while communicating with the Mailchimp servers: <p><em>%s</em></p>', 'pmpro-mailchimp')
                    );
                    return false;
            }
        } else if ( is_numeric( $resp_code ) && 200 == $resp_code ) {
            $body = $this->decode_response($response['body']);
            $this->all_lists = isset($body->lists) ? $body->lists : array();
        } else {
			$this->set_error_msg( $response, __( 'Error while communicating with the Mailchimp servers.', 'pmpro-mailchimp' ) );
			return false;
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
		///echo "(subscribe: " . $list . ")";		
		
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

        //make sure merge fields are setup if PMPro is active
        if (function_exists('pmpro_getMembershipLevelForUser')) {
            $this->add_pmpro_merge_fields($list);
        }

        //build request
        $request = array(
            'email_type' => $email_type,
            'email_address' => $user_obj->user_email,
            'merge_fields' => $merge_fields,
            'status' => (1 == $dbl_opt_in ? 'pending' : 'subscribed'),
            // 'interests' => $this->set_interests($user_obj), /** TODO: Incorporate segmentation using membership level */
        );
		
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
	        $this->set_error_msg($resp);
	        return false;
        }

        return true;
    }

    /**
     * Unsubscribe user from the specified distribution list (MC)
     *
     * @param string $list - MC distribution list ID
     * @param array $user_ids - Users to unsubscribe
     * @return bool - True/False depending on whether the operation is successful.
     *
     * @since 2.0.0
     */
    public function unsubscribe($list = '', $user_ids = []) {
		
		    // Can't be empty.
        if (empty($list) || empty($user_ids)) {
            return false;
        }
        
        $data = new \stdClass();
        $data->members = [];
        
        foreach ( $user_ids as $user_id ) {
          $user_data = new \stdClass();
          $user_data->email_address = get_userdata( $user_id )->user_email;
          $user_data->status = 'unsubscribed';
          $user_data->merge_fields = new \stdClass();
          $data->members[] = $user_data;
        }
        
        $data->update_existing = true;
        
        $url = self::$api_url . "/lists/{$list}";
        $args = array(
            'method' => 'POST', // Allows us update a user ID
            'user-agent' => self::$user_agent,
            'headers' => $this->url_args['headers'],
            'timeout' => $this->url_args['timeout'],
            'body' => json_encode($data),
        );
        $resp = wp_remote_post($url, $args);
						
	      if ( 200 !== wp_remote_retrieve_response_code( $resp ) ) {
		      $this->set_error_msg($resp);
		      return false;
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

        $merge_fields = apply_filters(
            "pmpro_mailchimp_listsubscribe_fields",
            array(
                "FNAME" => $new_user->first_name,
                "LNAME" => $new_user->last_name
            ),
            $new_user,
			$list_id
        );

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
     * Check if a merge field is in an array of merge fields
     */
    public function in_merge_fields($field_name, $fields)
    {
        if (empty($fields))
            return false;

        foreach ($fields as $field)
            if ($field->tag == $field_name)
                return true;

        return false;
    }

    /**
     * Make sure a list has the PMPLEVELID, PMPALLIDS and PMPLEVEL merge fields.
     *
     * @param string $list_id - The MC list ID
     *
     * @since 2.0.0
     */
    public function add_pmpro_merge_fields($list_id)
    {
        /**
         * Filter the list of merge fields for PMPro to generate.
         *
         * @param string $list_id - The MC list ID
         *
         * @since 2.0.0
         */
        $pmpro_merge_fields = apply_filters('pmpro_mailchimp_merge_fields',
            array(
                array('name' => 'PMPLEVELID', 'type' => 'number'),
                array('name' => 'PMPALLIDS', 'type' => 'text'),
				array('name' => 'PMPLEVEL', 'type' => 'text'),
            ),
            $list_id);

        //get merge fields for this list
        $list_merge_fields = $this->get_merge_fields($list_id);
		
		foreach ($pmpro_merge_fields as $merge_field) {

			if (is_array($merge_field)) {

				//pull from array
				$field_name = $merge_field['name'];
				$field_type = $merge_field['type'];

				if (!empty($merge_field['public'])) {
					$field_public = $merge_field['public'];
				} else {
					$field_public = false;
				}
			} else {

				//defaults
				$field_name = $merge_field;
				$field_type = 'text';
				$field_public = false;
			}
			
			//add field if missing
			if (empty($list_merge_fields) || false === $this->in_merge_fields($field_name, $list_merge_fields)) {

				$new_merge_field = $this->add_merge_field($field_name, $field_type, $field_public, $list_id);

				//and add to cache
				$this->merge_fields[$list_id][] = $new_merge_field;
			}
		}
    }

    /**
     * Get merge fields for a list
     *
     * @param string $list_id - The MC list ID
     * @param bool $force - Whether to force a read/write
     *
     * @return mixed - False if error or the merge fields for the list_id
     * @since 2.0.0
     */
    public function get_merge_fields($list_id, $force = false)
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
     * Add a merge field to a list
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
        ///echo "(add merge field: $merge_field)";
		
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
            'method' => 'POST', // Allows us to add or update a user ID
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
     * Build an interest object to use for MailChimp API
     * @param \WP_User $user - User object
     * @return \stdClass() $interestes - Object containing the required Interests settings for MC-API v3.0
     *
     * @since 2.0.0
     */
    private function set_user_interest($user, $list_id)
    {
        $level = pmpro_getMembershipLevelForUser($user->ID);
        $interests = new stdClass();
        $interests->id = $level->id;
        $interests->label = $level->name;

        return $interests;
    }

    /**
     * @param $obj
     *
     * @since 2.0.0
     */
    private function set_error_msg($obj, $message = NULL)
    {
        global $msgt;
        global $msg;

        $msgt = 'error';
		
	    if ( !is_string($obj) && ( 200 !== wp_remote_retrieve_response_code( $obj )) ) {
		    //there is an error and we have some kind of array or response object
			if(is_array($obj) && !empty($obj['response'])) {
				//this is the format the MailChimp API returns				
								
				if(!empty($obj['body'])) {
					//check for details in the body in json format
					$json_response = $this->decode_response($obj['body']);
					
					if(!empty($json_response->status)) {
						$msg = sprintf('<p><em>%s: %s. ' . __('Detail', 'pmpro-mailchimp') . ': %s</em></p>', $json_response->status, $json_response->title, $json_response->detail);
					}
				}
				
				if(empty($msg)) {
					//only have code and message
					$msg = $obj['response']['code'] . ' ' . $obj['response']['message'];				
				}
				
			} else {
				//assume a WP_Error object or something else that supports ->get_error_message();
				$msg = $obj->get_error_message();
			}
        } elseif ( is_string($obj) ) {
            //just a string, use it
			$msg = $obj;
        } else {
			//default error
        	$msg = __("Unable to identify error message", "pmpromc");
	    }
		
		//if an additional string message was passed in, append it to the beginning or use sprintf
		if(!empty($message) && strpos($message, '%s') !== false)
			$msg = sprintf($message, $msg);
		else
			$msg = $message . " " . $msg;
    }
}
