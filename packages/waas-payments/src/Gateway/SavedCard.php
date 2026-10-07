<?php

namespace WaaSPayments\Gateway;

use FluentCart\App\Models\SubscriptionMeta;
use WaaSPayments\Driver\DriverResult;

/**
 * The card a subscription's renewals are charged to, as FluentCart stores it for auto-charged
 * ("system") subscriptions: subscription.vendor_customer_id holds the Authorize.Net customer
 * profile, and the subscription meta active_payment_method holds the payment profile.
 *
 * The meta has the shape FluentCart's own gateways write (PaymentHelper::parsePaymentMethodDetails
 * plus vendor_method_id, read by Stripe::chargeRenewal), so FluentCart's customer portal and
 * admin show the card. It holds references and the masked card only.
 */
class SavedCard
{
    const META_KEY = 'active_payment_method';

    /**
     * @param object $subscription FluentCart Subscription
     * @return array{customer_ref:string, method_ref:string, network_trans_id:string, env:string, last_4:string, brand:string}|null
     *         null when no Authorize.Net card is saved.
     */
    public static function read($subscription): ?array
    {
        $meta = $subscription ? $subscription->getMeta(self::META_KEY, []) : [];
        $meta = is_array($meta) ? $meta : [];

        if (($meta['method'] ?? '') !== AuthorizeNetGateway::ROUTE) {
            return null;
        }

        $customerRef = (string) ($meta['customer_ref'] ?? $subscription->vendor_customer_id ?? '');
        $methodRef = (string) ($meta['vendor_method_id'] ?? '');

        if ($customerRef === '' || $methodRef === '') {
            return null;
        }

        return [
            'customer_ref'     => $customerRef,
            'method_ref'       => $methodRef,
            'network_trans_id' => (string) ($meta['network_trans_id'] ?? ''),
            'env'              => (string) ($meta['env'] ?? ''),
            'last_4'           => (string) ($meta['details']['last_4'] ?? ''),
            'brand'            => (string) ($meta['details']['brand'] ?? ''),
        ];
    }

    /**
     * Saves the card from a driver result (data: customer_ref, method_ref, card_last4,
     * card_brand, network_trans_id).
     *
     * @return array|null The card it replaced, if it was a different one (to delete at Authorize.Net).
     */
    public static function store($subscription, DriverResult $result, bool $sandbox): ?array
    {
        $previous = self::read($subscription);
        $data = $result->data;

        $card = [
            'method'           => AuthorizeNetGateway::ROUTE,
            'type'             => 'card',
            'vendor_method_id' => (string) $data['method_ref'],
            'customer_ref'     => (string) $data['customer_ref'],
            // The card network's ID for the customer-present charge that saved the card,
            // sent with every later charge of it. A card saved without a charge has none.
            'network_trans_id' => (string) ($data['network_trans_id'] ?? ''),
            // Saved cards only exist in the environment that saved them.
            'env'              => $sandbox ? 'sandbox' : 'production',
            'details'          => [
                'type'              => 'card',
                'brand'             => (string) ($data['card_brand'] ?? ''),
                'last_4'            => (string) ($data['card_last4'] ?? ''),
                'payment_method_id' => (string) $data['method_ref'],
            ],
        ];

        $subscription->vendor_customer_id = $card['customer_ref'];
        $subscription->save();
        $subscription->updateMeta(self::META_KEY, $card);

        if ($previous && ($previous['customer_ref'] !== $card['customer_ref'] || $previous['method_ref'] !== $card['vendor_method_id'])) {
            return $previous;
        }

        return null;
    }

    /**
     * Whether a subscription other than $subscriptionId renews on this card. Authorize.Net keeps one payment
     * profile per card on a customer profile, so a customer's subscriptions on the same card share it, and
     * deleting it for one would break the others' renewals.
     */
    public static function isUsedByAnotherSubscription(array $card, $subscriptionId): bool
    {
        return SubscriptionMeta::query()
            ->where('meta_key', self::META_KEY)
            ->where('subscription_id', '!=', (int) $subscriptionId)
            // Stored as JSON (FluentCart's meta mutator).
            ->where('meta_value', 'like', '%"vendor_method_id":"' . $card['method_ref'] . '"%')
            ->exists();
    }
}
