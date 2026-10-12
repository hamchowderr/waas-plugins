<?php

namespace WaaSPayments\Gateway;

use FluentCart\App\Helpers\Helper;
use FluentCart\App\Modules\PaymentMethods\Core\BaseGatewaySettings;

/**
 * Stored in FluentCart's payment settings meta under $methodHandler
 * (loaded by BaseGatewaySettings::__construct, Core/BaseGatewaySettings.php:35-59).
 */
class AuthorizeNetSettings extends BaseGatewaySettings
{
    public $methodHandler = 'fluent_cart_payment_settings_waas_authorizenet';

    public static function getDefaults(): array
    {
        return [
            'is_active'         => 'no',
            'api_login_id'      => '',
            // Encrypted with FluentCart's Helper::encryptKey (app/Helpers/Helper.php:889).
            'transaction_key'   => '',
            'sandbox'           => 'yes',
            // Fetched from Authorize.Net when the settings are saved.
            'public_client_key' => '',
            // Signs webhook notifications. Encrypted like the Transaction Key.
            'signature_key'     => '',
            // What happened when the webhook was last registered with Authorize.Net.
            'webhook_status'    => '',
        ];
    }

    public function get($key = '')
    {
        if ($key) {
            return $this->settings[$key] ?? null;
        }

        return $this->settings;
    }

    public function getMode()
    {
        return $this->isSandbox() ? 'test' : 'live';
    }

    public function isActive()
    {
        return $this->get('is_active') === 'yes';
    }

    public function isSandbox(): bool
    {
        return $this->get('sandbox') !== 'no';
    }

    public function getTransactionKey(): string
    {
        return (string) Helper::decryptKey($this->get('transaction_key'));
    }

    public function getSignatureKey(): string
    {
        return trim((string) Helper::decryptKey($this->get('signature_key')));
    }
}
