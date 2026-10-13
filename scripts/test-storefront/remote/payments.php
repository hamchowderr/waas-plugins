<?php
// FluentCart in test mode; FluentCart's offline gateway on; the waas-payments Card gateway with Authorize.Net
// sandbox credentials as its test credentials (the store's test mode picks them). The Card credentials come from AUTHNET_LOGIN_ID / AUTHNET_TRANSACTION_KEY, plus the optional
// AUTHNET_SIGNATURE_KEY that registers its webhook, in the container's environment (locked Coolify variables);
// without them the gateway stays off for the owner to fill in. Prints no secrets.
//
// The subscription mode is the argument: `store` (default) for store-managed subscriptions with automatic renewal
// charges, the only mode the Card gateway sells subscriptions in; `gateway` for Gateway managed, where Stripe and
// PayPal bill the renewals. FluentCart stamps the mode on each subscription at checkout, so switching never changes
// existing subscriptions. Gateway mode leaves the store-managed renewal charge setting as it is (it applies only to
// store-managed subscriptions).
use FluentCart\App\Helpers\Helper;
use FluentCart\App\Models\Meta;
use WaaSPayments\Gateway\AuthorizeNetGateway;

$mode = $args[0] ?? 'store';
if (!in_array($mode, ['store', 'gateway'], true)) {
    WP_CLI::error('payments takes store or gateway');
}

$store = get_option('fluent_cart_store_settings', []);
$store = is_array($store) ? $store : [];
$store['order_mode'] = 'test';
if ($mode === 'gateway') {
    $store['subscription_management_mode'] = 'gateway_managed';
} else {
    $store['subscription_management_mode'] = 'store_managed';
    $store['subscription_system_charge'] = 'yes';
}
update_option('fluent_cart_store_settings', $store);
\FluentCart\Api\StoreSettings::clearCache();

function waas_storefront_save_gateway(string $key, array $value): void
{
    $meta = Meta::where('meta_key', $key)->first();
    if ($meta) {
        $meta->meta_value = $value;
        $meta->save();
    } else {
        Meta::create(['meta_key' => $key, 'meta_value' => $value]);
    }
}

function waas_storefront_gateway(string $key): array
{
    $meta = Meta::where('meta_key', $key)->first();
    return $meta && is_array($meta->meta_value) ? $meta->meta_value : [];
}

$offline = array_merge(waas_storefront_gateway('fluent_cart_payment_settings_offline_payment'), ['is_active' => 'yes', 'payment_mode' => 'test']);
waas_storefront_save_gateway('fluent_cart_payment_settings_offline_payment', $offline);

$cardKey = 'fluent_cart_payment_settings_waas_authorizenet';
$card = \WaaSPayments\Gateway\AuthorizeNetSettings::migrateLegacy(array_merge(['is_active' => 'no'], waas_storefront_gateway($cardKey)));
$loginId = (string) getenv('AUTHNET_LOGIN_ID');
$transactionKey = (string) getenv('AUTHNET_TRANSACTION_KEY');
$cardStatus = 'no credentials in the environment; left for the owner';
if ($loginId !== '' && $transactionKey !== '') {
    // The same path as saving the form: check with Authorize.Net, encrypt the key, fetch the Public Client Key.
    $data = array_merge($card, ['test_api_login_id' => $loginId, 'test_transaction_key' => Helper::encryptKey($transactionKey), 'is_active' => 'yes']);
    // Optional: with a Signature Key, saving also registers the store's Authorize.Net webhook.
    if ((string) getenv('AUTHNET_SIGNATURE_KEY') !== '') {
        $data['test_signature_key'] = Helper::encryptKey((string) getenv('AUTHNET_SIGNATURE_KEY'));
    }
    $check = AuthorizeNetGateway::validateSettings($data);
    if (($check['status'] ?? '') === 'success') {
        $card = AuthorizeNetGateway::beforeSettingsUpdate($data, $card);
        $cardStatus = 'credentials verified';
    } else {
        $card['is_active'] = 'no';
        $cardStatus = 'credentials rejected: ' . ($check['message'] ?? '?');
    }
}
waas_storefront_save_gateway($cardKey, $card);

$settings = new \FluentCart\Api\StoreSettings();
echo 'order_mode: ' . $settings->get('order_mode') . "\n";
echo 'subscriptions: ' . $settings->get('subscription_management_mode') . ', automatic charge ' . $settings->get('subscription_system_charge') . "\n";
echo 'offline gateway: active=' . $offline['is_active'] . "\n";
echo 'card gateway: ' . $cardStatus . '; active=' . $card['is_active'] . ' (test credentials)'
    . ' public_client_key=' . (($card['test_public_client_key'] ?? '') !== '' ? 'set' : 'missing') . "\n";
echo 'card webhook: ' . (($card['test_webhook_status'] ?? '') ?: 'no Signature Key') . "\n";
