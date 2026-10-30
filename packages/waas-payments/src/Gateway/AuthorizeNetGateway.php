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
    const ROUTE = 'waas_authorizenet';

    // The only dataDescriptor Accept.js issues (Accept.js docs, "Building the Opaque Data Payment Type").
    const TOKEN_DESCRIPTOR = 'COMMON.ACCEPT.INAPP.PAYMENT';

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
        // A store-billed subscription's payment is a one-time charge (and saves the card when
        // FluentCart auto-charges its renewals). A gateway-billed one would need Authorize.Net
        // to run the schedule, which this gateway doesn't do.
        $subscription = $paymentInstance->subscription;
        if ($subscription && !in_array($subscription->collection_method, ['manual', 'system'], true)) {
            return new \WP_Error(
                'waas_payments_no_subscriptions',
                __('This subscription can\'t be paid by card. The store needs FluentCart\'s subscription setting "Store managed".', 'waas-payments')
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
        $loginId = $this->settings->getApiLoginId();
        $clientKey = $this->settings->getPublicClientKey();

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
        return [
            'notice'       => [
                'value' => $this->modeNotice(),
                'label' => __('Authorize.Net', 'waas-payments'),
                'type'  => 'notice',
            ],
            'payment_mode' => [
                'type'   => 'tabs',
                'schema' => [
                    [
                        'type'   => 'tab',
                        'label'  => __('Live credentials', 'waas-payments'),
                        'value'  => 'live',
                        'schema' => $this->credentialFields('live'),
                    ],
                    [
                        'type'   => 'tab',
                        'label'  => __('Test credentials', 'waas-payments'),
                        'value'  => 'test',
                        'schema' => $this->credentialFields('test'),
                    ],
                ],
            ],
            'webhook_info' => [
                'value' => $this->webhookInfo(),
                'label' => __('Webhook', 'waas-payments'),
                'type'  => 'html_attr',
            ],
        ];
    }

    private function credentialFields(string $mode): array
    {
        $where = $mode === 'test'
            ? __('Sandbox account (sandbox.authorize.net)', 'waas-payments')
            : __('Live account (account.authorize.net)', 'waas-payments');
        $path = __('Account > Settings > Security Settings > API Credentials and Keys', 'waas-payments');

        return [
            $mode . '_status'          => [
                'value' => $this->statusInfo($mode),
                'label' => __('Status', 'waas-payments'),
                'type'  => 'html_attr',
            ],
            $mode . '_api_login_id'    => [
                'value'       => '',
                'label'       => __('API Login ID', 'waas-payments'),
                'type'        => 'text',
                'placeholder' => __('API Login ID', 'waas-payments'),
                /* translators: 1: which Authorize.Net account, 2: menu path */
                'tooltip'     => sprintf(__('%1$s: %2$s.', 'waas-payments'), $where, $path),
            ],
            $mode . '_transaction_key' => [
                'value'       => '',
                'label'       => __('Transaction Key', 'waas-payments'),
                'type'        => 'password',
                'placeholder' => __('Transaction Key', 'waas-payments'),
                /* translators: 1: which Authorize.Net account, 2: menu path */
                'tooltip'     => sprintf(__('%1$s: %2$s > New Transaction Key.', 'waas-payments'), $where, $path),
            ],
            $mode . '_signature_key'   => [
                'value'       => '',
                'label'       => __('Signature Key (optional)', 'waas-payments'),
                'type'        => 'password',
                'placeholder' => __('Signature Key', 'waas-payments'),
                /* translators: 1: which Authorize.Net account, 2: menu path */
                'tooltip'     => sprintf(__('%1$s: %2$s > New Signature Key. Lets refunds and voids made in Authorize.Net show up here.', 'waas-payments'), $where, $path),
            ],
        ];
    }

    /** Which keys the store is using, like FluentCart's renderStoreModeNotice (Core/AbstractPaymentGateway.php:419). */
    private function modeNotice(): string
    {
        $mode = $this->settings->getMode();
        $settingsLink = '<a href="' . esc_url(admin_url('admin.php?page=fluent-cart#/settings/store-settings/')) . '">' . esc_html__('Store Settings', 'waas-payments') . '</a>';

        if ($mode === 'test') {
            $text = '<span class="text-warning-500">' . esc_html__('Your store is in test mode: cards are charged in the Authorize.Net sandbox with the test credentials. No real money moves.', 'waas-payments') . '</span>';
        } else {
            $text = '<span class="text-success-500">' . esc_html__('Your store is in live mode: cards are charged with the live credentials.', 'waas-payments') . '</span>';
        }

        /* translators: %s: link to FluentCart's Store Settings */
        $how = sprintf(esc_html__('Switch with Order Mode in %s.', 'waas-payments'), $settingsLink);

        return '<div class="mt-5">' . $text . ' ' . $how . '</div>';
    }

    /** One line about a mode's credentials: connected to which account, or what's wrong. */
    private function statusInfo(string $mode): string
    {
        $login = $this->settings->getApiLoginId($mode);
        $error = $this->settings->forMode('error', $mode);
        $merchant = $this->settings->forMode('merchant_name', $mode);
        $verifiedAt = (int) $this->settings->forMode('verified_at', $mode);
        $inUse = $mode === $this->settings->getMode();

        if ($login === '') {
            $line = $inUse
                ? '<strong style="color:#b32d2e">' . esc_html__('Not set up. Card payments are off until these are filled in.', 'waas-payments') . '</strong>'
                : esc_html__('Not set up.', 'waas-payments');
        } elseif ($error !== '') {
            /* translators: %s: Authorize.Net's error message */
            $line = '<strong style="color:#b32d2e">' . esc_html(sprintf(__('Not connected: %s', 'waas-payments'), $error)) . '</strong>';
        } elseif ($this->settings->getPublicClientKey($mode) !== '') {
            $name = $merchant !== '' ? $merchant : $login;
            /* translators: %s: the merchant's name at Authorize.Net */
            $line = '<strong style="color:#00a32a">' . esc_html(sprintf(__('Connected to %s', 'waas-payments'), $name)) . '</strong>';
            if ($verifiedAt) {
                /* translators: %s: date and time */
                $line .= ' · ' . esc_html(sprintf(__('checked %s', 'waas-payments'), wp_date(get_option('date_format') . ' ' . get_option('time_format'), $verifiedAt)));
            }
            if ($mode === 'live' && $this->settings->forMode('account_test_mode', $mode) === 'yes') {
                $line .= '<br><strong style="color:#b32d2e">' . esc_html__('This Authorize.Net account is in Test Mode, so its charges are not real. Turn Test Mode off in the Merchant Interface (Account > Settings > Test Mode).', 'waas-payments') . '</strong>';
            }
        } else {
            $line = esc_html__('Saved but not checked yet. Save again to check the credentials with Authorize.Net.', 'waas-payments');
        }

        $parts = ['<p>' . $line . '</p>'];
        if ($inUse) {
            $parts[] = '<p>' . esc_html__('The store uses these credentials now.', 'waas-payments') . '</p>';
        }

        $webhook = $this->settings->forMode('webhook_status', $mode);
        $last = AuthorizeNetSettings::lastWebhookAt($mode);
        if ($webhook !== '' || $last) {
            $line = esc_html($webhook);
            if ($last) {
                /* translators: %s: how long ago, e.g. "5 mins" */
                $line .= ($line !== '' ? ' ' : '') . esc_html(sprintf(__('Last notification received %s ago.', 'waas-payments'), human_time_diff($last)));
            }
            $parts[] = '<p>' . $line . '</p>';
        }

        return implode('', $parts);
    }

    /** What the settings page says about the webhook. */
    private function webhookInfo(): string
    {
        return '<p>' . esc_html__('With a Signature Key, saving these settings registers a webhook with Authorize.Net, so refunds and voids made in Authorize.Net, and payments it confirms late, show up in FluentCart. Payments work without it.', 'waas-payments') . '</p>'
            . '<p>' . esc_html__('Webhook URL:', 'waas-payments') . ' <code>' . esc_html(Webhook::url()) . '</code></p>';
    }

    /** The store mode, for the static settings hooks (no settings instance there). */
    private static function storeMode(): string
    {
        return (new AuthorizeNetSettings())->getMode();
    }

    /**
     * Checks the credentials with Authorize.Net before activating
     * (called from AbstractPaymentGateway::updateSettings, Core/AbstractPaymentGateway.php:138-150).
     * The store's current mode needs credentials; the other mode's are checked when filled in.
     */
    public static function validateSettings($data): array
    {
        $data = AuthorizeNetSettings::migrateLegacy((array) $data);
        $current = self::storeMode();

        foreach (AuthorizeNetSettings::MODES as $mode) {
            $loginId = trim((string) ($data[$mode . '_api_login_id'] ?? ''));
            $key = (string) Helper::decryptKey($data[$mode . '_transaction_key'] ?? '');
            $label = $mode === 'test' ? __('test', 'waas-payments') : __('live', 'waas-payments');

            if ($loginId === '' && $key === '' && $mode !== $current) {
                continue;
            }

            if ($loginId === '' || $key === '') {
                /* translators: %s: "test" or "live" */
                return ['status' => 'failed', 'message' => sprintf(__('Enter the %s API Login ID and Transaction Key.', 'waas-payments'), $label)];
            }

            $result = self::makeDriver($loginId, $key, $mode === 'test')->verifyCredentials();
            if (!$result->success) {
                $title = $mode === 'test' ? __('Test credentials', 'waas-payments') : __('Live credentials', 'waas-payments');
                /* translators: 1: "Test credentials" or "Live credentials", 2: Authorize.Net's error */
                return ['status' => 'failed', 'message' => sprintf(__('%1$s: %2$s', 'waas-payments'), $title, $result->message)];
            }
        }

        return ['status' => 'success', 'message' => __('Authorize.Net credentials verified.', 'waas-payments')];
    }

    /**
     * Encrypts the keys, checks each mode's credentials with Authorize.Net, stores the account's
     * Public Client Key (the hosted card form needs it) and registers the webhook
     * (Core/AbstractPaymentGateway.php:152).
     */
    public static function beforeSettingsUpdate($data, $oldSettings): array
    {
        $data = AuthorizeNetSettings::migrateLegacy((array) $data);
        // Shown, never stored.
        unset($data['webhook_info'], $data['notice'], $data['test_status'], $data['live_status']);

        foreach (AuthorizeNetSettings::MODES as $mode) {
            $data[$mode . '_api_login_id'] = sanitize_text_field(trim((string) ($data[$mode . '_api_login_id'] ?? '')));
            $key = (string) Helper::decryptKey($data[$mode . '_transaction_key'] ?? '');
            $data[$mode . '_transaction_key'] = Helper::encryptKey($key);
            $signatureKey = trim((string) Helper::decryptKey($data[$mode . '_signature_key'] ?? ''));
            $data[$mode . '_signature_key'] = Helper::encryptKey($signatureKey);
            foreach (['public_client_key', 'merchant_name', 'account_test_mode', 'verified_at', 'error'] as $field) {
                $data[$mode . '_' . $field] = '';
            }

            if ($data[$mode . '_api_login_id'] === '' || $key === '') {
                $data[$mode . '_webhook_status'] = '';
                continue;
            }

            $driver = self::makeDriver($data[$mode . '_api_login_id'], $key, $mode === 'test');
            $result = $driver->verifyCredentials();
            if (!$result->success) {
                $data[$mode . '_error'] = $result->message;
                continue;
            }

            $data[$mode . '_public_client_key'] = (string) ($result->data['public_client_key'] ?? '');
            $data[$mode . '_merchant_name'] = (string) ($result->data['merchant_name'] ?? '');
            $data[$mode . '_account_test_mode'] = !empty($result->data['is_test_mode']) ? 'yes' : 'no';
            $data[$mode . '_verified_at'] = (string) time();

            if ($signatureKey !== '') {
                $data[$mode . '_webhook_status'] = self::registerWebhook($driver);
            }
        }

        return $data;
    }

    /** Registers (or updates) this site's webhook with Authorize.Net; returns what to show. */
    private static function registerWebhook(GatewayDriver $driver): string
    {
        $url = Webhook::url();
        if (strpos($url, '?') !== false) {
            return __('Webhook not registered: Authorize.Net needs a webhook URL without "?". Turn on pretty permalinks (Settings > Permalinks), then save again.', 'waas-payments');
        }

        if (!$driver instanceof AuthorizeNetDriver) {
            return '';
        }

        $result = $driver->registerWebhook($url, Webhook::EVENT_TYPES);

        return $result->success
            /* translators: %s: date and time */
            ? sprintf(__('Webhook registered with Authorize.Net on %s.', 'waas-payments'), gmdate('Y-m-d H:i') . ' UTC')
            /* translators: %s: why the webhook could not be registered */
            : sprintf(__('Webhook not registered: %s', 'waas-payments'), $result->message);
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
