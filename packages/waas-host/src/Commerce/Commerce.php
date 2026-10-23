<?php

namespace WaaSHost\Commerce;

use LogicException;
use WaaSHost\Core\ProvisioningApi;

/**
 * Registry of cart adapters. The first registered adapter whose cart is active
 * wins; the waas_commerce_adapter filter can pick another.
 */
final class Commerce
{
    /** @var CommerceAdapter[] */
    private static array $adapters = [];
    private static ?CommerceAdapter $active = null;
    private static bool $resolved = false;

    public static function register(CommerceAdapter $adapter): void
    {
        self::$adapters[$adapter->get_id()] = $adapter;
        self::$resolved = false;
    }

    public static function adapter(): ?CommerceAdapter
    {
        if (!self::$resolved) {
            $available = array_values(array_filter(self::$adapters, fn (CommerceAdapter $a) => $a->is_available()));
            $active = apply_filters('waas_commerce_adapter', $available[0] ?? null, self::$adapters);
            self::$active = $active instanceof CommerceAdapter ? $active : null;
            self::$resolved = true;
        }

        return self::$active;
    }

    /**
     * For code that only runs because an adapter fired an event.
     *
     * @throws LogicException when no cart adapter is active
     */
    public static function require_adapter(): CommerceAdapter
    {
        $adapter = self::adapter();
        if ($adapter === null) {
            throw new LogicException('No supported cart is active (WooCommerce or FluentCart).');
        }

        return $adapter;
    }

    /** Boot the active adapter, if any. */
    public static function boot(ProvisioningApi $provisioning_api): void
    {
        $adapter = self::adapter();
        if ($adapter !== null) {
            $adapter->boot($provisioning_api);
        }
    }

    /** Tests only. */
    public static function reset(): void
    {
        self::$adapters = [];
        self::$active = null;
        self::$resolved = false;
    }
}
