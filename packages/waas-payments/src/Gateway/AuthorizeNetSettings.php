<?php

namespace WaaSPayments\Gateway;

use FluentCart\Api\StoreSettings;
use FluentCart\App\Helpers\Helper;
use FluentCart\App\Modules\PaymentMethods\Core\BaseGatewaySettings;

/**
 * Stored in FluentCart's payment settings meta under $methodHandler
 * (loaded by BaseGatewaySettings::__construct, Core/BaseGatewaySettings.php:35-59).
 *
 * Like FluentCart's own Stripe and PayPal (StripeSettingsBase.php:143-146), the mode comes from
 * the store's Order Mode (FluentCart > Settings > Store Settings), and each mode has its own
 * credentials: test_* for the Authorize.Net sandbox, live_* for production.
 */
class AuthorizeNetSettings extends BaseGatewaySettings
{
    public $methodHandler = 'fluent_cart_payment_settings_waas_authorizenet';

    const MODES = ['test', 'live'];

    /** Per-mode keys. Transaction and Signature Keys are encrypted with Helper::encryptKey (app/Helpers/Helper.php:889). */
    const MODE_KEYS = [
        'api_login_id',
        'transaction_key',
        'signature_key',
        // Fetched from Authorize.Net when the settings are saved.
        'public_client_key',
        // What the last check of the credentials found, shown on the settings page.
        'merchant_name',
        'account_test_mode',
        'verified_at',
        'error',
        // What happened when the webhook was last registered with Authorize.Net.
        'webhook_status',
    ];

    /** Before 0.2.0 there was one set of keys and a "sandbox" setting that chose the environment. */
    const LEGACY_KEYS = ['api_login_id', 'transaction_key', 'signature_key', 'public_client_key', 'webhook_status'];

    /** wp_options row with the time of the last signed webhook notification, per mode. */
    const LAST_WEBHOOK_OPTION = 'waas_payments_authorizenet_last_webhook';

    public static function getDefaults(): array
    {
        $defaults = [
            'is_active'    => 'no',
            // The credentials tab last open on the settings page; the mode itself is the store's.
            'payment_mode' => 'test',
        ];

        foreach (self::MODES as $mode) {
            foreach (self::MODE_KEYS as $key) {
                $defaults[$mode . '_' . $key] = '';
            }
        }

        return $defaults;
    }

    public function __construct()
    {
        parent::__construct();
        $this->settings = self::migrateLegacy((array) $this->settings);
        // The settings page opens on the credentials the store is using.
        $this->settings['payment_mode'] = $this->getMode();
    }

    /**
     * Moves 0.1.x settings (one set of keys plus "sandbox") into the mode they were used for, so
     * an update keeps working: sandbox keys become the test keys, live keys the live keys.
     */
    public static function migrateLegacy(array $settings): array
    {
        if (!array_key_exists('sandbox', $settings) && !array_intersect_key($settings, array_flip(self::LEGACY_KEYS))) {
            return $settings;
        }

        $mode = ($settings['sandbox'] ?? 'yes') === 'no' ? 'live' : 'test';
        // Saving removes the old keys, so any still here were written after the last save: they win.
        foreach (self::LEGACY_KEYS as $key) {
            if ((string) ($settings[$key] ?? '') !== '') {
                $settings[$mode . '_' . $key] = $settings[$key];
            }
        }

        foreach (array_merge(self::LEGACY_KEYS, ['sandbox']) as $key) {
            unset($settings[$key]);
        }

        // Remember which environment the old keys were for, used when the store mode can't be read.
        $settings['legacy_mode'] = $mode;

        return $settings;
    }

    public function get($key = '')
    {
        if ($key) {
            return $this->settings[$key] ?? null;
        }

        return $this->settings;
    }

    /** 'test' or 'live': the store's Order Mode. */
    public function getMode()
    {
        $storeMode = class_exists(StoreSettings::class) ? (new StoreSettings())->get('order_mode') : null;
        if (in_array($storeMode, self::MODES, true)) {
            return $storeMode;
        }

        // No store mode to read (FluentCart not loaded): fall back to the migrated keys' mode.
        return in_array($this->settings['legacy_mode'] ?? '', self::MODES, true) ? $this->settings['legacy_mode'] : 'test';
    }

    public function isActive()
    {
        return $this->get('is_active') === 'yes';
    }

    public function isSandbox(): bool
    {
        return $this->getMode() === 'test';
    }

    public static function modeFor(bool $sandbox): string
    {
        return $sandbox ? 'test' : 'live';
    }

    /** A per-mode setting; $mode defaults to the store's mode. */
    public function forMode(string $key, ?string $mode = null): string
    {
        return (string) ($this->settings[($mode ?? $this->getMode()) . '_' . $key] ?? '');
    }

    public function getApiLoginId(?string $mode = null): string
    {
        return $this->forMode('api_login_id', $mode);
    }

    public function getPublicClientKey(?string $mode = null): string
    {
        return $this->forMode('public_client_key', $mode);
    }

    public function getTransactionKey(?string $mode = null): string
    {
        return (string) Helper::decryptKey($this->forMode('transaction_key', $mode));
    }

    public function getSignatureKey(?string $mode = null): string
    {
        return trim((string) Helper::decryptKey($this->forMode('signature_key', $mode)));
    }

    public static function lastWebhookAt(string $mode): int
    {
        $times = get_option(self::LAST_WEBHOOK_OPTION, []);

        return is_array($times) ? (int) ($times[$mode] ?? 0) : 0;
    }

    public static function recordWebhook(string $mode): void
    {
        $times = get_option(self::LAST_WEBHOOK_OPTION, []);
        $times = is_array($times) ? $times : [];
        $times[$mode] = time();
        update_option(self::LAST_WEBHOOK_OPTION, $times, false);
    }
}
