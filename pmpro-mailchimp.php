<?php
/**
 * Plugin Name: Paid Memberships Pro - Mailchimp Add On
 * Plugin URI: https://www.paidmembershipspro.com/add-ons/pmpro-mailchimp-integration/
 * Description: Sync your WordPress users and members with Mailchimp audiences.
 * Version: 2.3.5
 * Author: Paid Memberships Pro
 * Author URI: https://www.paidmembershipspro.com
 * Text Domain: pmpro-mailchimp
 */
/**
 * Copyright 2011-2023	Stranger Studios
 * (email : info@paidmembershipspro.com)
 * GPLv2 Full license details in license.txt
 */

define( 'PMPROMC_BASE_FILE', __FILE__ );
define( 'PMPROMC_DIR', dirname( __FILE__ ) );

require_once PMPROMC_DIR . '/classes/class-pmpromc-mailchimp-api.php'; // Connect PMPromc to Mailchimp.
require_once PMPROMC_DIR . '/includes/api-wrapper.php'; // Simplify API interaction.
require_once PMPROMC_DIR . '/includes/functions.php';
require_once PMPROMC_DIR . '/includes/profile.php'; // Set up fields on user profile.
require_once PMPROMC_DIR . '/includes/settings.php'; // Set up settings page.
require_once PMPROMC_DIR . '/includes/deprecated.php'; // Set up settings page.

/**
 * Load the languages folder for translations.
 */
function pmpromc_load_textdomain() {
	load_plugin_textdomain( 'pmpro-mailchimp', false, basename( dirname( __FILE__ ) ) . '/languages' );
}
add_action( 'plugins_loaded', 'pmpromc_load_textdomain' );

/*
	Load CSS, JS files
*/
function pmpromc_scripts() {
	wp_enqueue_style("pmprorh_frontend", plugins_url( 'css/pmpromc.css', PMPROMC_BASE_FILE ), NULL, "");
}
add_action( 'admin_enqueue_scripts', 'pmpromc_scripts' );
add_action( 'wp_enqueue_scripts', 'pmpromc_scripts' );

/**
 * Set Default options when activating plugin
 */
function pmpromc_activation() {
	// Get options.
	$options = get_option( 'pmpromc_options', array() );

	// If options are not set, apply defaults.
	if ( empty( $options ) ) {

		$options = array(
			'api_key'          => '',
			'double_opt_in'    => 0,
			'unsubscribe'      => 1,
			'profile_update'   => 0,
			'users_lists'      => array(),
			'additional_lists' => array(),
			'level_field'      => '',
		);
		update_option( 'pmpromc_options', $options );

	} elseif ( ! isset( $options['unsubscribe'] ) ) {

		$options['unsubscribe'] = 1;
		update_option( 'pmpromc_options', $options );
	}
}
register_activation_hook( __FILE__, 'pmpromc_activation' );

/**
 * Add links to the plugin action links
 *
 * @param $links (array) - The existing link array
 * @return array -- Array of links to use
 *
 */
function pmpromc_add_action_links( $links ) {

	$new_links = array(
		'<a href="' . get_admin_url( null, 'options-general.php?page=pmpromc_options' ) . '">' . __( 'Settings', 'pmpro-mailchimp' ) . '</a>',
	);
	return array_merge( $new_links, $links );
}
add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), 'pmpromc_add_action_links' );

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
			'<a href="' . esc_url('https://www.paidmembershipspro.com/support/') . '" title="' . esc_attr(__('Visit Customer Support Forum', 'pmpro-mailchimp')) . '">' . __('Support', 'pmpro-mailchimp') . '</a>',
		);
		$links = array_merge($links, $new_links);
	}
	return $links;
}
add_filter('plugin_row_meta', 'pmpromc_plugin_row_meta', 10, 2);
