<?php

namespace WaaSPayments\Gateway;

use FluentCart\Api\Resource\CustomerResource;
use FluentCart\App\Helpers\Status;
use FluentCart\App\Models\Order;
use FluentCart\App\Models\Subscription;
use FluentCart\App\Modules\Subscriptions\Services\SystemChargeService;
use WaaSPayments\Driver\RecurringGatewayDriver;
use WaaSPayments\Driver\SavePaymentMethodRequest;

/**
 * Lets a customer replace the card their subscription renews on.
 *
 * FluentCart's customer portal only has a built-in card form for Stripe and PayPal; for any
 * other gateway it opens the subscription's card_update_url in a new tab
 * (AuthorizeNetGateway::getSubscriptionUrl). That URL is this page: the customer types the
 * new card into Authorize.Net's hosted form (AcceptUI), and the token is saved as a new
 * payment profile on their Authorize.Net customer profile. The old one is deleted.
 *
 * If a renewal invoice is waiting because the old card was declined, it is charged to the
 * new card straight away (FluentCart's SystemChargeService::chargeNow).
 */
class CardUpdate
{
    const QUERY_VAR = 'waas_payments_card_update';
    const ACTION = 'waas_payments_authorizenet_card_update';

    private $gateway;

    public function __construct(AuthorizeNetGateway $gateway)
    {
        $this->gateway = $gateway;
    }

    public function register(): void
    {
        add_action('template_redirect', [$this, 'maybeRender']);
        add_action('wp_ajax_' . self::ACTION, [$this, 'handleAjax']);
    }

    public static function url($subscription): string
    {
        return add_query_arg(self::QUERY_VAR, rawurlencode((string) $subscription->uuid), home_url('/'));
    }

    public function maybeRender(): void
    {
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- a link to the page; ownedSubscription() only finds the signed-in customer's own subscription.
        if (empty($_GET[self::QUERY_VAR])) {
            return;
        }

        if (!is_user_logged_in()) {
            auth_redirect();
            exit;
        }

        $uuid = sanitize_text_field(wp_unslash($_GET[self::QUERY_VAR]));
        // phpcs:enable WordPress.Security.NonceVerification.Recommended
        $subscription = self::ownedSubscription($uuid);
        $card = $subscription ? SavedCard::read($subscription) : null;

        if (!$subscription || !$card || $subscription->collection_method !== 'system') {
            wp_die(esc_html__('This subscription has no card on file to update.', 'waas-payments'), '', ['response' => 404]);
        }

        nocache_headers();
        $this->render($subscription, $card);
        exit;
    }

    public function handleAjax(): void
    {
        $uuid = sanitize_text_field(wp_unslash($_POST['subscription'] ?? ''));

        if (!wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'] ?? '')), self::nonceAction($uuid))) {
            wp_send_json(['status' => 'failed', 'message' => __('Your session expired. Please reload the page and try again.', 'waas-payments')], 403);
        }

        $subscription = self::ownedSubscription($uuid);
        if (!$subscription) {
            wp_send_json(['status' => 'failed', 'message' => __('Subscription not found.', 'waas-payments')], 404);
        }

        $result = $this->update(
            $subscription,
            sanitize_text_field(wp_unslash($_POST['data_descriptor'] ?? '')),
            // The token is opaque and must reach Authorize.Net unmodified.
            trim(wp_unslash((string) ($_POST['data_value'] ?? ''))), // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
            get_current_user_id()
        );

        wp_send_json($result['body'], $result['status']);
    }

    /**
     * @param object $subscription FluentCart Subscription, already checked to belong to the user.
     * @return array{status:int, body:array}
     */
    public function update($subscription, string $tokenDescriptor, string $tokenValue, int $userId = 0): array
    {
        $card = SavedCard::read($subscription);
        if (!$card || $subscription->collection_method !== 'system') {
            return self::reply(409, 'failed', __('This subscription has no card on file to update.', 'waas-payments'));
        }

        if ($tokenDescriptor !== AuthorizeNetGateway::TOKEN_DESCRIPTOR || $tokenValue === '') {
            return self::reply(400, 'failed', __('The card form did not return a payment token. Please try again.', 'waas-payments'));
        }

        $sandbox = $this->gateway->settings->isSandbox();
        $driver = $this->gateway->driver($sandbox);
        if (!$driver instanceof RecurringGatewayDriver) {
            return self::reply(409, 'failed', __('Card updates are not available. Please contact the store.', 'waas-payments'));
        }

        // A card saved in the other Authorize.Net environment can't hold the new one.
        $sameEnvironment = $card['env'] === ($sandbox ? 'sandbox' : 'production');
        $order = $subscription->order;

        $result = $driver->savePaymentMethod(new SavePaymentMethodRequest(
            $tokenDescriptor,
            $tokenValue,
            $sameEnvironment ? $card['customer_ref'] : '',
            [
                'customer_id'    => (string) $subscription->customer_id,
                'customer_email' => (string) ($order->email ?? ''),
                'billing'        => PaymentConfirmation::billingAddress($order),
            ]
        ));

        if (!$result->success) {
            return self::reply(422, 'failed', $result->message ?: __('The card could not be saved. Please try another card.', 'waas-payments'));
        }

        $replaced = SavedCard::store($subscription, $result, $sandbox);
        if ($replaced) {
            PaymentConfirmation::deleteCard($this->gateway, $replaced, $subscription->id);
        }

        // The old card's decline reason no longer applies; FluentCart's retry bookkeeping stays,
        // and the next attempt reads the new card.
        SystemChargeService::clearFailureState($subscription);

        $subscription->addLog(
            __('Payment method updated', 'waas-payments'),
            /* translators: 1: card brand, 2: last four digits */
            sprintf(__('The customer saved %1$s ending in %2$s for automatic renewal charges.', 'waas-payments'), $result->data['card_brand'] ?? '', $result->data['card_last4'] ?? ''),
            'info'
        );

        $paymentMethod = $subscription->getMeta(SavedCard::META_KEY, []);
        do_action('fluent_cart/subscriptions/system_payment_method_updated', [
            'subscription'   => $subscription,
            'payment_method' => $paymentMethod,
        ]);

        $message = __('Your card was updated. Future renewals will be charged to it.', 'waas-payments');

        $invoice = self::failedInvoice($subscription);
        if ($invoice) {
            $charge = SystemChargeService::chargeNow($invoice, $subscription, $userId);
            if (is_wp_error($charge)) {
                $message .= ' ' . __('Your overdue renewal could not be charged yet; it will be retried automatically.', 'waas-payments');
            } elseif (($charge['status'] ?? '') === 'paid') {
                $message .= ' ' . __('Your overdue renewal was charged to the new card.', 'waas-payments');
            } else {
                /* translators: %s: why the charge failed */
                $message .= ' ' . sprintf(__('Your overdue renewal was not charged: %s', 'waas-payments'), $charge['message'] ?? '');
            }
        }

        return [
            'status' => 200,
            'body'   => [
                'status'  => 'success',
                'message' => $message,
                'card'    => ['brand' => $result->data['card_brand'] ?? '', 'last_4' => $result->data['card_last4'] ?? ''],
            ],
        ];
    }

    /**
     * The renewal invoice an automatic charge failed on (payment status pending), if the
     * subscription can still be charged. Invoices not yet due are left to the schedule.
     */
    private static function failedInvoice($subscription)
    {
        if (!in_array($subscription->status, [Status::SUBSCRIPTION_ACTIVE, Status::SUBSCRIPTION_TRIALING, Status::SUBSCRIPTION_PAST_DUE, Status::SUBSCRIPTION_EXPIRED], true)) {
            return null;
        }

        return Order::query()
            ->where('parent_id', $subscription->parent_order_id)
            ->where('type', Status::ORDER_TYPE_RENEWAL)
            ->where('payment_status', Status::PAYMENT_PENDING)
            ->orderBy('id', 'DESC')
            ->first();
    }

    /** The subscription, if it belongs to the logged-in customer. */
    private static function ownedSubscription(string $uuid)
    {
        $customer = $uuid !== '' ? CustomerResource::getCurrentCustomer() : null;
        if (!$customer) {
            return null;
        }

        return Subscription::query()
            ->where('uuid', $uuid)
            ->where('customer_id', $customer->id)
            ->first();
    }

    private static function nonceAction(string $uuid): string
    {
        return self::ACTION . ':' . $uuid;
    }

    private function render($subscription, array $card): void
    {
        $config = [
            'ajax_url'          => admin_url('admin-ajax.php'),
            'action'            => self::ACTION,
            'nonce'             => wp_create_nonce(self::nonceAction((string) $subscription->uuid)),
            'subscription'      => (string) $subscription->uuid,
            'api_login_id'      => $this->gateway->settings->getApiLoginId(),
            'public_client_key' => $this->gateway->settings->getPublicClientKey(),
            'sandbox'           => $this->gateway->settings->isSandbox(),
            'translations'      => [
                'Card details'             => __('Card details', 'waas-payments'),
                'Save card'                => __('Save card', 'waas-payments'),
                'Saving...'                => __('Saving...', 'waas-payments'),
                'Update card'              => __('Update card', 'waas-payments'),
                'Could not load the card form.' => __('Could not load the card form.', 'waas-payments'),
                'The card could not be saved. Please try another card.' => __('The card could not be saved. Please try another card.', 'waas-payments'),
            ],
        ];

        $state = $subscription->getMeta('system_charge_state', []);
        $lastError = is_array($state) ? (string) ($state['last_error'] ?? '') : '';
        $title = (string) ($subscription->item_name ?: __('Subscription', 'waas-payments'));

        header('Content-Type: text/html; charset=utf-8');
        ?><!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title><?php esc_html_e('Update your card', 'waas-payments'); ?></title>
    <?php
    // A page of its own, without the theme or other plugins' scripts, but its files still go through WordPress's queues.
    wp_register_style('waas-card-update', WAAS_PAYMENTS_URL . 'assets/authorizenet-checkout.css', [], WAAS_PAYMENTS_VERSION);
    wp_register_script('waas-card-update', WAAS_PAYMENTS_URL . 'assets/authorizenet-card-update.js', [], WAAS_PAYMENTS_VERSION, true);
    wp_add_inline_script('waas-card-update', 'window.waas_authorizenet_card_update = ' . wp_json_encode($config) . ';', 'before');
    wp_print_styles('waas-card-update');
    ?>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; background: #f5f6f8; color: #1c3141; margin: 0; }
        .waas-card-update { max-width: 460px; margin: 48px auto; padding: 0 16px; }
        .waas-card-update section { background: #fff; border-radius: 8px; padding: 24px; box-shadow: 0 1px 3px rgba(0,0,0,.08); }
        .waas-card-update h1 { font-size: 1.4rem; margin: 0 0 4px; }
        .waas-card-update .waas-sub { color: #5b6b78; margin: 0 0 20px; }
        .waas-anet-success { background: #e8f6ee; color: #135e33; border-radius: 6px; padding: 10px 12px; margin-top: 12px; }
    </style>
</head>
<body>
<main class="waas-card-update">
    <section>
        <h1><?php esc_html_e('Update your card', 'waas-payments'); ?></h1>
        <p class="waas-sub"><?php echo esc_html($title); ?></p>
        <p>
            <?php
            /* translators: 1: card brand, 2: last four digits */
            echo esc_html(sprintf(__('Renewals are charged to %1$s ending in %2$s.', 'waas-payments'), $card['brand'] ?: __('the card', 'waas-payments'), $card['last_4']));
            ?>
        </p>
        <?php if ($lastError !== '') : ?>
            <div class="waas-anet-error" role="alert">
                <?php
                /* translators: %s: why the charge failed, from the payment gateway */
                echo esc_html(sprintf(__('The last renewal charge failed: %s', 'waas-payments'), $lastError));
                ?>
            </div>
        <?php endif; ?>
        <p class="waas-anet-info"><?php esc_html_e('Your card is entered on a secure Authorize.Net form. This site never sees your card number.', 'waas-payments'); ?></p>
        <div id="waas-card-update-box"></div>
    </section>
</main>
<?php wp_print_scripts('waas-card-update'); ?>
</body>
</html>
        <?php
    }

    private static function reply(int $status, string $result, string $message): array
    {
        return ['status' => $status, 'body' => ['status' => $result, 'message' => $message]];
    }
}
