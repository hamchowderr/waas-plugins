<?php

namespace WaaSHost\Migrations;

use WaaSHost\Commerce\FluentCart\FluentCartStore;
use WaaSHost\Commerce\FluentCart\WaasProvisioningIntegration;

/**
 * One-time update of WaaS Provisioning feeds made before subscription_reactivated was a default trigger: feeds
 * still on the old default set get it added, so a late payment after the subscription expired resumes the store
 * without editing every product. Feeds whose triggers were changed by hand are left as they are.
 *
 * Runs on the first FluentCart load after the plugin is updated (the stored version is behind VERSION).
 */
class FluentCartFeedTriggers
{
    public const OPTION = 'waas_host_fluentcart_feed_triggers';
    public const VERSION = 1;

    /** WaasProvisioningIntegration's default triggers before VERSION 1. */
    public const OLD_DEFAULT_TRIGGERS = ['subscription_activated', 'subscription_canceled', 'subscription_eot'];

    private FluentCartStore $store;

    public function __construct(FluentCartStore $store)
    {
        $this->store = $store;
    }

    /** @return int|null feeds updated, or null when it already ran */
    public function maybe_run(): ?int
    {
        if ((int) get_option(self::OPTION, 0) >= self::VERSION) {
            return null;
        }

        $updated = $this->store->add_feed_trigger(self::OLD_DEFAULT_TRIGGERS, 'subscription_reactivated');
        update_option(self::OPTION, self::VERSION);

        return $updated;
    }

    /** The triggers a feed should have after the update: the new default set when it had the old one. */
    public static function updated_triggers(array $triggers): ?array
    {
        $sorted = array_values(array_unique(array_map('strval', $triggers)));
        sort($sorted);
        $old = self::OLD_DEFAULT_TRIGGERS;
        sort($old);

        return $sorted === $old ? WaasProvisioningIntegration::DEFAULT_TRIGGERS : null;
    }
}
