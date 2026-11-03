jQuery(function($){
    const anypressProductCheckbox = $('#is_anypress_product');
    const anypressProductOptionsTab = $('.anypress_options');

    function manageProductOptionsTab() {
        if (anypressProductCheckbox.is(':checked')) {
            anypressProductOptionsTab.show();
        } else {
            anypressProductOptionsTab.hide();
        }
    }

    manageProductOptionsTab();

    anypressProductCheckbox.on('change', function(){
        manageProductOptionsTab();
    });
});
