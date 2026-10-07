<?php
/**
 * Refunds through the real FluentCart, entered where the admin enters: the order screen's
 * refund endpoint (POST fluent-cart/v2/orders/{id}/refund, OrderController::refundOrder).
 * Authorize.Net's answers come from tests/fixtures/authorizenet through pre_http_request.
 *
 *   scripts/test-env.sh wp eval-file wp-content/plugins/waas-payments/tests/Integration/refund-guard.php setup
 *   scripts/test-env.sh wp eval-file wp-content/plugins/waas-payments/tests/Integration/refund-guard.php
 *
 * "setup" activates the gateway with sandbox credentials. Run the checks in a second call:
 * FluentCart reads gateway settings once per request.
 */

use FluentCart\App\App;
use FluentCart\App\Models\Order;
use FluentCart\App\Models\OrderTransaction;

$fixtures = dirname(__DIR__) . '/fixtures/authorizenet/';
$GLOBALS['waas_refund_answer'] = 'refund-approved';
$GLOBALS['waas_anet_calls'] = [];

add_filter('pre_http_request', function ($pre, $args, $url) use ($fixtures) {
    if (strpos($url, 'authorize.net') === false) {
        return $pre;
    }
    $body = json_decode($args['body'], true);
    if (isset($body['getMerchantDetailsRequest'])) {
        $fixture = 'merchant-details';
    } else {
        $type = $body['createTransactionRequest']['transactionRequest']['transactionType'];
        $GLOBALS['waas_anet_calls'][] = $type;
        $fixture = $type === 'voidTransaction' ? 'void-approved' : $GLOBALS['waas_refund_answer'];
    }
    return ['headers' => [], 'body' => file_get_contents($fixtures . $fixture . '.json'), 'response' => ['code' => 200, 'message' => 'OK'], 'cookies' => [], 'filename' => null];
}, 10, 3);

wp_set_current_user(1);

if (($args[0] ?? '') === 'setup') {
    App::gateway('waas_authorizenet')->updateSettings([
        'is_active'       => 'yes',
        'api_login_id'    => 'test-login',
        'transaction_key' => 'test-key',
        'sandbox'         => 'yes',
    ]);
    WP_CLI::success('Authorize.Net gateway active (sandbox).');
    return;
}

$failures = 0;
$check = function ($label, $expected, $actual) use (&$failures) {
    $ok = $expected === $actual;
    $failures += $ok ? 0 : 1;
    WP_CLI::log(sprintf('  [%s] %s: expected %s, got %s', $ok ? 'ok' : 'FAIL', $label, json_encode($expected), json_encode($actual)));
};

$newOrder = function () {
    $order = Order::query()->create([
        'status'         => 'processing',
        'type'           => 'payment',
        'payment_method' => 'waas_authorizenet',
        'payment_status' => 'paid',
        'currency'       => 'USD',
        'subtotal'       => 5000,
        'total_amount'   => 5000,
        'total_paid'     => 5000,
        'mode'           => 'test',
    ]);
    $charge = OrderTransaction::query()->create([
        'order_id'         => $order->id,
        'order_type'       => 'payment',
        'transaction_type' => 'charge',
        'payment_method'   => 'waas_authorizenet',
        'payment_mode'     => 'test',
        'status'           => 'succeeded',
        'currency'         => 'USD',
        'total'            => 5000,
        'vendor_charge_id' => '2149186954',
        'card_last_4'      => '1111',
        'meta'             => ['authorizenet_env' => 'sandbox'],
    ]);
    return [$order, $charge];
};

$refund = function ($order, $charge, int $cents) {
    $GLOBALS['waas_anet_calls'] = [];
    $request = new WP_REST_Request('POST', '/fluent-cart/v2/orders/' . $order->id . '/refund');
    $request->set_body_params(['refund_info' => ['transaction_id' => $charge->id, 'amount' => $cents, 'reason' => 'test']]);
    return rest_do_request($request)->get_data();
};

$state = function ($order, $charge) {
    $order = Order::query()->find($order->id);
    $charge = OrderTransaction::query()->find($charge->id);
    return [
        'payment_status' => $order->payment_status,
        'total_refund'   => (int) $order->total_refund,
        'refunded_total' => (int) ($charge->meta['refunded_total'] ?? 0),
        'refund_rows'    => OrderTransaction::query()->where('order_id', $order->id)->where('transaction_type', 'refund')->get()
            ->map(fn ($row) => [(int) $row->total, $row->vendor_charge_id])->toArray(),
    ];
};

WP_CLI::log('1. Partial refund of an unsettled charge (Authorize.Net refuses):');
[$order, $charge] = $newOrder();
$GLOBALS['waas_refund_answer'] = 'refund-not-settled';
$response = $refund($order, $charge, 1000);
$check('admin sees the gateway error', 'failed', $response['gateway_refund']['status'] ?? null);
WP_CLI::log('     message: ' . ($response['gateway_refund']['message'] ?? json_encode($response)));
$check('Authorize.Net calls', ['refundTransaction'], $GLOBALS['waas_anet_calls']);
$check('order and charge unchanged', ['payment_status' => 'paid', 'total_refund' => 0, 'refunded_total' => 0, 'refund_rows' => []], $state($order, $charge));

WP_CLI::log('2. Partial refund of a settled charge (Authorize.Net refunds):');
$GLOBALS['waas_refund_answer'] = 'refund-approved';
$response = $refund($order, $charge, 1000);
$check('admin sees success', 'success', $response['gateway_refund']['status'] ?? null);
$check('refund recorded', ['payment_status' => 'partially_refunded', 'total_refund' => 1000, 'refunded_total' => 1000, 'refund_rows' => [[1000, '2149186960']]], $state($order, $charge));

WP_CLI::log('3. Refused refund after an earlier one leaves the earlier one alone:');
$GLOBALS['waas_refund_answer'] = 'charge-declined';
$response = $refund($order, $charge, 1000);
$check('admin sees the gateway error', 'failed', $response['gateway_refund']['status'] ?? null);
$check('only the earlier refund remains', ['payment_status' => 'partially_refunded', 'total_refund' => 1000, 'refunded_total' => 1000, 'refund_rows' => [[1000, '2149186960']]], $state($order, $charge));

WP_CLI::log('4. Full refund of an unsettled charge (voided instead):');
[$order, $charge] = $newOrder();
$GLOBALS['waas_refund_answer'] = 'refund-not-settled';
$response = $refund($order, $charge, 5000);
$check('admin sees success', 'success', $response['gateway_refund']['status'] ?? null);
$check('Authorize.Net calls', ['refundTransaction', 'voidTransaction'], $GLOBALS['waas_anet_calls']);
// Recorded fixtures start with Authorize.Net's byte order mark.
$voidId = json_decode(preg_replace('/^\xEF\xBB\xBF/', '', file_get_contents($fixtures . 'void-approved.json')), true)['transactionResponse']['transId'];
$check('void recorded as a full refund', ['payment_status' => 'refunded', 'total_refund' => 5000, 'refunded_total' => 5000, 'refund_rows' => [[5000, $voidId]]], $state($order, $charge));

$failures ? WP_CLI::error($failures . ' check(s) failed.') : WP_CLI::success('All refund checks passed.');
