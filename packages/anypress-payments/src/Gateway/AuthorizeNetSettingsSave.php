<?php

namespace AnyPressPayments\Gateway;

use FluentCart\App\Helpers\Helper;
use AnyPressPayments\Driver\AuthorizeNet\AuthorizeNetDriver;
use AnyPressPayments\Driver\GatewayDriver;

/** Saving the Authorize.Net settings: checks each mode's credentials, encrypts the keys and registers the webhook. */
class AuthorizeNetSettingsSave
{
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
    public static function validate($data): array
    {
        $data = AuthorizeNetSettings::migrateLegacy((array) $data);
        $current = self::storeMode();

        foreach (AuthorizeNetSettings::MODES as $mode) {
            $loginId = trim((string) ($data[$mode . '_api_login_id'] ?? ''));
            $key = (string) Helper::decryptKey($data[$mode . '_transaction_key'] ?? '');
            $label = $mode === 'test' ? __('test', 'anypress-payments') : __('live', 'anypress-payments');

            if ($loginId === '' && $key === '' && $mode !== $current) {
                continue;
            }

            if ($loginId === '' || $key === '') {
                /* translators: %s: "test" or "live" */
                return ['status' => 'failed', 'message' => sprintf(__('Enter the %s API Login ID and Transaction Key.', 'anypress-payments'), $label)];
            }

            $result = AuthorizeNetGateway::makeDriver($loginId, $key, $mode === 'test')->verifyCredentials();
            if (!$result->success) {
                $title = $mode === 'test' ? __('Test credentials', 'anypress-payments') : __('Live credentials', 'anypress-payments');
                /* translators: 1: "Test credentials" or "Live credentials", 2: Authorize.Net's error */
                return ['status' => 'failed', 'message' => sprintf(__('%1$s: %2$s', 'anypress-payments'), $title, $result->message)];
            }
        }

        return ['status' => 'success', 'message' => __('Authorize.Net credentials verified.', 'anypress-payments')];
    }

    /**
     * Encrypts the keys, checks each mode's credentials with Authorize.Net, stores the account's
     * Public Client Key (the hosted card form needs it) and registers the webhook
     * (Core/AbstractPaymentGateway.php:152).
     */
    public static function beforeUpdate($data): array
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

            $driver = AuthorizeNetGateway::makeDriver($data[$mode . '_api_login_id'], $key, $mode === 'test');
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
            return __('Webhook not registered: Authorize.Net needs a webhook URL without "?". Turn on pretty permalinks (Settings > Permalinks), then save again.', 'anypress-payments');
        }

        if (!$driver instanceof AuthorizeNetDriver) {
            return '';
        }

        $result = $driver->registerWebhook($url, Webhook::EVENT_TYPES);

        return $result->success
            /* translators: %s: date and time */
            ? sprintf(__('Webhook registered with Authorize.Net on %s.', 'anypress-payments'), gmdate('Y-m-d H:i') . ' UTC')
            /* translators: %s: why the webhook could not be registered */
            : sprintf(__('Webhook not registered: %s', 'anypress-payments'), $result->message);
    }
}
