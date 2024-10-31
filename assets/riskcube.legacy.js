console.log('Registered RiskCUBE Legacy JavaScript');
jQuery( function($) {
    window.setTimeout(function() {
        var requiredFields = jQuery('#billing_first_name, #billing_last_name, #billing_address_1, #billing_postcode, #billing_city, #billing_phone, #billing_email');
        var shippingToggle = jQuery('#ship-to-different-address-checkbox');
        var shippingFields = jQuery('#shipping_first_name, #shipping_last_name, #shipping_address_1, #shipping_postcode, #shipping_city');
        jQuery("#billing_first_name_field, #billing_last_name_field, #billing_company_field, #billing_email").addClass("address-field");

        jQuery(requiredFields).off('keydown').on('change', executeCall);
        jQuery(shippingFields).off('keydown').on('change', executeCall);

        function executeCall() {
            var emptyFields, fields;

            fields = jQuery(requiredFields);

            if (jQuery(shippingToggle).is(':checked')) {
                fields = fields.add(jQuery(shippingFields));
            }
            
            emptyFields = jQuery(fields).filter(function () {
                return jQuery(this).val() === "";
            });

            if (emptyFields.length <= 1) {
                jQuery(document.body).trigger('update_checkout');
            }
        }
    }, 1000);
});
