<?php
/*
Plugin Name: PMPro MailChimp Integration
Plugin URI: http://www.paidmembershipspro.com/pmpro-mailchimp/
Description: Sync your WordPress users and members with MailChimp lists.
Version: .3.5
Author: Stranger Studios
Author URI: http://www.strangerstudios.com
*/
/*
	Copyright 2011	Stranger Studios	(email : jason@strangerstudios.com)
	GPLv2 Full license details in license.txt
*/

/*
	* MCAPI class
	* options
	- MailChimp API Key
	
	If PMPro is not installed:
	- New users should be subscribed to these lists: [ ]
	- Remove members from list when they unsubscribe/delete their account? [ ]
	
	If PMPro is installed:
	* All new users should be subscribed to these lists:
	* New users with no membership should be subscribed to these lists:
	* New users with membership # should be subscribed to these lists: 
	* (Show each level)		
	
	* Provide export for initial import?
*/

//init
function pmpromc_init()
{
	//include MCAPI Class if we don't have it already
	if(!class_exists("MCAPI"))
	{
		require_once(dirname(__FILE__) . "/includes/MCAPI.class.php");
	}
	
	//get options for below
	$options = get_option("pmpromc_options");
	
	//setup hooks for new users	
	if(!empty($options['users_lists']))
		add_action("user_register", "pmpromc_user_register");
	
	//setup hooks for PMPro levels
	pmpromc_getPMProLevels();
	global $pmpromc_levels;
	if(!empty($pmpromc_levels))
	{		
		add_action("pmpro_after_change_membership_level", "pmpromc_pmpro_after_change_membership_level", 15, 2);
	}
	
	
}
add_action("init", "pmpromc_init", 0);


function pmpromc_add_custom_user_profile_fields( $user ) {
?>
	<h3><?php _e('Additional MailChimp Lists', ''); ?></h3>
	
	<table class="form-table">
		<tr>
			<th>
				<label for="address"><?php _e('Address', 'your_textdomain'); ?>
			</label></th>
			<td>
		<?php
	
	$options = get_option("pmpromc_options");
	$all_lists = get_option("pmpromc_all_lists");
	$additional_lists = $options['additional_lists'];
		
	$api = new MCAPI( $options['api_key'] );
	$lists = $api->lists( array(), 0, 100 );
	$additional_lists_array = array();

	foreach ($lists['data'] as $list)
	{
		if(!empty($additional_lists))
		{
			foreach($additional_lists as $additional_list)
			{
				if($list['id'] == $additional_list)	
				{	
					$additional_lists_array[] = $list;
					break;
				}
			}
		}
	}
		

		global $profileuser;
		$user_additional_lists = get_user_meta($profileuser->ID,'pmpromc_additional_lists',true);
	
				
		if(isset($user_additional_lists))
			$selected_lists = $user_additional_lists;
		else
			$selected_lists = array();
		
		//get_user_meta($user_id2, 'pmpromc_additional_lists',true);
		
		echo "<select multiple='yes' name=\"additional_lists[]\">";
		foreach($additional_lists_array as $list)
		{
			echo "<option value='" . $list['id'] . "' ";
			if(is_array($selected_lists) && in_array($list['id'], $selected_lists))
				echo "selected='selected'";
			echo ">" . $list['name'] . "</option>";
		}
		echo "</select>";

					?>
				
				
				<span class="description"><?php _e('Please enter your address.', 'your_textdomain'); ?></span>
			</td>
		</tr>
	</table>
<?php }

function pmpromc_save_custom_user_profile_fields( $user_id )
{
	$options = get_option("pmpromc_options");
	$all_additional_lists = $options['additional_lists'];

	update_user_meta($user_id, 'pmpromc_additional_lists',$_REQUEST['additional_lists']); 
	$additional_user_lists = get_user_meta($user_id,'pmpromc_additional_lists',true);
	
	//get all pmpro additional lists
	//if they aren't in $additional_user_lists Unsubscribe them from those
	
	$list_user = get_userdata($user_id);
	$api = new MCAPI( $options['api_key']);
	
	if(!empty($all_additional_lists))
	{
		foreach($all_additional_lists as $list)
		{
			//If we find the list in the user selected lists then subscribe them
			if(in_array($list, $additional_user_lists))
			{
				//Subscribe them
				$api->listSubscribe($list, $list_user->user_email, apply_filters("pmpro_mailchimp_listsubscribe_fields", array("FNAME" => $list_user->first_name, "LNAME" => $list_user->last_name), $list_user), "html", $options['double_opt_in']);
			}
		
			//If we do not find them in the user selected lists, then unsubscribe them.
			else
			{
				//Unsubscribe them
				$api->listUnsubscribe($list, $list_user->user_email);
			}
		}
	}
}

add_action( 'show_user_profile', 'pmpromc_add_custom_user_profile_fields', 12 );
add_action( 'edit_user_profile', 'pmpromc_add_custom_user_profile_fields',12 );

add_action( 'personal_options_update',  'pmpromc_save_custom_user_profile_fields' );
add_action( 'edit_user_profile_update', 'pmpromc_save_custom_user_profile_fields' );



//use a different action if we are on the checkout page
function pmpromc_wp()
{
	if(is_admin())
		return;
		
	global $post;
	if(!empty($post->post_content) && strpos($post->post_content, "[pmpro_checkout]") !== false)
	{
		remove_action("pmpro_after_change_membership_level", "pmpromc_pmpro_after_change_membership_level");
		add_action("pmpro_after_checkout", "pmpromc_pmpro_after_checkout", 15);		
	}
}
add_action("wp", "pmpromc_wp", 0);

//for when checking out
function pmpromc_pmpro_after_checkout($user_id)
{
	pmpromc_pmpro_after_change_membership_level(intval($_REQUEST['level']), $user_id);
	subscribe_to_additional_lists($user_id);
}

function subscribe_to_additional_lists($user_id)
{
	$options = get_option("pmpromc_options");
	$additional_lists = $_REQUEST['additional_lists'];
	
	if(!empty($additional_lists))
	{
		update_user_meta($user_id, 'pmpromc_additional_lists', $additional_lists);
		
		$api = new MCAPI( $options['api_key']);
		
		$list_user = get_userdata($user_id);		
		
		foreach($additional_lists as $list)
		{					
			//subscribe them
			$api->listSubscribe($list, $list_user->user_email, apply_filters("pmpro_mailchimp_listsubscribe_fields", array("FNAME" => $list_user->first_name, "LNAME" => $list_user->last_name), $list_user), "html", $options['double_opt_in']);			
		}
	}
}

//subscribe users when they register
function pmpromc_user_register($user_id)
{
	clean_user_cache($user_id);
	
	$options = get_option("pmpromc_options");
	
	//should we add them to any lists?
	if(!empty($options['users_lists']) && !empty($options['api_key']))
	{
		//get user info
		$list_user = get_userdata($user_id);
		
		//subscribe to each list
		$api = new MCAPI( $options['api_key']);
		foreach($options['users_lists'] as $list)
		{					
			//subscribe them
			$api->listSubscribe($list, $list_user->user_email, apply_filters("pmpro_mailchimp_listsubscribe_fields", array("FNAME" => $list_user->first_name, "LNAME" => $list_user->last_name), $list_user), "html", $options['double_opt_in']);
		}
	}
}

//subscribe new members (PMPro) when they register
function pmpromc_pmpro_after_change_membership_level($level_id, $user_id)
{
	clean_user_cache($user_id);
	
	global $pmpromc_levels;
	$options = get_option("pmpromc_options");
	$all_lists = get_option("pmpromc_all_lists");	

	//should we add them to any lists?
	if(!empty($options['level_' . $level_id . '_lists']) && !empty($options['api_key']))
	{

		//get user info
		$list_user = get_userdata($user_id);		
		
		//subscribe to each list
		$api = new MCAPI( $options['api_key']);
		foreach($options['level_' . $level_id . '_lists'] as $list)
		{					
			//echo "<hr />Trying to subscribe to " . $list . "...";
			
			//subscribe them
			$api->listSubscribe($list, $list_user->user_email, apply_filters("pmpro_mailchimp_listsubscribe_fields", array("FNAME" => $list_user->first_name, "LNAME" => $list_user->last_name), $list_user), "html", $options['double_opt_in']);
		}
		
		//unsubscribe them from lists not selected
		if($options['unsubscribe'])
		{

			/*
			 * Which level did they have last? (second to alst entry in pmpro_memberships_users)
			 * If they had a leevl, get the lists for that level
			 * Remove any list that are for their new level
			 * Unsubscribe them from the remaining lists
			 * 
			 * Caution: Check if they are signing up for the first time.
			 */
			
			//Get their prior level
			global $wpdb;
			$second_to_last_entry = $wpdb->get_results("SELECT* FROM $wpdb->pmpro_memberships_users WHERE `user_id` = $user_id ORDER BY `id` DESC LIMIT 1,1");
			
			if($second_to_last_entry)
			{			
				$previous_level = $second_to_last_entry[0]->membership_id;
				$prev_level_lists = $options['level_'.$previous_level.'_lists'];
			
				//get the lists for thier current level.
				$curr_level_lists = $options['level_' . $level_id . '_lists'];
				
				//unique merge with additional lists.

				foreach($prev_level_lists as $list)
				{					
					if(!in_array($list, $curr_level_lists))
					{
						//the list was not found in our current level lists so unsubscribe
						$api->listUnsubscribe($list, $list_user->user_email);		
					}
				}
			}
		}
	}
	
	elseif(!empty($options['api_key']) && count($options) > 3)
	{
		//now they are a normal user should we add them to any lists?
		//Case where PMPro is not installed?
		if(!empty($options['users_lists']) && !empty($options['api_key']))
		{
			//get user info
			$list_user = get_userdata($user_id);
			
			//subscribe to each list
			$api = new MCAPI( $options['api_key']);
			foreach($options['users_lists'] as $list)
			{					
				//subscribe them
				$api->listSubscribe($list, $list_user->user_email, apply_filters("pmpro_mailchimp_listsubscribe_fields", array("FNAME" => $list_user->first_name, "LNAME" => $list_user->last_name), $list_user), "html", $options['double_opt_in']);
			}
			
			//unsubscribe from any list not assigned to users
			if($options['unsubscribe'])
			{
				foreach($all_lists as $list)
				{
					$additional_lists = $options['additional_lists'];
					if(!in_array($list['id'], $additional_lists))
					{
						if(!in_array($list['id'], $options['users_lists']))
							$api->listUnsubscribe($list['id'], $list_user->user_email);
					}
				
				}
			}
		}
		else
		{
			//some memberships are on lists. assuming the admin intends this level to be unsubscribed from everything
			if($options['unsubscribe'])
			{
				if(is_array($all_lists))
				{
					//get user info
					$list_user = get_userdata($user_id);
					
					//unsubscribe to each list
					$api = new MCAPI( $options['api_key']);

					foreach($all_lists as $list)
					{
						$api->listUnsubscribe($list['id'], $list_user->user_email);

					}
				}
			}
		}
	}	
}

//change email in MailChimp if a user's email is changed in WordPress
function pmpromc_profile_update($user_id, $old_user_data)
{
	$new_user_data = get_userdata($user_id);
	if($new_user_data->user_email != $old_user_data->user_email)
	{			
		//get all lists
		$options = get_option("pmpromc_options");
		$api = new MCAPI( $options['api_key'] );
		$lists = $api->lists( array(), 0, 100 );
			
		if(!empty($lists['data']))
		{
			foreach($lists['data'] as $list)
			{
				//check for member
				$member = $api->listMemberInfo($list['id'], array($old_user_data->user_email));
								
				//update member's email
				if(!empty($member['success']))
				{
					$api->listUpdateMember($list['id'], $old_user_data->user_email, array("email" => $new_user_data->user_email));					
				}
			}
		}
	}
}
add_action("profile_update", "pmpromc_profile_update", 10, 2);

//admin init. registers settings
function pmpromc_admin_init()
{
	//setup settings
	register_setting('pmpromc_options', 'pmpromc_options', 'pmpromc_options_validate');	
	add_settings_section('pmpromc_section_general', 'General Settings', 'pmpromc_section_general', 'pmpromc_options');	
	add_settings_field('pmpromc_option_api_key', 'MailChimp API Key', 'pmpromc_option_api_key', 'pmpromc_options', 'pmpromc_section_general');		
	add_settings_field('pmpromc_option_users_lists', 'All Users List', 'pmpromc_option_users_lists', 'pmpromc_options', 'pmpromc_section_general');	
	add_settings_field('pmpromc_option_double_opt_in', 'Require Double Opt-in?', 'pmpromc_option_double_opt_in', 'pmpromc_options', 'pmpromc_section_general');	
	add_settings_field('pmpromc_option_unsubscribe', 'Unsubscribe on Level Change?', 'pmpromc_option_unsubscribe', 'pmpromc_options', 'pmpromc_section_general');	
	
	//pmpro-related options	
	add_settings_section('pmpromc_section_levels', 'Membership Levels and Lists', 'pmpromc_section_levels', 'pmpromc_options');		
	
	//add options for levels
	pmpromc_getPMProLevels();
	global $pmpromc_levels;
	
	if(!empty($pmpromc_levels))
	{						
		foreach($pmpromc_levels as $level)
		{
			add_settings_field('pmpromc_option_memberships_lists_' . $level->id, $level->name, 'pmpromc_option_memberships_lists', 'pmpromc_options', 'pmpromc_section_levels', array($level));
		}
	
		add_settings_field('pmpromc_option_additional_lists', 'Additional Lists', 'pmpromc_option_additional_lists', 'pmpromc_options', 'pmpromc_section_levels');
		
	}
	
	
}
add_action("admin_init", "pmpromc_admin_init");

function pmpromc_option_additional_lists(){

	global $pmpromc_lists;
	
	$options = get_option('pmpromc_options');
		
	if(isset($options['additional_lists']) && is_array($options['additional_lists']))
		$selected_lists = $options['additional_lists'];
	else
		$selected_lists = array();

	if(!empty($pmpromc_lists))
	{
		echo "<select multiple='yes' name=\"pmpromc_options[additional_lists][]\">";
		foreach($pmpromc_lists as $list)
		{
			echo "<option value='" . $list['id'] . "' ";
			if(in_array($list['id'], $selected_lists))
				echo "selected='selected'";
			echo ">" . $list['name'] . "</option>";
		}
		echo "</select>";
	}
	else
	{
		echo "No lists found.";
	}

}

//Dispaly additional list fields on checkout
function pmpromc_additional_lists_on_checkout()
{
	$options = get_option("pmpromc_options");
	$additional_lists = $options['additional_lists'];
		
	$api = new MCAPI( $options['api_key'] );
	$lists = $api->lists( array(), 0, 100 );
	
	$additional_lists_array = array();
	foreach ($lists['data'] as $list)
	{
		if(!empty($additional_lists))
		{
			foreach($additional_lists as $additional_list)
			{
				if($list['id'] == $additional_list)	
				{	
					$additional_lists_array[] = $list;
					break;
				}
			}
		}
	}
	
	foreach($additional_lists_array as $key=> $additional_list)
	{?>
		<input type="checkbox" name="additional_lists[]" value="<?php echo $additional_list['id'];?>" /><?php echo $additional_list['name'];?><br />
	<?php
	}
}
add_action('pmpro_checkout_after_password', 'pmpromc_additional_lists_on_checkout');

//set the pmpromc_levels array if PMPro is installed
function pmpromc_getPMProLevels()
{	
	global $pmpromc_levels, $wpdb;	
	if(!empty($wpdb->pmpro_membership_levels))
		$pmpromc_levels = $wpdb->get_results("SELECT * FROM $wpdb->pmpro_membership_levels ORDER BY id");			
	else
		$pmpromc_levels = false;
}

//options sections
function pmpromc_section_general()
{	
?>
<p></p>
<?php
}

//options sections
function pmpromc_section_levels()
{	
	global $wpdb, $pmpromc_levels;
	
	//do we have PMPro installed?
	if(class_exists("MemberOrder"))
	{
	?>
		<p>PMPro is installed.</p>
	<?php
		//do we have levels?
		if(empty($pmpromc_levels))
		{
		?>
		<p>Once you've <a href="admin.php?page=pmpro-membershiplevels">created some levels in Paid Memberships Pro</a>, you will be able to assign MailChimp lists to them here.</p>
		<?php
		}
		else
		{
		?>
		<p>For each level below, choose the lists which should be subscribed to when a new user registers.</p>
		<p>You can also specify Additional Lists available at checkout that a member can subscribe to.</p>
		<?php
		}
	}
	else
	{
		//just deactivated or needs to be installed?
		if(file_exists(dirname(__FILE__) . "/../paid-memberships-pro/paid-memberships-pro.php"))
		{
			//just deactivated
			?>
			<p><a href="plugins.php?plugin_status=inactive">Activate Paid Memberships Pro</a> to add membership functionality to your site and finer control over your MailChimp lists.</p>
			<?php
		}
		else
		{
			//needs to be installed
			?>
			<p><a href="plugin-install.php?tab=search&type=term&s=paid+memberships+pro&plugin-search-input=Search+Plugins">Install Paid Memberships Pro</a> to add membership functionality to your site and finer control over your MailChimp lists.</p>
			<?php
		}
	}
}


//options code
function pmpromc_option_api_key()
{
	$options = get_option('pmpromc_options');		
	if(isset($options['api_key']))
		$api_key = $options['api_key'];
	else
		$api_key = "";
	echo "<input id='pmpromc_api_key' name='pmpromc_options[api_key]' size='80' type='text' value='" . esc_attr($api_key) . "' />";
}

function pmpromc_option_users_lists()
{	
	global $pmpromc_lists;
	$options = get_option('pmpromc_options');
		
	if(isset($options['users_lists']) && is_array($options['users_lists']))
		$selected_lists = $options['users_lists'];
	else
		$selected_lists = array();
	
	if(!empty($pmpromc_lists))
	{
		echo "<select multiple='yes' name=\"pmpromc_options[users_lists][]\">";
		foreach($pmpromc_lists as $list)
		{
			echo "<option value='" . $list['id'] . "' ";
			if(in_array($list['id'], $selected_lists))
				echo "selected='selected'";
			echo ">" . $list['name'] . "</option>";
		}
		echo "</select>";
	}
	else
	{
		echo "No lists found.";
	}	
}

function pmpromc_option_double_opt_in()
{
	$options = get_option('pmpromc_options');	
	?>
	<select name="pmpromc_options[double_opt_in]">
		<option value="0" <?php selected($options['double_opt_in'], 0);?>>No</option>
		<option value="1" <?php selected($options['double_opt_in'], 1);?>>Yes</option>		
	</select>
	<?php
}

function pmpromc_option_unsubscribe()
{
	$options = get_option('pmpromc_options');	
	?>
	<select name="pmpromc_options[unsubscribe]">
		<option value="0" <?php selected($options['unsubscribe'], 0);?>>No</option>
		<option value="1" <?php selected($options['unsubscribe'], 1);?>>Yes</option>		
	</select>
	<small>Recommended: Yes. However, if you manage multiple lists in MailChimp and have users subscribe outside of WordPress, you may want to choose No so contacts aren't unsubscribed from other lists when they register on your site.</small>
	<?php
}


function pmpromc_option_memberships_lists($level)
{	
	global $pmpromc_lists;
	$options = get_option('pmpromc_options');
	
	$level = $level[0];	//WP stores this in the first element of an array
		
	if(isset($options['level_' . $level->id . '_lists']) && is_array($options['level_' . $level->id . '_lists']))
		$selected_lists = $options['level_' . $level->id . '_lists'];
	else
		$selected_lists = array();
	
	if(!empty($pmpromc_lists))
	{
		echo "<select multiple='yes' name=\"pmpromc_options[level_" . $level->id . "_lists][]\">";
		foreach($pmpromc_lists as $list)
		{
			echo "<option value='" . $list['id'] . "' ";
			if(in_array($list['id'], $selected_lists))
				echo "selected='selected'";
			echo ">" . $list['name'] . "</option>";
		}
		echo "</select>";
	}
	else
	{
		echo "No lists found.";
	}	
}

// validate our options
function pmpromc_options_validate($input) 
{					
	//api key
	$newinput['api_key'] = trim(preg_replace("[^a-zA-Z0-9\-]", "", $input['api_key']));		
	$newinput['double_opt_in'] = intval($input['double_opt_in']);
	$newinput['unsubscribe'] = intval($input['unsubscribe']);
	
	//user lists
	if(!empty($input['users_lists']) && is_array($input['users_lists']))
	{
		$count = count($input['users_lists']);
		for($i = 0; $i < $count; $i++)
			$newinput['users_lists'][] = trim(preg_replace("[^a-zA-Z0-9\-]", "", $input['users_lists'][$i]));	;
	}
	
	//membership lists
	global $pmpromc_levels;		
	if(!empty($pmpromc_levels))
	{
		foreach($pmpromc_levels as $level)
		{
			if(!empty($input['level_' . $level->id . '_lists']) && is_array($input['level_' . $level->id . '_lists']))
			{
				$count = count($input['level_' . $level->id . '_lists']);
				for($i = 0; $i < $count; $i++)
					$newinput['level_' . $level->id . '_lists'][] = trim(preg_replace("[^a-zA-Z0-9\-]", "", $input['level_' . $level->id . '_lists'][$i]));	;
			}
		}
	}
	
	if(!empty($input['additional_lists']) && is_array($input['additional_lists']))
	{
		$count = count($input['additional_lists']);
		for($i = 0; $i < $count; $i++)
			$newinput['additional_lists'][] = trim(preg_replace("[^a-zA-Z0-9\-]", "", $input['additional_lists'][$i]));	
	}
	
	return $newinput;
}		

// add the admin options page	
function pmpromc_admin_add_page() 
{
	add_options_page('PMPro MailChimp Options', 'PMPro MailChimp', 'manage_options', 'pmpromc_options', 'pmpromc_options_page');
}
add_action('admin_menu', 'pmpromc_admin_add_page');

//html for options page
function pmpromc_options_page()
{
	global $pmpromc_lists;
	
	//get options
	$options = get_option("pmpromc_options");
		
	//defaults
	if(empty($options))
	{
		$options = array("unsubscribe"=>1);
		update_option("pmpromc_options", $options);
	}
	elseif(!isset($options['unsubscribe']))
	{
		$options['unsubscribe'] = 1;
		update_option("pmpromc_options", $options);
	}	
	
	//check for a valid API key and get lists
	$api_key = $options['api_key'];
	if(!empty($api_key))
	{
		/** Ping the MailChimp API to make sure this API Key is valid */
		$api = new MCAPI( $api_key );
		$api->ping();		
		
		/** Get necessary data and store it into our options field */
		if ( ! empty( $api->errorCode ) ) {
			/** Looks like there was an error */
			$msg = sprintf( __( 'Sorry, but MailChimp was unable to verify your API key. MailChimp gave this response: <p><em>%s</em></p> Please try entering your API key again.', 'pmpro-mailchimp' ), $api->errorMessage );
			$msgt = "error";
			add_settings_error( 'pmpro-mailchimp', 'apikey-fail', $message, 'error' );
		}
		else {						
			/** Support up to 100 lists (but most users won't have nearly that many */
			$lists = $api->lists( array(), 0, 100 );
			$pmpromc_lists = $lists['data'];								
			$all_lists = array();
			
			//save all lists in an option
			$i = 0;			
			foreach ( $pmpromc_lists as $list ) {
				$all_lists[$i]['id'] = $list['id'];
				$all_lists[$i]['web_id'] = $list['web_id'];
				$all_lists[$i]['name'] = $list['name'];
				$i++;
			}
			
			/** Save all of our new data */
			update_option( "pmpromc_all_lists", $all_lists);		
		}
	}
?>
<div class="wrap">
	<div id="icon-options-general" class="icon32"><br></div>
	<h2>PMPro MailChimp Integration Options</h2>		
	
	<?php if(!empty($msg)) { ?>
		<div class="message <?php echo $msgt; ?>"><p><?php echo $msg; ?></p></div>
	<?php } ?>
	
	<form action="options.php" method="post">
		
		<p>This plugin will integrate your site with MailChimp. You can choose one or more MailChimp lists to have users subscribed to when they signup for your site.</p>
		<p>If you have <a href="http://www.paidmembershipspro.com">Paid Memberships Pro</a> installed, you can also choose one or more MailChimp lists to have members subscribed to for each membership level.</p>
		<p>Don't have a MailChimp account? <a href="http://eepurl.com/k4aAH" target="_blank">Get one here</a>. It's free.</p>
		
		<?php settings_fields('pmpromc_options'); ?>
		<?php do_settings_sections('pmpromc_options'); ?>

		<p><br /></p>
						
		<div class="bottom-buttons">
			<input type="hidden" name="pmprot_options[set]" value="1" />
			<input type="submit" name="submit" class="button-primary" value="<?php esc_attr_e('Save Settings'); ?>">				
		</div>
		
	</form>
</div>
<?php
}

/*
	Defaults on Activation
*/
function pmpromc_activation()
{
	//get options
	$options = get_option("pmpromc_options");	
	
	//defaults
	if(empty($options))
	{
		$options = array("unsubscribe"=>1);
		update_option("pmpromc_options", $options);
	}
	elseif(!isset($options['unsubscribe']))
	{
		$options['unsubscribe'] = 1;
		update_option("pmpromc_options", $options);
	}
}
register_activation_hook(__FILE__, "pmpromc_activation");
