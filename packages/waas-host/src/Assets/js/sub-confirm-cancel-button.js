// Ask before a customer cancels a subscription that has a website: cancelling takes the website offline.
// WP Swings' cancel button, and WooCommerce Subscriptions' "Cancel" action on the subscription page.
jQuery(function ($) {
    const dangerousCancelButtons = $('.button.wps_sfw_cancel_subscription, .woocommerce-MyAccount-content .subscription_details a.button.cancel');

    dangerousCancelButtons.each(function () {
        const dangerousCancelButton = $(this);
        const cancelConfirmButton = $('<a class="button wpcs-cancel-confirm-button" href="#"></a>').text('Cancel subscription');

        dangerousCancelButton.hide().after(cancelConfirmButton);

        cancelConfirmButton.on('click', function (event) {
            event.preventDefault();
            cancelConfirmButton.hide();
            dangerousCancelButton
                .before($('<div class="wpcs-cancel-explanation-button"></div>').text('Are you sure you want to cancel? Your website will be taken offline, and deleted if the subscription is not reactivated in time.'))
                .text('Yes, cancel my subscription')
                .show();
        });
    });
});
