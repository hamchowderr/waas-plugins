<?php

namespace AnyPressPayments\Driver\AuthorizeNet;

use AnyPressPayments\Driver\ChargeRequest;
use AnyPressPayments\Driver\DriverResult;
use AnyPressPayments\Driver\RecurringGatewayDriver;
use AnyPressPayments\Driver\RefundRequest;
use AnyPressPayments\Driver\SavedChargeRequest;
use AnyPressPayments\Driver\SavePaymentMethodRequest;

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
    public const APPROVED = '1';
    public const HELD_FOR_REVIEW = '4';

    // Error 54: "The referenced transaction does not meet the criteria for issuing a credit."
    // Returned when refunding a charge that has not settled yet.
    public const ERROR_NOT_SETTLED = '54';

    // "A duplicate record with ID 123 already exists." (customer profile, or payment profile).
    public const ERROR_DUPLICATE = 'E00039';
    // "The record cannot be found."
    public const ERROR_NOT_FOUND = 'E00040';
    // getTransactionListForCustomer: "No records found."

    // Letters, digits and underscores only (Webhooks API).
    public const WEBHOOK_NAME = 'anypress_payments';

    // getTransactionDetails transactionStatus values, by what they mean for a charge.
    public const PAID_STATUSES = ['capturedPendingSettlement', 'settledSuccessfully', 'approvedReview'];
    public const PENDING_STATUSES = ['FDSPendingReview', 'FDSAuthorizedPendingReview', 'underReview', 'authorizedPendingCapture'];

    private $client;

    public function __construct(AuthorizeNetClient $client)
    {
        $this->client = $client;
    }

    public function charge(ChargeRequest $request): DriverResult
    {
        $transaction = [
            'transactionType' => 'authCaptureTransaction',
            'amount'          => AuthorizeNetFields::formatAmount($request->amount),
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
            'invoiceNumber' => AuthorizeNetFields::limit($request->invoiceNumber, 20),
            'description'   => AuthorizeNetFields::limit($request->description, 255),
        ]);
        if ($order) {
            $transaction['order'] = $order;
        }

        $customer = array_filter([
            'id'    => AuthorizeNetFields::limit($request->customerId, 20),
            'email' => AuthorizeNetFields::limit($request->customerEmail, 255),
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
        $result = AuthorizeNetResponses::transactionResult($response);

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
            'amount'          => AuthorizeNetFields::formatAmount($request->amount),
            'profile'         => [
                'customerProfileId' => $request->customerRef,
                'paymentProfile'    => ['paymentProfileId' => $request->methodRef],
            ],
        ];

        $order = array_filter([
            'invoiceNumber' => AuthorizeNetFields::limit($request->invoiceNumber, 20),
            'description'   => AuthorizeNetFields::limit($request->description, 255),
        ]);
        if ($order) {
            $transaction['order'] = $order;
        }

        // Merchant-initiated charge of a stored card (card-network stored-credential rules).
        $transaction['processingOptions'] = ['isSubsequentAuth' => true, 'isStoredCredentials' => true];
        if ($request->originalNetworkTransactionId !== '') {
            $transaction['subsequentAuthInformation'] = ['originalNetworkTransId' => $request->originalNetworkTransactionId];
        }

        return AuthorizeNetResponses::transactionResult($this->client->send('createTransactionRequest', ['transactionRequest' => $transaction]));
    }

    public function savePaymentMethod(SavePaymentMethodRequest $request): DriverResult
    {
        $paymentProfile = [];
        $billTo = AuthorizeNetFields::billTo($request->billing);
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
        $validationMode = AuthorizeNetFields::isCompleteAddress($billTo) ? 'liveMode' : 'testMode';

        if ($request->customerRef === '') {
            $response = $this->client->send('createCustomerProfileRequest', [
                'profile'        => array_filter([
                    'merchantCustomerId' => AuthorizeNetFields::limit($request->customerId, 20),
                    'email'              => AuthorizeNetFields::limit($request->customerEmail, 255),
                    'paymentProfiles'    => [$paymentProfile],
                ]),
                'validationMode' => $validationMode,
            ]);

            $existing = AuthorizeNetResponses::duplicateId($response);
            if ($existing === '') {
                return AuthorizeNetResponses::savedResult($response, (string) ($response['customerProfileId'] ?? ''), AuthorizeNetResponses::lastId($response['customerPaymentProfileIdList'] ?? []), $response['validationDirectResponseList'][0] ?? '');
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
        if ($methodRef !== '' && AuthorizeNetResponses::apiErrorCode($response) === self::ERROR_DUPLICATE) {
            $response['messages']['resultCode'] = 'Ok';
        }

        return AuthorizeNetResponses::savedResult($response, $request->customerRef, $methodRef, $response['validationDirectResponse'] ?? '');
    }

    public function saveFromCharge(string $transactionId, string $customerRef): DriverResult
    {
        $fields = ['transId' => $transactionId];
        if ($customerRef !== '') {
            $fields['customerProfileId'] = $customerRef;
        }

        $response = $this->client->send('createCustomerProfileFromTransactionRequest', $fields);

        // The customer already has a profile: add the card to it.
        $existing = $customerRef === '' ? AuthorizeNetResponses::duplicateId($response) : '';
        if ($existing !== '') {
            return $this->saveFromCharge($transactionId, $existing);
        }

        return AuthorizeNetResponses::savedResult($response, (string) ($response['customerProfileId'] ?? $customerRef), AuthorizeNetResponses::lastId($response['customerPaymentProfileIdList'] ?? []));
    }

    public function deleteSavedPaymentMethod(string $customerRef, string $methodRef): DriverResult
    {
        $response = $this->client->send('deleteCustomerPaymentProfileRequest', [
            'customerProfileId'        => $customerRef,
            'customerPaymentProfileId' => $methodRef,
        ]);

        if (is_wp_error($response)) {
            return DriverResult::failure(AuthorizeNetResponses::unreachable($response), 'http_error');
        }

        // Already deleted counts as deleted.
        if (AuthorizeNetResponses::isOk($response) || AuthorizeNetResponses::apiErrorCode($response) === self::ERROR_NOT_FOUND) {
            return DriverResult::success('');
        }

        return AuthorizeNetResponses::apiFailure($response);
    }

    public function chargeStatus(string $transactionId): DriverResult
    {
        $response = $this->client->send('getTransactionDetailsRequest', ['transId' => $transactionId]);

        if (is_wp_error($response)) {
            return DriverResult::failure(AuthorizeNetResponses::unreachable($response), 'http_error');
        }

        if (!AuthorizeNetResponses::isOk($response)) {
            return AuthorizeNetResponses::apiFailure($response);
        }

        $transaction = $response['transaction'] ?? [];
        $status = (string) ($transaction['transactionStatus'] ?? '');

        return DriverResult::success($transactionId, $status, [
            'state'              => AuthorizeNetResponses::stateOf($status),
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
            return DriverResult::failure(AuthorizeNetResponses::unreachable($response), 'http_error');
        }

        if (!AuthorizeNetResponses::isOk($response)) {
            return AuthorizeNetResponses::apiFailure($response);
        }

        $invoiceNumber = AuthorizeNetFields::limit($invoiceNumber, 20);
        foreach ((array) ($response['transactions'] ?? []) as $transaction) {
            if (
                (string) ($transaction['invoiceNumber'] ?? '') !== $invoiceNumber
                || (int) round(((float) ($transaction['settleAmount'] ?? 0)) * 100) !== $amount
            ) {
                continue;
            }

            $status = (string) ($transaction['transactionStatus'] ?? '');

            return DriverResult::success((string) $transaction['transId'], $status, [
                'state'              => AuthorizeNetResponses::stateOf($status),
                'transaction_status' => $status,
                'card_last4'         => substr((string) ($transaction['accountNumber'] ?? ''), -4),
                'card_brand'         => (string) ($transaction['accountType'] ?? ''),
            ]);
        }

        return DriverResult::failure(__('Authorize.Net has no charge for this invoice.', 'anypress-payments'), 'not_found');
    }

    /** The profile a createProfile charge made, or the existing one it collided with. */
    private function savedProfileFromCharge(array $response, string $transactionId): DriverResult
    {
        $profile = $response['profileResponse'] ?? [];

        $existing = AuthorizeNetResponses::duplicateId($profile);
        if ($existing !== '') {
            return $this->saveFromCharge($transactionId, $existing);
        }

        return AuthorizeNetResponses::savedResult($profile, (string) ($profile['customerProfileId'] ?? ''), AuthorizeNetResponses::lastId($profile['customerPaymentProfileIdList'] ?? []));
    }

    public function refund(RefundRequest $request): DriverResult
    {
        if ($request->chargeId === '') {
            return DriverResult::failure(__('This payment has no Authorize.Net transaction ID to refund.', 'anypress-payments'), 'missing_charge_id');
        }

        $refund = AuthorizeNetResponses::transactionResult($this->client->send('createTransactionRequest', [
            'transactionRequest' => [
                'transactionType' => 'refundTransaction',
                'amount'          => AuthorizeNetFields::formatAmount($request->amount),
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
                __('This payment has not settled yet (Authorize.Net settles once a day), so only the full amount can be cancelled now. Refund the full amount, or wait until it settles to refund part of it.', 'anypress-payments'),
                self::ERROR_NOT_SETTLED
            );
        }

        $void = AuthorizeNetResponses::transactionResult($this->client->send('createTransactionRequest', [
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
            return DriverResult::failure(AuthorizeNetResponses::unreachable($response), 'http_error');
        }

        if (($response['messages']['resultCode'] ?? '') !== 'Ok') {
            $message = $response['messages']['message'][0] ?? [];
            return DriverResult::failure(
                /* translators: %s: the error from Authorize.Net */
                sprintf(__('Authorize.Net rejected the credentials: %s', 'anypress-payments'), $message['text'] ?? __('unknown error', 'anypress-payments')),
                (string) ($message['code'] ?? '')
            );
        }

        if (empty($response['publicClientKey'])) {
            return DriverResult::failure(
                __('This Authorize.Net account has no Public Client Key yet. Generate one in the Authorize.Net Merchant Interface under Account > Settings > Security Settings > General Security Settings > Manage Public Client Key, then save again.', 'anypress-payments'),
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
            return DriverResult::failure(AuthorizeNetResponses::unreachable($webhooks), 'http_error');
        }
        if (isset($webhooks['status'], $webhooks['reason'])) {
            return AuthorizeNetResponses::restFailure($webhooks);
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
            return DriverResult::failure(AuthorizeNetResponses::unreachable($response), 'http_error');
        }
        if (empty($response['webhookId'])) {
            return AuthorizeNetResponses::restFailure($response);
        }

        return DriverResult::success((string) $response['webhookId'], (string) ($response['status'] ?? ''));
    }
}
