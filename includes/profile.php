<?php

/*
	Add opt-in Lists to the user profile/edit user page.
*/
function pmpromc_add_custom_user_profile_fields($user)
{
	$options = get_option("pmpromc_options");
	$all_lists = get_option("pmpromc_all_lists");
	$lists = array();

	if (!empty($options['additional_lists']))
		$additional_lists = $options['additional_lists'];
	else
		$additional_lists = array();

	//get API and bail if we can't set it
	$api = pmpromc_getAPI();
	if(empty($api))
		return;

	//get lists
	$lists = $api->get_all_lists();

	//no lists?
	if (!empty($lists)) {
		$additional_lists_array = array();

		foreach ($lists as $list) {
			if (!empty($additional_lists)) {
				foreach ($additional_lists as $additional_list) {
					if ($list->id == $additional_list) {
						$additional_lists_array[] = $list;
						break;
					}
				}
			}
		}
	}

	if (empty($additional_lists_array))
		return;
	?>
	<h3><?php _e('Opt-in Mailchimp Audiences', 'pmpro-mailchimp'); ?></h3>

	<table class="form-table">
		<tr>
			<th>
				<label for="address"><?php _e('Mailing Lists', 'pmpro-mailchimp'); ?>
				</label></th>
			<td>
				<?php
				global $profileuser;
				$user_additional_lists = get_user_meta($profileuser->ID, 'pmpromc_additional_lists', true);

				if (isset($user_additional_lists))
					$selected_lists = $user_additional_lists;
				else
					$selected_lists = array();

				echo '<input type="hidden" name="additional_lists_profile" value="1" />';
				echo "<select multiple='yes' name=\"additional_lists[]\">";
				foreach ($additional_lists_array as $list) {
					echo "<option value='" . $list->id . "' ";
					if (is_array($selected_lists) && in_array($list->id, $selected_lists))
						echo "selected='selected'";
					echo ">" . $list->name . "</option>";
				}
				echo "</select>";
				?>
			</td>
		</tr>
	</table>
	<?php
}
add_action('show_user_profile', 'pmpromc_add_custom_user_profile_fields', 12);
add_action('edit_user_profile', 'pmpromc_add_custom_user_profile_fields', 12);

//saving additional lists on profile save
function pmpromc_save_custom_user_profile_fields($user_id)
{
	//only if additional lists is set
	if (!isset($_REQUEST['additional_lists_profile']))
		return;

	$options = get_option("pmpromc_options", array());
	$all_additional_lists = $options['additional_lists'];

	if (isset($_REQUEST['additional_lists']))
		$additional_user_lists = $_REQUEST['additional_lists'];
	else
		$additional_user_lists = array();
	update_user_meta($user_id, 'pmpromc_additional_lists', $additional_user_lists);

	//get all pmpro additional lists
	//if they aren't in $additional_user_lists Unsubscribe them from those

	$list_user = get_userdata($user_id);

	if (!empty($all_additional_lists)) {
		foreach ($all_additional_lists as $list) {
			//If we find the list in the user selected lists then subscribe them
			if (in_array($list, $additional_user_lists)) {
				//Subscribe them
				pmpromc_queue_subscription( $list_user, $list );
			} //If we do not find them in the user selected lists, then unsubscribe them.
			else {
				//Unsubscribe them
				pmpromc_queue_unsubscription($list_user, $list);
			}
		}
	}
}
add_action('personal_options_update', 'pmpromc_save_custom_user_profile_fields');
add_action('edit_user_profile_update', 'pmpromc_save_custom_user_profile_fields');

/**
 * Change email in Mailchimp if a user's email is changed in WordPress
 *
 * @param $user_id (int) -- ID of user
 * @param $old_user_data -- WP_User object
 */
function pmpromc_profile_update( $user_id, $old_user_data ) {
	$new_user_data = get_userdata( $user_id );

	// By default only update users if their email has changed.
	$update_user = ( $new_user_data->user_email != $old_user_data->user_email );

	/**
	 * Filter in case they want to update the user on all updates
	 *
	 * @param bool $update_user true or false if user should be updated at Mailchimp
	 * @param int $user_id ID of user in question
	 * @param object $old_user_data old data from before this profile update
	 *
	 * @since 2.0.3
	 */
	$update_user = apply_filters( 'pmpromc_profile_update', $update_user, $user_id, $old_user_data );

	if ( $update_user ) {
		// Get API and bail if we can't set it.
		$api = pmpromc_getAPI();
		if ( empty( $api ) ) {
			return;
		}

		// Get all audiences.
		$audiences = $api->get_all_lists();

		if ( ! empty( $audiences ) ) {
			// Execute changes that are already queued.
			pmpromc_process_audience_member_updates_queue();
			foreach ( $audiences as $audience ) {
				// Check for member.
				$member = $api->get_listinfo_for_member( $audience->id, $old_user_data );
				if ( ! empty( $member ) ) {
					global $pmpromc_audience_member_updates;
					if ( $new_user_data->user_email != $old_user_data->user_email ) {
						// If the user is changing emails, unsubscribe the old email.
						$user_data = (object) array(
							'email_address' => $old_user_data->user_email,
							'status'		=> 'unsubscribed',
							'merge_fields'  => apply_filters( 'pmpro_mailchimp_listsubscribe_fields', array( 'FNAME' => $new_user_data->first_name, 'LNAME' => $new_user_data->last_name), $new_user_data, $audience ),
						);
						// Manually add email removal to queue since the user's email has changed.
						if ( empty( $pmpromc_audience_member_updates ) ) {
							$pmpromc_audience_member_updates = array();
						}

						if ( ! array_key_exists( $audience->id, $pmpromc_audience_member_updates ) ) {
							$pmpromc_audience_member_updates[ $audience->id ] = array();
						}
						// Set as user id 0 as a special case to avoid conflict with new email being added.
						$pmpromc_audience_member_updates[ $audience->id ][0] = $user_data;
					}
					// Update the user's merge fields.
					pmpromc_add_audience_member_update( $user_id, $audience->id, $member->status );
				}
			}
		}
	}
}
add_action( 'profile_update', 'pmpromc_profile_update', 20, 2 );