<?php

namespace AnyPressPayments\Gateway;

use FluentCart\App\Modules\PaymentMethods\Core\AbstractPaymentGateway;
use FluentCart\App\Services\Payments\PaymentInstance;
use AnyPressPayments\Driver\AuthorizeNet\AuthorizeNetClient;
use AnyPressPayments\Driver\AuthorizeNet\AuthorizeNetDriver;
use AnyPressPayments\Driver\GatewayDriver;
use AnyPressPayments\Driver\RefundRequest;

/**
 * Card payments through Authorize.Net, as a FluentCart payment method.
 *
 * Checkout flow (same shape as FluentCart's Paystack add-on, which FluentCart publishes as
 * the reference for third-party gateways):
 * 1. assets/authorizenet-checkout.js renders a Pay button. On click it calls FluentCart's
 *    orderHandler, which creates the order and calls makePaymentFromPaymentInstance().
 * 2. The script opens Authorize.Net's hosted card form (Accept.js "AcceptUI"). The card goes
 *    from the customer's browser straight to Authorize.Net, which returns a single-use token.
 * 3. The script posts the token to PaymentConfirmation, which charges it (authorize and
 *    capture) and marks the order paid.
 *
 * Subscriptions: store-billed only (FluentCart > Settings > Subscriptions: "Store managed",
 * with "Automatic charge" on for renewals charged to the saved card). FluentCart runs the
 * schedule, retries and status changes; this gateway saves the card at Authorize.Net (CIM)
 * on the first payment and charges it when FluentCart asks (chargeRenewal). No subscription
 * module is passed to the parent, so there's no 'subscriptions' feature: FluentCart never
 * offers this method for gateway-billed ("automatic") subscriptions, which would need
 * Authorize.Net to run the schedule.
 */
class AuthorizeNetGateway extends AbstractPaymentGateway
{
    public const ROUTE = 'anypress_authorizenet';

    // The only dataDescriptor Accept.js issues (Accept.js docs, "Building the Opaque Data Payment Type").
    public const TOKEN_DESCRIPTOR = 'COMMON.ACCEPT.INAPP.PAYMENT';

    // system_subscription: FluentCart's SystemChargeService may charge renewals through
    // chargeRenewal(). card_update: the customer portal links to CardUpdate's page.
    public array $supportedFeatures = ['payment', 'refund', 'system_subscription', 'card_update'];

    /** @var callable|null Builds the driver; replaced in tests. */
    private $driverFactory;

    public function __construct(?callable $driverFactory = null)
    {
        $this->driverFactory = $driverFactory;

        // Core/AbstractPaymentGateway.php:32 — no AbstractSubscriptionModule, so no 'subscriptions' feature.
        parent::__construct(new AuthorizeNetSettings());

        // Our script draws its own Pay button instead of FluentCart's Place Order button
        // (read in app/Modules/Templating/AssetLoader.php:710).
        add_filter('fluent_cart/payment_methods_with_custom_checkout_buttons', function ($methods) {
            $methods[] = self::ROUTE;
            return $methods;
        });
    }

    /** Called by GatewayManager::register (Core/GatewayManager.php:86-89). */
    public function boot()
    {
        (new PaymentConfirmation($this))->register();
        (new CardUpdate($this))->register();
        (new Webhook($this))->register();
    }

    public function meta(): array
    {
        return [
            'title'              => __('Card', 'anypress-payments'),
            'route'              => self::ROUTE,
            'slug'               => self::ROUTE,
            'label'              => 'Authorize.Net',
            'admin_title'        => 'Authorize.Net',
            'description'        => __('Accept credit and debit cards through Authorize.Net. Card details go straight from the customer to Authorize.Net and never touch this site.', 'anypress-payments'),
            'logo'               => ANYPRESS_PAYMENTS_URL . 'assets/card.svg',
            'icon'               => ANYPRESS_PAYMENTS_URL . 'assets/card.svg',
            'brand_color'        => '#1c3141',
            'status'             => $this->settings->get('is_active') === 'yes',
            'upcoming'           => false,
            'supported_features' => $this->supportedFeatures,
        ];
    }

    /**
     * Called when the order is placed. Nothing is charged here: the browser still has to
     * collect the card. Returns the data the checkout script needs for the next step.
     */
    public function makePaymentFromPaymentInstance(PaymentInstance $paymentInstance)
    {
        // A store-billed subscription's payment is a one-time charge (and saves the card when
        // FluentCart auto-charges its renewals). A gateway-billed one would need Authorize.Net
        // to run the schedule, which this gateway doesn't do.
        $subscription = $paymentInstance->subscription;
        if ($subscription && !in_array($subscription->collection_method, ['manual', 'system'], true)) {
            return new \WP_Error(
                'anypress_payments_no_subscriptions',
                __('This subscription can\'t be paid by card. The store needs FluentCart\'s subscription setting "Store managed".', 'anypress-payments')
            );
        }

        return [
            'status'     => 'success',
            'nextAction' => self::ROUTE,
            'actionName' => 'custom',
            'message'    => __('Opening the secure card form...', 'anypress-payments'),
            'data'       => [
                'transaction_hash' => $paymentInstance->transaction->uuid,
            ],
        ];
    }

    /** Answers the checkout script's paymentInfoUrl request (app/Http/Controllers/CheckoutController.php:66). */
    public function getOrderInfo(array $data)
    {
        wp_send_json($this->checkoutConfig(), 200);
    }

    public function checkoutConfig(): array
    {
        $loginId = $this->settings->getApiLoginId();
        $clientKey = $this->settings->getPublicClientKey();

        if ($loginId === '' || $clientKey === '') {
            return [
                'status'  => 'failed',
                'message' => __('Card payments are not set up yet. Please contact the store.', 'anypress-payments'),
            ];
        }

        return [
            'status'       => 'success',
            'message'      => __('Order info retrieved!', 'anypress-payments'),
            'payment_args' => [
                'api_login_id'      => $loginId,
                'public_client_key' => $clientKey,
                'sandbox'           => $this->settings->isSandbox(),
            ],
        ];
    }

    /**
     * Authorize.Net CIM can save a card without charging it (a subscription with nothing to
     * pay today). Core/AbstractPaymentGateway.php supportsSetupWithoutCharge().
     */
    public function supportsSetupWithoutCharge(): bool
    {
        return true;
    }

    /**
     * Charges a renewal invoice to the saved card, for FluentCart's SystemChargeService.
     *
     * @param PaymentInstance $paymentInstance
     * @param array $args ['attempt' => int]
     * @return true|string|\WP_Error
     */
    public function chargeRenewal(PaymentInstance $paymentInstance, $args = [])
    {
        return (new RenewalCharge($this))->charge($paymentInstance, max(1, (int) ($args['attempt'] ?? 1)));
    }

    /**
     * @param PaymentInstance $paymentInstance
     * @return true|string|\WP_Error
     */
    public function reconcileRenewalCharge(PaymentInstance $paymentInstance)
    {
        return (new RenewalCharge($this))->reconcile($paymentInstance);
    }

    /**
     * FluentCart's customer portal opens this URL to change the card of a subscription paid by
     * a gateway other than Stripe or PayPal (its card_update_url, from Subscription::getUrlAttribute).
     */
    public function getSubscriptionUrl($url, $data)
    {
        $subscription = $data['subscription'] ?? null;

        if ($subscription && $subscription->collection_method === 'system' && SavedCard::read($subscription)) {
            return CardUpdate::url($subscription);
        }

        return $url;
    }

    /**
     * Not used: charges are confirmed synchronously, and Authorize.Net's webhooks come to
     * Webhook's REST route, since Authorize.Net won't send them to a URL with a query string.
     */
    public function handleIPN()
    {
    }

    public function processRefund($transaction, $amount, $args)
    {
        if (!$amount) {
            return new \WP_Error('anypress_payments_refund_error', __('Refund amount is required.', 'anypress-payments'));
        }

        $meta = is_array($transaction->meta) ? $transaction->meta : [];
        $sandbox = isset($meta['authorizenet_env'])
            ? $meta['authorizenet_env'] === 'sandbox'
            : $this->settings->isSandbox();

        $result = $this->driver($sandbox)->refund(new RefundRequest(
            (int) $amount,
            (int) $transaction->total,
            (string) $transaction->vendor_charge_id,
            (string) $transaction->card_last_4
        ));

        if (!$result->success) {
            $message = $result->message;
            if (FailedRefundRollback::undo($transaction, (int) $amount)) {
                $message .= ' ' . __('Nothing was refunded and the order is unchanged.', 'anypress-payments');
            }
            return new \WP_Error('anypress_payments_refund_failed', $message);
        }

        // Refund.php:85-91 stores this as the refund transaction's vendor_charge_id.
        return $result->transactionId;
    }

    public function driver(?bool $sandbox = null): GatewayDriver
    {
        $sandbox = $sandbox ?? $this->settings->isSandbox();

        if ($this->driverFactory) {
            return call_user_func($this->driverFactory, $sandbox);
        }

        // Each environment's own keys, so a sandbox charge is refunded with the sandbox keys even in live mode.
        $mode = AuthorizeNetSettings::modeFor($sandbox);

        return self::makeDriver($this->settings->getApiLoginId($mode), $this->settings->getTransactionKey($mode), $sandbox);
    }

    public static function makeDriver(string $apiLoginId, string $transactionKey, bool $sandbox): GatewayDriver
    {
        return new AuthorizeNetDriver(new AuthorizeNetClient($apiLoginId, $transactionKey, $sandbox));
    }

    public function fields(): array
    {
        /** @var AuthorizeNetSettings $settings */
        $settings = $this->settings;

        return (new AuthorizeNetSettingsFields($settings))->fields();
    }

    /** Called from AbstractPaymentGateway::updateSettings (Core/AbstractPaymentGateway.php:138-150). */
    public static function validateSettings($data): array
    {
        return AuthorizeNetSettingsSave::validate($data);
    }

    /** Called from AbstractPaymentGateway::updateSettings (Core/AbstractPaymentGateway.php:152). */
    public static function beforeSettingsUpdate($data, $oldSettings): array
    {
        return AuthorizeNetSettingsSave::beforeUpdate($data);
    }

    public function getEnqueueScriptSrc($hasSubscription = 'no'): array
    {
        return [
            [
                'handle'  => 'anypress-payments-authorizenet-checkout',
                'src'     => ANYPRESS_PAYMENTS_URL . 'assets/authorizenet-checkout.js',
                'version' => ANYPRESS_PAYMENTS_VERSION,
            ],
        ];
    }

    public function getEnqueueStyleSrc(): array
    {
        return [
            [
                'handle'  => 'anypress-payments-authorizenet-checkout',
                'src'     => ANYPRESS_PAYMENTS_URL . 'assets/authorizenet-checkout.css',
                'version' => ANYPRESS_PAYMENTS_VERSION,
            ],
        ];
    }

    public function getLocalizeData(): array
    {
        return [
            'anypress_authorizenet_data' => [
                'confirm_action' => PaymentConfirmation::ACTION,
                'nonce'          => wp_create_nonce(PaymentConfirmation::NONCE),
                'translations'   => [
                    'Pay with card'                    => __('Pay with card', 'anypress-payments'),
                    'Processing...'                    => __('Processing...', 'anypress-payments'),
                    'Confirming payment...'            => __('Confirming payment...', 'anypress-payments'),
                    'Card details'                     => __('Card details', 'anypress-payments'),
                    'Pay'                              => __('Pay', 'anypress-payments'),
                    'Loading Payment Processor...'     => __('Loading Payment Processor...', 'anypress-payments'),
                    'Could not load the card form.'    => __('Could not load the card form.', 'anypress-payments'),
                    'Payment failed. Please try again.' => __('Payment failed. Please try again.', 'anypress-payments'),
                    'Your card is entered on a secure Authorize.Net form. This site never sees your card number.' => __('Your card is entered on a secure Authorize.Net form. This site never sees your card number.', 'anypress-payments'),
                ],
            ],
        ];
    }

    public function getTransactionUrl($url, $data)
    {
        $transaction = $data['transaction'] ?? null;
        if (!$transaction || !$transaction->vendor_charge_id) {
            return $url;
        }

        $meta = is_array($transaction->meta) ? $transaction->meta : [];
        $host = ($meta['authorizenet_env'] ?? '') === 'sandbox' ? 'sandbox.authorize.net' : 'account.authorize.net';

        return 'https://' . $host . '/ui/themes/anet/transaction/transactiondetail.aspx?transID=' . rawurlencode($transaction->vendor_charge_id);
    }
}
