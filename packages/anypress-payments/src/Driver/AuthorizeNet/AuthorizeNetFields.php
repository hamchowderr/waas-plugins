<?php

namespace AnyPressPayments\Driver\AuthorizeNet;

/** Request fields for AuthorizeNetDriver, shaped and cut to the lengths AnetApiSchema.xsd allows. */
final class AuthorizeNetFields
{
    /** customerAddressType, in schema order, without empty fields. */
    public static function billTo(array $billing): array
    {
        return array_filter([
            'firstName' => self::limit((string) ($billing['first_name'] ?? ''), 50),
            'lastName'  => self::limit((string) ($billing['last_name'] ?? ''), 50),
            'address'   => self::limit((string) ($billing['address'] ?? ''), 60),
            'city'      => self::limit((string) ($billing['city'] ?? ''), 40),
            'state'     => self::limit((string) ($billing['state'] ?? ''), 40),
            'zip'       => self::limit((string) ($billing['zip'] ?? ''), 20),
            'country'   => self::limit((string) ($billing['country'] ?? ''), 60),
        ], static fn ($value) => (string) $value !== '');
    }

    public static function isCompleteAddress(array $billTo): bool
    {
        foreach (['firstName', 'lastName', 'address', 'city', 'state', 'zip', 'country'] as $field) {
            if (!isset($billTo[$field])) {
                return false;
            }
        }

        return true;
    }

    public static function formatAmount(int $cents): string
    {
        return number_format($cents / 100, 2, '.', '');
    }

    public static function limit(string $value, int $length): string
    {
        return function_exists('mb_substr') ? mb_substr($value, 0, $length) : substr($value, 0, $length);
    }
}
