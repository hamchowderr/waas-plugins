<?php

namespace WaaSHost\Commerce\WooCommerce;

use WaaSHost\Commerce\Commerce;
use WaaSHost\Core\WPCSTenant;
use WaaSHost\Features\NameConflicts;
use WaaSHost\Features\SingleLoginService;

/**
 * The store's panel on the customer's subscription page: the domain form (wpcs_after_subscription_details_html),
 * the one-click login link and the store's status. Shown only on subscriptions that have a store, except for the
 * result of a new website name the customer just submitted.
 */
final class SubscriptionDetails
{
    public static function render($subscription_id): void
    {
        // Also when the panel is not shown: a new name submitted after the subscription stopped is refused here.
        NameConflicts::render_result($subscription_id);

        $commerce = Commerce::require_adapter();
        $external_id = $commerce->get_subscription_meta($subscription_id, WPCSTenant::WPCS_TENANT_EXTERNAL_ID_META);
        $state = $commerce->get_subscription_meta($subscription_id, WPCSTenant::WPCS_TENANT_STATE);
        if (!WPCSTenant::shown_to_customer($external_id, $state)) {
            return;
        }
        $has_store = !empty($external_id);

        if ($has_store) {
            do_action('wpcs_after_subscription_details_html', $subscription_id); // the custom domain form
        }

        $status = WPCSTenant::customer_status_label($state);
        $username = SingleLoginService::get_formatted_username($subscription_id);
        ?>
        <p class="wpcs-tenant-status"><?php esc_html_e('Website status:', WPCS_WAAS_HOST_TEXTDOMAIN); ?> <?php echo esc_html($status); ?></p>
        <?php if ($state === WPCSTenant::NEEDS_NEW_NAME) {
            NameConflicts::render_form($subscription_id);
        }
        if (!$has_store) {
            return; // nothing to log in to yet
        } ?>
        <p>
            <a href="<?php echo esc_url(SingleLoginService::get_login_link($subscription_id)); ?>" target="_blank" rel="noopener" class="wpcs-single-login-button">
                <?php echo esc_html(sprintf(__('Login as: %s', WPCS_WAAS_HOST_TEXTDOMAIN), $username)); ?>
                <span class="dashicons dashicons-admin-network"></span>
            </a>
        </p>
        <?php
    }
}
