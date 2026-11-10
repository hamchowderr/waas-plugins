<?php

namespace AnyPressPayments\Driver\AuthorizeNet;

use AnyPressPayments\Driver\DriverResult;

/** Reads AuthorizeNetDriver's API answers into DriverResults. */
final class AuthorizeNetResponses
{
    /**
     * @param array|\WP_Error $response
     */
    public static function savedResult($response, string $customerRef, string $methodRef, string $directResponse = ''): DriverResult
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

    /** An error from the Webhooks REST API ({status, reason, message, details}). */
    public static function restFailure($response): DriverResult
    {
        $message = (string) ($response['message'] ?? '');
        foreach ((array) ($response['details'] ?? []) as $detail) {
            $message .= ' ' . (string) ($detail['message'] ?? '');
        }

        // Authorize.Net answers a generic error when the account has no Signature Key.
        if (($response['reason'] ?? '') === 'INVALID_DATA' && empty($response['details'])) {
            $message = __('Authorize.Net would not create the webhook. Check that the account has a Signature Key (Account > Settings > Security Settings > API Credentials and Keys).', 'anypress-payments');
        }

        return DriverResult::failure(trim($message) ?: __('Authorize.Net did not accept the webhook.', 'anypress-payments'), (string) ($response['reason'] ?? ''));
    }

    /**
     * @param array|\WP_Error $response
     */
    public static function transactionResult($response): DriverResult
    {
        if (is_wp_error($response)) {
            return DriverResult::failure(self::unreachable($response), 'http_error');
        }

        $transaction = $response['transactionResponse'] ?? [];
        $responseCode = (string) ($transaction['responseCode'] ?? '');
        $transactionId = (string) ($transaction['transId'] ?? '');

        if ($responseCode === AuthorizeNetDriver::APPROVED && ($response['messages']['resultCode'] ?? '') === 'Ok') {
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

        if ($responseCode === AuthorizeNetDriver::HELD_FOR_REVIEW) {
            // Authorized but held by the merchant's fraud filters; it is only paid once the
            // merchant approves it in Authorize.Net.
            return DriverResult::failure(
                __('The payment is being held for review by the store. You have not been charged yet.', 'anypress-payments'),
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
            (string) ($message['text'] ?? __('Authorize.Net did not approve the transaction.', 'anypress-payments')),
            (string) ($message['code'] ?? ''),
            [],
            $transactionId
        );
    }

    public static function isOk($response): bool
    {
        return is_array($response) && ($response['messages']['resultCode'] ?? '') === 'Ok';
    }

    public static function apiErrorCode($response): string
    {
        return (string) ($response['messages']['message'][0]['code'] ?? '');
    }

    /** The existing record's ID from an E00039 duplicate error, or ''. */
    public static function duplicateId($response): string
    {
        if (self::apiErrorCode($response) !== AuthorizeNetDriver::ERROR_DUPLICATE) {
            return '';
        }

        return preg_match('/ID (\d+)/', (string) ($response['messages']['message'][0]['text'] ?? ''), $match) ? $match[1] : '';
    }

    public static function apiFailure($response): DriverResult
    {
        $message = $response['messages']['message'][0] ?? [];

        return DriverResult::failure(
            (string) ($message['text'] ?? __('Authorize.Net did not accept the request.', 'anypress-payments')),
            (string) ($message['code'] ?? '')
        );
    }

    public static function lastId($ids): string
    {
        $ids = array_values((array) $ids);

        return $ids ? (string) end($ids) : '';
    }

    public static function stateOf(string $transactionStatus): string
    {
        if (in_array($transactionStatus, AuthorizeNetDriver::PAID_STATUSES, true)) {
            return AuthorizeNetDriver::STATE_PAID;
        }

        if (in_array($transactionStatus, AuthorizeNetDriver::PENDING_STATUSES, true)) {
            return AuthorizeNetDriver::STATE_PENDING;
        }

        return AuthorizeNetDriver::STATE_FAILED;
    }

    public static function unreachable(\WP_Error $error): string
    {
        /* translators: %s: the network error */
        return sprintf(__('Could not reach Authorize.Net: %s', 'anypress-payments'), $error->get_error_message());
    }
}
