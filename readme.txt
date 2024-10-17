=== Paid Memberships Pro - Mailchimp Add On ===
Contributors: strangerstudios, dlparker1005, paidmembershipspro
Tags: paid memberships pro, pmpro, mailchimp, email marketing
Requires at least: 5.4
Tested up to: 6.6
Stable tag: 2.4
License: GPLv3
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Add users and members to Mailchimp audiences based on their membership level and allow members to opt-in to specific audiences.

== Description ==

Subscribe WordPress users and members to your Mailchimp audiences.

This plugin offers extended functionality for [membership websites using the Paid Memberships Pro plugin](https://www.paidmembershipspro.com) available for free in the WordPress plugin repository. 

With Paid Memberships Pro installed, you can specify unique audiences for each membership level, as well as opt-in audiences that a member can join as part of checkout or by editing their user profile. By default, the integration will merge the user's email address and membership level information. You can send additional user profile details to Mailchimp [using the method described here](https://www.paidmembershipspro.com/send-additional-user-information-fields-mailchimp/).

The settings page allows the site admin to specify which audience lists to assign users and members to plus additional features  you may wish to adjust. The first step is to connect your website to Mailchimp using your account's API Key. Here's how to find the API key in Mailchimp:

https://www.youtube.com/watch?v=ctcy1_npmRE

= Additional Settings =

* **Non-member Audiences:** These are the audiences that users will be added to if they do not have a membership level. They will also be removed from these audiences when they gain a membership level (assuming the audiences are not also set in the “Membership Levels and Audiences” option for their new level).
* **Opt-in Audiences:** These are the audiences that users will have the option to subscribe to during the PMPro checkout process. Users are later able to update their choice from their profile. Audiences set as Opt-in Audiences should not also be set as a Non-member Audience nor a Level Audience.
* **Require Double Opt-in?:** If set to “Yes (All audiences)”, users will be set to “Pending” status in Mailchimp when they are added to an audience instead of being subscribed right away. They will then receive an email from Mailchimp to opt-in to the audience.
* **Unsubscribe on Level Change?:** If set to “No”, users will not be automatically unsubscribed from any audiences when they lose a membership level. If set to “Yes (Only old level audiences.)”, users will be unsubscribed from any level audiences they are subscribed to when they lose that level, assuming that audience is not a Non-Member audience as well. If set to “Yes (Old level and opt-in audiences.)”, users will also be unsubscribed from opt-in audiences when they lose their membership level (though they can re-subscribe by updating the setting on their profile).
* **Update on Profile Save:** If set to “Yes”, PMPro will update Mailchimp audiences whenever a user’s profile page is saved. If set to “No”, PMPro will only update Mailchimp when a user’s membership level is changed, email is changed, or chosen opt-in audiences are changed.
* **Log API Calls?:** If set to “Yes”, API calls to Mailchimp will be logged in the `/pmpro-mailchimp/logs` folder.
* **Membership Levels and Audiences:** These are the audiences that users will automatically be subscribed to when they receive a membership level.

== Installation ==
This plugin works with and without Paid Memberships Pro installed.

= Download, Install and Activate! =
1. Upload the `pmpro-mailchimp` directory to the `/wp-content/plugins/` directory of your site.
1. Activate the plugin through the 'Plugins' menu in WordPress.
1. Navigate to Settings > PMPro Mailchimp to proceed with setup.

= Configuration and Settings =

**Enter your Mailchimp API Key:** Your Mailchimp API key can be found within your Mailchimp account under Account > Extras > API keys. If you don't have a Mailchimp account, [you can create one here](http://eepurl.com/k4aAH). Read our documentation for a [video demonstrating how to locate your Mailchimp API key](https://www.paidmembershipspro.com/add-ons/pmpro-mailchimp-integration/#api-key).

After entering your API Key, continue with the setup by assigning User or Member Audiences and reviewing the additional settings.

For full documentation on all settings, please visit the [Mailchimp Integration Add On documentation page at Paid Memberships Pro](https://www.paidmembershipspro.com/add-ons/pmpro-mailchimp-integration/). 

Several action and filter hooks are available for developers that need to customize specific aspects of the integration. [Please explore the plugin's action and filter hooks here](https://www.paidmembershipspro.com/add-ons/pmpro-mailchimp-integration/#hooks).

== Frequently Asked Questions ==

= I found a bug in the plugin. =

Please post it in the issues section of GitHub and we'll fix it as soon as we can. Thanks for helping. [https://github.com/strangerstudios/pmpro-mailchimp/issues](https://github.com/strangerstudios/pmpro-mailchimp/issues)

= I need help installing, configuring, or customizing the plugin. =

Please visit [our support site at https://www.paidmembershipspro.com](https://www.paidmembershipspro.com/) for more documentation and our support forums.

== Screenshots ==

1. General Settings for plugin, including the non-member audiences opt-in rules, and unsubscribe rules.
2. Specific settings for Membership Levels and Audiences.

== Changelog ==
= 2.4 - 2024-10-17 =
* FEATURE: Now updating the plugin from paidmembershipspro.com.
* ENHANCEMENT: Updated translation files bundled with the plugin.

= 2.3.7 - 2024-09-24 =
* ENHANCEMENT: Updated UI for compatibility with PMPro v3.1. #145 (@andrewlimaza, @kimcoleman)
* BUG FIX: Fixed over-escaping in settings. #144 (@dparker1005)
* BUG FIX: Fixed issue with exporting CSV of members for Mailchimp Import. #139 (@dparker1005)
* BUG FIX: Fixed warning when site admin enters an improperly formatted Mailchimp API Key. #146 (@kimcoleman)

= 2.3.6 - 2024-03-27 =
* SECURITY: Now preparing SQL statements.
* SECURITY: Improved escaping of strings.
* ENHANCEMENT: Added translator comments for placeholders.

= 2.3.5 - 2024-03-08 =
* SECURITY: Now adding a randomized suffix to the log file name to prevent unauthorized access. #138 (@dparker1005)
* SECURITY: Now preventing access to the `/log/` directory listing. #138 (@dparker1005)
* ENHANCEMENT: Added a filter `pmpromc_update_audience_members_data` to allow modifying data sent to the `/lists/{$audience}` Mailchimp endpoint. #137 (@efc)

= 2.3.4 - 2023-11-15 =
* SECURITY: Now obfuscating email domains in debug logs. #135 (@andrewlimaza)
* ENHANCEMENT: Updating `<h3>` tags to `<h2>` tags for better accessibility. #133 (@kimwhite)
* REFACTOR: No longer pulling the checkout level from the `$_REQUEST` variable. #132 (@dparker1005)

= 2.3.3 - 2023-03-01 =
* ENHANCEMENT: Improved formatting of opt-in audience section on checkout page. (@mircobabini)
* ENHANCEMENT: Added filter `pmpromc_log_path` to allow changing the path where API calls are logged. (@JarrydLong)
* BUG FIX/ENHANCEMENT: Now using `readfile()` during CSV export if `fpassthru()` is not available. (@JarrydLong)
* REFACTOR: Marking the `set_user_interest()` method as deprecated. (@dparker1005)

= 2.3.2 - 2021-03-02 =
* ENHANCEMENT: Added setting to log API calls sent to Mailchimp in the `pmpro-mailchimp/logs` folder.
* ENHANCEMENT: Added a pmpromc_user_data filter to filter user data taht is sent to Mailchimp.
* ENHANCEMENT: Audience checkboxes are now shown as scrollable list on settings page if there are more than 5.
* BUG FIX/ENHANCEMENT: Added CSS class for checkbox labels.
* BUG FIX/ENHANCEMENT: Now passing a valid user object when generating CSV export file headers.
* BUG FIX: Fixed undefined variable in pmpromc_user_register() (Thanks, x140l31 on GitHub).
* BUG FIX: Fixed required parameter being included after optional parameters in add_merge_field().
* BUG FIX: Fixed URL to PMPro support page (Thanks, majerus1223 on GitHub).


= 2.3.1 - 2020-04-28 =
* ENHANCEMENT: Added support for Paid Memberships Pro v2.3+ front-end profile edit page.
* ENHANCEMENT: Now using checkboxes to select audiences instead of <select> fields

= 2.3 - 2020-03-25 =
* FEATURE: Subscriptions/unsubscriptions in Mailchimp now carry over to PMPro for opt-in audiences
* ENHANCEMENT: Added setting to update contact in Mailchimp whenever profile is saved
* ENHANCEMENT: Included audience names in debug logs and improved error reporting
* BUG FIX: Fixed issue where contacts may be created in an unsubscribed status in opt-in audiences they had not subscribed to
* BUG FIX: Fixed issue where contacts would not be removed from non-member audiences when they are given a level
* BUG FIX: Fixed strings using the incorrect text domain
* BUG FIX: Fixed ampersands in names being encoded when sent to Mailchimp
* BUG FIX: Resolved PHP warning in API function in_merge_fields()
* BUG FIX/ENHANCEMENT: Contacts in Mailchimp are now updated when a user’s email is changed instead of being replaced
* REFACTOR: Organized code into different files

= 2.2.1 - 2019-12-31 =
* BUG FIX: Fixed merge fields not being sent during user profile updates
* BUG FIX: Fixed logging for Mailchimp API calls
* BUG FIX: Fixed Mailchimp updates not being sent during wp_redirect filter

= 2.2 - 2019-12-19 =
* BUG FIX: Fixed email address updates via profile.
* BUG FIX: Fixed "Invalid API Key" error that would sometimes occur with newer API keys.
* ENHANCEMENT: Using "Audience" instead of "List" in strings throughout the plugin for consistency with Mailchimp's name changes.
* ENHANCEMENT: Using "Mailchimp" instead of "MailChimp" in strings throughout the plugin for consistency with Mailchimp's name changes.
* ENHANCEMENT: Removed default columns besides email from Mailchimp CSV export. Now using the pmpro_mailchimp_listsubscribe_fields filter instead.
* ENHANCEMENT: Users are now unsubscribed from all opt-in audiences when they cancel membership.
* BUG FIX/ENHANCEMENT: Mailchimp subscriber updates are processed using the /lists/ API endpoint to prevent rate limiting by Mailchimp. This fixes issues that would sometime occur when many members were expired on the same day.
* BUG FIX/ENHANCEMENT: Users who cancel are now unsubscibed from audiences instead of being deleted from Mailchimp.
* BUG FIX/ENHANCEMENT: Now using the Mailchimp member "status" property when unsubscribing members instead of deleting them.

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
