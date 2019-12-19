=== Paid Memberships Pro - Mailchimp Add On ===
Contributors: strangerstudios
Tags: paid memberships pro, pmpro, mailchimp, email marketing
Requires at least: 4
Tested up to: 5.3.2
Stable tag: 2.2

Sync WordPress Users and PMPro Members with MailChimp lists.

== Description ==

Specify the subscripiton list(s) for your site's WordPress Users and PMPro Members. If Paid Memberships Pro is installed you can specify additional list settings by membership level.

The plugin has a setting to require/not require Mailchimp's double opt-in, as well as a setting to unsubscribe members on level change. This allows you to move members from list to list based on membership level. It is important to note that with this setting active the member will be unsubscribed from ALL other lists on membership level change and will only be subscribed to the list(s) set for their active membership level.

== Installation ==
This plugin works with and without Paid Memberships Pro installed.

= Download, Install and Activate! =
1. Upload the `pmpro-mailchimp` directory to the `/wp-content/plugins/` directory of your site.
1. Activate the plugin through the 'Plugins' menu in WordPress.
1. The settings page is at Settings --> PMPro Mailchimp in the WP dashboard.

= Mailchimp API Key =
Enter your Mailchimp API key. If you don't have a Mailchimp account, you can create one here http://eepurl.com/k4aAH

= Non-member Users =
Choose which lists to add new non-member users to. Note this is only users who register through the default WP process or are added in the admin with no membership level selected.

= Opt-in Audiences =
Choose lists to be added to the bottom of the PMPro checkout page. Users can check to opt into those lists at checkout.

= Double Opt-in Required? =
Select Yes or No to require/not require Mailchimp's double opt-in.

= Unsubscribe on Level Change? =
This setting allows you to move members from list to list based on membership level. It is important to note that with this setting active the member will be unsubscribed from ALL other lists on membership level change and will ONLY be subscribed to the list(s) set for their active membership level.

= Membership Levels and Audiences =
Choose the lists members of each level should be added to when gaining that level.

== Frequently Asked Questions ==

= I found a bug in the plugin. =

Please post it in the issues section of GitHub and we'll fix it as soon as we can. Thanks for helping. https://github.com/strangerstudios/pmpro-mailchimp/issues

= I need help installing, configuring, or customizing the plugin. =

Please visit our premium support site at http://www.paidmembershipspro.com for more documentation and our support forums.

== Screenshots ==

1. General settings for all members/subscribers list, opt-in rules, and unsubscribe rules.
2. Membership-level specific list subscription settings.

== Changelog ==

= 2.2 - 2019-12-19 =
* BUG FIX: Fixed email address updates via profile.
* ENHANCEMENT: Using "Audience" instead of "List" in strings throughout the plugin for consistency with Mailchimp's name changes.
* ENHANCEMENT: Using "Mailchimp" instead of "MailChimp" in strings throughout the plugin for consistency with Mailchimp's name changes.
* BUG FIX/ENHANCEMENT: Mailchimp subscriber updates are processed using the /lists/ API endpoint to prevent rate limiting by Mailchimp.
* BUG FIX/ENHANCEMENT: Now using the Mailchimp member "status" property when unsubscribing members instead of deleting them.
* ENHANCEMENT: Removed default columns besides email from Mailchimp CSV export. Now using the pmpro_mailchimp_listsubscribe_fields filter instead.

= 2.1.2 =
* BUG FIX: Checking for 204 status when unsubscribing. We were checking for 200 before and throwing an error incorrectly.
* BUG FIX: Fixed bug where users weren't unsubscribed from MailChimp when they expired. A further refactoring is needed to avoid hitting the MailChimp API limit if many users are processed at once.
* BUG FIX/ENHANCEMENT: The "All Users" label was changed to "Non-member Users" to match how the setting is actually used.

= 2.1.1 =
* BUG FIX: Fixed issues with error handling and the display of error messages. Specifically, entering an incorrect API key will no longer crash the settings page. (Thanks, Hugh Brock)

= 2.1 =
* BUG: Fixed a variety of bugs related to the MailChimp API, including a bug introduced in v2.0.3 that sometimes kept the plugin from subscribing users to lists.
* BUG/ENHANCEMENT: Doing a better job of limiting the number of API requests made to avoid API limits.
* ENHANCEMENT: Supports the pmpro-multiple-memberships-per-user Add On.
* ENHANCEMENT: Added localization support. (Now should be able to create language files via GlotPress)

= 2.0.3 =
* BUG: Fixed issue with updating email addresses in MailChimp when email addresses are updated in WordPress.
* ENHANCEMENT: Added a filter pmpromc_profile_update that you can set to __return_true to tell the addon to synchronize user data on every profile update. By default, PMPro MailChimp will only synchronize data if the email address has changed. Filter passes $update_user, $user_id, and $old_user_data and is documented in the code.

= 2.0.2 =
* BUG: Fixed issue where the wrong subscriber id was being used for subsequent API calls when calls were made for more than one subscriber (e.g. when importing, bulk updating, or members are expiring).
* BUG: Fixed other warnings, typos, and minor bugs.
* ENHANCEMENT: Added filter (`pmpro_addon_mc_api_timeout`) to modify API connection timeout (default is 10 seconds which should be plenty!)


= 2.0.1 =
* BUG: Fixed bug where "additional opt-in lists" were causing fatal errors at checkout if set.

= 2.0.0 =
* FIX/ENHANCEMENT: Removed the v2 MailChimp API class and now using our own API class based on MailChimps v3 API.
* FEATURE: Now adding PMPLEVEL and PMPLEVELID merge fields for users added to lists. These can be used to create segments and groups in MailChimp.
* FEATURE: Added a link on the settings page to export a CSV file formatted specifically for importing into MailChimp. This can be used to import existing members into MailChimp as new subscribers or just to update the merge fields for existing subscribers.

= 1.2 =
* Updated the MailChimp API used to have a $verify_ssl property that controls the CURLOPT_SSL_VERIFYPEER option of the CURL connection. This is set to false by default, avoiding some issues people have had connecting to the MailChimp API.

= 1.1 =
* Added option for passing membership level to MailChimp as a custom field.

= 1.0.7 =
* ENHANCEMENT: Mailing Lists section of edit profile page doesn't show up if there are no lists set for "opt-in lists".
* BUG: Not showing the "additional lists" options on the review page when using PayPal Express/Standard/etc. (Thanks, Christopher Souser)
* BUG: Fixed some warnings.

= 1.0.6 =
* BUG: Avoiding warnings when unsubscribing. (Thanks, Adam Shaw)

= 1.0.5 =
* ENHANCEMENT: Won't try to subscribe/unsubscribe if the user doesn't have an email address. Doesn't come up often in WP, but can.
* BUG: Fixed bug where if users unchecked all optional lists options, the plugin would not remove them from the lists. (Thanks, Darlene)

= 1.0.4 =
* BUG: Avoiding warnings in some cases where levels have been deleted.

= 1.0.3 =
* BUG: Removed add_settings_error call to avoid fatal error on front end. Wasn't using it.

= 1.0.2 =
* BUG: Better error handling when invalid API keys are entered.

= 1.0.1 =
* BUG: Fixed some warnings and fatal errors if site is run with an empty or invalid API key.

= 1.0 =
* Admitting that we're officially released with a 1.0 version. :)
* Now using Mailchimp v2.0 API.

= .3.6.2 =
* Updated code to make sure that when checking out, sub adds run on pmpro_after_checkout instead of pmpro_after_change_membership_level.

= .3.6.1 =
* Fixed some warnings that would show up if the plugin was not connected to the API yet.

= .3.6 =
* Now 3 options for the "Unsubscribe on Level Change" option. No, Yes (Only old level lists.), and Yes (All other lists.).
* Fixed possibly issues introduced in the .3.5 version.

= .3.5 =
* Added the "Opt-in Lists" that will show up on the PMPro checkout page as checkboxes allowing the member to opt into one or more lists.
* Instead of unsubscribing users from all lists when changing membership levels (before adding them back to lists for the new membership level), we only unsubscribe users from the lists that were selected for their old level. For example, if list #1 is given to a user for level 1, users changing away from level 1 will only be unsubscribed from list #1. They will remain on any other list they might have gotten outside of PMPro MailChimp.

= .3.4 =
* Fixing SQL warning when running PMPro Mailchimp without PMPro. (Thanks, kateM82)

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
