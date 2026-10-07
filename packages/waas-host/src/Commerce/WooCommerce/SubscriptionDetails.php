<?php

namespace WaaSHost\Commerce\WooCommerce;

use WaaSHost\Commerce\Commerce;
use WaaSHost\Core\WPCSTenant;
use WaaSHost\Features\SingleLoginService;

/**
 * The store's panel on the customer's subscription page: the domain form (wpcs_after_subscription_details_html),
 * the one-click login link and the store's status. Shown only on subscriptions that have a store.
 */
final class SubscriptionDetails
{
    public static function render($subscription_id): void
    {
        $commerce = Commerce::require_adapter();
        if (empty($commerce->get_subscription_meta($subscription_id, WPCSTenant::WPCS_TENANT_EXTERNAL_ID_META))) {
            return;
        }

        do_action('wpcs_after_subscription_details_html', $subscription_id);

        $status = (string) (new WPCSTenant($subscription_id))->get_status();
        $username = SingleLoginService::get_formatted_username($subscription_id);
        ?>
        <p class="wpcs-tenant-status"><?php esc_html_e('Website status:', WPCS_WAAS_HOST_TEXTDOMAIN); ?> <?php echo esc_html($status); ?></p>
        <p>
            <a href="<?php echo esc_url(SingleLoginService::get_login_link($subscription_id)); ?>" target="_blank" rel="noopener" class="wpcs-single-login-button">
                <?php echo esc_html(sprintf(__('Login as: %s', WPCS_WAAS_HOST_TEXTDOMAIN), $username)); ?>
                <span class="dashicons dashicons-admin-network"></span>
            </a>
        </p>
        <?php
    }
}
