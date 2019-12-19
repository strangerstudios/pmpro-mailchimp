#---------------------------
# This script generates a new pmpro-mailchimp.pot file for use in translations.
# To generate a new pmpro-mailchimp.pot, cd to the main /pmpro-mailchimp/ directory,
# then execute `languages/gettext.sh` from the command line.
# then fix the header info (helps to have the old pmpro-mailchimp.pot open before running script above)
# then execute `cp languages/pmpro-mailchimp.pot languages/pmpro-mailchimp.po` to copy the .pot to .po
# then execute `msgfmt languages/pmpro-mailchimp.po --output-file languages/pmpro-mailchimp.mo` to generate the .mo
#---------------------------
echo "Updating pmpro-mailchimp.pot... "
xgettext -j -o languages/pmpro-mailchimp.pot \
--default-domain=pmpro-mailchimp \
--language=PHP \
--keyword=_ \
--keyword=__ \
--keyword=_e \
--keyword=_ex \
--keyword=_n \
--keyword=_x \
--sort-by-file \
--package-version=1.0 \
--msgid-bugs-address="info@paidmembershipspro.com" \
$(find . -name "*.php")
echo "Done!"