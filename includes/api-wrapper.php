<?php

/**
 * Load and return an object for the Mailchimp API
 */
function pmpromc_getAPI() {
	$options = get_option( 'pmpromc_options' );

	if ( empty( $options ) || empty( $options['api_key'] ) ) {
		return false;
	}

	if ( isset( $options['api_key'] ) ) {
		$api = apply_filters( 'get_mailchimpapi_class_instance', null );
		if ( ! empty( $api ) ) {
			$api->set_key();
			if ( $api->connect() !== false ) {
				$r = $api;
			} else {
				$r = false;
			}
		}
	} else {
		$r = false;
	}

	// Log error if API fails to load, each use of $api in the larger code base should catch $api === false and fail quietly.
	if ( empty( $r ) ) {
		if ( WP_DEBUG ) {
			error_log( 'Error loading Mailchimp API' );
		}

		/**
		 * Hook in case we want to handle cases where $r is false and throw an error
		 * @param $api False if API didn't init, or might have an error if setting keys or connecting failed.
		 */
		do_action( 'pmpromc_get_api_failed', $api );
	}

	return $r;
}

/**
 * Add a user to the queue to subscribe to an audience
 *
 * @param WP_User|int  $user - The WP_User object or user_id for the user.
 * @param Array|string $audiences - The id(s) of the audience(s) to add the user to.
 */
function pmpromc_queue_subscription( $user, $audiences ) {
	$options = get_option( 'pmpromc_options' );
	$status  = ( 1 == $options['double_opt_in'] ? 'pending' : 'subscribed' );

	// Add member to queue.
	pmpromc_add_audience_member_update( $user, $audiences, $status );
}

/**
 * Unsubscribe a user from a specific list
 *
 * @param WP_User|int  $user - The WP_User object or user_id for the user.
 * @param Array|string $audiences - The id(s) of the audience(s) to remove the user from.
 */
function pmpromc_queue_unsubscription( $user, $audiences ) {
	pmpromc_add_audience_member_update( $user, $audiences, 'unsubscribed' );
}

/**
 * Queue an update to an audience
 *
 * @param WP_User|int  $user - The WP_User object or user_id for the user to be updated.
 * @param Array|string $audiences - The id(s) of the audience(s) to remove the user from.
 * @param string       $status - The mailchimp status to set the user to.
 */
function pmpromc_add_audience_member_update( $user, $audiences, $status = 'subscribed' ) {
	global $pmpromc_audience_member_updates;
	if ( ! is_object( $user ) ) {
		$user = get_userdata( $user );
	}

	// Check for valid status.
	if ( ! in_array( $status, array( 'subscribed', 'unsubscribed', 'pending' ), true ) ) {
		return;
	}

	if ( ! is_array( $audiences ) ) {
		$audiences = array( $audiences );
	}

	// Build empty user data.
	$user_data = null;

	// Loop through audiences.
	foreach ( $audiences as $audience ) {

		// Check validity of audience.
		$all_audiences  = get_option( 'pmpromc_all_lists' );
		$audience_found = false;
		foreach ( $all_audiences as $index => $temp_audience ) {
			if ( $temp_audience['id'] === $audience ) {
				$audience_found = true;
			}
		}
		if ( ! $audience_found ) {
			continue;
		}

		// Build user profile if not yet built for previous audience update.
		if ( null === $user_data ) {
			$user_data = (object) array(
				'email_address' => $user->user_email,
				'status'        => $status,
				'merge_fields'  => apply_filters( 'pmpro_mailchimp_listsubscribe_fields', array('FNAME' => $user->first_name, 'LNAME' => $user->last_name), $user, $audience ),
			);
		}

		// Add user to $pmpromc_audience_member_updates for list.
		if ( empty( $pmpromc_audience_member_updates ) ) {
			$pmpromc_audience_member_updates = array();
		}

		if ( ! array_key_exists( $audience, $pmpromc_audience_member_updates ) ) {
			$pmpromc_audience_member_updates[ $audience ] = array();
		}
		$pmpromc_audience_member_updates[ $audience ][ $user->ID ] = $user_data;
	}
}

/**
 * Execute the updates queued by pmpromc_add_audience_member_update()
 *
 * @param mixed $filter_contents - Is returned as is for when function is run on filter.
 */
function pmpromc_process_audience_member_updates_queue( $filter_contents = null ) {
	global $pmpromc_audience_member_updates;

	// Return if nothing in queue.
	if ( empty( $pmpromc_audience_member_updates ) ) {
		return $filter_contents;
	}

	// Init API.
	$api = pmpromc_getAPI();
	if ( empty( $api ) ) {
		return $filter_contents;
	}
	// Loop through queue and call API for each audience.
	foreach ( $pmpromc_audience_member_updates as $audience => $updates ) {
		$updates_simple = array_values( $updates ); // Change associative array to simple array.
		if ( $api ) {
			// Process max 500 members at a time.
			$index_to_process = 0;
			while ( $index_to_process < count( $updates_simple ) ) {
				$api->update_audience_members( $audience, array_slice( $updates_simple, $index_to_process, 500 ) );
				$index_to_process += 500;
			}
		} else {
			wp_die( __('Error during unsubscribe operation. Please report this error to the administrator', 'pmpro-mailchimp') );
		}
	}

	// Unset the global.
	$pmpromc_audience_member_updates = array();
	return $filter_contents;
}
add_action( 'template_redirect', 'pmpromc_process_audience_member_updates_queue', 2 );
add_filter( 'wp_redirect', 'pmpromc_process_audience_member_updates_queue', 100 );
add_action( 'pmpro_membership_post_membership_expiry', 'pmpromc_process_audience_member_updates_queue' );
add_action( 'shutdown', 'pmpromc_process_audience_member_updates_queue' );

/**
 * Membership level as merge values.
 *
 * @param array   $fields - Merge fields (preexisting).
 * @param WP_USER $user User object.
 * @param string  $list - the List ID.
 * @return mixed - Array of $merge fields;
 */
function pmpromc_pmpro_mailchimp_listsubscribe_fields( $fields, $user, $list ) {
	// Make sure PMPro is active.
	if ( ! function_exists( 'pmpro_getMembershipLevelForUser' ) ) {
		return $fields;
	}

	$options = get_option( 'pmpromc_options' );

	$levels      = pmpro_getMembershipLevelsForUser( $user->ID );
	$level_ids   = array();
	$level_names = array();
	foreach ( $levels as $level ) {
		$level_ids[]   = $level->id;
		$level_names[] = $level->name;
	}

	// Make sure we don't have dupes.
	$level_ids   = array_unique( $level_ids );
	$level_names = array_unique( $level_names );

	if ( ! empty( $level_ids ) ) {
		$fields['PMPLEVELID'] = $level_ids[0];
		$fields['PMPALLIDS']  = '{' . implode( '}{', $level_ids ) . '}';
		$fields['PMPLEVEL']   = implode( ',', $level_names );
	} else {
		$fields['PMPLEVELID'] = '';
		$fields['PMPALLIDS']  = '{}';
		$fields['PMPLEVEL']   = '';
	}

	return $fields;
}
add_filter( 'pmpro_mailchimp_listsubscribe_fields', 'pmpromc_pmpro_mailchimp_listsubscribe_fields', 10, 3 );

/**
 * Checks user's status for opt-in lists in Mailchimp and updates PMPro accordingly

 * @param  WP_USER|int $user to check.
 */
function pmpromc_check_additional_audiences_for_user( $user ) {
	$options = get_option( 'pmpromc_options' );
	if ( empty( $options['additional_lists'] ) || empty( $user ) ) {
		return;
	}

	if ( ! is_object( $user ) ) {
		$user = get_userdata( $user );
	}

	$active_lists = array();
	foreach ( $options['additional_lists'] as $list ) {
		// Get user's status in Mailchimp for audience.
		$status = pmpromc_get_list_status_for_user( $list, $user );

		// Add active lists.
		if ( in_array( $status, array( 'subscribed', 'pending' ), true ) ) {
			$active_lists[] = $list;
		}
	}
	update_user_meta( $user->ID, 'pmpromc_additional_lists', $active_lists );
}

/**
 * Saves changes to user's opt-in audiences and updates Mailchimp
 *
 * @param WP_USER|int $user to update.
 * @param array       $updated_additional_audiences audiences that user should be subscribed to.
 */
function pmpromc_set_user_additional_list_meta( $user, $updated_additional_audiences ) {
	if ( ! is_object( $user ) ) {
		$user = get_userdata( $user );
	}

	$old_additional_audiences = get_user_meta( $user->ID, 'pmpromc_additional_lists', true );
	if ( ! empty( $old_additional_audiences ) ) {
		if ( empty( $updated_additional_audiences ) ) {
			$updated_additional_audiences = array();
		}
		$audiences_to_remove = array_diff( $old_additional_audiences, $updated_additional_audiences );
		pmpromc_queue_unsubscription( $user, $audiences_to_remove );
	}
	update_user_meta( $user->ID, 'pmpromc_additional_lists', $updated_additional_audiences );
	pmpromc_sync_additional_audiences_for_user( $user );
}

/**
 * Subscribes user to additional audiences they have set in usermeta
 *
 * @param WP_USER|int $user to sync.
 */
function pmpromc_sync_additional_audiences_for_user( $user ) {
	if ( ! is_object( $user ) ) {
		$user = get_userdata( $user );
	}

	$additional_audiences = get_user_meta( $user->ID, 'pmpromc_additional_lists', true );
	if ( ! empty( $additional_audiences ) ) {
		pmpromc_queue_subscription( $user, $additional_audiences );
	}
}

/**
 * Gets the status of a user in a Mailchimp list
 *
 * @param string      $list to check.
 * @param WP_USER|int $user to get status for.
 * @return string|null status.
 */
function pmpromc_get_list_status_for_user( $list, $user ) {
	$api = pmpromc_getAPI();
	if ( empty( $api ) ) {
		return;
	}
	if ( ! is_object( $user ) ) {
		$user = get_userdata( $user );
	}
	$list_info = $api->get_listinfo_for_member( $list, $user );
	if ( empty( $list_info ) || empty( $list_info->status ) ) {
		return null;
	}
	return $list_info->status;
}
