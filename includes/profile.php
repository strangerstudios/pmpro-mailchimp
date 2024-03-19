<?php

/*
	Add opt-in Lists to the user profile/edit user page.
*/
function pmpromc_add_custom_user_profile_fields( $user ) {
	global $pmpro_pages;

	$options = get_option( 'pmpromc_options' );

	$additional_audiences = array();
	if ( ! empty( $options['additional_lists'] ) ) {
		$additional_audiences = $options['additional_lists'];
	}

	// Get API and bail if we can't set it.
	$api = pmpromc_getAPI();
	if ( empty( $api ) ) {
		return;
	}

	// Get all audiences.
	global $pmpromc_lists;
	if ( empty( $pmpromc_lists ) ) {
		$pmpromc_lists = get_option( 'pmpromc_all_lists' );
	}

	// If we now have audiences...
	if ( ! empty( $pmpromc_lists ) ) {
		$additional_audiences_info = array();

		foreach ( $pmpromc_lists as $audience_arr ) {
			if ( ! empty( $additional_audiences ) ) {
				foreach ( $additional_audiences as $additional_audience ) {
					if ( $audience_arr['id'] == $additional_audience ) {
						$additional_audiences_info[] = $audience_arr;
						break;
					}
				}
			}
		}
	}

	// If no additional lists to show, return.
	if ( empty( $additional_audiences_info ) ) {
		return;
	}

	// Get user's MC subscription status for additional audiences from MC.
	pmpromc_check_additional_audiences_for_user( $user->ID );


	if ( ! isset( $pmpro_pages['member_profile_edit'] ) || ! is_page( $pmpro_pages['member_profile_edit'] ) ) {
	?>
		<h2><?php esc_html_e( 'Opt-in Mailchimp Audiences', 'pmpro-mailchimp' ); ?></h2>

		<table class="form-table">
			<tr>
				<th>
					<label for="address"><?php esc_html_e( 'Mailing Lists', 'pmpro-mailchimp' ); ?>
					</label></th>
				<td>
					<?php
					$user_additional_audiences = get_user_meta( $user->ID, 'pmpromc_additional_lists', true );

					if ( isset( $user_additional_audiences ) ) {
						$selected_audiences = $user_additional_audiences;
					} else {
						$selected_audiences = array();
					}

					echo '<input type="hidden" name="additional_lists_profile" value="1" />';
					foreach ( $additional_audiences_info as $audience_arr ) {
						$checked_modifier = ( is_array( $selected_audiences ) && in_array( $audience_arr['id'], $selected_audiences ) ) ? ' checked' : '';
						echo( "<input type='checkbox' name='additional_lists[]' value='" . esc_attr( $audience_arr['id'] ) . "' id='pmpromc_additional_lists_" . esc_attr( $audience_arr['id'] ) . "'" . esc_attr( $checked_modifier ) . ">" );
						echo( "<label for='pmpromc_additional_lists_" . esc_attr( $audience_arr['id'] ) .  "' class='pmpromc-checkbox-label'>" . esc_html( $audience_arr['name'] ) .  "</label><br>" );
					}
					?>
				</td>
			</tr>
		</table>
	<?php
	} else { // Show on front-end profile page.
	?>

	<div class="pmpro_member_profile_edit-field pmpro_member_profile_edit-field-pmpromc_opt_in_list">
	<label for="address">
		<?php esc_html_e( 'Opt-in Mailchimp Mailing Lists', 'pmpro-mailchimp' );?>
	</label>
	<?php
	$user_additional_audiences = get_user_meta( $user->ID, 'pmpromc_additional_lists', true );

			if ( isset( $user_additional_audiences ) ) {
				$selected_audiences = $user_additional_audiences;
			} else {
				$selected_audiences = array();
			}

			echo '<input type="hidden" name="additional_lists_profile" value="1" />';
			foreach ( $additional_audiences_info as $audience_arr ) {
				$checked_modifier = ( is_array( $selected_audiences ) && in_array( $audience_arr['id'], $selected_audiences ) ) ? ' checked' : '';
				echo( "<input type='checkbox' name='additional_lists[]' value='" . esc_attr( $audience_arr['id'] ) . "' id='pmpromc_additional_lists_" . esc_attr( $audience_arr['id'] ) . "'" . esc_attr( $checked_modifier ) . ">" );
				echo( "<label for='pmpromc_additional_lists_" . esc_attr( $audience_arr['id'] ) .  "' class='pmpromc-checkbox-label'>" . esc_html( $audience_arr['name'] ) .  "</label><br>" );
			} ?>
	</div> <!-- end pmpro_member_profile_edit-field-first_name -->
	<?php
	}
}
add_action( 'show_user_profile', 'pmpromc_add_custom_user_profile_fields', 12 );
add_action( 'edit_user_profile', 'pmpromc_add_custom_user_profile_fields', 12 );
add_action( 'pmpro_show_user_profile', 'pmpromc_add_custom_user_profile_fields', 12 );

// Saving additional lists on profile save.
function pmpromc_save_custom_user_profile_fields( $user_id ) {
	// Only if additional lists is set.
	if ( ! isset( $_REQUEST['additional_lists_profile'] ) ) {
		return;
	}

	// Get user's new additional lists.
	if ( empty( $_REQUEST['additional_lists'] ) ) {
		$_REQUEST['additional_lists'] = array();
	}

	// Get user's current additional lists.
	$current_lists = get_user_meta( $user_id, 'pmpromc_additional_lists', true );
	if ( empty( $current_lists ) ) {
		$current_lists = array();
	}

	$options = get_option( 'pmpromc_options' );
	if ( ! isset( $options['profile_update'] ) ) {
		$options['profile_update'] = 0; // Default value.
	}

	if (
		1 == $options['profile_update'] ||
		! empty( array_diff( $current_lists, $_REQUEST['additional_lists'] ) ) ||
		! empty( array_diff( $_REQUEST['additional_lists'], $current_lists ) )
	) {
		// Option set to update MC on every profile save or opt-in lists have changed.
		pmpromc_set_user_additional_list_meta( $user_id, $_REQUEST['additional_lists'] );
	}
}
add_action( 'personal_options_update', 'pmpromc_save_custom_user_profile_fields' );
add_action( 'edit_user_profile_update', 'pmpromc_save_custom_user_profile_fields' );
add_action( 'pmpro_personal_options_update', 'pmpromc_save_custom_user_profile_fields' );

/**
 * Change email in Mailchimp if a user's email is changed in WordPress
 *
 * @param $user_id (int) -- ID of user
 * @param $old_user_data -- WP_User object
 */
function pmpromc_profile_update( $user_id, $old_user_data ) {
	$new_user_data = get_userdata( $user_id );

	// By default only update users if their email has changed.
	$email_changed = ( $new_user_data->user_email != $old_user_data->user_email );
	$update_user   = $email_changed;

	$options = get_option( 'pmpromc_options' );
	if ( isset( $options['profile_update'] ) && $options['profile_update'] == 1 ) {
		$update_user = true;
	}

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
			pmpromc_process_audience_member_updates_queue();

			// Execute changes that are already queued.
			foreach ( $audiences as $audience ) {
				// Check for member.
				$member = $api->get_listinfo_for_member( $audience->id, $old_user_data );
				if ( isset( $member->status ) ) {
					global $pmpromc_audience_member_updates;
					if ( $email_changed ) {
						// Update the email.
						$data = array(
							'email_address' => $new_user_data->user_email,
						);
						if ( WP_DEBUG ) {
							error_log( "Updating user's email address from {$old_user_data->user_email} to {$new_user_data->user_email} for audience {$audience->name} ({$audience->id})." );
						}
						$update_successful = $api->update_contact( $member, $data );
						if ( ! $update_successful ) {
							// User's new email may already existed in audience.
							// Unsubscribe old email address to be sure that it does not recieve emails.
							if ( WP_DEBUG ) {
								error_log( "Handling error by unsubscribing {$old_user_data->user_email} from audience {$audience->name} ({$audience->id})." );
							}
							$user_data = (object) array(
								'email_address' => $old_user_data->user_email,
								'status'        => 'unsubscribed',
								'merge_fields'  => apply_filters(
									'pmpro_mailchimp_listsubscribe_fields',
									array(
										'FNAME' => wp_specialchars_decode( $new_user_data->first_name ),
										'LNAME' => wp_specialchars_decode( $new_user_data->last_name ),
									),
									$new_user_data,
									$audience->id
								),
							);
							if ( empty( $pmpromc_audience_member_updates ) ) {
								$pmpromc_audience_member_updates = array();
							}
							if ( ! array_key_exists( $audience->id, $pmpromc_audience_member_updates ) ) {
								$pmpromc_audience_member_updates[ $audience->id ] = array();
							}
							// Use user_id '0' to fake a user.
							$pmpromc_audience_member_updates[ $audience->id ][0] = $user_data;
						}
					}
					// Update the user's merge fields.
					pmpromc_add_audience_member_update( $user_id, $audience->id, $member->status );
				}
			}
		}
	}
}
add_action( 'profile_update', 'pmpromc_profile_update', 20, 2 );

