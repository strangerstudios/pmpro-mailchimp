=== MailChimp Add On for Paid Memberships Pro ===
Contributors: strangerstudios
Tags: paid memberships pro, pmpro, mailchimp, email marketing
Requires at least: 3.1
Tested up to: 3.6
Stable tag: .3.3

Sync WordPress Users and PMPro Members with MailChimp lists.

== Description ==

Specify the subscripiton list(s) for your site's WordPress Users and PMPro Members. If Paid Memberships Pro is installed you can specify additional list settings by membership level.

The plugin has a setting to require/not require MailChimp's double opt-in, as well as a setting to unsubscribe members on level change. This allows you to move members from list to list based on membership level. It is important to note that with this setting active the member will be unsubscribed from ALL other lists on membership level change and will only be subscribed to the list(s) set for their active membership level.

== Installation ==
This plugin works with and without Paid Memberships Pro installed.

= Download, Install and Activate! =
1. Upload the `pmpro-mailchimp` directory to the `/wp-content/plugins/` directory of your site.
1. Activate the plugin through the 'Plugins' menu in WordPress.
1. The settings page is at Settings --> PMPro Mailchimp in the WP dashboard.

= Double Opt-in Required? =
Select Yes or No to require/not require MailChimp's double opt-in.

= Unsubscribe on Level Change? =
This setting allows you to move members from list to list based on membership level. It is important to note that with this setting active the member will be unsubscribed from ALL other lists on membership level change and will ONLY be subscribed to the list(s) set for their active membership level.

== Frequently Asked Questions ==

= I found a bug in the plugin. =

Please post it in the issues section of GitHub and we'll fix it as soon as we can. Thanks for helping. https://github.com/strangerstudios/pmpro-mailchimp/issues

= I need help installing, configuring, or customizing the plugin. =

Please visit our premium support site at http://www.paidmembershipspro.com for more documentation and our support forums.

== Screenshots ==

1. General settings for all members/subscribers list, opt-in rules, and unsubscribe rules.
2. Membership-level specific list subscription settings.

== Changelog ==
= .3.3 =
* Added option to turn of unsubscribes entirely. If you manage multiple lists in MailChimp and have users subscribe outside of WordPress, you may want to choose No so contacts aren't unsubscribed from other lists when they register on your site.

= .3.2 =
* Updated pmpro_mailchimp_listsubscribe_fields filter to pass the $list_user object along as well.

= .3.1 =
* Updating email addresses in MailChimp lists if a user's email address is changed.

= .3 =
* Added pmpro_mailchimp_listsubscribe_fields filters to add fields passed the listSubscribe API call.
* Changed some things to make sure that the user cache is clean and the listSubscribe call happens late enough so that first and last name are populated.

= .2.2 =
* First logged release with a readme.
* Added a "Require Double Opt-in" setting that will determine if an additional opt in email is sent for confirmation before adding users to a list. Defaults to "No".
