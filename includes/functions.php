<?php

/**
 * Set up PMPromc
 */
function pmpromc_init() {
	// Get pmpromc options.
	$options = get_option( 'pmpromc_options' );

	$GLOBALS['pmpromc_api'] = apply_filters( 'get_mailchimpapi_class_instance', null );
	if ( is_null( $GLOBALS['pmpromc_api'] ) ) {
		$GLOBALS['pmpromc_api'] = new PMPromc_Mailchimp_API();
	}
	$GLOBALS['pmpromc_api']->set_key();

	// Are we on the checkout page?
	$is_checkout_page = ( isset( $_REQUEST['submit-checkout'] ) || ( isset( $_REQUEST['confirm'] ) && isset( $_REQUEST['gateway'] ) ) );

	// Setup hooks for user_register.
	if ( ! empty( $options['users_lists'] ) && ! $is_checkout_page ) {
		add_action( 'user_register', 'pmpromc_user_register' );
	}

	// Setup hooks for PMPro levels.
	pmpromc_getPMProLevels();
	global $pmpromc_levels;

	if ( ! empty( $pmpromc_levels ) && ! $is_checkout_page ) {
		add_action( 'pmpro_after_change_membership_level', 'pmpromc_pmpro_after_change_membership_level', 15, 2 );
	} elseif ( ! empty( $pmpromc_levels ) ) {
		// Usermeta is added after membership level changed, so we should wait for additional lists to be added to usermeta before updating.
		add_action( 'pmpro_after_checkout', 'pmpromc_pmpro_after_checkout', 15 );
	}
}
add_action( 'init', 'pmpromc_init', 0 );

/**
 * Set the pmpromc_levels array if PMPro is installed
 */
function pmpromc_getPMProLevels() {
	global $pmpromc_levels, $wpdb;
	if ( ! empty( $wpdb->pmpro_membership_levels ) ) {
		$pmpromc_levels = $wpdb->get_results( "SELECT * FROM $wpdb->pmpro_membership_levels ORDER BY id" );
	} else {
		$pmpromc_levels = false;
	}
}

/**
 * Subscribe users to lists when they register.
 *
 * @param int $user_id that was registered.
 */
function pmpromc_user_register( $user_id ) {
	clean_user_cache( $user_id );

	$options = get_option( 'pmpromc_options', array() );

	// Should we add them to any lists?
	if ( ! empty( $options['users_lists'] ) && ! empty( $options['api_key'] ) ) {

		// Subscribe to each list.
		foreach ( $options['users_lists'] as $list ) {
			// Subscribe them.
			pmpromc_queue_subscription( $user_id, $list );
		}
	}
}

/**
 * Subscribe new members (PMPro) when their membership level changes
 *
 * @param $level_id (int) -- ID of pmpro membership level
 * @param $user_id (int) -- ID for user
 *
 */
function pmpromc_pmpro_after_change_membership_level( $level_id, $user_id ) {
	clean_user_cache( $user_id );

	// Remove? Not being used...
	global $pmpromc_levels;

	$options = get_option("pmpromc_options");

	// Remove? Not being used...
	$all_lists = get_option("pmpromc_all_lists");
	
	// Clear opt-in lists on cancellation or expiration
	if ( $level_id === 0 ) {
	  update_user_meta( $user_id, 'pmpromc_additional_lists', array() );
	}

	//should we add them to any lists?
	if (!empty($options['level_' . $level_id . '_lists']) && !empty($options['api_key'])) {

		//subscribe to each list
		foreach ($options['level_' . $level_id . '_lists'] as $list) {

			//subscribe them
			pmpromc_queue_subscription($user_id, $list);
		}

		//unsubscribe them from lists not selected, or all lists from their old level
		pmpromc_queue_smart_unsubscriptions($user_id);

	} elseif (!empty($options['api_key']) && count($options) > 3) {

		//now they are a normal user should we add them to any lists?
		//Case where PMPro is not installed?
		if (!empty($options['users_lists']) && !empty($options['api_key'])) {

			//subscribe to each list
			foreach ($options['users_lists'] as $list) {
				//subscribe them
				pmpromc_queue_subscription($user_id, $list);
			}

			//unsubscribe from any list not assigned to users
			pmpromc_queue_smart_unsubscriptions($user_id);
		} else {

			//some memberships are on lists. assuming the admin intends this level to be unsubscribed from everything
			pmpromc_queue_smart_unsubscriptions($user_id);
		}

	}
}

/**
 * CHECKOUT FUNCTIONS
 */

/**
 * Dispaly additional opt-in list fields on checkout
 */
function pmpromc_additional_lists_on_checkout() {
	global $pmpro_review;

	$options = get_option( 'pmpromc_options' );

	// Get API and bail if we can't set it.
	$api = pmpromc_getAPI();
	if ( empty( $api ) ) {
		return;
	}

	// Are there additional lists?
	if ( ! empty( $options['additional_lists'] ) ) {
		$additional_lists = $options['additional_lists'];
	} else {
		return;
	}

	// Okay get through API.
	$lists = $api->get_all_lists();

	// No lists?
	if ( empty( $lists ) ) {
		return;
	}

	$additional_lists_array = array();
	foreach ( $lists as $list ) {
		if ( ! empty( $additional_lists ) ) {
			foreach ( $additional_lists as $additional_list ) {
				if ( $list->id == $additional_list ) {
					$additional_lists_array[] = $list;
					break;
				}
			}
		}
	}

	// No lists? do nothing.
	if ( empty( $additional_lists_array ) ) {
		return;
	}

	$display_modifier = empty( $pmpro_review ) ? '' : 'style="display: none;"';
	?>
	<table id="pmpro_mailing_lists" class="pmpro_checkout top1em" width="100%" cellpadding="0" cellspacing="0"
		border="0" <?php echo( $display_modifier ); ?>>
		<thead>
		<tr>
			<th>
				<?php
				if ( count( $additional_lists_array ) > 1 ) {
					esc_html_e( 'Join one or more of our mailing lists.', 'pmpro-mailchimp' );
				} else {
					esc_html_e( 'Join our mailing list.', 'pmpro-mailchimp' );
				}
				?>
			</th>
		</tr>
		</thead>
		<tbody>
		<tr class="odd">
			<td>
				<?php
				global $current_user;
				if ( isset( $_REQUEST['additional_lists'] ) ) {
					$additional_lists_selected = $_REQUEST['additional_lists'];
				} elseif ( isset( $_SESSION['additional_lists'] ) ) {
					$additional_lists_selected = $_SESSION['additional_lists'];
				} elseif ( ! empty( $current_user->ID ) ) {
					$additional_lists_selected = get_user_meta( $current_user->ID, 'pmpromc_additional_lists', true );
				} else {
					$additional_lists_selected = array();
				}

				$count = 0;
				foreach ( $additional_lists_array as $key => $additional_list ) {
					$count++;
					?>
					<input type="checkbox" id="additional_lists_<?php echo $count; ?>" name="additional_lists[]"
						   value="<?php echo $additional_list->id; ?>" <?php if (is_array($additional_lists_selected) && !empty($additional_lists_selected[$count - 1])) checked($additional_lists_selected[$count - 1]->id, $additional_list->id); ?> />
					<label for="additional_lists_<?php echo $count; ?>"
						   class="pmpro_normal pmpro_clickable"><?php echo $additional_list->name; ?></label><br/>
					<?php
				}
				?>
			</td>
		</tr>
		</tbody>
	</table>
	<?php
}
add_action( 'pmpro_checkout_after_tos_fields', 'pmpromc_additional_lists_on_checkout' );

/**
 * Preserve info when going off-site for payment w/offsite payment gateway (PayPal Express).
 * Sets Session variables.
 */
function pmpromc_pmpro_paypalexpress_session_vars() {
	if ( isset( $_REQUEST['additional_lists'] ) ) {
		$_SESSION['additional_lists'] = $_REQUEST['additional_lists'];
	}
}
add_action( 'pmpro_paypalexpress_session_vars', 'pmpromc_pmpro_paypalexpress_session_vars' );

/*
	Update Mailchimp lists when users checkout
*/
function pmpromc_pmpro_after_checkout($user_id)
{
	global $pmpro_checkout_levels;

	if(!empty($pmpro_checkout_levels) && is_array($pmpro_checkout_levels)) {
		// MMPU installed.

		foreach($pmpro_checkout_levels as $level_to_subscribe) {

			pmpromc_pmpro_after_change_membership_level(intval($level_to_subscribe->id), $user_id);
		}
	} else {

		pmpromc_pmpro_after_change_membership_level(intval($_REQUEST['level']), $user_id);
	}

	pmpromc_subscribeToAdditionalLists($user_id);
}

/*
	Subscribe a user to any additional opt-in lists selected
*/
function pmpromc_subscribeToAdditionalLists($user_id)
{
	$options = get_option("pmpromc_options");
	if (!empty($_REQUEST['additional_lists']))
		$additional_lists = $_REQUEST['additional_lists'];

	if (!empty($additional_lists)) {
		update_user_meta($user_id, 'pmpromc_additional_lists', $additional_lists);

		foreach ($additional_lists as $list) {
			//subscribe them
			pmpromc_queue_subscription($user_id, $list);
		}
	}
}
