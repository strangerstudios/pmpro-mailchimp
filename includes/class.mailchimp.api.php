<?php
/*
 * License:

	Copyright 2016 - Eighty / 20 Results by Wicked Strong Chicks, LLC (thomas@eighty20results.com)

	This program is free software; you can redistribute it and/or modify
	it under the terms of the GNU General Public License, version 2, as
	published by the Free Software Foundation.

	This program is distributed in the hope that it will be useful,
	but WITHOUT ANY WARRANTY; without even the implied warranty of
	MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
	GNU General Public License for more details.

	You should have received a copy of the GNU General Public License
	along with this program; if not, write to the Free Software
	Foundation, Inc., 51 Franklin St, Fifth Floor, Boston, MA  02110-1301  USA
*/
/**
 * Class PMProMailChimp
 * @version 2.1
 */
class PMProMailChimp {

	/**
	 * @var string      $api_key    API Key used to access MailChimp API server
	 */
	private static $api_key;

	/**
	 * @var string      $api_url    The base URL to the MailChimp API server
	 */
	private static $api_url;

	/**
	 * @var string      $dc         The datacenter (xxYY where XX is country code, YY is numeric) identifier
	 */
	private static $dc;

	/**
	 * @var PMProMailChimp  $class      Instance of this class
	 */
	private static $class;

	/**
	 * @var string  $user_agent         User Agent string for the API class
	 */
	private static $user_agent = 'WordPress/pmpro_addon_mc;http://paidmembershipspro.com';

	/**
	 * @var array   $options            Options for the MailChimp lists
	 */
	private static $options;

	private $url_args;
	/**
	 * @var array   $all_lists          Lists retrieved from the MC API server
	 */
	private $all_lists = array();

	private $subscriber_id;

	/**
	 * API constructor - Configure the settings, if the API key gets passed on instantiation.
	 *
	 * @param null $api_key - Key for Mailchimp API.
	 *
	 * @since 2.0.0
	 */
	public function __construct( $api_key = null ) {
		if ( isset( self::$class ) ) {
			return self::$class;
		}

		self::$class = $this;

		if ( ! is_null( $api_key ) ) {
			// Save the API key
			self::$api_key = $api_key;

			$this->url_args = array(
				'timeout' => apply_filters( 'pmpro_addon_mc_api_timeout', 10 ),
				'headers' => array(
					'Authorization' => 'Basic ' . self::$api_key
				),
			);

			// the datacenter that the key belongs to.
			list( , self::$dc ) = explode( '-', $api_key );

			// Build the URL based on the datacenter
			self::$api_url    = "https://" . self::$dc . ".api.mailchimp.com/3.0";
			self::$user_agent = apply_filters('pmpromc_api_user_agent', self::$user_agent );
		}

		add_filter( 'get_mailchimpapi_class_instance', array( $this, 'get_instance' ) );

		// Fix the 'groupings setting once it has been converted to interest group(s).
		add_filter( 'pmpro_mailchimp_listsubscribe_fields', array( $this, 'fix_listsubscribe_fields'), -1, 3 );

		return self::$class;
	}

	/**
	 * Returns the instance of the current class.
	 *
	 * @return PMProMailChimp object (active)
	 * @since 2.0.0
	 */
	public function get_instance() {

		return self::$class;
	}

	/**
	 * Set the API key for Mailchimp & configure headers for requests.
	 * @since 2.0.0
	 */
	public function set_key() {

		self::$options = get_option( "pmpromc_options" );

		// Save the API key
		if ( ! isset( self::$options['api_key'] ) || empty( self::$options['api_key'] ) ) {
			return;
		}

		self::$api_key = self::$options['api_key'];

		$this->url_args = array(
			'timeout' => apply_filters( 'pmpromc_api_timeout', 10 ),
			'headers' => array(
				'Authorization' => 'Basic ' . self::$api_key
			),
		);

		// the datacenter that the key belongs to.
		list( , self::$dc ) = explode( '-', self::$api_key );

		// Build the URL based on the datacenter
		self::$api_url    = "https://" . self::$dc . ".api.mailchimp.com/3.0";
		self::$user_agent = apply_filters( 'pmpromc_api_user_agent', self::$user_agent );
	}

	/**
	 * Static function to return the datacenter identifier for the API/MailChimp user
	 *
	 * @return string   Mailchimp datacenter identifier
	 */
	public static function get_mc_dc() {
		return self::$dc;
	}

	/**
	 * Connect to Mailchimp API services, test the API key & fetch any existing lists.
	 *
	 * @return bool - True if able to conenct to MailChimp API services.
	 * @since 2.0.0
	 */
	public function connect() {

		$options = $options    = get_option( 'pmpromc_options', false );

		/**
		 * Set the number of lists to return from the MailChimp server.
		 *
		 * @since 2.0.0
		 *
		 * @param   int $max_lists - Max number of lists to return
		 */
		$max = !empty( $options['mc_api_fetch_list_limit'] ) ? $options['mc_api_fetch_list_limit'] : apply_filters( 'pmpro_addon_mc_api_fetch_list_limit', 15 );

		$url      = self::$api_url . "/lists/?count={$max}";
		$response = wp_remote_get( $url, $this->url_args );

		// Fix: is_wp_error() appears to be unreliable since WordPress v4.5
		if ( 200 != wp_remote_retrieve_response_code( $response ) ) {

			switch ( wp_remote_retrieve_response_code( $response ) ) {
				case 401:
					$this->set_error_msg(
						sprintf(
							__(
								'Sorry, but MailChimp was unable to verify your API key. MailChimp gave this response: <p><em>%s</em></p> Please try entering your API key again.',
								'pmpro-mailchimp'
							),
							wp_remote_retrieve_response_message( $response )
						)
					);

					if (WP_DEBUG) {
						error_log( wp_remote_retrieve_response_message( $response ) );
					}

					return false;
					break;

				default:
					$this->set_error_msg(
						sprintf(
							__(
								'Error while communicating with the Mailchimp servers: <p><em>%s</em></p>',
								'pmpro-mailchimp'
							),
							wp_remote_retrieve_response_message( $response )
						)
					);

					if (WP_DEBUG) {
						error_log( wp_remote_retrieve_response_message( $response ) );
					}

					return false;
			}
		} else {

			$body = $this->decode_response( $response['body'] );

			foreach ( $body->lists as $key => $list ) {
				$this->all_lists[ $list->id ] = $list;
			}
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
	 *
	 * @return bool -- True if successful, false otherwise.
	 *
	 * @since 2.0.0
	 */
	public function subscribe( $list = '', WP_User $user_obj = null, $merge_fields = array(), $email_type = 'html', $dbl_opt_in = false ) {

		// Can't be empty
		$test = (array) ( $user_obj );

		if ( empty( $list ) || empty( $test ) ) {

			global $msg;
			global $msgt;

			$msgt = "error";

			if ( empty( $list ) ) {
				$msg = __( "No list ID specified for subscribe operation", "pmpromc" );
			}

			if ( empty( $test ) ) {
				$msg = __( "No user specified for subscribe operation", "pmpromc" );
			}

			return false;
		}

		$interests    = $this->populate_interest_groups( $list, $user_obj );
		$merge_fields = $this->populate_merge_fields( $list, $user_obj );

		//build request
		$request = array(
			'email_type'    => $email_type,
			'email_address' => $user_obj->user_email,
			'status'        => ( 1 == $dbl_opt_in ? 'pending' : 'subscribed' ),
		);

		// add populated merge fields (if applicable)
		if ( ! empty( $merge_fields ) ) {
			$request['merge_fields'] = $merge_fields;
		}

		// add populated interests, (if applicable)
		if ( ! empty( $interests ) ) {
			$request['interests'] = $interests;
		}

		$args = array(
			'method'     => 'PUT', // Allows us to add or update a user ID
			'user-agent' => self::$user_agent,
			'timeout'    => $this->url_args['timeout'],
			'headers'    => $this->url_args['headers'],
			'body'       => $this->encode( $request ),
		);

		//hit api
		$url  = self::$api_url . "/lists/{$list}/members/" . $this->subscriber_id( $user_obj->user_email );
		$resp = wp_remote_request( $url, $args );

		//handle response
		if ( 200 !== wp_remote_retrieve_response_code( $resp ) ) {

			// try updating the Merge Fields & Interest Groups on the MailChimp Server(s).
			if ( true === $this->update_server_settings( $list ) ) {

				// retry the update with updated interest & merge groups
				$resp = wp_remote_request( $url, $args );

				if ( 200 !== wp_remote_retrieve_response_code( $resp ) ) {
					$this->set_error_msg(wp_remote_retrieve_response_message( $resp ) );

					return false;
				}

			} else {

				$this->set_error_msg( wp_remote_retrieve_response_message( $resp ) );

				return false;
			}
		}

		return true;
	}

	/**
	 * Update interest groups & merge fields on the remote MailChimp server (if possible)
	 *
	 * @param   string $list_id ID of MailChimp list to attempt to update
	 *
	 * @return  bool
	 */
	private function update_server_settings( $list_id ) {

		$retVal = true;

		// configure & update interest groups both locally & on MC server
		$retVal = $retVal && $this->update_interest_groups( $list_id );

		// configure & update merge fields both locally and on MC server
		$retVal = $retVal && $this->configure_merge_fields( $list_id );

		return $retVal;
	}

	/**
	 * Unsubscribe user from the specified distribution list (MC)
	 *
	 * @param string $list - MC distribution list ID
	 * @param \WP_User|null $user_objs - The User's WP_User object
	 *
	 * @return bool - True/False depending on whether the operation is successful.
	 *
	 * @since 2.0.0
	 */
	public function unsubscribe( $list = '', WP_User $user_objs = null ) {
		// Can't be empty
		if ( empty( $list ) || empty( $user_objs ) ) {
			return false;
		}

		// Force the emails into an array
		if ( ! is_array( $user_objs ) ) {
			$user_objs = array( $user_objs );
		}

		$url = self::$api_url . "/lists/{$list}/members";

		$args = array(
			'method'     => 'DELETE', // Allows us remove a user ID
			'user-agent' => self::$user_agent,
			'timeout'    => $this->url_args['timeout'],
			'headers'    => $this->url_args['headers'],
			'body'       => null,
		);

		foreach ( $user_objs as $user ) {
			$user_id  = $this->subscriber_id( $user->user_email );
			$user_url = $url . "/{$user_id}";

			$resp = wp_remote_request( $user_url, $args );

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
	 *
	 * @return array|bool|mixed|object - Member information for the specified MC list, or on error false.
	 *
	 * @since 2.0.0
	 */
	public function get_listinfo_for_member( $list_id = null, WP_User $user_data = null ) {
		if ( empty( $list_id ) ) {
			$this->set_error_msg( __( "Error: Need to specify the list ID to receive member info", "pmpromc" ) );

			return false;
		}

		$url = self::$api_url . "/lists/{$list_id}/members/" . $this->subscriber_id( $user_data->user_email );

		$resp = wp_remote_get( $url, $this->url_args );

		if ( 200 !== wp_remote_retrieve_response_code( $resp ) ) {
			$this->set_error_msg( wp_remote_retrieve_response_message( $resp ) );

			return false;
		}

		$member_info = $this->decode_response( $resp['body'] );

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
	 *
	 * @return bool - Success/failure during update operation
	 *
	 * @since 2.0.0
	 */
	public function update_list_member( $list_id = null, WP_User $old_user = null, WP_User $new_user = null ) {
		$url = self::$api_url . "/lists/{$list_id}/members/" . $this->subscriber_id( $old_user->user_email );

		// configure merge fields & interests
		$merge_fields    = $this->populate_merge_fields( $list_id, $new_user );
		$interest_groups = $this->populate_interest_groups( $list_id, $new_user );

		if ( $old_user->user_email != $new_user->user_email ) {

			$retval = $this->unsubscribe( $list_id, $old_user );

			// Don't use double opt-in since the user is already subscribed.
			$retval = $retval && $this->subscribe( $list_id, $new_user, $merge_fields, 'html', false );

			if ( false === $retval ) {

				$this->set_error_msg( __( "Error while updating email address for user!", "pmpromc" ) );
			}

			return $retval;
		}

		// Not trying to change the email address of the user, so we'll attempt to update.
		$request = array(
			'email_type'   => 'html',
			'merge_fields' => $merge_fields,
		);

		// Add any configured interest groups/groupings.
		if ( ! empty( $interest_groups ) ) {
			$request['interests'] = $interest_groups;
		}

		$args = array(
			'method'     => 'PATCH', // Allows us to add or update a user ID
			'user-agent' => self::$user_agent,
			'timeout'    => $this->url_args['timeout'],
			'headers'    => $this->url_args['headers'],
			'body'       => $this->encode( $request ),
		);

		$resp = wp_remote_request( $url, $args );

		if ( 200 !== wp_remote_retrieve_response_code( $resp ) ) {
			$this->set_error_msg( wp_remote_retrieve_response_message( $resp ) );

			return false;
		}

		return true;
	}

	/**
	 * Add/configure merge fields for the specified list ID (and the user object)
	 *
	 * @param   string $list_id -- The ID of the MC mailing list
	 * @param   WP_User $user - User object
	 *
	 * @return array  {
	 *      Merge field array w/field name & data value.
	 * @type    string $name Merge Field name
	 * @type    string $value Merge Field value
	 * }
	 */
	public function populate_merge_fields( $list_id, $user ) {

		// get local configuration for merge fields
		$mc_list_settings = get_option( 'pmcapi_list_settings', null );

		/**
		 * Populate merge fields for specified distribution list
		 *
		 * @since   2.1     Added $list_id (ID of MC list) to allow list specific merge fields
		 */
		$filtered_fields = apply_filters(
			"pmpro_mailchimp_listsubscribe_fields",
			array(
				"FNAME" => $user->first_name,
				"LNAME" => $user->last_name
			),
			$user, $list_id
		);

		if ( WP_DEBUG ) {
			error_log( "MCAPI: Defined merge fields for {$list_id} and user {$user->ID}: " . print_r( $filtered_fields, true ) );
		}

		$configured_fields = array();

		// Check whether there are upstream merge fields we need to worry about
		if ( empty( $mc_list_settings[ $list_id ]->merge_fields ) ) {

			$this->get_existing_remote_merge_fields( $list_id, true );
		}

		// identify any configured merge fields that are (now) stored locally
		if ( !empty( $mc_list_settings[ $list_id ]->merge_fields ) ) {

			foreach ( $mc_list_settings[ $list_id ]->merge_fields as $tag => $field ) {
				$configured_fields[ $field->tag ] = null;
			}
		}

		// check if there's a difference between what we have stored & what the user has specified in filters, etc.
		if ( empty( $mc_list_settings[ $list_id ]->merge_fields ) ) {

			$config_changed = $filtered_fields;

		} else {

			$config_changed = array_diff_key( $configured_fields, $filtered_fields );
		}

		if ( WP_DEBUG ) {
			error_log("MCAPI: Need to add the following merge fields: " . print_r( $config_changed, true ));
		}
		// update the server configuration for the merge fields
		if ( ! empty( $config_changed ) ) {

			$this->configure_merge_fields( $list_id, $config_changed );
		}

		// need to convert groupings (v2 feature) to interest categories?
		foreach ( $filtered_fields as $key => $value ) {

			if ( 'groupings' === strtolower( $key ) ) {

				// clear from merge field list
				unset( $filtered_fields[ $key ] );
			}
		}

		return $filtered_fields;
	}

	/**
	 * Configure interests for the user in MailChimp list
	 *
	 * @param   string $list_id ID of MC list
	 * @param   WP_User $user User object
	 *
	 * @return  array       $interests {
	 *      Array of interests to assign the user to
	 *
	 * @type   string $interest_id ID of the interest for the list ($list_id)
	 * @type   boolean $assign_to_user Whether to assign the interest to the user for the $list_id
	 * }
	 */
	public function populate_interest_groups( $list_id, $user ) {

		$pmpro_active = false;

		global $pmpro_level;

		if ( isset( $pmpro_level->id ) ) {
			$level = $pmpro_level;
		} else {
			$level = pmpro_getMembershipLevelForUser( $user->ID );
		}

		if ( is_plugin_active( 'paid-memberships-pro/paid-memberships-pro.php' ) ) {

			$pmpro_active = true;
		}

		$interests = array();

		foreach ( self::$options["level_{$level->id}_interests"][ $list_id ] as $category_id => $interest_list ) {

			if ( ! empty( $interest_list ) ) {

				foreach ( $interest_list as $interest ) {
					// assign the interest to this user Id(filtered, but set to true by default).
					$interests[ $interest ] = apply_filters( 'pmpro_addon_mc_api_assign_interest_to_user', true, $user, $interest, $list_id, ( $pmpro_active ? $level : null ) );
				}
			}
		}

		if ( WP_DEBUG && !empty( $interests ) ) {
			error_log( "Returning interest groups for level {$pmpro_level->name} and list {$list_id}: " . print_r( $interests, true ) );
		}

		return $interests;
	}

	/**
	 * Early filter for 'pmpro_mailchimp_listsubscribe_fields' once the groupings parameter has been processed.
	 *
	 * @param array     $fields     Array of MailChimp Merge Fields defined by the user
	 * @param WP_User   $user
	 * @param string    $list_id
	 *
	 * @return array
	 */
	public function fix_listsubscribe_fields( $fields, $user = null, $list_id = null ) {

		$options = self::$options;

		// Only process if the 'GROUPINGS' Setting has been converted to an interest group
		if ( !empty( $options['groupings_updated'] ) ) {

			if (in_array( 'groupings', $fields ) ) {
				unset($fields['groupings']);
			}

			if (in_array( 'GROUPINGS', $fields ) ) {
				unset($fields['GROUPINGS']);
			}
		}

		return $fields;
	}

	/**
	 * Returns the interest groups for the specified MailChimp distribution list
	 *
	 * @since 2.1
	 *
	 * @param       string $list_id
	 *
	 * @return      array       List of Interest Groups
	 *
	 */
	/*	public function get_local_interest_categories( $list_id ) {

			$mc_list_settings = get_option( 'pmcapi_list_settings' );

			return empty( $mc_list_settings[ $list_id ]->interest_categories ) ? array() : $mc_list_settings[ $list_id ]->interest_categories;
		}
	*/
	/**
	 * Updates server side interest categories for a mailing list (id)
	 *
	 * @since 2.1
	 *
	 * @param   string $list_id - ID for the MC mailing list
	 *
	 * @return  boolean
	 */
	public function update_interest_groups( $list_id ) {

		global $current_user;

		$this->get_all_lists();

		/**
		 * Local definition for list settings (merge fields & interest categories)
		 * @since 2.1
		 *
		 *    {@internal Format of $mcapi_list_settings configuration:
		 *  array(
		 *      $list_id => stdClass(),
		 *                  -> = string
		 *                  ->merge_fields = array( '<merge_field_id>' => mergefield object )
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

		// if there are no stored list settings
		if ( empty( $mcapi_list_settings ) ) {

			$mcapi_list_settings                                  = array();
			$mcapi_list_settings[ $list_id ]                      = new stdClass();
			$mcapi_list_settings[ $list_id ]->name                = $this->all_lists[$list_id]->name;
			$mcapi_list_settings[ $list_id ]->interest_categories = array();
			$mcapi_list_settings[ $list_id ]->merge_fields        = array();
		}

		$mcapi_list_settings[ $list_id ]->interest_categories = $this->get_interest_categories( $list_id );

		$filtered_mf_config = apply_filters( 'pmpro_mailchimp_merge_fields',
			array(
				array( 'name' => 'PMPLEVELID', 'type' => 'number' ),
				array( 'name' => 'PMPLEVEL', 'type' => 'text' ),
			),
			$list_id
		);

		$user_merge_fields = apply_filters('pmpro_mailchimp_listsubscribe_fields', array(), null, $list_id );
		$v2_category_def = array();

		if ( ! empty( $user_merge_fields ) ) {

			foreach( $user_merge_fields as $field_name => $value ) {

				if ( false == $this->in_merge_fields( $field_name, $filtered_mf_config ) && strtolower( $field_name ) !== 'groupings') {

					// using the default merge field definition (text type).
					$filtered_mf_config[] = array( 'name' => $field_name, 'type' => 'text' );

				} elseif ( 'groupings' == strtolower( $field_name ) ) {

					// do we have an old-style interest group definition?
					if ( WP_DEBUG ) {
						error_log( "MCAPI: Found v2 style interest category definition" );
					}

					$v2_category_def = $value;
				}
			}
		}

		// look for categories
		$category_type = apply_filters( 'pmpro_mailchimp_list_interest_category_type', 'checkboxes', $list_id );

		// process & convert any MCAPI-v2-style interest groups (groupings) aka interest categories.

		if ( ! empty( $v2_category_def ) ) {

			$new_ics = array();

			foreach ( $v2_category_def as $key => $grouping_def ) {

				foreach( $grouping_def['groups'] as $group_name ) {

					// Only add if not already present in list.
					if ( false === $this->in_interest_groups( $group_name, $mcapi_list_settings[ $list_id ]->interest_categories ) ) {
						$new_ic            = new stdClass();
						$new_ic->type      = 'checkboxes';
						$new_ic->id        = null;
						$new_ic->name      = $group_name;
						$new_ic->interests = array();

						$new_ics[] = $new_ic;
						$new_ic = null;
					}
				}

				if (WP_DEBUG) {
					error_log("New Interest Categories: " . print_r( $new_ics, true ) );
				}

				$mcapi_list_settings[ $list_id ]->interest_categories = $mcapi_list_settings[ $list_id ]->interest_categories + $new_ics;
			}
		}

		// Update server unknown interest categories are found locally
		if (! empty( $new_ics ) ) {

			// patch all existing interest categories to MC servers
			$url = self::$api_url . "/lists/{$list_id}/interest-categories";

			$args = array(
				'user-agent' => self::$user_agent,
				'timeout'    => $this->url_args['timeout'],
				'headers'    => $this->url_args['headers'],
			);

			// Update the on-server (MailChimp server) interest category definition(s) for the system
			foreach ( $mcapi_list_settings[ $list_id ]->interest_categories as $id => $category ) {

				// Do we have a new or (likely) existing category
				if ( is_numeric( $id ) ) {

					if ( WP_DEBUG ) {
						error_log( "MCAPI: Adding interest category {$category->name} to the MailChimp servers" );
					}

					$ic_url         = $url;
					$args['method'] = 'POST'; // Allows us to add


					$request = array(
						'title' => $category->name,
						'type'  => ( $category->type != $category_type ? $category_type : $category->type ),
					);

					$args['body'] = $this->encode( $request );

					$resp = wp_remote_request( $ic_url, $args );

					if ( 200 !== wp_remote_retrieve_response_code( $resp ) ) {

						$msg = wp_remote_retrieve_response_message( $resp );

						if ( WP_DEBUG ) {
							error_log( "MCAPI: Error adding interest category {$category->name}: {$msg}");
						}

						$this->set_error_msg( $msg );

						return false;

					} else {

						$cat = $this->decode_response( wp_remote_retrieve_body( $resp ) );

						if (WP_DEBUG) {
							error_log("Added {$cat->title} on the MailChimp Server: {$cat->id}");
						}

						$mcapi_list_settings[ $list_id ]->interest_categories[$cat->id] = new stdClass();
						$mcapi_list_settings[ $list_id ]->interest_categories[$cat->id]->name = $cat->title;
						$mcapi_list_settings[ $list_id ]->interest_categories[$cat->id]->type      = $cat->type;
						$mcapi_list_settings[ $list_id ]->interest_categories[$cat->id]->id        = $cat->id;
						$mcapi_list_settings[ $list_id ]->interest_categories[$cat->id]->interests = array();

						unset( $mcapi_list_settings[ $list_id ]->interest_categories[$id] );
					}
				}
			}
		}

		// update the PMPro MailChimp API settings for all lists (no autoload)
		update_option( 'pmcapi_list_settings', $mcapi_list_settings, false );

		return true;
	}

	/**
	 * Update the list of interests belonging to the $list_id mailing list for the $cat_id interest category on the
	 * MailChimp server.
	 *
	 * @since       2.1
	 *
	 * @param       string $list_id - ID of the MailChimp distribution list
	 * @param       string $cat_id - ID of the Interest Cateogry belonging to $list_id
	 * @param       array $interests - array( $interest_id => $interest_name )
	 *
	 * @return      bool
	 */
	private function edit_interests_on_server( $list_id, $cat_id, $interests ) {

		// patch all existing interest categories to MC servers
		$url = self::$api_url . "/lists/{$list_id}/interest-categories/{$cat_id}/interests";

		$args = array(
			'method'     => 'PATCH', // Allows us to add or update
			'user-agent' => self::$user_agent,
			'timeout'    => $this->url_args['timeout'],
			'headers'    => $this->url_args['headers'],
		);

		foreach ( $interests as $id => $name ) {

			$args['body'] = $this->encode( array(
					'name' => $name,
				)
			);

			// handle v2 conversion
			if ( false !== stripos( $id, 'add_new_' ) ) {
				$args['method'] = "POST";
				$i_url          = "{$url}";
			} else {
				$args['method'] = "PATCH";
				$i_url          = "{$url}/{$id}";
			}

			if ( WP_DEBUG ) {
				error_log( "MCAPI: Updating interest '{$name}' (id: {$id}) for category {$cat_id} in list {$list_id} on the MailChimp server" );
			}

			$resp = wp_remote_request( $i_url, $args );

			if ( 200 !== wp_remote_retrieve_response_code( $resp ) ) {
				$this->set_error_msg( wp_remote_retrieve_response_message( $resp ) );

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
	 * @param       string $list_id MailChimp List ID
	 *
	 * @return      mixed           False = error | array( interest-category-id => object[1], )
	 *
	 * @see http://developer.mailchimp.com/documentation/mailchimp/reference/lists/interest-categories/ - Docs for Interest Categories on MailChimp
	 */
	public function get_interest_categories( $list_id ) {

		$max = !empty( $options['mc_api_fetch_list_limit'] ) ? $options['mc_api_fetch_list_limit'] : apply_filters( 'pmpro_addon_mc_api_fetch_list_limit', 15 );

		// get all existing interest categories from MC servers
		$url = self::$api_url . "/lists/{$list_id}/interest-categories/?count={$max}";

		$args = array(
			'method'     => 'GET', // Fetch data (read)
			'user-agent' => self::$user_agent,
			'timeout'    => $this->url_args['timeout'],
			'headers'    => $this->url_args['headers'],
			'body'       => null
		);

		if ( WP_DEBUG ) {
			error_log( "MCAPI: Fetching interest categories for {$list_id} from the MailChimp servers" );
		}

		$resp = wp_remote_request( $url, $args );

		if ( 200 !== wp_remote_retrieve_response_code( $resp ) ) {
			$this->set_error_msg( wp_remote_retrieve_response_message( $resp ) );

			return false;
		}

		$group = $this->decode_response( wp_remote_retrieve_body( $resp ) );
		$ic = array();

		// Save the interest category information we (may) need
		foreach ( $group->categories as $cat ) {

			$ic[ $cat->id ]            = new stdClass();
			$ic[ $cat->id ]->id        = $cat->id;
			$ic[ $cat->id ]->type      = $cat->type;
			$ic[ $cat->id ]->name      = $cat->title;
			$ic[ $cat->id ]->interests = $this->get_interests_for_category( $list_id, $cat->id );
		}

		return $ic;
	}

	/**
	 * Read all interests for an interest category from the MailChimp server
	 *
	 * @since 2.1
	 *
	 * @param   string $list_id ID of the Distribution List on MailChimp server
	 * @param   string $cat_id ID of the Interest Category on MailChimp server
	 *
	 * @return  array|bool      Array of interest names & IDs
	 */
	public function get_interests_for_category( $list_id, $cat_id ) {

		$max = !empty( $options['mc_api_fetch_list_limit'] ) ? $options['mc_api_fetch_list_limit'] : apply_filters( 'pmpro_addon_mc_api_fetch_list_limit', 15 );

		$url = self::$api_url . "/lists/{$list_id}/interest-categories/{$cat_id}/interests/?count={$max}";

		$args = array(
			'method'     => 'GET', // Allows us to add or update a user ID
			'user-agent' => self::$user_agent,
			'timeout'    => $this->url_args['timeout'],
			'headers'    => $this->url_args['headers'],
			'body'       => null
		);

		if ( WP_DEBUG ) {
			error_log( "MCAPI: Fetching interests for category {$cat_id} in list {$list_id} from the MailChimp servers" );
		}

		$resp = wp_remote_request( $url, $args );

		if ( 200 !== wp_remote_retrieve_response_code( $resp ) ) {
			$this->set_error_msg( wp_remote_retrieve_response_message( $resp ) );

			if (WP_DEBUG) {
				error_log( wp_remote_retrieve_response_message( $resp ) );
			}

			return false;
		}

		$i_list = $this->decode_response( wp_remote_retrieve_body( $resp ) );
		$interests = array();

		foreach ( $i_list->interests as $interest ) {
			$interests[ $interest->id ] = $interest->name;
		}

		return $interests;
	}

	/**
	 * Determine whether a specific interest group name is already defined on the local server
	 *
	 * @param string    $ig_name        Name of interest group to (attempt to) find
	 * @param array     $ig_list        List of interest groups to compare against
	 *
	 * @return bool|string      Returns the ID of the interest category if found.
	 */
	public function in_interest_groups( $ig_name, $ig_list ) {

		if (empty( $ig_list ) ) {
			return false;
		}

		foreach( $ig_list as $id => $ig_obj ) {

			if ( $ig_obj->name == $ig_name ) {
				return $id;
			}
		}

		return false;
	}

	/**
	 * Check if a merge field is in an array of merge fields
	 *
	 * @param   string $field_name
	 * @param   array $fields
	 *
	 * @return  boolean
	 */
	public function in_merge_fields( $field_name, $fields ) {

		if ( empty( $fields ) ) {
			return false;
		}

		foreach ( $fields as $field ) {
			if ( $field['name'] == $field_name ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Configure merge fields for Mailchimp (uses filter)
	 *
	 * @param       string $list_id - The MC list ID
	 * @param       array $delta {
	 * Optional. Array of merge fields we don't think are defined on the MailChimp server
	 *
	 * @type    array $field_definition {
	 *
	 *          Optional. Field definition for the merge field (name, type)
	 * @type   string $key name | type
	 * @type   string $value string or field type
	 * @type   boolean $public Whether the field is to be hidden (false)
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

		$mcapi_list_settings = get_option( 'pmcapi_list_settings', null );

		$mf_config = apply_filters( 'pmpro_mailchimp_merge_fields',
			array(
				array( 'name' => 'PMPLEVELID', 'type' => 'number' ),
				array( 'name' => 'PMPLEVEL', 'type' => 'text' ),
			),
			$list_id
		);

		/**
		 * // check if the local settings are roughly the same as the filtered values
		 * if ( count( $mf_config[0] ) == count( $mcapi_list_settings[ $list_id ]->mf_config ) ) {
		 *
		 * if ( WP_DEBUG ) {
		 * error_log( "MCAPI: No difference in count between locally stored option & supplied filter value" );
		 * }
		 *
		 * return;
		 * }
		 */
		foreach ( $mf_config as $key => $settings ) {

			// new field that needs to be configured on server
			if ( ! empty( $delta[ $settings['name'] ] ) ) {

				if ( WP_DEBUG ) {
					error_log( "MCAPI Processing merge field: {$delta[$settings['name']]} for type {$delta[$settings['type']]}" );
				}

				// include field in MailChimp profile for user (on MailChimp server)
				$visibility = isset( $settings['visible'] ) ? $settings['visible'] : false;

				if ( empty( $mcapi_list_settings[ $list_id ]->merge_fields[ $settings['name'] ] ) ) {
					// Add the field to the upstream server (MC API Server)
					$mcapi_list_settings[ $list_id ]->merge_fields[ $settings['name'] ] = $this->add_merge_field( $list_id, $settings['name'], $settings['type'], $visibility );
				}
			}
		}

		// update the PMPro MailChimp API settings for all lists (no autoload)
		update_option( 'pmcapi_list_settings', $mcapi_list_settings, false );
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
	public function get_existing_remote_merge_fields( $list_id, $force = false ) {

		$mcapi_list_settings = get_option('pmcapi_list_settings', false );

		//hit the API
		$url      = self::$api_url . "/lists/" . $list_id . "/merge-fields";
		$response = wp_remote_get( $url, $this->url_args );

		//check response
		if ( 200 !== wp_remote_retrieve_response_code( $response ) ) {
			$this->set_error_msg( wp_remote_retrieve_response_message( $response ) );

			return false;
		} else {

			$body                           = $this->decode_response( $response['body'] );
			$fields = isset( $body->merge_fields ) ? $body->merge_fields : array();

			if ( !isset($mcapi_list_settings[ $list_id ]->merge_fields) || empty( $mcapi_list_settings[ $list_id ]->merge_fields ) ) {

				$mcapi_list_settings[ $list_id ]->merge_fields = array();
			}

			foreach( $fields as $field ) {

				// Clean up list (if needed)
				if ( !empty( $mcapi_list_settings[ $list_id ]->merge_fields[ $field->merge_id ] ) ) {
					unset( $mcapi_list_settings[ $list_id ]->merge_fields[ $field->merge_id ] );
				}

				$mcapi_list_settings[ $list_id ]->merge_fields[ $field->tag ] = $field;
			}

			if ( WP_DEBUG ) {
				error_log( "MCAPI: Updating local list settings for {$list_id}." );
			}

			// update the PMPro MailChimp API settings for all lists (no autoload)
			update_option( 'pmcapi_list_settings', $mcapi_list_settings, false );

			return $mcapi_list_settings[ $list_id ]->merge_fields;
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
	public function add_merge_field( $list_id, $name, $type = null, $public = false ) {

		//default type to text
		if ( empty( $type ) ) {
			$type = 'text';
		}

		//prepare request
		$new_field = array(
			'tag'    => $name,
			'name'   => $name,
			'type'   => $type,
			'public' => $public,
		);

		$add_args = array(
			'method'     => 'POST',
			'user-agent' => self::$user_agent,
			'timeout'    => $this->url_args['timeout'],
			'headers'    => $this->url_args['headers'],
			'body'       => $this->encode( $new_field ),
		);

		$check_args = array(

		);

		// Build the API URL for the request
		$url      = self::$api_url . "/lists/{$list_id}/merge-fields/";

		// Check if the merge field exists

		$response = wp_remote_request( $url, $add_args );
		$resp_code = wp_remote_retrieve_response_code( $response );

		if (WP_DEBUG) {
			error_log( "Request args: " . print_r( $add_args, true ) );
			error_log( "Response Code: " . print_r( $resp_code, true ) );
			error_log( "Response from API server: " . print_r( $response, true ));
		}

		//check response
		if ( 200 !== $resp_code ) {
			$this->set_error_msg( wp_remote_retrieve_response_message( $response ) );

			return false;
		} else {

			$body        = $this->decode_response( $response['body'] );
			$merge_field = isset( $body->merge_field ) ? $body->merge_field : array();

			if (WP_DEBUG) {
				error_log("Merge field info: " . print_r( $merge_field, true ));
			}
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
	public function get_all_lists() {
		if ( empty( $this->all_lists ) ) {
			$this->connect();
		}

		return $this->all_lists;
	}

	/**
	 * Decode the JSON object we received
	 *
	 * @param $response
	 *
	 * @return array|mixed|object
	 *
	 * @since 2.0.0
	 * @since 2.1 - Updated to handle UTF-8 BOM character
	 */
	private function decode_response( $response ) {

		// UTF-8 BOM handling
		$bom  = pack( 'H*', 'EFBBBF' );
		$json = preg_replace( "/^$bom/", '', $response );

		if ( null !== ( $obj = json_decode( $json ) ) ) {
			return $obj;
		}

		return false;
	}

	/**
	 * @param $data
	 *
	 * @return bool|mixed|string|void
	 *
	 * @since 2.0.0
	 */
	private function encode( $data ) {
		if ( false !== ( $json = json_encode( $data ) ) ) {
			return $json;
		}

		return false;
	}

	/**
	 * @param $user_email
	 *
	 * @return string
	 *
	 * @since 2.0.0
	 */
	private function subscriber_id( $user_email ) {
		$this->subscriber_id = md5( strtolower( $user_email ) );

		return $this->subscriber_id;
	}

	/**
	 * Set visible error message (WordPress dashboard and/or PMPro error field).
	 *
	 * @param   WP_Http|string HTML object with error status, or text message to display
	 *
	 * @since 2.0.0
	 * @since 2.1   Added to the pmpro_msg[t] error messaging system
	 */
	private function set_error_msg( $obj ) {
		global $msgt, $pmpro_msgt;
		global $msg, $pmpro_msg;

		$msgt = 'error';


		if ( ! is_string( $obj ) && ! is_array( $obj ) && ( 200 !== wp_remote_retrieve_response_code( $obj ) ) ) {
			error_log("Object: " . print_r( $obj, true ));
			$msg = wp_remote_retrieve_response_message(  $obj );
		} elseif ( is_string( $obj ) ) {
			$msg = $obj;
		} elseif ( is_array( $obj ) ) {
			foreach ( $obj as $o ) {
				$msg = '';
				$msg .= $o->get_error_message();
			}
		} else {
			$msg = __( "Unable to identify error message", "pmpromc" );
		}

		if ( is_plugin_active( 'paid-memberships-pro/paid-memberships-pro.php' ) ) {
			$pmpro_msg  = $msg;
			$pmpro_msgt = $msgt;
		}
	}
}