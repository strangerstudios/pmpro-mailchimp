<?php 

/*
	Add the admin options page
*/
function pmpromc_admin_add_page()
{
	add_options_page('PMPro Mailchimp Options', 'PMPro Mailchimp', 'manage_options', 'pmpromc_options', 'pmpromc_options_page');
}
add_action('admin_menu', 'pmpromc_admin_add_page');

//html for options page
function pmpromc_options_page()
{
	global $pmpromc_lists, $msg, $msgt;

	//get options
	$options = get_option("pmpromc_options");

	//defaults
	if (empty($options)) {
		$options = array("unsubscribe" => 1);
		update_option("pmpromc_options", $options);
	} elseif (!isset($options['unsubscribe'])) {
		$options['unsubscribe'] = 1;
		update_option("pmpromc_options", $options);
	}

	//check for a valid API key and get lists
	if (!empty($options['api_key']))
		$api_key = $options['api_key'];
	else
		$api_key = false;

	//get API and bail if we can't set it
	$api = pmpromc_getAPI();

	if (!empty($api)) {
		$pmpromc_lists = $api->get_all_lists();
		$all_lists = array();

		if (!empty($pmpromc_lists)) {

			//save all lists in an option
			$i = 0;
			foreach ($pmpromc_lists as $list) {

				$all_lists[$i] = array();
				$all_lists[$i]['id'] = $list->id;
				$all_lists[$i]['web_id'] = $list->id;
				$all_lists[$i]['name'] = $list->name;
				$i++;
			}

			/** Save all of our new data */
			update_option("pmpromc_all_lists", $all_lists);
		}
	}
	?>
	<div class="wrap">
		<div id="icon-options-general" class="icon32"><br></div>
		<h2><?php _e( 'Mailchimp Integration Options and Settings', 'pmpro-mailchimp' );?></h2>

		<?php if (!empty($msg)) { ?>
			<div class="message <?php echo $msgt; ?>"><p><?php echo $msg; ?></p></div>
		<?php } ?>

		<form action="options.php" method="post">
			<h2><?php _e('Subscribe users to one or more Mailchimp audiences when they sign up for your site.', 'pmpro-mailchimp');?></h2>
			<p><?php printf(__('If you have <a href="%s" target="_blank">Paid Memberships Pro</a> installed, you can subscribe members to one or more Mailchimp audiences based on their membership level or specify "Opt-in Audiences" that members can select at membership checkout. <a href="%s" target="_blank">Get a Free Mailchimp account</a>.', 'pmpro-mailchimp'), 'https://www.paidmembershipspro.com', 'http://eepurl.com/k4aAH');?></p>
			<?php if (function_exists('pmpro_getAllLevels')) { ?>
				<hr/>
				<h2><?php _e("Synchronize a Member's Level Name and ID", 'pmpro-mailchimp');?></h2>
				<p><?php _e("Since v2.0, this plugin creates and synchronizes the <code>PMPLEVEL</code> and <code>PMPLEVELID</code> merge field in Mailchimp. <strong>This will only affect new or updated members.</strong> You must import this data into MailChimp for existing members.", 'pmpro-mailchimp');?> <a href="http://www.paidmembershipspro.com/import-level-name-id-existing-members-using-new-merge-fields-pmpro-mailchimp-v2-0/" target="_blank"><?php _e('Read the documentation on importing existing members into MailChimp', 'pmpro-mailchimp');?></a>.</p>
				<p><a class="button" onclick="jQuery('#pmpromc_export_instructions').show();"><?php _e('Click here to export your members list for a MailChimp Import', 'pmpro-mailchimp');?></a></p>
				<hr/>

				<div id="pmpromc_export_instructions" class="postbox" style="display: none;">
					<div class="inside">
						<h2><?php _e('Export a CSV for your Mailchimp Import', 'pmpro-mailchimp');?></h2>
						<p><?php _e('Membership Level', 'pmpro-mailchimp');?>:
							<select id="pmpromc_export_level" name="l">
								<?php
								$levels = pmpro_getAllLevels(true, true);
								foreach ($levels as $level) {
									?>
									<option value="<?php echo $level->id ?>"><?php echo $level->name ?></option>
									<?php
								}
								?>
							</select> <a class="button-primary" id="pmpromc_export_link" href="" target="_blank"><?php _e('Download List (.CSV)', 'pmpro-mailchimp');?></a></p>
						<hr/>
						<p><strong><?php _e('Mailchimp Import Steps', 'pmpro-mailchimp');?></strong></p>
						<ol>
							<li><?php _e('Download a CSV of member data for each membership level.', 'pmpro-mailchimp');?></li>
							<li><?php _e('Log in to Mailchimp.', 'pmpro-mailchimp');?></li>
							<li><?php _e('Go to Audiences -> Choose an Audience -> Add Members -> Import Members -> CSV or tab-delimited text file.', 'pmpro-mailchimp');?>
							</li>
							<li><?php _e('Import columns <code>PMPLEVEL</code> and <code>PMPLEVELID</code>. The fields should have those exact names in all uppercase letters.', 'pmpro-mailchimp');?>
							</li>
							<li><?php _e('Check "auto update my existing audience". Click "Import".', 'pmpro-mailchimp');?></li>
						</ol>

						<p><?php printf(__('For more detailed instructions and screenshots, <a href="%s" target="_blank">click here to read our documentation on importing existing members into Mailchimp</a>.', 'pmpro-mailchimp'), 'http://www.paidmembershipspro.com/import-level-name-id-existing-members-using-new-merge-fields-pmpro-mailchimp-v2-0/');?></p>

					</div>
				</div>
				<script>
					jQuery(document).ready(function () {
						var exporturl = '<?php echo admin_url('admin-ajax.php?action=pmpro_mailchimp_export_csv');?>';

						//function to update export link
						function pmpromc_update_export_link() {
							jQuery('#pmpromc_export_link').attr('href', exporturl + '&l=' + jQuery('#pmpromc_export_level').val());
						}

						//update on change
						jQuery('#pmpromc_export_level').change(function () {
							pmpromc_update_export_link();
						});

						//update on load
						pmpromc_update_export_link();
					});
				</script>
			<?php } ?>

			<?php settings_fields('pmpromc_options'); ?>
			<?php do_settings_sections('pmpromc_options'); ?>

			<p><br/></p>

			<div class="bottom-buttons">
				<input type="hidden" name="pmpromc_options[set]" value="1"/>
				<input type="submit" name="submit" class="button-primary" value="<?php esc_attr_e(__('Save Settings', 'pmpro-mailchimp')); ?>">
			</div>

		</form>
	</div>
	<?php
}

/*
	Registers settings on admin init
*/
function pmpromc_admin_init()
{
	//setup settings
	register_setting('pmpromc_options', 'pmpromc_options', 'pmpromc_options_validate');
	add_settings_section('pmpromc_section_general', __('General Settings', 'pmpro-mailchimp'), 'pmpromc_section_general', 'pmpromc_options');
	add_settings_field('pmpromc_option_api_key', __('Mailchimp API Key', 'pmpro-mailchimp'), 'pmpromc_option_api_key', 'pmpromc_options', 'pmpromc_section_general');
	add_settings_field('pmpromc_option_users_lists', __('Non-member Audiences', 'pmpro-mailchimp'), 'pmpromc_option_users_lists', 'pmpromc_options', 'pmpromc_section_general');

	//only if PMPro is installed
	if (function_exists("pmpro_hasMembershipLevel"))
		add_settings_field('pmpromc_option_additional_lists', __('Opt-in Audiences', 'pmpro-mailchimp'), 'pmpromc_option_additional_lists', 'pmpromc_options', 'pmpromc_section_general');

	add_settings_field('pmpromc_option_double_opt_in', __('Require Double Opt-in?', 'pmpro-mailchimp'), 'pmpromc_option_double_opt_in', 'pmpromc_options', 'pmpromc_section_general');
	add_settings_field('pmpromc_option_unsubscribe', __('Unsubscribe on Level Change?', 'pmpro-mailchimp'), 'pmpromc_option_unsubscribe', 'pmpromc_options', 'pmpromc_section_general');
	add_settings_field('pmpromc_option_profile_update', __('Update on Profile Save?', 'pmpro-mailchimp'), 'pmpromc_option_profile_update', 'pmpromc_options', 'pmpromc_section_general');
	add_settings_field('pmpromc_option_logging_enabled', __('Log API Calls?', 'pmpro-mailchimp'), 'pmpromc_option_logging_enabled', 'pmpromc_options', 'pmpromc_section_general');

	//pmpro-related options
	add_settings_section('pmpromc_section_levels', __('Membership Levels and Audiences', 'pmpro-mailchimp'), 'pmpromc_section_levels', 'pmpromc_options');

	//add options for levels
	pmpromc_getPMProLevels();
	global $pmpromc_levels;

	if (!empty($pmpromc_levels)) {
		foreach ($pmpromc_levels as $level) {
			add_settings_field('pmpromc_option_memberships_lists_' . $level->id, $level->name, 'pmpromc_option_memberships_lists', 'pmpromc_options', 'pmpromc_section_levels', array($level));
		}
	}
}
add_action("admin_init", "pmpromc_admin_init");

// validate our options
function pmpromc_options_validate($input)
{	
	$newinput = array();

	//api key
	$newinput['api_key'] = isset($input['api_key']) ? trim(preg_replace("[^a-zA-Z0-9\-]", "", $input['api_key'])) : null;
	$newinput['double_opt_in'] = isset($input['double_opt_in']) ? intval($input['double_opt_in']) : null;
	$newinput['unsubscribe'] = isset($input['unsubscribe']) ? preg_replace("[^a-zA-Z0-9\-]", "", $input['unsubscribe']) : null;
	$newinput['profile_update'] = isset($input['profile_update']) ? preg_replace("[^a-zA-Z0-9\-]", "", $input['profile_update']) : null;
	$newinput['logging_enabled'] = isset($input['logging_enabled']) ? intval($input['logging_enabled']) : null;

	//user lists
	if (!empty($input['users_lists']) && is_array($input['users_lists'])) {
		$count = count($input['users_lists']);
		for ($i = 0; $i < $count; $i++)
			$newinput['users_lists'][] = trim(preg_replace("[^a-zA-Z0-9\-]", "", $input['users_lists'][$i]));;
	}

	//membership lists
	global $pmpromc_levels;
	if (!empty($pmpromc_levels)) {
		foreach ($pmpromc_levels as $level) {
			if (!empty($input['level_' . $level->id . '_lists']) && is_array($input['level_' . $level->id . '_lists'])) {
				$count = count($input['level_' . $level->id . '_lists']);
				for ($i = 0; $i < $count; $i++)
					$newinput['level_' . $level->id . '_lists'][] = trim(preg_replace("[^a-zA-Z0-9\-]", "", $input['level_' . $level->id . '_lists'][$i]));;
			}
		}
	}

	if (!empty($input['additional_lists']) && is_array($input['additional_lists'])) {
		$count = count($input['additional_lists']);
		for ($i = 0; $i < $count; $i++)
			$newinput['additional_lists'][] = trim(preg_replace("[^a-zA-Z0-9\-]", "", $input['additional_lists'][$i]));
	}

	return $newinput;
}

/**
 * Show any warnings on PMPromc settings page
 */
function pmpromc_section_general() {
	global $pmpromc_levels;
	$options = get_option( 'pmpromc_options' );
	$show_error = false;

	if ( empty( $options['additional_lists'] ) ) {
		return;
	}

	foreach ( $pmpromc_levels as $level ) {
		if ( ! empty( $options[ 'level_' . $level->id . '_lists' ] ) && ! empty( array_intersect( $options['additional_lists'], $options[ 'level_' . $level->id . '_lists' ] ) ) ) {
			$show_error = true;
		}
	}

	if ( ! empty( $options['users_lists'] ) && ! empty( array_intersect( $options['additional_lists'], $options['users_lists'] ) ) ) {
		$show_error = true;
	}

	if ( $show_error ) {
		?>
		<div class="notice notice-error">
			<p><strong><?php esc_html_e( 'Opt-in audiences cannot be set to also be non-member audiences or levels audiences.', 'pmpro-mailchimp' ); ?></strong></p>
		</div>
		<?php
	}
}

/*
	options code
*/
function pmpromc_option_api_key()
{
	$options = get_option('pmpromc_options');
	if (isset($options['api_key']))
		$api_key = $options['api_key'];
	else
		$api_key = "";
	echo "<input id='pmpromc_api_key' name='pmpromc_options[api_key]' size='80' type='text' value='" . esc_attr($api_key) . "' />";
}

function pmpromc_option_users_lists()
{
	global $pmpromc_lists;
	$options = get_option('pmpromc_options');

	if (isset($options['users_lists']) && is_array($options['users_lists']))
		$selected_lists = $options['users_lists'];
	else
		$selected_lists = array();

	if (!empty($pmpromc_lists)) {
		?>
		<div <?php if(count($pmpromc_lists) > 5) { ?>class="pmpromc-checkbox-list-scrollable"<?php } ?>>
		<?php
		foreach ($pmpromc_lists as $list) {
			$checked_modifier = in_array($list->id, $selected_lists) ? ' checked' : '';
			echo( "<input type='checkbox' name='pmpromc_options[users_lists][]' value='" . esc_attr( $list->id ) . "' id='pmpromc_user_lists_" . esc_attr( $list->id ) . "'" . $checked_modifier . ">" );
			echo( "<label for='pmpromc_user_lists_" . esc_attr( $list->id ) .  "' class='pmpromc-checkbox-label'>" . esc_html( $list->name ) .  "</label><br>" );
		}
		echo '</div>';
	} else {
		echo "No audiences found.";
	}
}

/*
	Show a dropdown of additional opt-in lists.
*/
function pmpromc_option_additional_lists()
{
	
	global $pmpromc_lists;

	$options = get_option('pmpromc_options');

	if (isset($options['additional_lists']) && is_array($options['additional_lists']))
		$selected_lists = $options['additional_lists'];
	else
		$selected_lists = array();

	if (!empty($pmpromc_lists)) {
		?>
		<div <?php if(count($pmpromc_lists) > 5) { ?>class="pmpromc-checkbox-list-scrollable"<?php } ?>>
		<?php
		foreach ($pmpromc_lists as $list) {
			$checked_modifier = in_array($list->id, $selected_lists) ? ' checked' : '';
			echo( "<input type='checkbox' name='pmpromc_options[additional_lists][]' value='" . esc_attr( $list->id ) . "' id='pmpromc_additional_lists_" . esc_attr( $list->id ) . "'" . $checked_modifier . ">" );
			echo( "<label for='pmpromc_additional_lists_" . esc_attr( $list->id ) .  "' class='pmpromc-checkbox-label'>" . esc_html( $list->name ) .  "</label><br>" );
		}
		echo '</div>';
	} else {
		echo "No audiences found.";
	}

}

function pmpromc_option_double_opt_in()
{
	$options = get_option('pmpromc_options');
	?>
	<select name="pmpromc_options[double_opt_in]">
		<option value="0" <?php selected($options['double_opt_in'], 0); ?>><?php _e('No', 'pmpro-mailchimp');?></option>
		<option value="1" <?php selected($options['double_opt_in'], 1); ?>><?php _e('Yes (All audiences)', 'pmpro-mailchimp');?></option>
	</select>
	<?php
}

function pmpromc_option_unsubscribe()
{
	$options = get_option('pmpromc_options');
	?>
	<select name="pmpromc_options[unsubscribe]">
		<option value="0" <?php selected($options['unsubscribe'], 0); ?>><?php _e('No', 'pmpro-mailchimp');?></option>
		<option value="1" <?php selected($options['unsubscribe'], 1); ?>><?php _e('Yes (Only old level audiences.)', 'pmpro-mailchimp');?></option>
		<option value="all" <?php selected($options['unsubscribe'], "all"); ?>><?php _e('Yes (Old level and opt-in audiences.)', 'pmpro-mailchimp');?></option>
	</select>
	<p class="description"><?php _e("Recommended: Yes. However, if you manage multiple audiences in Mailchimp and have users subscribe outside of WordPress, you may want to choose No so contacts aren't unsubscribed from other audiences when they register on your site.", 'pmpro-mailchimp');?>
	</p>
	<?php
}

function pmpromc_option_profile_update() {
	$options        = get_option( 'pmpromc_options' );
	$profile_update = 0;
	if ( ! empty( $options['profile_update'] ) ) {
		$profile_update = $options['profile_update'];
	}
	?>
	<select name="pmpromc_options[profile_update]">
		<option value="0" <?php selected( $profile_update, 0 ); ?>><?php esc_html_e( 'No', 'pmpro-mailchimp' ); ?></option>
		<option value="1" <?php selected( $profile_update, 1 ); ?>><?php esc_html_e( 'Yes', 'pmpro-mailchimp' ); ?></option>
	</select>
	<p class="description"><?php esc_html_e( "Choosing 'No' will still update Mailchimp when user's level is changed, email is changed, or chosen opt-in audiences are changed.", 'pmpro-mailchimp' ); ?>
	</p>
	<?php
}

function pmpromc_option_logging_enabled() {
	$options         = get_option( 'pmpromc_options' );
	$logging_enabled = 0;
	if ( ! empty( $options['logging_enabled'] ) ) {
		$logging_enabled = $options['logging_enabled'];
	}
	?>
	<select name="pmpromc_options[logging_enabled]">
		<option value="0" <?php selected( $logging_enabled, 0 ); ?>><?php esc_html_e( 'No', 'pmpro-mailchimp' ); ?></option>
		<option value="1" <?php selected( $logging_enabled, 1 ); ?>><?php esc_html_e( 'Yes', 'pmpro-mailchimp' ); ?></option>
	</select>
	<p class="description"><?php printf( esc_html__( "Debug log can be found at %s", 'pmpro-mailchimp' ), '<code>' . esc_html( pmpromc_get_log_file_path() ) . '</code>' ); ?>
	</p>
	<?php
}

/*
	options sections
*/
function pmpromc_section_levels()
{
	global $wpdb, $pmpromc_levels;

	//do we have PMPro installed?
	if (defined('PMPRO_VERSION')) {
		?>
		<p><?php _e('PMPro is installed.', 'pmpro-mailchimp');?></p>
		<?php
		//do we have levels?
		if (empty($pmpromc_levels)) {
			?>
			<p><?php printf(__("Once you've <a href='%s'>created some levels in Paid Memberships Pro</a>, you will be able to assign Mailchimp audiences to them here.", 'pmpro-mailchimp'), 'admin.php?page=pmpro-membershiplevels');?></p>
			<?php
		} else {
			?>
			<p><?php _e('For each level below, choose the audience(s) that a new user should be subscribed to when they register.', 'pmpro-mailchimp');?></p>
			<?php
		}
	} else {
		//just deactivated or needs to be installed?
		if (file_exists(dirname(__FILE__) . "/../paid-memberships-pro/paid-memberships-pro.php")) {
			//just deactivated
			?>
			<p><?php printf(__('<a href="%s">Activate Paid Memberships Pro</a> to add membership functionality to your site and finer control over your Mailchimp audiences.', 'pmpro-mailchimp'), 'plugins.php?plugin_status=inactive');?></p>
			<?php
		} else {
			//needs to be installed
			?>
			<p><?php printf(__('<a href="%s">Install Paid Memberships Pro</a> to add membership functionality to your site and finer control over your Mailchimp audiences.', 'pmpro-mailchimp'), 'plugin-install.php?tab=search&type=term&s=paid+memberships+pro&plugin-search-input=Search+Plugins');?></p>
			<?php
		}
	}
}

function pmpromc_option_memberships_lists($level)
{
	global $pmpromc_lists;
	$options = get_option('pmpromc_options');

	$level = $level[0];	//WP stores this in the first element of an array

	if (isset($options['level_' . $level->id . '_lists']) && is_array($options['level_' . $level->id . '_lists']))
		$selected_lists = $options['level_' . $level->id . '_lists'];
	else
		$selected_lists = array();

	if (!empty($pmpromc_lists)) {
		?>
		<div <?php if(count($pmpromc_lists) > 5) { ?>class="pmpromc-checkbox-list-scrollable"<?php } ?>>
		<?php
		foreach ($pmpromc_lists as $list) {
			$checked_modifier = in_array($list->id, $selected_lists) ? ' checked' : '';
			echo( "<input type='checkbox' name='pmpromc_options[level_" . $level->id . "_lists][]' value='" . esc_attr( $list->id ) . "' id='pmpromc_level_" . $level->id . "_lists_" . esc_attr( $list->id ) . "'" . $checked_modifier . ">" );
			echo( "<label for='pmpromc_level_" . $level->id . "_lists_" . esc_attr( $list->id ) .  "' class='pmpromc-checkbox-label'>" . esc_html( $list->name ) .  "</label><br>" );
		}
		echo "</div>";
	} else {
		echo "No audiences found.";
	}
}

/*
	If the sync link was clicked, setup the update script and redirect there.
*/
function pmpromc_admin_init_sync()
{
	if (is_admin() && !empty($_REQUEST['page']) && $_REQUEST['page'] == 'pmpromc_options' && !empty($_REQUEST['sync'])) {
		if (!current_user_can('manage_options'))
			wp_die('You do not have sufficient permission to access this page.');
		else {
			if (!function_exists('pmpro_addUpdate'))
				wp_die('Paid Memberships Pro must be active to use this function.');
			else {
				//add the update
				pmpro_addUpdate('pmpromc_sync_merge_fields_ajax');

				//redirect to run the update
				wp_redirect(admin_url('admin.php?page=pmpro-updates'));
				exit;
			}
		}
	}
}

add_action('admin_init', 'pmpromc_admin_init_sync');

/*
	Update script to sync merge fields for existing users/members
*/
function pmpromc_sync_merge_fields_ajax()
{
	//setup vars
	global $wpdb;

	//get API and bail if we can't set it
	$api = pmpromc_getAPI();
	if(empty($api))
		return;

	$last_user_id = get_option('pmpromc_sync_merge_fields_last_user_id', 0);
	$limit = 3;
	$options = get_option("pmpromc_options");
	$all_lists = get_option("pmpromc_all_lists");

	//get next batch of users
	$user_ids = $wpdb->get_col("SELECT DISTINCT(user_id) FROM $wpdb->pmpro_memberships_users WHERE status = 'active' AND user_id > $last_user_id ORDER BY user_id LIMIT $limit");

	//track progress
	$first_load = get_transient('pmpro_updates_first_load');
	if ($first_load) {
		$total_users = $wpdb->get_var("SELECT COUNT(DISTINCT(user_id)) FROM $wpdb->pmpro_memberships_users WHERE user_id > $last_user_id");
		update_option('pmpromc_sync_merge_fields_total', $total_users, 'no');
		$progress = 0;
	} else {
		$total_users = get_option('pmpromc_sync_merge_fields_total', 0);
		$progress = get_option('pmpromc_sync_merge_fields_progress', 0);
	}
	update_option('pmpromc_sync_merge_fields_progress', $progress + count($user_ids), 'no');
	global $pmpro_updates_progress;
	if ($total_users > 0)
		$pmpro_updates_progress = "[" . $progress . "/" . $total_users . "]";
	else
		$pmpro_updates_progress = "";

	if (empty($user_ids)) {
		//we're done
		pmpro_removeUpdate('pmpromc_sync_merge_fields_ajax');
		delete_option('pmpromc_sync_merge_fields_last_user_id');
		delete_option('pmpromc_sync_merge_fields_total');
		delete_option('pmpromc_sync_merge_fields_progress');
	} else {
		//update merge fields for users
		foreach ($user_ids as $user_id) {
			//get user data
			$user = get_userdata($user_id);
			$user->membership_levels = pmpro_getMembershipLevelsForUser($user_id);

			//no level? DB is wrong, skip 'em
			if (empty($user->membership_level))
				continue;

			//check users lists
			if (!empty($options['users_lists'])) {
				foreach ($options['users_lists'] as $users_list) {
					//check if he's on the list already
					$list = $api->get_listinfo_for_member($users_list, $user);

					//subscribe again to update merge fields
					if ( ! empty( $list ) )
						pmpromc_add_audience_member_update( $user, $users_list, $list->status );
				}
			}

			//get lists for this user's membership level
			foreach($user->membership_levels as $user_level) {
				if (!empty($options['level_' . $user_level->id . '_lists']) && !empty($options['api_key'])) {
					foreach ($options['level_' . $user_level->id . '_lists'] as $level_list) {
						//check if he's on the list already
						$list = $api->get_listinfo_for_member($level_list, $user);

						//subscribe again to update merge fields
						if ( ! empty( $list ) )
			  pmpromc_add_audience_member_update( $user, $users_list, $list->status );
					}
				}
			}
		}
		update_option('pmpromc_sync_merge_fields_last_user_id', $user_id, 'no');
	}
}

/*
	Setup CSV export service.
*/
function pmpromv_wp_ajax_pmpro_mailchimp_export_csv()
{
	require_once PMPROMC_DIR . '/includes/export-csv.php';
	exit;
}

add_action('wp_ajax_pmpro_mailchimp_export_csv', 'pmpromv_wp_ajax_pmpro_mailchimp_export_csv');
