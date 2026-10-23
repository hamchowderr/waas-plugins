jQuery(function($){
    const waasProductCheckbox = $('#is_waas_product');
    const waasProductOptionsTab = $('.waas_options');

    function manageProductOptionsTab() {
        if (waasProductCheckbox.is(':checked')) {
            waasProductOptionsTab.show();
        } else {
            waasProductOptionsTab.hide();
        }
    }

    manageProductOptionsTab();

    waasProductCheckbox.on('change', function(){
        manageProductOptionsTab();
    });
});
