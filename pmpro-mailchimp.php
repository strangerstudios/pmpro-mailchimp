<?php
/*
Plugin Name: Paid Memberships Pro - Mailchimp Add On
Plugin URI: http://www.paidmembershipspro.com/pmpro-mailchimp/
Description: Sync your WordPress users and members with Mailchimp audiences.
Version: 2.1.2
Author: Stranger Studios
Author URI: http://www.strangerstudios.com
Text Domain: pmpro-mailchimp
*/
/*
	Copyright 2011-2019	Stranger Studios	(email : jason@strangerstudios.com)
	GPLv2 Full license details in license.txt
*/

//init
function pmpromc_init()
{
    //get options for below
    $options = get_option("pmpromc_options");

    //include MCAPI Class if we don't have it already
    if (!class_exists('PMProMailChimp')) {
        require_once(dirname(__FILE__) . '/includes/class.mailchimp.api.php');
    }

	$GLOBALS['pmpromc_api'] = apply_filters('get_mailchimpapi_class_instance', null);
    if (is_null($GLOBALS['pmpromc_api'])) {
        $GLOBALS['pmpromc_api'] = new PMProMailChimp();
    }
    $GLOBALS['pmpromc_api']->set_key();

    //are we on the checkout page?
    $is_checkout_page = (isset($_REQUEST['submit-checkout']) || (isset($_REQUEST['confirm']) && isset($_REQUEST['gateway'])));

    //setup hooks for user_register
    if (!empty($options['users_lists']) && !$is_checkout_page)
        add_action("user_register", "pmpromc_user_register");

    //setup hooks for PMPro levels
    pmpromc_getPMProLevels();
    global $pmpromc_levels;

    if (!empty($pmpromc_levels) && !$is_checkout_page) {
        add_action("pmpro_after_change_membership_level", "pmpromc_pmpro_after_change_membership_level", 15, 2);
    } elseif (!empty($pmpromc_levels)) {
        add_action("pmpro_after_checkout", "pmpromc_pmpro_after_checkout", 15);
    }
}
add_action("init", "pmpromc_init", 0);

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
                    if (!empty($list) && $list->status == 'subscribed')
                        pmpromc_queue_subscription( $user, $users_list );
                }
            }

            //get lists for this user's membership level
            foreach($user->membership_levels as $user_level) {
				if (!empty($options['level_' . $user_level->id . '_lists']) && !empty($options['api_key'])) {
					foreach ($options['level_' . $user_level->id . '_lists'] as $level_list) {
						//check if he's on the list already
						$list = $api->get_listinfo_for_member($level_list, $user);

						//subscribe again to update merge fields
						if (!empty($list) && $list->status == 'subscribed')
              pmpromc_queue_subscription( $user, $level_list );
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
    require_once(dirname(__FILE__) . "/includes/export-csv.php");
    exit;
}

add_action('wp_ajax_pmpro_mailchimp_export_csv', 'pmpromv_wp_ajax_pmpro_mailchimp_export_csv');

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
    <h3><?php _e('Opt-in Mailchimp Audiences', ''); ?></h3>

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
add_action('show_user_profile', 'pmpromc_add_custom_user_profile_fields', 12);
add_action('edit_user_profile', 'pmpromc_add_custom_user_profile_fields', 12);

add_action('personal_options_update', 'pmpromc_save_custom_user_profile_fields');
add_action('edit_user_profile_update', 'pmpromc_save_custom_user_profile_fields');

/*
	Update Mailchimp lists when users checkout
*/
function pmpromc_pmpro_after_checkout($user_id)
{
	global $pmpro_checkout_levels;

	if(!empty($pmpro_checkout_levels) && is_array($pmpro_checkout_levels)) {

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

/*
	Subscribe users to lists when they register.
*/
function pmpromc_user_register($user_id)
{
    clean_user_cache($user_id);

    $options = get_option("pmpromc_options", array());

    //should we add them to any lists?
    if (!empty($options['users_lists']) && !empty($options['api_key'])) {

        //subscribe to each list
        foreach ($options['users_lists'] as $list) {
            //subscribe them
           pmpromc_queue_subscription($user_id, $list);
        }
    }
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
    add_settings_field('pmpromc_option_users_lists', __('Non-member Users', 'pmpro-mailchimp'), 'pmpromc_option_users_lists', 'pmpromc_options', 'pmpromc_section_general');

    //only if PMPro is installed
    if (function_exists("pmpro_hasMembershipLevel"))
        add_settings_field('pmpromc_option_additional_lists', __('Opt-in Audiences', 'pmpro-mailchimp'), 'pmpromc_option_additional_lists', 'pmpromc_options', 'pmpromc_section_general');

    add_settings_field('pmpromc_option_double_opt_in', __('Require Double Opt-in?', 'pmpro-mailchimp'), 'pmpromc_option_double_opt_in', 'pmpromc_options', 'pmpromc_section_general');
    add_settings_field('pmpromc_option_unsubscribe', __('Unsubscribe on Level Change?', 'pmpro-mailchimp'), 'pmpromc_option_unsubscribe', 'pmpromc_options', 'pmpromc_section_general');

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
        echo "<select multiple='yes' name=\"pmpromc_options[additional_lists][]\">";
        foreach ($pmpromc_lists as $list) {
            echo "<option value='" . $list->id . "' ";
            if (in_array($list->id, $selected_lists))
                echo "selected='selected'";
            echo ">" . $list->name . "</option>";
        }
        echo "</select>";
    } else {
        echo "No audiences found.";
    }

}

/*
	Dispaly additional opt-in list fields on checkout
*/
function pmpromc_additional_lists_on_checkout()
{
    global $pmpro_review;

    $options = get_option("pmpromc_options");

    //get API and bail if we can't set it
    $api = pmpromc_getAPI();
	if(empty($api))
		return;

    //are there additional lists?
    if (!empty($options['additional_lists']))
        $additional_lists = $options['additional_lists'];
    else
        return;

    //okay get through API
    $lists = $api->get_all_lists();

    //no lists?
    if (empty($lists))
        return;

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

    //no lists? do nothing
    if (empty($additional_lists_array))
        return;

    ?>
    <table id="pmpro_mailing_lists" class="pmpro_checkout top1em" width="100%" cellpadding="0" cellspacing="0"
           border="0" <?php if (!empty($pmpro_review)) { ?>style="display: none;"<?php } ?>>
        <thead>
        <tr>
            <th>
                <?php
                if (count($additional_lists_array) > 1)
                    _e('Join one or more of our mailing lists.', 'pmpro');
                else
                    _e('Join our mailing list.', 'pmpro');
                ?>
            </th>
        </tr>
        </thead>
        <tbody>
        <tr class="odd">
            <td>
                <?php
                global $current_user;
                if (isset($_REQUEST['additional_lists']))
                    $additional_lists_selected = $_REQUEST['additional_lists'];
                elseif (isset($_SESSION['additional_lists']))
                    $additional_lists_selected = $_SESSION['additional_lists'];
                elseif (!empty($current_user->ID))
                    $additional_lists_selected = get_user_meta($current_user->ID, "pmpromc_additional_lists", true);
                else
                    $additional_lists_selected = array();

                $count = 0;
                foreach ($additional_lists_array as $key => $additional_list) {
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
add_action('pmpro_checkout_after_tos_fields', 'pmpromc_additional_lists_on_checkout');

/*
	Set the pmpromc_levels array if PMPro is installed
*/
function pmpromc_getPMProLevels()
{
    global $pmpromc_levels, $wpdb;
    if (!empty($wpdb->pmpro_membership_levels))
        $pmpromc_levels = $wpdb->get_results("SELECT * FROM $wpdb->pmpro_membership_levels ORDER BY id");
    else
        $pmpromc_levels = false;
}

/*
	options sections
*/
function pmpromc_section_general()
{
    ?>
    <p></p>
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
        echo "<select multiple='yes' name=\"pmpromc_options[users_lists][]\">";
        foreach ($pmpromc_lists as $list) {
            echo "<option value='" . $list->id . "' ";
            if (in_array($list->id, $selected_lists))
                echo "selected='selected'";
            echo ">" . $list->name . "</option>";
        }
        echo "</select>";
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
        <option value="1" <?php selected($options['double_opt_in'], 1); ?>><?php _e('Yes (Only old level audiences.)', 'pmpro-mailchimp');?></option>
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
        <option value="all" <?php selected($options['unsubscribe'], "all"); ?>><?php _e('Yes (All other audiences.)', 'pmpro-mailchimp');?></option>
    </select>
    <small><?php _e("Recommended: Yes. However, if you manage multiple audiences in Mailchimp and have users subscribe outside of WordPress, you may want to choose No so contacts aren't unsubscribed from other audiences when they register on your site.", 'pmpro-mailchimp');?>
    </small>
    <?php
}

function pmpromc_option_level_field()
{
    $options = get_option('pmpromc_options');
    if (isset($options['level_field']))
        $level_field = $options['level_field'];
    else
        $level_field = "";

    ?>
    <input id='pmpromc_level_field' name='pmpromc_options[level_field]' size='20' type='text'
           value='<?php echo esc_attr($level_field); ?>'/>
    <small><?php _e('To segment your audience subscribers by membership level, create a custom field in Mailchimp and enter the merge tag here.', 'pmpro-mailchimp');?></small>
    <?php
}

function pmpromc_option_memberships_lists($level)
{
    global $pmpromc_lists;
    $options = get_option('pmpromc_options');

    $level = $level[0];    //WP stores this in the first element of an array

    if (isset($options['level_' . $level->id . '_lists']) && is_array($options['level_' . $level->id . '_lists']))
        $selected_lists = $options['level_' . $level->id . '_lists'];
    else
        $selected_lists = array();

    if (!empty($pmpromc_lists)) {
        echo "<select multiple='yes' name=\"pmpromc_options[level_" . $level->id . "_lists][]\">";
        foreach ($pmpromc_lists as $list) {
            echo "<option value='" . $list->id . "' ";
            if (in_array($list->id, $selected_lists))
                echo "selected='selected'";
            echo ">" . $list->name . "</option>";
        }
        echo "</select>";
    } else {
        echo "No audiences found.";
    }
}

// validate our options
function pmpromc_options_validate($input)
{
    $newinput = array();

    //api key
    $newinput['api_key'] = isset($input['api_key']) ? trim(preg_replace("[^a-zA-Z0-9\-]", "", $input['api_key'])) : null;
    $newinput['double_opt_in'] = isset($input['double_opt_in']) ? intval($input['double_opt_in']) : null;
    $newinput['unsubscribe'] = isset($input['unsubscribe']) ? preg_replace("[^a-zA-Z0-9\-]", "", $input['unsubscribe']) : null;
    $newinput['level_field'] = isset($input['level_field']) ? preg_replace("[^a-zA-Z0-9\-]", "", $input['level_field']) : null;

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
            <h3><?php _e('Subscribe users to one or more Mailchimp audiences when they sign up for your site.', 'pmpro-mailchimp');?></h3>
            <p><?php printf(__('If you have <a href="%s" target="_blank">Paid Memberships Pro</a> installed, you can subscribe members to one or more Mailchimp audiences based on their membership level or specify "Opt-in Audiences" that members can select at membership checkout. <a href="%s" target="_blank">Get a Free Mailchimp account</a>.', 'pmpro-mailchimp'), 'https://www.paidmembershipspro.com', 'http://eepurl.com/k4aAH');?></p>
            <p><?php _e( 'TIP: To deselect audiences use CTRL+Click(PC) or CMD+Click(Mac).', 'pmpro-mailchimp' );?></p>
            <?php if (function_exists('pmpro_getAllLevels')) { ?>
                <hr/>
                <h3><?php _e("Synchronize a Member's Level Name and ID", 'pmpro-mailchimp');?></h3>
                <p><?php _e("Since v2.0, this plugin creates and synchronizes the <code>PMPLEVEL</code> and <code>PMPLEVELID</code> merge field in Mailchimp. <strong>This will only affect new or updated members.</strong> You must import this data into Mailchimp for existing members.", 'pmpro-mailchimp');?> <a href="http://www.paidmembershipspro.com/import-level-name-id-existing-members-using-new-merge-fields-pmpro-mailchimp-v2-0/" target="_blank"><?php _e('Read the documentation on importing existing members into Mailchimp', 'pmpro-mailchimp');?></a>.</p>
                <p><a class="button" href="javascript:jQuery('#pmpromc_export_instructions').show();"><?php _e('Click here to export your members list for a Mailchimp Import', 'pmpro-mailchimp');?></a></p>
                <hr/>

                <div id="pmpromc_export_instructions" class="postbox" style="display: none;">
                    <div class="inside">
                        <h3><?php _e('Export a CSV for your Mailchimp Import', 'pmpro-mailchimp');?></h3>
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

/**
 * Set Default options when activating plugin
 */
function pmpromc_activation()
{
    //get options
    $options = get_option("pmpromc_options", array());

    //defaults
    if (empty($options)) {

        $options = array(
            "api_key" => "",
            "double_opt_in" => 0,
            "unsubscribe" => 1,
            "users_lists" => array(),
            "additional_lists" => array(),
            "level_field" => "",
        );
        update_option("pmpromc_options", $options);

    } elseif (!isset($options['unsubscribe'])) {

        $options['unsubscribe'] = 1;
        update_option("pmpromc_options", $options);
    }
}
register_activation_hook(__FILE__, "pmpromc_activation");

/**
 * Preserve info when going off-site for payment w/offsite payment gateway (PayPal Express).
 * Sets Session variables.
 *
 */
function pmpromc_pmpro_paypalexpress_session_vars()
{
    if (isset($_REQUEST['additional_lists']))
        $_SESSION['additional_lists'] = $_REQUEST['additional_lists'];
}

add_action("pmpro_paypalexpress_session_vars", "pmpromc_pmpro_paypalexpress_session_vars");



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
  if ( ! in_array( $status, [ 'subscribed', 'unsubscribed', 'pending' ] ) ) {
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
    }
    
    // Build user profile if not yet built with status
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
      $pmpromc_audience_member_updates[ $audience ] = [];
    }
    $pmpromc_audience_member_updates[ $audience ][$user->ID] = $user_data;
  }
}

/**
 * Execute the updates queued by pmpromc_add_audience_member_update()
 */
function pmpromc_process_audience_member_updates_queue() {
  global $pmpromc_audience_member_updates;
  
  // Return if nothing in queue
  if ( empty( $pmpromc_audience_member_updates ) ) {
    return;
  }

  // Init API
  $api = pmpromc_getAPI();
  if ( empty( $api ) ) {
    return;
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
  unset( $pmpromc_audience_member_updates);
}
add_action('template_redirect', 'pmpromc_process_audience_member_updates_queue', 2);
//add_filter('wp_redirect', 'pmpromc_process_audience_member_updates_queue', 100); // For some reason, this causes errors on activation
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
 * Subscribe new members (PMPro) when their membership level changes
 *
 * @param $level_id (int) -- ID of pmpro membership level
 * @param $user_id (int) -- ID for user
 *
 */
function pmpromc_pmpro_after_change_membership_level($level_id, $user_id)
{
    clean_user_cache($user_id);

    // Remove? Not being used...
    global $pmpromc_levels;

    $options = get_option("pmpromc_options");

    // Remove? Not being used...
    $all_lists = get_option("pmpromc_all_lists");

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
 * Change email in Mailchimp if a user's email is changed in WordPress
 *
 * @param $user_id (int) -- ID of user
 * @param $old_user_data -- WP_User object
 */
function pmpromc_profile_update($user_id, $old_user_data)
{
    $new_user_data = get_userdata($user_id);

    //by default only update users if their email has changed
    $update_user = ($new_user_data->user_email != $old_user_data->user_email);

    /**
     * Filter in case they want to update the user on all updates
	 *
	 * @param bool $update_user true or false if user should be updated at Mailchimp
	 * @param int $user_id ID of user in question
	 * @param object $old_user_data old data from before this profile update
	 *
	 * @since 2.0.3
     */
    $update_user = apply_filters('pmpromc_profile_update', $update_user, $user_id, $old_user_data);

    if ($update_user) {
		//get API and bail if we can't set it
		$api = pmpromc_getAPI();
		if(empty($api))
			return;

        //get all lists
        $lists = $api->get_all_lists();

        if ( ! empty($lists)) {

            foreach ($lists as $list) {
              error_log( print_r( $old_user_data, true ) );
                //check for member
                $member = $api->get_listinfo_for_member($list->id, $old_user_data);
                //update member's email and other values (only if user is already subscribed - not pending!)
                if ('subscribed' === $member->status) {
                    // TODO: Could do this all at once if we allow passing array of lists
                    pmpromc_queue_user_update( $old_user_data, $new_user_data, $list->id );
                }
            }
        }
    }
}
add_action("profile_update", "pmpromc_profile_update", 20, 2);

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
 * Load the languages folder for translations.
 */
function pmpromc_load_textdomain(){
	load_plugin_textdomain( 'pmpro-mailchimp', false, basename( dirname( __FILE__ ) ) . '/languages' );
}
add_action( 'plugins_loaded', 'pmpromc_load_textdomain' );

/**
 * Add links to the plugin action links
 *
 * @param $links (array) - The existing link array
 * @return array -- Array of links to use
 *
 */
function pmpromc_add_action_links($links)
{

    $new_links = array(
        '<a href="' . get_admin_url(NULL, 'options-general.php?page=pmpromc_options') . '">' . __('Settings', 'pmpro-mailchimp') . '</a>',
    );
    return array_merge($new_links, $links);
}
add_filter('plugin_action_links_' . plugin_basename(__FILE__), 'pmpromc_add_action_links');

/**
 * Add links to the plugin row meta
 *
 * @param $links - Links for plugin
 * @param $file - main plugin filename
 * @return array - Array of links
 */
function pmpromc_plugin_row_meta($links, $file)
{
    if (strpos($file, 'pmpro-mailchimp.php') !== false) {
        $new_links = array(
            '<a href="' . esc_url('https://www.paidmembershipspro.com/add-ons/pmpro-mailchimp-integration/') . '" title="' . esc_attr(__('View Documentation', 'pmpro-mailchimp')) . '">' . __('Docs', 'pmpro-mailchimp') . '</a>',
            '<a href="' . esc_url('https://wwww.paidmembershipspro.com/support/') . '" title="' . esc_attr(__('Visit Customer Support Forum', 'pmpro-mailchimp')) . '">' . __('Support', 'pmpro-mailchimp') . '</a>',
        );
        $links = array_merge($links, $new_links);
    }
    return $links;
}
add_filter('plugin_row_meta', 'pmpromc_plugin_row_meta', 10, 2);

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
