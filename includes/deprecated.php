<?php

/**
 * Subscribe a user to any additional opt-in lists selected
 *
 * @deprecated TBD Use pmpromc_set_user_additional_list_meta() instead
 */
function pmpromc_subscribeToAdditionalLists($user_id){
	_deprecated_function( __FUNCTION__, 'TBD', 'pmpromc_set_user_additional_list_meta' );

	// Nonce checks not needed as this function is not used anymore and is deprecated.
	if (!empty($_REQUEST['additional_lists'])) // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$additional_lists = $_REQUEST['additional_lists']; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

	if (!empty($additional_lists)) {
		update_user_meta($user_id, 'pmpromc_additional_lists', $additional_lists);

		foreach ($additional_lists as $list) {
			//subscribe them
			pmpromc_queue_subscription($user_id, $list);
		}
	}
}

/**
 * Add a user to the queue to process unsubscriptions form
 * all levels that they should unsubscribe from
 *
 * @param WP_User|int $user - The WP_User object or user_id for the user.
 *
 * @deprecated TBD
 */
function pmpromc_queue_smart_unsubscriptions( $user ) {
	_deprecated_function( __FUNCTION__, 'TBD' );
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
 * @deprecated TBD
 *
 * @param WP_User $old_user - The old WP_User object being changed
 * @param WP_User $old_user - The new WP_User object being added
 * @param Array|string $audiences - The id(s) of the audience(s) to remove the user from
 */
function pmpromc_queue_user_update( $old_user, $new_user, $audiences ) {
	_deprecated_function( __FUNCTION__, 'TBD' );
	pmpromc_queue_unsubscription( $old_user, $audiences );
	pmpromc_queue_subscription( $new_user, $audiences );
}

/**
 * @deprecated TBD
 */
function pmpromc_subscribe( $list, $user ) {
	_deprecated_function( __FUNCTION__, 'TBD' );
	pmpromc_queue_subscription( $user, $list );
	pmpromc_process_audience_member_updates_queue();
}

/**
 * @deprecated TBD
 */
function pmpromc_queueUserToSubscribeToList($user_id, $list) {
	_deprecated_function( __FUNCTION__, 'TBD' );
	pmpromc_queue_subscription( $user_id, $list );
}

/**
 * @deprecated TBD
 */
function pmpromc_processSubscriptions($param) {
	pmpromc_process_audience_member_updates_queue();
}

/**
 * @deprecated TBD
 */
function pmpromc_unsubscribe($list, $user) {
	_deprecated_function( __FUNCTION__, 'TBD' );
	pmpromc_queue_unsubscription( $user, $list );
	pmpromc_process_audience_member_updates_queue();
}

/**
 * @deprecated TBD
 */
function pmpromc_queueUserToUnsubscribeFromLists($user_id) {
	_deprecated_function( __FUNCTION__, 'TBD' );
	pmpromc_queue_smart_unsubscriptions( $user_id );
}

/**
 * @deprecated TBD
 */
function pmpromc_processUnsubscriptions($param) {
	_deprecated_function( __FUNCTION__, 'TBD' );
	pmpromc_process_audience_member_updates_queue();
}

/**
 * @deprecated TBD
 */
function pmpromc_unsubscribeFromLists($user_id, $level_id = NULL) {
	_deprecated_function( __FUNCTION__, 'TBD' );
	pmpromc_queue_smart_unsubscriptions( $user_id );
	pmpromc_process_audience_member_updates_queue();
}

/**
 * Get array of lists to unsubscribe a user from
 *
 * @deprecated TBD
 *
 * @param $user_id (int) - User Id
 */
function pmpromc_get_unsubscribe_audiences( $user_id ) {
	_deprecated_function( __FUNCTION__, 'TBD' );
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
	$levels_unsubscribing_from = $wpdb->get_col( $wpdb->prepare("SELECT DISTINCT(membership_id) FROM $wpdb->pmpro_memberships_users WHERE user_id = %d AND membership_id NOT IN(%s) AND status IN('admin_changed', 'admin_cancelled', 'cancelled', 'changed', 'expired', 'inactive') AND modified > NOW() - INTERVAL 15 MINUTE ", $user_id, $user_level_ids_string) );

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