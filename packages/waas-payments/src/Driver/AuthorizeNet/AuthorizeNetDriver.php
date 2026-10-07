<?php

namespace WaaSPayments\Driver\AuthorizeNet;

use WaaSPayments\Driver\ChargeRequest;
use WaaSPayments\Driver\DriverResult;
use WaaSPayments\Driver\GatewayDriver;
use WaaSPayments\Driver\RefundRequest;

/**
 * Authorize.Net through its JSON API. The browser tokenizes the card with Accept.js
 * (hosted form), so charges use the opaqueData payment type, never card numbers.
 *
 * Field order follows AnetApiSchema.xsd (transactionRequestType, orderType, customerDataType).
 */
class AuthorizeNetDriver implements GatewayDriver
{
    // transactionResponse.responseCode values.
    const APPROVED = '1';
    const HELD_FOR_REVIEW = '4';

    // Error 54: "The referenced transaction does not meet the criteria for issuing a credit."
    // Returned when refunding a charge that has not settled yet.
    const ERROR_NOT_SETTLED = '54';

    private $client;

    public function __construct(AuthorizeNetClient $client)
    {
        $this->client = $client;
    }

    public function charge(ChargeRequest $request): DriverResult
    {
        $transaction = [
            'transactionType' => 'authCaptureTransaction',
            'amount'          => self::formatAmount($request->amount),
            'payment'         => [
                'opaqueData' => [
                    'dataDescriptor' => $request->tokenDescriptor,
                    'dataValue'      => $request->tokenValue,
                ],
            ],
        ];

        $order = array_filter([
            'invoiceNumber' => self::limit($request->invoiceNumber, 20),
            'description'   => self::limit($request->description, 255),
        ]);
        if ($order) {
            $transaction['order'] = $order;
        }

        $customer = array_filter([
            'id'    => self::limit($request->customerId, 20),
            'email' => self::limit($request->customerEmail, 255),
        ]);
        if ($customer) {
            $transaction['customer'] = $customer;
        }

        if ($request->customerIp !== '') {
            $transaction['customerIP'] = $request->customerIp;
        }

        $response = $this->client->send('createTransactionRequest', ['transactionRequest' => $transaction]);

        return $this->transactionResult($response);
    }

    public function refund(RefundRequest $request): DriverResult
    {
        if ($request->chargeId === '') {
            return DriverResult::failure(__('This payment has no Authorize.Net transaction ID to refund.', 'waas-payments'), 'missing_charge_id');
        }

        $refund = $this->transactionResult($this->client->send('createTransactionRequest', [
            'transactionRequest' => [
                'transactionType' => 'refundTransaction',
                'amount'          => self::formatAmount($request->amount),
                'payment'         => [
                    'creditCard' => [
                        'cardNumber'     => $request->cardLast4,
                        'expirationDate' => 'XXXX',
                    ],
                ],
                'refTransId'      => $request->chargeId,
            ],
        ]));

        if ($refund->success || $refund->code !== self::ERROR_NOT_SETTLED) {
            return $refund;
        }

        // Unsettled charges can't be refunded, only voided, and a void cancels the whole charge.
        if ($request->amount < $request->chargedAmount) {
            return DriverResult::failure(
                __('This payment has not settled yet (Authorize.Net settles once a day), so only the full amount can be cancelled now. Refund the full amount, or wait until it settles to refund part of it.', 'waas-payments'),
                self::ERROR_NOT_SETTLED
            );
        }

        $void = $this->transactionResult($this->client->send('createTransactionRequest', [
            'transactionRequest' => [
                'transactionType' => 'voidTransaction',
                'refTransId'      => $request->chargeId,
            ],
        ]));

        if ($void->success) {
            $void->data['action'] = 'void';
        }

        return $void;
    }

    public function verifyCredentials(): DriverResult
    {
        $response = $this->client->send('getMerchantDetailsRequest', []);

        if (is_wp_error($response)) {
            return DriverResult::failure(self::unreachable($response), 'http_error');
        }

        if (($response['messages']['resultCode'] ?? '') !== 'Ok') {
            $message = $response['messages']['message'][0] ?? [];
            return DriverResult::failure(
                sprintf(__('Authorize.Net rejected the credentials: %s', 'waas-payments'), $message['text'] ?? __('unknown error', 'waas-payments')),
                (string) ($message['code'] ?? '')
            );
        }

        if (empty($response['publicClientKey'])) {
            return DriverResult::failure(
                __('This Authorize.Net account has no Public Client Key yet. Generate one in the Authorize.Net Merchant Interface under Account > Settings > Security Settings > General Security Settings > Manage Public Client Key, then save again.', 'waas-payments'),
                'missing_public_client_key'
            );
        }

        return DriverResult::success('', '', [
            'public_client_key' => (string) $response['publicClientKey'],
            'merchant_name'     => (string) ($response['merchantName'] ?? ''),
            'is_test_mode'      => !empty($response['isTestMode']),
        ]);
    }

    /**
     * @param array|\WP_Error $response
     */
    private function transactionResult($response): DriverResult
    {
        if (is_wp_error($response)) {
            return DriverResult::failure(self::unreachable($response), 'http_error');
        }

        $transaction = $response['transactionResponse'] ?? [];
        $responseCode = (string) ($transaction['responseCode'] ?? '');
        $transactionId = (string) ($transaction['transId'] ?? '');

        if ($responseCode === self::APPROVED && ($response['messages']['resultCode'] ?? '') === 'Ok') {
            return DriverResult::success(
                $transactionId,
                (string) ($transaction['messages'][0]['description'] ?? ''),
                [
                    'card_last4' => substr((string) ($transaction['accountNumber'] ?? ''), -4),
                    'card_brand' => (string) ($transaction['accountType'] ?? ''),
                    'auth_code'  => (string) ($transaction['authCode'] ?? ''),
                    'avs_result' => (string) ($transaction['avsResultCode'] ?? ''),
                    'cvv_result' => (string) ($transaction['cvvResultCode'] ?? ''),
                ],
                $responseCode
            );
        }

        if ($responseCode === self::HELD_FOR_REVIEW) {
            // Authorized but held by the merchant's fraud filters; it is only paid once the
            // merchant approves it in Authorize.Net.
            return DriverResult::failure(
                __('The payment is being held for review by the store. You have not been charged yet.', 'waas-payments'),
                'held_for_review',
                [],
                $transactionId
            );
        }

        // A declined or failed transaction explains itself in transactionResponse.errors;
        // a request the API refused outright (bad credentials, malformed) only in messages.
        $error = $transaction['errors'][0] ?? null;
        if ($error) {
            return DriverResult::failure((string) $error['errorText'], (string) $error['errorCode'], [], $transactionId);
        }

        $message = $response['messages']['message'][0] ?? [];

        return DriverResult::failure(
            (string) ($message['text'] ?? __('Authorize.Net did not approve the transaction.', 'waas-payments')),
            (string) ($message['code'] ?? ''),
            [],
            $transactionId
        );
    }

    private static function unreachable(\WP_Error $error): string
    {
        return sprintf(__('Could not reach Authorize.Net: %s', 'waas-payments'), $error->get_error_message());
    }

    private static function formatAmount(int $cents): string
    {
        return number_format($cents / 100, 2, '.', '');
    }

    private static function limit(string $value, int $length): string
    {
        return function_exists('mb_substr') ? mb_substr($value, 0, $length) : substr($value, 0, $length);
    }
}
