<?php

namespace WaaSHost\Commerce\FluentCart;

/** Order notes for what waas-host did with a FluentCart event. */
class WaasEventNotes
{
    /**
     * Fires a waas-host subscription event and writes the order note only if waas-host acted on it (the store's
     * recorded state changed). An event that changes nothing, such as a cancel reported twice, leaves no note.
     *
     * @param \FluentCart\App\Models\Order|null $order
     */
    public static function fire(FluentCartStore $store, $order, int $subscription_id, string $action, array $args, string $title, string $detail): bool
    {
        $before = $store->get_waas_state($subscription_id);
        do_action($action, $subscription_id, ...$args);
        if ($store->get_waas_state($subscription_id) === $before) {
            return false;
        }

        if ($order) {
            $order->addLog($title, $detail, 'info', 'WaaS');
        }

        return true;
    }
}
