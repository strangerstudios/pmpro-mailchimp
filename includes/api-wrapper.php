<?php

/*
	Load and return an object for the Mailchimp API
*/
function pmpromc_getAPI()
{
	$options = get_option("pmpromc_options");

	if (empty($options) || empty($options['api_key']))
		return false;

	if (isset($options['api_key'])) {
		$api = apply_filters('get_mailchimpapi_class_instance', null);
		if(!empty($api)) {
			$api->set_key();
			if($api->connect() !== false)
				$r = $api;
			else
				$r = false;
		}
	} else {
		$r = false;
	}

	//log error if API fails to load, each use of $api in the larger code base should catch $api === false and fail quietly
	if(empty($r)) {
		if(WP_DEBUG) {
			error_log('Error loading Mailchimp API');
		}

		/**
		 * Hook in case we want to handle cases where $r is false and throw an error
		 * @param $api False if API didn't init, or might have an error if setting keys or connecting failed.
		 */
		do_action('pmpromc_get_api_failed', $api);
	}

	return $r;
}

/**
 * Add a user to the queue to subscribe to an audience
 *
 * @param WP_User|int $user - The WP_User object or user_id for the user.
 * @param Array|string $audiences - The id(s) of the audience(s) to add the user to
 */
function pmpromc_queue_subscription( $user, $audiences ) {
  $options = get_option("pmpromc_options");
  $status = ( 1 == $options['double_opt_in'] ? 'pending' : 'subscribed' );

  // Add member to queue
  pmpromc_add_audience_member_update( $user, $audiences, $status );
}

/**
 * Unsubscribe a user from a specific list
 *
 * @param WP_User|int $user - The WP_User object or user_id for the user.
 * @param Array|string $audiences - The id(s) of the audience(s) to remove the user from
 */
function pmpromc_queue_unsubscription( $user, $audiences )
{
  pmpromc_add_audience_member_update( $user, $audiences, 'unsubscribed' );
}

/**
 * Add a user to the queue to process unsubscriptions form
 * all levels that they should unsubscribe from
 *
 * @param WP_User|int $user - The WP_User object or user_id for the user.
 */
function pmpromc_queue_smart_unsubscriptions( $user ) {
	// Get the user object if user_id is passed.
  if( ! is_object( $user ) ) {
		$user = get_userdata($user);
  }

  // Get user lists to unsubscribe from
  $unsubscribe_audiences = pmpromc_get_unsubscribe_audiences( $user->ID );
  
  // Add member to queue
  pmpromc_add_audience_member_update( $user, $unsubscribe_audiences, 'unsubscribed' );
}

/**
 * Update a user's Mailchimp information when profile is updated
 *
 * @param WP_User $old_user - The old WP_User object being changed
 * @param WP_User $old_user - The new WP_User object being added
 * @param Array|string $audiences - The id(s) of the audience(s) to remove the user from
 */
function pmpromc_queue_user_update( $old_user, $new_user, $audiences ) {
  pmpromc_queue_unsubscription( $old_user, $audiences );
  pmpromc_queue_subscription( $new_user, $audiences );
}

/**
 * Queue an update to an audience
 *
 * @param WP_User|int $user - The WP_User object or user_id for the user to be updated
 * @param Array|string $audiences - The id(s) of the audience(s) to remove the user from
 * @param string $status - The mailchimp status to set the user to
 */
function pmpromc_add_audience_member_update( $user, $audiences, $status = 'subscribed' ) {
  global $pmpromc_audience_member_updates;
  if( ! is_object( $user ) ) {
		$user = get_userdata( $user );
  }
  
  // Check for valid status
  if ( ! in_array( $status, array( 'subscribed', 'unsubscribed', 'pending' ) ) ) {
	return;
  }
  
  if ( ! is_array($audiences) ) {
	$audiences = array( $audiences );
  }
  
  // Build empty user data.
  $user_data = null;
  
  // Loop through audiences.
  foreach ( $audiences as $audience ) {

	// Check validity of audience
	$all_audiences = get_option("pmpromc_all_lists");
	$audience_found = false;
	foreach ($all_audiences as $index => $temp_audience) {
	  if ( $temp_audience['id'] === $audience ) {
		$audience_found = true;
	  }
	}
	if ( ! $audience_found ) {
	  continue;
	}
  
	// Make sure that user isn't already in audience's queue
	if ( isset( $pmpromc_audience_member_updates[ $audience ][$user->ID] ) ) {
	  // TODO: Should we process the queue now? Just overwrite the current value? Continue?
	  // Currently overwrites.
	}
	
	// Build user profile if not yet built for previous audience update
	if ( null === $user_data ) {
	  $user_data = (object) array(
		  'email_address' => $user->user_email,
		  'status' => $status,
		  'merge_fields' => apply_filters("pmpro_mailchimp_listsubscribe_fields", array("FNAME" => $user->first_name, "LNAME" => $user->last_name), $user, $audience),
	  );
	}

	// Add user to $pmpromc_audience_member_updates for list.
	if ( empty( $pmpromc_audience_member_updates ) ) {
	  $pmpromc_audience_member_updates = array();
	}
	
	if ( ! array_key_exists( $audience, $pmpromc_audience_member_updates ) ) {
	  $pmpromc_audience_member_updates[ $audience ] = array();
	}
	$pmpromc_audience_member_updates[ $audience ][$user->ID] = $user_data;
  }
}

/**
 * Execute the updates queued by pmpromc_add_audience_member_update()
 */
function pmpromc_process_audience_member_updates_queue( $filter_contents = null ) {
  global $pmpromc_audience_member_updates;

  // Return if nothing in queue
  if ( empty( $pmpromc_audience_member_updates ) ) {
	return $filter_contents;
  }

  // Init API
  $api = pmpromc_getAPI();
  if ( empty( $api ) ) {
	return $filter_contents;
  }
  // Loop through queue and call API for each audience
  foreach ( $pmpromc_audience_member_updates as $audience => $updates ) {
	$updates_simple = array_values( $updates ); // Change associative array to simple array.
	if ( $api ) {
		// Process max 500 members at a time
		$index_to_process = 0;
		while( $index_to_process < count( $updates_simple ) ) {
		  $api->update_audience_members( $audience, array_slice ( $updates_simple, $index_to_process, 500 ) );
		  $index_to_process += 500;
		}
	} else {
		wp_die( __('Error during unsubscribe operation. Please report this error to the administrator', 'pmpro-mailchimp') );
	}
  }
  
  // Unset the global
  $pmpromc_audience_member_updates = array();
  return $filter_contents;
}
add_action('template_redirect', 'pmpromc_process_audience_member_updates_queue', 2);
add_filter('wp_redirect', 'pmpromc_process_audience_member_updates_queue', 100);
add_action('pmpro_membership_post_membership_expiry', 'pmpromc_process_audience_member_updates_queue');
add_action('shutdown', 'pmpromc_process_audience_member_updates_queue');


/**
 * Get array of lists to unsubscribe a user from
 *
 * @param $user_id (int) - User Id
 */
function pmpromc_get_unsubscribe_audiences( $user_id ) {
  global $wpdb;
  $options = get_option("pmpromc_options");
  $all_lists = get_option("pmpromc_all_lists");

  //don't unsubscribe if unsubscribe option is no
  if (empty($options['unsubscribe'])) {

	  if (WP_DEBUG) {
		  error_log("No need to unsubscribe {$user_id}");
	  }
	  return;
  }

  //what levels does the user have now?
  $user_levels = pmpro_getMembershipLevelsForUser($user_id);
  if(!empty($user_levels)) {
	$user_level_ids = array();
	foreach($user_levels as $level)
	  $user_level_ids[] = $level->id;
  } else {
	$user_level_ids = array();
  }

  //unsubscribing from all lists or just old level lists?
  if ($options['unsubscribe'] == "all") {
	  $unsubscribe_lists = wp_list_pluck($all_lists, "id");
  } else {
  //format user's current levels as string for query
  if(!empty($user_level_ids))
	$user_level_ids_string = implode(',', $user_level_ids);
  else
	$user_level_ids_string = '0';

  //get levels in (admin_changed, inactive, changed) status with modified dates within the past few minutes
  $sqlQuery = $wpdb->prepare("SELECT DISTINCT(membership_id) FROM $wpdb->pmpro_memberships_users WHERE user_id = %d AND membership_id NOT IN(%s) AND status IN('admin_changed', 'admin_cancelled', 'cancelled', 'changed', 'expired', 'inactive') AND modified > NOW() - INTERVAL 15 MINUTE ", $user_id, $user_level_ids_string);
  $levels_unsubscribing_from = $wpdb->get_col($sqlQuery);

  //figure out which lists to unsubscribe from
  $unsubscribe_lists = array();
  foreach($levels_unsubscribing_from as $unsub_level_id) {
	if (!empty($options['level_' . $unsub_level_id . '_lists'])) {
	  $unsubscribe_lists = array_merge($unsubscribe_lists, $options['level_' . $unsub_level_id . '_lists']);
	}
  }
  $unsubscribe_lists = array_unique($unsubscribe_lists);
}
//still lists to unsubscribe from?
  if (empty($unsubscribe_lists)) {
	  return;
  }

  $level_lists = array();
  if (!empty($user_level_ids)) {
	foreach($user_level_ids as $user_level_id) {
	  if (!empty($options['level_' . $user_level_id . '_lists'])) {
		$level_lists = array_merge($level_lists, $options['level_' . $user_level_id . '_lists']);
	  }
	}
  } else {
	  $level_lists = isset($options['users_lists']) ? $options['users_lists'] : array();
  }

  //we don't want to unsubscribe from lists for the new level(s) or any additional lists the user is subscribed to
  $user_additional_lists = get_user_meta($user_id, 'pmpromc_additional_lists', true);
  if (!is_array($user_additional_lists)) {

	  $user_additional_lists = array();
  }

  //merge
  $dont_unsubscribe_lists = array_merge($user_additional_lists, $level_lists);
  return array_diff($unsubscribe_lists, $dont_unsubscribe_lists);
}

/**
 * Membership level as merge values.
 *
 * @param $fields - Merge fields (preexisting)
 * @param $user (WP_User) - User object
 * @param $list - the List ID
 * @return mixed - Array of $merge fields;
 */
function pmpromc_pmpro_mailchimp_listsubscribe_fields($fields, $user, $list)
{
	//make sure PMPro is active
	if (!function_exists('pmpro_getMembershipLevelForUser')) {
		return $fields;
	}

	$options = get_option("pmpromc_options");

	$levels = pmpro_getMembershipLevelsForUser($user->ID);
	$level_ids = array();
	$level_names = array();
	foreach($levels as $level) {
		$level_ids[] = $level->id;
		$level_names[] = $level->name;
	}

	//make sure we don't have dupes
	$level_ids = array_unique($level_ids);
	$level_names = array_unique($level_names);

	if(!empty($level_ids)) {
		$fields['PMPLEVELID'] = $level_ids[0];
		$fields['PMPALLIDS'] = '{' . implode('}{', $level_ids) . '}';
		$fields['PMPLEVEL'] = implode(',', $level_names);
	} else {
		$fields['PMPLEVELID'] = '';
		$fields['PMPALLIDS'] = '{}';
		$fields['PMPLEVEL'] = '';
	}

	return $fields;
}
add_filter('pmpro_mailchimp_listsubscribe_fields', 'pmpromc_pmpro_mailchimp_listsubscribe_fields', 10, 3);

/**
 * DEPRECATED FUNCTIONS BELOW
 */
function pmpromc_subscribe( $list, $user ) {
  pmpromc_queue_subscription( $user, $list );
  pmpromc_process_audience_member_updates_queue();
}

function pmpromc_queueUserToSubscribeToList($user_id, $list) {
  pmpromc_queue_subscription( $user_id, $list );
}

function pmpromc_processSubscriptions($param) {
  pmpromc_process_audience_member_updates_queue();
}
  
function pmpromc_unsubscribe($list, $user) {
  pmpromc_queue_unsubscription( $user, $list );
  pmpromc_process_audience_member_updates_queue();
}

function pmpromc_queueUserToUnsubscribeFromLists($user_id) {
  pmpromc_queue_smart_unsubscriptions( $user_id );
}

function pmpromc_processUnsubscriptions($param) {
  pmpromc_process_audience_member_updates_queue();
}
  
function pmpromc_unsubscribeFromLists($user_id, $level_id = NULL) {
  pmpromc_queue_smart_unsubscriptions( $user_id );
  pmpromc_process_audience_member_updates_queue();
}

