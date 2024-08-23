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
	$options = get_option( 'pmpromc_options' );
	if ( ! empty( $options['users_lists'] ) && ( ! function_exists( 'pmpro_is_checkout' ) || ! pmpro_is_checkout() ) ) {
		// Registering for site without recieving level. Add to non-member lists.
		pmpromc_queue_subscription( $user_id, $options['users_lists'] );
	}
}
add_action( 'user_register', 'pmpromc_user_register' );

/**
 * Subscribe new members (PMPro) when their membership level changes
 *
 * @param $level_id (int) -- ID of pmpro membership level.
 * @param $user_id (int) -- ID for user.
 */
function pmpromc_pmpro_after_change_membership_level( $level_id, $user_id ) {
	clean_user_cache( $user_id );

	$options = get_option( 'pmpromc_options' );

	// Find subscribe and unsubscribe audiences for user.
	$subscribe_audiences   = array();
	$unsubscribe_audiences = array();

	// Calculate subscribe_audiences.
	$user_levels    = pmpro_getMembershipLevelsForUser( $user_id );
	$user_level_ids = array();
	if ( ! empty( $user_levels ) ) {
		foreach ( $user_levels as $level ) {
			$user_level_ids[] = $level->id;
			if ( ! empty( $options[ 'level_' . $level->id . '_lists' ] ) ) {
				$subscribe_audiences = array_merge( $subscribe_audiences, $options[ 'level_' . $level->id . '_lists' ] );
			}
		}
	}

	// Calculate unsubscribe audiences.
	if ( $options['unsubscribe'] != '0' ) {
		// Get levels in (admin_changed, inactive, changed) status with modified dates within the past few minutes.
		global $wpdb;
		$levels_unsubscribing_from = $wpdb->get_col( 
			$wpdb->prepare(
				"SELECT DISTINCT(membership_id) FROM $wpdb->pmpro_memberships_users WHERE user_id = %d AND membership_id NOT IN(%s) AND status IN('admin_changed', 'admin_cancelled', 'cancelled', 'changed', 'expired', 'inactive') AND modified > NOW() - INTERVAL 15 MINUTE ",
				$user_id,
				implode(',', $user_level_ids)
			)
		 );
		foreach ( $levels_unsubscribing_from as $unsub_level_id ) {
			if ( ! empty( $options[ 'level_' . $unsub_level_id . '_lists' ] ) ) {
				$unsubscribe_audiences = array_merge( $unsubscribe_audiences, $options[ 'level_' . $unsub_level_id . '_lists' ] );
			}
		}
	}

	// Calculate non-member audiences.
	if ( ! empty( $options['users_lists'] ) && empty( $user_levels ) ) {
		// Add user to non-member lists.
		$subscribe_audiences = array_merge( $subscribe_audiences, $options['users_lists'] );
	} elseif ( ! empty( $options['users_lists'] ) ) {
		// Remove user from non-member lists.
		// Additional checks need to be done to prevent unnecessary entries from being created in Mailchimp.
		// Ex. don't unsubscribe if user is not already in audience.
		foreach ( $options['users_lists'] as $audience ) {
			if ( in_array( $audience, $subscribe_audiences ) || in_array( $audience, $unsubscribe_audiences ) ) {
				// Audience already being updated, so safe to add.
				$unsubscribe_audiences[] = $audience;
			} elseif ( in_array( pmpromc_get_list_status_for_user( $audience, $user_id ), array( 'subscribed', 'unsubscribed', 'pending' ) ) ) {
				// User present in audience, update.
				$unsubscribe_audiences[] = $audience;
			}
		}
	}

	$subscribe_audiences   = array_unique( $subscribe_audiences );
	$unsubscribe_audiences = array_unique( $unsubscribe_audiences );

	// Subscribe/Unsubscribe user.
	pmpromc_queue_subscription( $user_id, $subscribe_audiences );
	pmpromc_queue_unsubscription( $user_id, array_diff( $unsubscribe_audiences, $subscribe_audiences ) );

	// Update opt-in audiences and user audiences.
	if ( empty( $user_level_ids ) && 'all' === $options['unsubscribe'] ) {
		pmpromc_set_user_additional_list_meta( $user_id, array() );
		// Nonce not needed as we only want to make sure that this REQUEST variable is empty, not process it as form data.
		if ( isset( $_REQUEST['additional_lists'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			// In case level is changed from profile.
			$_REQUEST['additional_lists'] = array();
		}
	} else {
		pmpromc_sync_additional_audiences_for_user( $user_id );
	}

	// Update all audiences on profile save in case usermeta
	// is changed after this level change.
	add_filter( 'pmpromc_profile_update', '__return_true' );
}
add_action( 'pmpro_after_change_membership_level', 'pmpromc_pmpro_after_change_membership_level', 15, 2 );

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

	global $current_user;
	pmpromc_check_additional_audiences_for_user( $current_user->ID );

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
	<fieldset id="pmpro_form_fieldset-mailchimp-opt-in" class="<?php echo esc_attr( pmpro_get_element_class( 'pmpro_form_fieldset' ) ); ?>">
		<div id="pmpro_mailing_lists" class="<?php echo esc_attr( pmpro_get_element_class( 'pmpro_card' ) ); ?>" <?php echo esc_html( $display_modifier ); ?>>
			<div class="<?php echo esc_attr( pmpro_get_element_class( 'pmpro_card_content' ) ); ?>">
				<legend class="<?php echo esc_attr( pmpro_get_element_class( 'pmpro_form_legend' ) ); ?>">
					<h2 class="<?php echo esc_attr( pmpro_get_element_class( 'pmpro_form_heading pmpro_font-large', 'pmpro_mailchimp_opt_in_header' ) ); ?>">
						<?php
						if ( count( $additional_lists_array ) > 1 ) {
							esc_html_e( 'Join one or more of our mailing lists.', 'pmpro-mailchimp' );
						} else {
							esc_html_e( 'Join our mailing list.', 'pmpro-mailchimp' );
						}
						?>
					</h2>
				</legend>
				<div class="<?php echo esc_attr( pmpro_get_element_class( 'pmpro_form_fields' ) ); ?>">
					<div class="<?php echo esc_attr( pmpro_get_element_class( 'pmpro_checkout-field pmpro_checkout-field-checkbox' ) ); ?>">
						<?php
						global $current_user;
						// Nonce not needed as this is only setting the default value for the checkbox, not processing form data.
						if ( isset( $_REQUEST['additional_lists'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
							$additional_lists_selected = $_REQUEST['additional_lists']; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
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
							<input type="checkbox" id="additional_lists_<?php echo esc_attr( $count ); ?>" name="additional_lists[]" value="<?php echo esc_attr( $additional_list->id ); ?>" class="<?php echo esc_attr( pmpro_get_element_class( 'pmpro_form_input pmpro_form_input-checkbox' ) ); ?>"
								<?php
								if ( is_array( $additional_lists_selected ) ) {
									checked( in_array( $additional_list->id, $additional_lists_selected ) );
								};
								?>
							/>
							<label for="additional_lists_<?php echo esc_attr( $count ); ?>" class="<?php echo esc_attr( pmpro_get_element_class( 'pmpro_form_label pmpro_form_label-inline pmpro_clickable' ) ); ?>"><?php echo esc_html( $additional_list->name ); ?></label><br/>
							<?php
						}
						?>
					</div>
				</div>
			</div>
		</div>
	</fieldset>
    <?php
}
add_action( 'pmpro_checkout_after_tos_fields', 'pmpromc_additional_lists_on_checkout' );

/**
 * Preserve info when going off-site for payment w/offsite payment gateway (PayPal Express).
 * Sets Session variables.
 */
function pmpromc_pmpro_paypalexpress_session_vars() {
	// Nonce not needed as this only runs within the PMPro checkout process.
	if ( isset( $_REQUEST['additional_lists'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$_SESSION['additional_lists'] = $_REQUEST['additional_lists']; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	}
}
add_action( 'pmpro_paypalexpress_session_vars', 'pmpromc_pmpro_paypalexpress_session_vars' );

/**
 * Delay the call to pmpromc_pmpro_after_change_membership_level() during checkout until
 * after usermeta is saved. Function call re-added in pmpromc_pmpro_after_checkout().
 */
function pmpromc_pmpro_checkout_before_change_membership_level() {
	remove_action( 'pmpro_after_change_membership_level', 'pmpromc_pmpro_after_change_membership_level', 15, 2 );
}
add_action( 'pmpro_checkout_before_change_membership_level', 'pmpromc_pmpro_checkout_before_change_membership_level' );

/**
 * Update Mailchimp opt-in audiences when users checkout after usermeta is saved.
 *
 * @param int $user_id of user who checked out.
 */
function pmpromc_pmpro_after_checkout( $user_id, $order ) {
	pmpromc_pmpro_after_change_membership_level( $order->membership_id, $user_id );
	// Nonce not needed as this only runs within the PMPro checkout process.
	if ( empty( $_REQUEST['additional_lists'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$_REQUEST['additional_lists'] = array(); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	}
	pmpromc_set_user_additional_list_meta( $user_id, $_REQUEST['additional_lists'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
}
add_action( 'pmpro_after_checkout', 'pmpromc_pmpro_after_checkout', 15, 2 );

function pmpromc_log( $entry ) {
	$options = get_option( 'pmpromc_options' );
	if ( empty( $options['logging_enabled'] ) ) {
		// Logging is not enabled.
		return;
	}

	// Define a regular expression pattern to match email addresses
	$pattern = '/(?<=@)([a-zA-Z0-9._%+-]+)(?=\.[a-zA-Z]{2,})/';
	$entry = preg_replace( $pattern, '****', $entry );

	$logstr = "Logged On: " . date_i18n("m/d/Y H:i:s") . "\n";
	$logstr .= $entry;
	$logstr .= "\n-------------\n";

	$loghandle = fopen( pmpromc_get_log_file_path(), "a+" );
	fwrite($loghandle, $logstr);
	fclose($loghandle);
}

/**
 * Get the file path for the debug log file.
 *
 * @since 2.3.5
 *
 * @return string
 */
function pmpromc_get_log_file_path() {
	// Check if we have a unique file name saved already.
	$pmpromc_file_name = get_option( 'pmpromc_log_file_name' );
	if ( empty( $pmpromc_file_name ) ) {
		$pmpromc_file_name = 'pmpromc-log-' . uniqid() . '.txt';
		update_option( 'pmpromc_log_file_name', $pmpromc_file_name );
	}

	// Build the debug log file path.
	$pmpromc_log_path = PMPROMC_DIR . "/logs/" . $pmpromc_file_name;

	/**
	 * Filter the debug log file path. 
	 * By default this points to /plugins/pmpro-mailchimp/logs/pmpromc-log.txt
	 * 
	 * @param string $path
	 *
	 * @since 2.3.3
	 */
	$pmpromc_log_path = apply_filters( 'pmpromc_log_path', $pmpromc_log_path );

	return $pmpromc_log_path;
}

