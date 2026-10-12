<?php

namespace WaaSHost\Commerce\WooCommerce;

use WaaSHost\Commerce\Commerce;
use WaaSHost\Core\Tenant;
use WaaSHost\Features\NameConflicts;
use WaaSHost\Features\Notifications;
use WaaSHost\Features\SingleLoginService;
use WaaSHost\Features\TenantsSubscriptionManager;

/**
 * The store's panel on the customer's subscription page: the domain form (wpcs_after_subscription_details_html),
 * the one-click login link and the store's status ("Active until <date>" while a cancelled subscription's paid period
 * runs out). Shown only on subscriptions that have a store, except for the
 * result of a new website name the customer just submitted.
 */
final class SubscriptionDetails
{
    public static function render($subscription_id): void
    {
        // Also when the panel is not shown: a new name submitted after the subscription stopped is refused here.
        NameConflicts::render_result($subscription_id);

        $commerce = Commerce::require_adapter();
        $external_id = $commerce->get_subscription_meta($subscription_id, Tenant::TENANT_EXTERNAL_ID_META);
        $state = $commerce->get_subscription_meta($subscription_id, Tenant::TENANT_STATE);
        if (!Tenant::shown_to_customer($external_id, $state)) {
            return;
        }
        $has_store = !empty($external_id);

        if ($has_store) {
            do_action('waas_after_subscription_details_html', $subscription_id); // the custom domain form
        }

        $status = Tenant::customer_status_label($state);
        $suspend_at = $state === Tenant::READY ? (int) $commerce->get_subscription_meta($subscription_id, Tenant::TENANT_SUSPEND_AT_META) : 0;
        if ($suspend_at) {
            /* translators: %s: date */
            $status = sprintf(__('Active until %s', WAAS_HOST_TEXTDOMAIN), Notifications::format_date($suspend_at));
        }
        $username = SingleLoginService::get_formatted_username($subscription_id);
        ?>
        <p class="waas-tenant-status"><?php esc_html_e('Website status:', WAAS_HOST_TEXTDOMAIN); ?> <?php echo esc_html($status); ?></p>
        <?php if ($suspend_at) { ?>
            <p class="waas-tenant-suspend-at"><?php echo esc_html(sprintf(
                /* translators: %d: number of days */
                __('Your subscription is cancelled. Your website stays online until then; after that it is taken offline, and deleted %d days later.', WAAS_HOST_TEXTDOMAIN),
                TenantsSubscriptionManager::get_deletion_grace_days()
            )); ?></p>
        <?php }
        if ($state === Tenant::NEEDS_NEW_NAME) {
            NameConflicts::render_form($subscription_id);
        }
        if (!$has_store) {
            return; // nothing to log in to yet
        } ?>
        <p>
            <a href="<?php echo esc_url(SingleLoginService::get_login_link($subscription_id)); ?>" target="_blank" rel="noopener" class="waas-single-login-button">
                <?php echo esc_html(sprintf(__('Login as: %s', WAAS_HOST_TEXTDOMAIN), $username)); ?>
                <span class="dashicons dashicons-admin-network"></span>
            </a>
        </p>
        <?php
    }
}
