<?php

namespace WaaSPayments\Driver\AuthorizeNet;

use WaaSPayments\Driver\ChargeRequest;
use WaaSPayments\Driver\DriverResult;
use WaaSPayments\Driver\RecurringGatewayDriver;
use WaaSPayments\Driver\RefundRequest;
use WaaSPayments\Driver\SavedChargeRequest;
use WaaSPayments\Driver\SavePaymentMethodRequest;

/**
 * Authorize.Net through its JSON API. The browser tokenizes the card with Accept.js
 * (hosted form), so charges use the opaqueData payment type, never card numbers.
 *
 * Saved cards (subscriptions) use Customer Information Manager (CIM): a customer profile
 * holds payment profiles, and renewals charge a payment profile. ARB, Authorize.Net's own
 * recurring schedule, is not used: FluentCart runs the schedule and the retries.
 *
 * Field order follows AnetApiSchema.xsd (transactionRequestType, orderType, customerDataType,
 * customerProfileType, customerPaymentProfileType).
 */
class AuthorizeNetDriver implements RecurringGatewayDriver
{
    // transactionResponse.responseCode values.
    const APPROVED = '1';
    const HELD_FOR_REVIEW = '4';

    // Error 54: "The referenced transaction does not meet the criteria for issuing a credit."
    // Returned when refunding a charge that has not settled yet.
    const ERROR_NOT_SETTLED = '54';

    // "A duplicate record with ID 123 already exists." (customer profile, or payment profile).
    const ERROR_DUPLICATE = 'E00039';
    // "The record cannot be found."
    const ERROR_NOT_FOUND = 'E00040';
    // getTransactionListForCustomer: "No records found."
    const NO_RECORDS = 'I00004';

    // Letters, digits and underscores only (Webhooks API).
    const WEBHOOK_NAME = 'waas_payments';

    // getTransactionDetails transactionStatus values, by what they mean for a charge.
    const PAID_STATUSES = ['capturedPendingSettlement', 'settledSuccessfully', 'approvedReview'];
    const PENDING_STATUSES = ['FDSPendingReview', 'FDSAuthorizedPendingReview', 'underReview', 'authorizedPendingCapture'];

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

        // A new customer's card is saved by the charge itself. An existing customer's card is
        // added to their profile after the charge (saveFromCharge), because createProfile
        // would only report that the customer already exists.
        $createProfile = $request->savePaymentMethod && $request->customerRef === '';
        if ($createProfile) {
            $transaction['profile'] = ['createProfile' => true];
        }

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

        if ($request->savePaymentMethod) {
            // Card-network stored-credential flag: the customer agrees to later charges.
            $transaction['processingOptions'] = ['isFirstRecurringPayment' => true];
        }

        $response = $this->client->send('createTransactionRequest', ['transactionRequest' => $transaction]);
        $result = $this->transactionResult($response);

        if (!$request->savePaymentMethod || !$result->success) {
            return $result;
        }

        $saved = $createProfile
            ? $this->savedProfileFromCharge($response, $result->transactionId)
            : $this->saveFromCharge($result->transactionId, $request->customerRef);

        if ($saved->success) {
            $result->data['customer_ref'] = $saved->data['customer_ref'];
            $result->data['method_ref'] = $saved->data['method_ref'];
        } else {
            // The customer was charged; only saving the card failed.
            $result->data['save_error'] = $saved->message;
        }

        return $result;
    }

    public function chargeSaved(SavedChargeRequest $request): DriverResult
    {
        $transaction = [
            'transactionType' => 'authCaptureTransaction',
            'amount'          => self::formatAmount($request->amount),
            'profile'         => [
                'customerProfileId' => $request->customerRef,
                'paymentProfile'    => ['paymentProfileId' => $request->methodRef],
            ],
        ];

        $order = array_filter([
            'invoiceNumber' => self::limit($request->invoiceNumber, 20),
            'description'   => self::limit($request->description, 255),
        ]);
        if ($order) {
            $transaction['order'] = $order;
        }

        // Merchant-initiated charge of a stored card (card-network stored-credential rules).
        $transaction['processingOptions'] = ['isSubsequentAuth' => true, 'isStoredCredentials' => true];
        if ($request->originalNetworkTransactionId !== '') {
            $transaction['subsequentAuthInformation'] = ['originalNetworkTransId' => $request->originalNetworkTransactionId];
        }

        return $this->transactionResult($this->client->send('createTransactionRequest', ['transactionRequest' => $transaction]));
    }

    public function savePaymentMethod(SavePaymentMethodRequest $request): DriverResult
    {
        $paymentProfile = [];
        $billTo = self::billTo($request->billing);
        if ($billTo) {
            $paymentProfile['billTo'] = $billTo;
        }
        $paymentProfile['payment'] = [
            'opaqueData' => [
                'dataDescriptor' => $request->tokenDescriptor,
                'dataValue'      => $request->tokenValue,
            ],
        ];

        // liveMode checks the card with a $0 authorization, which Authorize.Net only runs
        // with a full billing address; without one, testMode checks the format only.
        $validationMode = self::isCompleteAddress($billTo) ? 'liveMode' : 'testMode';

        if ($request->customerRef === '') {
            $response = $this->client->send('createCustomerProfileRequest', [
                'profile'        => array_filter([
                    'merchantCustomerId' => self::limit($request->customerId, 20),
                    'email'              => self::limit($request->customerEmail, 255),
                    'paymentProfiles'    => [$paymentProfile],
                ]),
                'validationMode' => $validationMode,
            ]);

            $existing = self::duplicateId($response);
            if ($existing === '') {
                return $this->savedResult($response, (string) ($response['customerProfileId'] ?? ''), self::lastId($response['customerPaymentProfileIdList'] ?? []), $response['validationDirectResponseList'][0] ?? '');
            }

            // The customer already has a profile (an earlier subscription): add the card to it.
            $request = clone $request;
            $request->customerRef = $existing;
        }

        $response = $this->client->send('createCustomerPaymentProfileRequest', [
            'customerProfileId' => $request->customerRef,
            'paymentProfile'    => $paymentProfile,
            'validationMode'    => $validationMode,
        ]);

        // The same card is already saved: Authorize.Net names the existing payment profile.
        $methodRef = (string) ($response['customerPaymentProfileId'] ?? '');
        if ($methodRef !== '' && self::apiErrorCode($response) === self::ERROR_DUPLICATE) {
            $response['messages']['resultCode'] = 'Ok';
        }

        return $this->savedResult($response, $request->customerRef, $methodRef, $response['validationDirectResponse'] ?? '');
    }

    public function saveFromCharge(string $transactionId, string $customerRef): DriverResult
    {
        $fields = ['transId' => $transactionId];
        if ($customerRef !== '') {
            $fields['customerProfileId'] = $customerRef;
        }

        $response = $this->client->send('createCustomerProfileFromTransactionRequest', $fields);

        // The customer already has a profile: add the card to it.
        $existing = $customerRef === '' ? self::duplicateId($response) : '';
        if ($existing !== '') {
            return $this->saveFromCharge($transactionId, $existing);
        }

        return $this->savedResult($response, (string) ($response['customerProfileId'] ?? $customerRef), self::lastId($response['customerPaymentProfileIdList'] ?? []));
    }

    public function deleteSavedPaymentMethod(string $customerRef, string $methodRef): DriverResult
    {
        $response = $this->client->send('deleteCustomerPaymentProfileRequest', [
            'customerProfileId'        => $customerRef,
            'customerPaymentProfileId' => $methodRef,
        ]);

        if (is_wp_error($response)) {
            return DriverResult::failure(self::unreachable($response), 'http_error');
        }

        // Already deleted counts as deleted.
        if (self::isOk($response) || self::apiErrorCode($response) === self::ERROR_NOT_FOUND) {
            return DriverResult::success('');
        }

        return self::apiFailure($response);
    }

    public function chargeStatus(string $transactionId): DriverResult
    {
        $response = $this->client->send('getTransactionDetailsRequest', ['transId' => $transactionId]);

        if (is_wp_error($response)) {
            return DriverResult::failure(self::unreachable($response), 'http_error');
        }

        if (!self::isOk($response)) {
            return self::apiFailure($response);
        }

        $transaction = $response['transaction'] ?? [];
        $status = (string) ($transaction['transactionStatus'] ?? '');

        return DriverResult::success($transactionId, $status, [
            'state'              => self::stateOf($status),
            'transaction_status' => $status,
            'transaction_type'   => (string) ($transaction['transactionType'] ?? ''),
            'amount'             => (int) round(((float) ($transaction['settleAmount'] ?? $transaction['authAmount'] ?? 0)) * 100),
            // What was charged or refunded; settleAmount drops to 0 when a charge is voided.
            'auth_amount'        => (int) round(((float) ($transaction['authAmount'] ?? 0)) * 100),
            'card_last4'         => substr((string) ($transaction['payment']['creditCard']['cardNumber'] ?? ''), -4),
            'card_brand'         => (string) ($transaction['payment']['creditCard']['cardType'] ?? ''),
            'auth_code'          => (string) ($transaction['authCode'] ?? ''),
            'avs_result'         => (string) ($transaction['AVSResponse'] ?? ''),
            'cvv_result'         => (string) ($transaction['cardCodeResponse'] ?? ''),
            'reason'             => (string) ($transaction['responseReasonDescription'] ?? ''),
            // A refund's original charge.
            'ref_trans_id'       => (string) ($transaction['refTransId'] ?? ''),
            'invoice_number'     => (string) ($transaction['order']['invoiceNumber'] ?? ''),
            'customer_id'        => (string) ($transaction['customer']['id'] ?? ''),
            'customer_email'     => (string) ($transaction['customer']['email'] ?? ''),
            // Set when a saved card was charged.
            'customer_ref'       => (string) ($transaction['profile']['customerProfileId'] ?? ''),
            'network_trans_id'   => (string) ($transaction['networkTransId'] ?? ''),
        ]);
    }

    public function findSavedCharge(string $customerRef, string $invoiceNumber, int $amount): DriverResult
    {
        $response = $this->client->send('getTransactionListForCustomerRequest', [
            'customerProfileId' => $customerRef,
            'sorting'           => ['orderBy' => 'submitTimeUTC', 'orderDescending' => true],
            'paging'            => ['limit' => 100, 'offset' => 1],
        ]);

        if (is_wp_error($response)) {
            return DriverResult::failure(self::unreachable($response), 'http_error');
        }

        if (!self::isOk($response)) {
            return self::apiFailure($response);
        }

        $invoiceNumber = self::limit($invoiceNumber, 20);
        foreach ((array) ($response['transactions'] ?? []) as $transaction) {
            if ((string) ($transaction['invoiceNumber'] ?? '') !== $invoiceNumber
                || (int) round(((float) ($transaction['settleAmount'] ?? 0)) * 100) !== $amount) {
                continue;
            }

            $status = (string) ($transaction['transactionStatus'] ?? '');

            return DriverResult::success((string) $transaction['transId'], $status, [
                'state'              => self::stateOf($status),
                'transaction_status' => $status,
                'card_last4'         => substr((string) ($transaction['accountNumber'] ?? ''), -4),
                'card_brand'         => (string) ($transaction['accountType'] ?? ''),
            ]);
        }

        return DriverResult::failure(__('Authorize.Net has no charge for this invoice.', 'waas-payments'), 'not_found');
    }

    /** The profile a createProfile charge made, or the existing one it collided with. */
    private function savedProfileFromCharge(array $response, string $transactionId): DriverResult
    {
        $profile = $response['profileResponse'] ?? [];

        $existing = self::duplicateId($profile);
        if ($existing !== '') {
            return $this->saveFromCharge($transactionId, $existing);
        }

        return $this->savedResult($profile, (string) ($profile['customerProfileId'] ?? ''), self::lastId($profile['customerPaymentProfileIdList'] ?? []));
    }

    /**
     * @param array|\WP_Error $response
     */
    private function savedResult($response, string $customerRef, string $methodRef, string $directResponse = ''): DriverResult
    {
        if (is_wp_error($response)) {
            return DriverResult::failure(self::unreachable($response), 'http_error');
        }

        $fields = $directResponse !== '' ? str_getcsv($directResponse, ',', '"', '\\') : [];

        if (!self::isOk($response) || $customerRef === '' || $methodRef === '') {
            // A failed $0 check explains itself in the direct response (field 4: reason text).
            if (isset($fields[3]) && ($fields[0] ?? '') !== '1' && $fields[3] !== '') {
                return DriverResult::failure((string) $fields[3], (string) ($fields[2] ?? ''));
            }
            return self::apiFailure($response);
        }

        // Direct response fields 51 and 52: masked card number and card type.
        return DriverResult::success('', '', [
            'customer_ref' => $customerRef,
            'method_ref'   => $methodRef,
            'card_last4'   => substr((string) ($fields[50] ?? ''), -4),
            'card_brand'   => (string) ($fields[51] ?? ''),
        ]);
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
     * Makes sure Authorize.Net sends these events to $url: updates this site's webhook, or
     * creates it. Authorize.Net only creates webhooks once the account has a Signature Key.
     * Webhooks REST API: https://developer.authorize.net/api/reference/features/webhooks.html
     *
     * @param string[] $eventTypes
     */
    public function registerWebhook(string $url, array $eventTypes): DriverResult
    {
        $webhooks = $this->client->rest('GET', 'webhooks');
        if (is_wp_error($webhooks)) {
            return DriverResult::failure(self::unreachable($webhooks), 'http_error');
        }
        if (isset($webhooks['status'], $webhooks['reason'])) {
            return self::restFailure($webhooks);
        }

        $body = [
            'name'       => self::WEBHOOK_NAME,
            'url'        => $url,
            'eventTypes' => array_values($eventTypes),
            'status'     => 'active',
        ];

        $existing = null;
        foreach ((array) $webhooks as $webhook) {
            if (is_array($webhook) && ($webhook['url'] ?? '') === $url) {
                $existing = $webhook;
                break;
            }
        }

        $response = $existing
            ? $this->client->rest('PUT', 'webhooks/' . rawurlencode((string) $existing['webhookId']), $body)
            : $this->client->rest('POST', 'webhooks', $body);

        if (is_wp_error($response)) {
            return DriverResult::failure(self::unreachable($response), 'http_error');
        }
        if (empty($response['webhookId'])) {
            return self::restFailure($response);
        }

        return DriverResult::success((string) $response['webhookId'], (string) ($response['status'] ?? ''));
    }

    /** An error from the Webhooks REST API ({status, reason, message, details}). */
    private static function restFailure($response): DriverResult
    {
        $message = (string) ($response['message'] ?? '');
        foreach ((array) ($response['details'] ?? []) as $detail) {
            $message .= ' ' . (string) ($detail['message'] ?? '');
        }

        // Authorize.Net answers a generic error when the account has no Signature Key.
        if (($response['reason'] ?? '') === 'INVALID_DATA' && empty($response['details'])) {
            $message = __('Authorize.Net would not create the webhook. Check that the account has a Signature Key (Account > Settings > Security Settings > API Credentials and Keys).', 'waas-payments');
        }

        return DriverResult::failure(trim($message) ?: __('Authorize.Net did not accept the webhook.', 'waas-payments'), (string) ($response['reason'] ?? ''));
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
                    'network_trans_id' => (string) ($transaction['networkTransId'] ?? ''),
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

    private static function isOk($response): bool
    {
        return is_array($response) && ($response['messages']['resultCode'] ?? '') === 'Ok';
    }

    private static function apiErrorCode($response): string
    {
        return (string) ($response['messages']['message'][0]['code'] ?? '');
    }

    /** The existing record's ID from an E00039 duplicate error, or ''. */
    private static function duplicateId($response): string
    {
        if (self::apiErrorCode($response) !== self::ERROR_DUPLICATE) {
            return '';
        }

        return preg_match('/ID (\d+)/', (string) ($response['messages']['message'][0]['text'] ?? ''), $match) ? $match[1] : '';
    }

    private static function apiFailure($response): DriverResult
    {
        $message = $response['messages']['message'][0] ?? [];

        return DriverResult::failure(
            (string) ($message['text'] ?? __('Authorize.Net did not accept the request.', 'waas-payments')),
            (string) ($message['code'] ?? '')
        );
    }

    private static function lastId($ids): string
    {
        $ids = array_values((array) $ids);

        return $ids ? (string) end($ids) : '';
    }

    private static function stateOf(string $transactionStatus): string
    {
        if (in_array($transactionStatus, self::PAID_STATUSES, true)) {
            return self::STATE_PAID;
        }

        if (in_array($transactionStatus, self::PENDING_STATUSES, true)) {
            return self::STATE_PENDING;
        }

        return self::STATE_FAILED;
    }

    /** customerAddressType, in schema order, without empty fields. */
    private static function billTo(array $billing): array
    {
        return array_filter([
            'firstName' => self::limit((string) ($billing['first_name'] ?? ''), 50),
            'lastName'  => self::limit((string) ($billing['last_name'] ?? ''), 50),
            'address'   => self::limit((string) ($billing['address'] ?? ''), 60),
            'city'      => self::limit((string) ($billing['city'] ?? ''), 40),
            'state'     => self::limit((string) ($billing['state'] ?? ''), 40),
            'zip'       => self::limit((string) ($billing['zip'] ?? ''), 20),
            'country'   => self::limit((string) ($billing['country'] ?? ''), 60),
        ], 'strlen');
    }

    private static function isCompleteAddress(array $billTo): bool
    {
        foreach (['firstName', 'lastName', 'address', 'city', 'state', 'zip', 'country'] as $field) {
            if (!isset($billTo[$field])) {
                return false;
            }
        }

        return true;
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
