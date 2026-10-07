<?php
// FluentCart in test mode with store-managed subscriptions and automatic renewal charges; FluentCart's
// offline gateway on; the waas-payments Card gateway in Authorize.Net sandbox mode. The Card credentials
// come from AUTHNET_LOGIN_ID / AUTHNET_TRANSACTION_KEY in the container's environment (locked Coolify
// variables); without them the gateway stays off for the owner to fill in. Prints no secrets.
use FluentCart\App\Helpers\Helper;
use FluentCart\App\Models\Meta;
use WaaSPayments\Gateway\AuthorizeNetGateway;

$store = get_option('fluent_cart_store_settings', []);
$store = is_array($store) ? $store : [];
$store['order_mode'] = 'test';
$store['subscription_management_mode'] = 'store_managed';
$store['subscription_system_charge'] = 'yes';
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
$card = array_merge(['is_active' => 'no', 'api_login_id' => '', 'transaction_key' => '', 'public_client_key' => ''], waas_storefront_gateway($cardKey), ['sandbox' => 'yes']);
$loginId = (string) getenv('AUTHNET_LOGIN_ID');
$transactionKey = (string) getenv('AUTHNET_TRANSACTION_KEY');
$cardStatus = 'no credentials in the environment; left for the owner';
if ($loginId !== '' && $transactionKey !== '') {
    // The same path as saving the form: check with Authorize.Net, encrypt the key, fetch the Public Client Key.
    $data = array_merge($card, ['api_login_id' => $loginId, 'transaction_key' => Helper::encryptKey($transactionKey), 'is_active' => 'yes']);
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
echo 'card gateway: ' . $cardStatus . '; active=' . $card['is_active'] . ' sandbox=' . $card['sandbox']
    . ' public_client_key=' . (($card['public_client_key'] ?? '') !== '' ? 'set' : 'missing') . "\n";
