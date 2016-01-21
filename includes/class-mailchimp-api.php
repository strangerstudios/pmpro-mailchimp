<?php
namespace PMPro\Addon\MailChimp;
use PMPro\Addon\MailChimp as MailChimp;

class API
{
    private static $api_key;
    private static $api_url;
    private static $dc;
    private static $class;
    private static $user_agent;
    private static $options;

    private $url_args;
    private $all_lists;


    private $subscriber_id;

    /**
     * API constructor - Configure the settings, if the API key gets passed on instantiation.
     *
     * @param null $api_key - Key for Mailchimp API.
     * @since 2.0.0
     */
    public function __construct($api_key = null)
    {
        if (isset(self::$class))
        {
            return self::$class;
        }

        self::$class = $this;

        if (!is_null($api_key))
        {
            // Save the API key
            self::$api_key = $api_key;

            $this->url_args = array(
                'timeout' => 10,
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

        add_filter('get_mailchimpapi_class_instance', array($this, 'get_instance') );

        return self::$class;
    }

    /**
     * Returns the instance of the current class.
     *
     * @return MailChimp\API object (active)
     * @since 2.0.0
     */
    public function get_instance() {

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
        if (!isset(self::$options['api_key']) || empty(self::$options['api_key']))
        {
            return;
        }

        self::$api_key = self::$options['api_key'];

        $this->url_args = array(
            'timeout' => 10,
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
        $url = self::$api_url . "/lists";
        $response = wp_remote_get($url, $this->url_args);

        if (is_wp_error($response))
        {

            switch (wp_remote_retrieve_response_code($response))
            {
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
        }
        else {

            $body = $this->decode_response($response['body']);
            $this->all_lists = isset($body->lists) ? $body->lists : array();
        }

        return true;
    }

    /**
     * Subscribe user's email address to the specified list.
     *
     * @param string $list -- MC specific list ID
     * @param \WP_User|null $user_obj - The WP_User object
     * @param array $merge_fields - Merge fields (see Mailchimp API docs).
     * @param string $email_type - The type of message to send (text or html)
     * @param bool $dbl_opt_in - Whether the list should use double opt-in or not
     * @return bool -- True if successful, false otherwise.
     *
     * @since 2.0.0
     */
    public function subscribe($list = '', \WP_User $user_obj = null, $merge_fields = array(), $email_type = 'html', $dbl_opt_in = false)
    {
        // Can't be empty
        $test = (array)($user_obj);

        if (empty($list) || empty($test)) {

            global $msg;
            global $msgt;

            $msgt = "error";

            if (empty($list))
            {
                $msg = __("No list ID specified for subscribe operation", "pmpromc");
            }

            if (empty($test))
            {
                $msg = __("No user specified for subscribe operation", "pmpromc");
            }

            return false;
        }

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

        $url = self::$api_url . "/lists/{$list}/members/" . $this->subscriber_id($user_obj->user_email);

        $resp = wp_remote_request($url, $args);

        if (is_wp_error($resp))
        {

            $this->set_error_msg($resp);
            return false;
        }

        return true;
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
    public function unsubscribe($list = '', \WP_User $user_objs = null )
    {
        // Can't be empty
        if (empty($list) || empty($user_objs))
        {
            return false;
        }

        // Force the emails into an array
        if (!is_array($user_objs))
        {
            $user_objs = array($user_objs);
        }

        $url = self::$api_url . "/lists/{$list}/members";

        $args = array(
            'method' => 'DELETE', // Allows us to add or update a user ID
            'user-agent' => self::$user_agent,
            'timeout' => $this->url_args['timeout'],
            'headers' => $this->url_args['headers'],
            'body' => null,
        );

        foreach ($user_objs as $user)
        {
            $user_id = $this->subscriber_id($user->user_email);
            $user_url = $url . "/{$user_id}";

            $resp = wp_remote_request($user_url, $args);

            if (is_wp_error($resp))
            {
                $this->set_error_msg($resp);
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
    public function get_listinfo_for_member( $list_id = null, \WP_User $user_data = null )
    {
        if (empty($list_id))
        {
            $this->set_error_msg(__("Error: Need to specify the list ID to receive member info"), "pmpromc");
            return false;
        }

        $url = self::$api_url . "/lists/{$list_id}/members/" . $this->subscriber_id($user_data->user_email);

        $resp = wp_remote_get($url, $this->url_args);

        if (is_wp_error($resp))
        {
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
    public function update_list_member($list_id = null, \WP_User $old_user = null, \WP_User $new_user = null)
    {
        $url = self::$api_url . "/lists/{$list_id}/members/" . $this->subscriber_id($old_user->user_email);

        $merge_fields = apply_filters(
                            "pmpro_mailchimp_listsubscribe_fields",
                            array(
                                "FNAME" => $new_user->first_name,
                                "LNAME" => $new_user->last_name
                            ),
                            $new_user
                        );

        if ( $old_user->user_email != $new_user->user_email )
        {
            $retval = true;

            $retval = $this->unsubscribe($list_id, $old_user);

            // Don't use double opt-in since the user is already subscribed.
            $retval = $retval && $this->subscribe($list_id, $new_user, $merge_fields, 'html', false);

            if (false === $retval )
            {
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

        if ( is_wp_error($resp))
        {
            $this->set_error_msg($resp);
            return false;
        }

        return true;
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
        if (empty($this->all_lists))
        {
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
        if ( null !== $obj = json_decode($response) )
        {
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
        if ( false !== ($json = json_encode($data)) )
        {
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

        if (empty($this->subscriber_id))
        {
            $this->subscriber_id = md5(strtolower($user_email));
        }

        return $this->subscriber_id;
    }

    /**
     * Build an interest object to use for MailChimp API
     * @param \WP_User $user - User object
     * @return \stdClass() $interestes - Object containing the required Interests settings for MC-API v3.0
     *
     * @since 2.0.0
     */
    private function set_user_interests($user)
    {
        $level = pmpro_getMembershipLevelForUser($user->ID);
        $interests = new \stdClass();
        $interests->id = $level->id;
        $interests->label = $level->name;

        return $interests;
    }

    /**
     * @param $obj
     *
     * @since 2.0.0
     */
    private function set_error_msg($obj)
    {
        global $msgt;
        global $msg;

        $msgt = 'error';

        if (is_wp_error($obj))
        {
            $msg = $obj->get_error_message();
        }
        else {
            $msg = $obj;
        }
    }
}