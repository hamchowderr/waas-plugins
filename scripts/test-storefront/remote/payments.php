<?php
// FluentCart in test mode; the waas-payments Card gateway in Authorize.Net sandbox mode, inactive until the owner saves credentials.
use FluentCart\App\Models\Meta;

$store = get_option('fluent_cart_store_settings', []);
$store = is_array($store) ? $store : [];
$store['order_mode'] = 'test';
update_option('fluent_cart_store_settings', $store);

$key = 'fluent_cart_payment_settings_waas_authorizenet';
$meta = Meta::where('meta_key', $key)->first();
$value = $meta && is_array($meta->meta_value) ? $meta->meta_value : [];
$value = array_merge(['is_active' => 'no', 'api_login_id' => '', 'transaction_key' => '', 'public_client_key' => ''], $value, ['sandbox' => 'yes']);
if ($meta) { $meta->meta_value = $value; $meta->save(); } else { Meta::create(['meta_key' => $key, 'meta_value' => $value]); }
echo 'order_mode: ' . (new \FluentCart\Api\StoreSettings())->get('order_mode') . "\n";
echo 'card gateway: active=' . $value['is_active'] . ' sandbox=' . $value['sandbox'] . ' login_id_set=' . ($value['api_login_id'] !== '' ? 'yes' : 'no') . "\n";
