/*
 * License:

 Copyright 2016 - Eighty / 20 Results by Wicked Strong Chicks, LLC (thomas@eighty20results.com)

 This program is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License, version 2, as
 published by the Free Software Foundation.

 This program is distributed in the hope that it will be useful,
 but WITHOUT ANY WARRANTY; without even the implied warranty of
 MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 GNU General Public License for more details.

 You should have received a copy of the GNU General Public License
 along with this program; if not, write to the Free Software
 Foundation, Inc., 51 Franklin St, Fifth Floor, Boston, MA  02110-1301  USA
 */
jQuery(document).ready(function () {
    "use strict";
    var exporturl = pmpromc.admin_url;

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

(function( $ ) {
    "use strict";

    var pmpromc_admin = {
        init: function() {

            this.refresh_btn = $('input.pmpromc_server_refresh');
            var self = this;

            self.refresh_btn.on('click', function() {

                event.preventDefault();

                var btn = $(this);
                var list_id = btn.closest('div.pmpromc-server-refresh-form').find('.pmpro_refresh_list_id').val();
                var level = btn.closest('div.pmpromc-server-refresh-form').find('.pmpro_refresh_list_level_id').val();

                self.trigger_server_refresh( level, list_id );

            });


        },
        trigger_server_refresh: function( $level_id, $list_id ) {

            $.ajax({
                url: ajaxurl,
                type: 'POST',
                timeout: 10000,
                dataType: 'JSON',
                data: {
                    action: 'pmpromc_refresh_list_id',
                    'pmpromc_refresh_list_id' : $list_id,
                    'pmpromc_refresh_list_level': $level_id
                },
                success: function( $response ) {
                    window.console.log("Completed AJAX operation: ", $response);
                },
                error: function( hdr, $error, errorThrown ) {
                    window.alert("Error ( " + $error + " ) while refreshing MailChimp server info");
                    window.console.log("Error:", errorThrown, $error );
                }
            });
        }
    };

    pmpromc_admin.init();
}(jQuery));
