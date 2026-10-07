// Self-service Dashboard's "add product" modal: replace the clicked add button with a spinner while it works.
// One delegated handler, so buttons the modal renders later are covered without re-binding.
jQuery(function ($) {
    if (!document.querySelector('.ssd-modal-wrapper')) {
        return;
    }

    const spinner = (window.wpcsAddOnLoadingIndicator || {}).spinner || '';

    $(document).on('click', '.ssd-modal-wrapper .wpr-product-add-button .button', function () {
        const buttonContainer = $(this).parent();
        if (buttonContainer.find('.wpcs-product-added-spinner').length) {
            return;
        }
        buttonContainer.children().hide();
        buttonContainer.append($('<img class="wpcs-product-added-spinner" alt="" />').attr('src', spinner));
    });
});
