<?php

namespace WaaSPayments\Gateway;

use FluentCart\App\Helpers\Helper;
use FluentCart\App\Modules\PaymentMethods\Core\AbstractPaymentGateway;
use FluentCart\App\Services\Payments\PaymentInstance;
use WaaSPayments\Driver\AuthorizeNet\AuthorizeNetClient;
use WaaSPayments\Driver\AuthorizeNet\AuthorizeNetDriver;
use WaaSPayments\Driver\GatewayDriver;
use WaaSPayments\Driver\RefundRequest;

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
 * One-time payments only: no subscription module is passed to the parent, so FluentCart
 * never offers this method for gateway-billed subscriptions, and a store-billed
 * subscription is refused in makePaymentFromPaymentInstance().
 */
class AuthorizeNetGateway extends AbstractPaymentGateway
{
    const ROUTE = 'waas_authorizenet';

    // The only dataDescriptor Accept.js issues (Accept.js docs, "Building the Opaque Data Payment Type").
    const TOKEN_DESCRIPTOR = 'COMMON.ACCEPT.INAPP.PAYMENT';

    public array $supportedFeatures = ['payment', 'refund'];

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
    }

    public function meta(): array
    {
        return [
            'title'              => __('Card', 'waas-payments'),
            'route'              => self::ROUTE,
            'slug'               => self::ROUTE,
            'label'              => 'Authorize.Net',
            'admin_title'        => 'Authorize.Net',
            'description'        => __('Accept credit and debit cards through Authorize.Net. Card details go straight from the customer to Authorize.Net and never touch this site.', 'waas-payments'),
            'logo'               => WAAS_PAYMENTS_URL . 'assets/card.svg',
            'icon'               => WAAS_PAYMENTS_URL . 'assets/card.svg',
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
        if ($paymentInstance->subscription) {
            return new \WP_Error(
                'waas_payments_no_subscriptions',
                __('Subscriptions and recurring billing are not available for this store. Please remove the subscription from your cart.', 'waas-payments')
            );
        }

        return [
            'status'     => 'success',
            'nextAction' => self::ROUTE,
            'actionName' => 'custom',
            'message'    => __('Opening the secure card form...', 'waas-payments'),
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
        $loginId = (string) $this->settings->get('api_login_id');
        $clientKey = (string) $this->settings->get('public_client_key');

        if ($loginId === '' || $clientKey === '') {
            return [
                'status'  => 'failed',
                'message' => __('Card payments are not set up yet. Please contact the store.', 'waas-payments'),
            ];
        }

        return [
            'status'       => 'success',
            'message'      => __('Order info retrieved!', 'waas-payments'),
            'payment_args' => [
                'api_login_id'      => $loginId,
                'public_client_key' => $clientKey,
                'sandbox'           => $this->settings->isSandbox(),
            ],
        ];
    }

    /** Authorize.Net webhooks aren't used: the charge is confirmed synchronously. */
    public function handleIPN()
    {
    }

    public function processRefund($transaction, $amount, $args)
    {
        if (!$amount) {
            return new \WP_Error('waas_payments_refund_error', __('Refund amount is required.', 'waas-payments'));
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
                $message .= ' ' . __('Nothing was refunded and the order is unchanged.', 'waas-payments');
            }
            return new \WP_Error('waas_payments_refund_failed', $message);
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

        return self::makeDriver((string) $this->settings->get('api_login_id'), $this->settings->getTransactionKey(), $sandbox);
    }

    public static function makeDriver(string $apiLoginId, string $transactionKey, bool $sandbox): GatewayDriver
    {
        return new AuthorizeNetDriver(new AuthorizeNetClient($apiLoginId, $transactionKey, $sandbox));
    }

    public function fields(): array
    {
        return [
            'api_login_id'    => [
                'value'       => '',
                'label'       => __('API Login ID', 'waas-payments'),
                'type'        => 'text',
                'placeholder' => __('API Login ID', 'waas-payments'),
                'tooltip'     => __('Authorize.Net Merchant Interface: Account > Settings > Security Settings > API Credentials and Keys.', 'waas-payments'),
            ],
            'transaction_key' => [
                'value'       => '',
                'label'       => __('Transaction Key', 'waas-payments'),
                'type'        => 'password',
                'placeholder' => __('Transaction Key', 'waas-payments'),
            ],
            'sandbox'         => [
                'value'       => 'yes',
                'label'       => __('Sandbox mode', 'waas-payments'),
                'type'        => 'select',
                'options'     => [
                    ['value' => 'yes', 'label' => __('On: test transactions on apitest.authorize.net', 'waas-payments')],
                    ['value' => 'no', 'label' => __('Off: live payments', 'waas-payments')],
                ],
                'description' => __('Sandbox credentials only work with sandbox mode on, and live credentials only with it off.', 'waas-payments'),
            ],
        ];
    }

    /**
     * Checks the credentials with Authorize.Net before activating
     * (called from AbstractPaymentGateway::updateSettings, Core/AbstractPaymentGateway.php:138-150).
     */
    public static function validateSettings($data): array
    {
        $loginId = trim((string) ($data['api_login_id'] ?? ''));
        $key = (string) Helper::decryptKey($data['transaction_key'] ?? '');

        if ($loginId === '' || $key === '') {
            return ['status' => 'failed', 'message' => __('Enter the API Login ID and Transaction Key.', 'waas-payments')];
        }

        $result = self::makeDriver($loginId, $key, ($data['sandbox'] ?? 'yes') !== 'no')->verifyCredentials();

        if (!$result->success) {
            return ['status' => 'failed', 'message' => $result->message];
        }

        return ['status' => 'success', 'message' => __('Authorize.Net credentials verified.', 'waas-payments')];
    }

    /**
     * Encrypts the Transaction Key and stores the account's Public Client Key, which the
     * hosted card form needs (Core/AbstractPaymentGateway.php:152).
     */
    public static function beforeSettingsUpdate($data, $oldSettings): array
    {
        $data['api_login_id'] = trim((string) ($data['api_login_id'] ?? ''));
        $key = (string) Helper::decryptKey($data['transaction_key'] ?? '');
        $data['transaction_key'] = Helper::encryptKey($key);
        $data['public_client_key'] = '';

        if ($data['api_login_id'] !== '' && $key !== '') {
            $result = self::makeDriver($data['api_login_id'], $key, ($data['sandbox'] ?? 'yes') !== 'no')->verifyCredentials();
            if ($result->success) {
                $data['public_client_key'] = $result->data['public_client_key'];
            }
        }

        return $data;
    }

    public function getEnqueueScriptSrc($hasSubscription = 'no'): array
    {
        return [
            [
                'handle'  => 'waas-payments-authorizenet-checkout',
                'src'     => WAAS_PAYMENTS_URL . 'assets/authorizenet-checkout.js',
                'version' => WAAS_PAYMENTS_VERSION,
            ],
        ];
    }

    public function getEnqueueStyleSrc(): array
    {
        return [
            [
                'handle'  => 'waas-payments-authorizenet-checkout',
                'src'     => WAAS_PAYMENTS_URL . 'assets/authorizenet-checkout.css',
                'version' => WAAS_PAYMENTS_VERSION,
            ],
        ];
    }

    public function getLocalizeData(): array
    {
        return [
            'waas_authorizenet_data' => [
                'confirm_action' => PaymentConfirmation::ACTION,
                'nonce'          => wp_create_nonce(PaymentConfirmation::NONCE),
                'translations'   => [
                    'Pay with card'                    => __('Pay with card', 'waas-payments'),
                    'Processing...'                    => __('Processing...', 'waas-payments'),
                    'Confirming payment...'            => __('Confirming payment...', 'waas-payments'),
                    'Card details'                     => __('Card details', 'waas-payments'),
                    'Pay'                              => __('Pay', 'waas-payments'),
                    'Loading Payment Processor...'     => __('Loading Payment Processor...', 'waas-payments'),
                    'Could not load the card form.'    => __('Could not load the card form.', 'waas-payments'),
                    'Payment failed. Please try again.' => __('Payment failed. Please try again.', 'waas-payments'),
                    'Your card is entered on a secure Authorize.Net form. This site never sees your card number.' => __('Your card is entered on a secure Authorize.Net form. This site never sees your card number.', 'waas-payments'),
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
