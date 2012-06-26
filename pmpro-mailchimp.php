<?php
/*
Plugin Name: PMPro MailChimp Integration
Plugin URI: http://www.paidmembershipspro.com/pmpro-mailchimp/
Description: Sync your WordPress users and members with MailChimp lists.
Version: .2.1
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
		add_action("pmpro_after_change_membership_level", "pmpromc_pmpro_after_change_membership_level", 10, 2);
	}
}
add_action("init", "pmpromc_init");

//subscribe users when they register
function pmpromc_user_register($user_id)
{
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
			$api->listSubscribe($list, $list_user->user_email, array("FNAME" => $list_user->first_name, "LNAME" => $list_user->last_name));
		}
	}
}

//subscribe new members (PMPro) when they register
function pmpromc_pmpro_after_change_membership_level($level_id, $user_id)
{
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
			$api->listSubscribe($list, $list_user->user_email, array("FNAME" => $list_user->first_name, "LNAME" => $list_user->last_name));
		}
		
		//unsubscribe them from lists not selected
		foreach($all_lists as $list)
		{
			if(!in_array($list['id'], $options['level_' . $level_id . '_lists']))
				$api->listUnsubscribe($list['id'], $list_user->user_email);
		}
	}
	elseif(!empty($options['api_key']) && count($options) > 3)
	{
		//now they are a normal user should we add them to any lists?
		if(!empty($options['users_lists']) && !empty($options['api_key']))
		{
			//get user info
			$list_user = get_userdata($user_id);
			
			//subscribe to each list
			$api = new MCAPI( $options['api_key']);
			foreach($options['users_lists'] as $list)
			{					
				//subscribe them
				$api->listSubscribe($list, $list_user->user_email, array("FNAME" => $list_user->first_name, "LNAME" => $list_user->last_name));
			}
			
			//unsubscribe from any list not assigned to users
			foreach($all_lists as $list)
			{
				if(!in_array($list['id'], $options['users_lists']))
					$api->listUnsubscribe($list['id'], $list_user->user_email);
			}
		}
		else
		{
			//some memberships are on lists. assuming the admin intends this level to be unsubscribed from everything
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

//admin init. registers settings
function pmpromc_admin_init()
{
	//setup settings
	register_setting('pmpromc_options', 'pmpromc_options', 'pmpromc_options_validate');	
	add_settings_section('pmpromc_section_general', 'General Settings', 'pmpromc_section_general', 'pmpromc_options');	
	add_settings_field('pmpromc_option_api_key', 'MailChimp API Key', 'pmpromc_option_api_key', 'pmpromc_options', 'pmpromc_section_general');		
	add_settings_field('pmpromc_option_users_lists', 'All Users List', 'pmpromc_option_users_lists', 'pmpromc_options', 'pmpromc_section_general');	
	
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
	}		
}
add_action("admin_init", "pmpromc_admin_init");

//set the pmpromc_levels array if PMPro is installed
function pmpromc_getPMProLevels()
{	
	global $pmpromc_levels, $wpdb;
	$pmpromc_levels = $wpdb->get_results("SELECT * FROM $wpdb->pmpro_membership_levels ORDER BY id");			
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
	
	//check for a valid API key and get lists
	$options = get_option("pmpromc_options");	
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
			
			//save all lists in an option
			$i = 0;	
			foreach ( $pmpromc_lists as $list ) {
				$all_lists[$i]['id'] = $list['id'];
				$all_lists[$i][$i]['web_id'] = $list['web_id'];
				$all_lists[$i][$i]['name'] = $list['name'];
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
