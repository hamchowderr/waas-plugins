<?php

namespace WaaSHost\Features;

/**
 * The volume discount rule: the more live websites a customer has, the more they save on a new one. A live website is
 * a website plan subscription in an active status, and the one being bought counts. The cart applies it
 * (FluentCartVolumeDiscount), and a customer's existing plans follow their tier from their next renewal
 * (FluentCartVolumeRepricing). Off until the shop owner turns it on, so the live shop's prices don't change on update.
 */
class VolumeDiscount
{
    public const ENABLED_OPTION = 'waas_volume_discount_enabled';

    /**
     * Fewest live websites (counting the one being bought) => percent off. The code is what the customer sees on the
     * cart's discount line and what the order records. Filterable with waas_volume_discount_tiers.
     */
    public const TIERS = [
        ['sites' => 3, 'percent' => 10, 'code' => 'VOLUME10'],
        ['sites' => 10, 'percent' => 20, 'code' => 'VOLUME20'],
        ['sites' => 25, 'percent' => 30, 'code' => 'VOLUME30'],
    ];

    public static function is_enabled(): bool
    {
        return get_option(self::ENABLED_OPTION, '') === 'on';
    }

    /**
     * The tiers, fewest websites first. Tiers a filter got wrong (no websites, no percent, a percent over 100, a code
     * used twice) are dropped rather than guessed at.
     *
     * @return array<int, array{sites: int, percent: float, code: string}>
     */
    public static function tiers(): array
    {
        $tiers = [];
        $codes = [];
        foreach ((array) apply_filters('waas_volume_discount_tiers', self::TIERS) as $tier) {
            if (!is_array($tier)) {
                continue;
            }

            $sites = (int) ($tier['sites'] ?? 0);
            $percent = round((float) ($tier['percent'] ?? 0), 2);
            $code = trim((string) ($tier['code'] ?? ''));
            if ($code === '') {
                $code = 'VOLUME' . rtrim(rtrim(number_format($percent, 2, '.', ''), '0'), '.');
            }
            if ($sites < 1 || $percent <= 0 || $percent > 100 || isset($codes[$code])) {
                continue;
            }

            $codes[$code] = true;
            $tiers[] = ['sites' => $sites, 'percent' => $percent, 'code' => $code];
        }

        usort($tiers, fn ($a, $b) => $a['sites'] <=> $b['sites']);

        return $tiers;
    }

    /**
     * The tier a customer with $sites live websites (the new one included) gets, or null below the first tier.
     *
     * @return array{sites: int, percent: float, code: string}|null
     */
    public static function tier_for(int $sites): ?array
    {
        $found = null;
        foreach (self::tiers() as $tier) {
            if ($sites >= $tier['sites']) {
                $found = $tier;
            }
        }

        return $found;
    }

    /** @return array{sites: int, percent: float, code: string}|null */
    public static function tier_with_code(string $code): ?array
    {
        foreach (self::tiers() as $tier) {
            if ($tier['code'] === $code) {
                return $tier;
            }
        }

        return null;
    }

    /** A percent without trailing zeros: 10 for 10.00, 12.5 for 12.50. */
    public static function format_percent(float $percent): string
    {
        return rtrim(rtrim(number_format($percent, 2, '.', ''), '0'), '.');
    }

    /** "10% off at 3 to 9 websites, 20% off at 10 to 24 websites, 30% off at 25 or more websites", for the settings page. */
    public static function describe_tiers(): string
    {
        $tiers = self::tiers();
        $parts = [];
        foreach ($tiers as $index => $tier) {
            $percent = self::format_percent($tier['percent']);
            $next = $tiers[$index + 1] ?? null;
            if ($next !== null && $next['sites'] - 1 > $tier['sites']) {
                /* translators: 1: percent off, 2: fewest websites, 3: most websites */
                $parts[] = sprintf(__('%1$s%% off at %2$d to %3$d websites', WAAS_HOST_TEXTDOMAIN), $percent, $tier['sites'], $next['sites'] - 1);
            } elseif ($next !== null) {
                /* translators: 1: percent off, 2: number of websites */
                $parts[] = sprintf(__('%1$s%% off at %2$d websites', WAAS_HOST_TEXTDOMAIN), $percent, $tier['sites']);
            } else {
                /* translators: 1: percent off, 2: fewest websites */
                $parts[] = sprintf(__('%1$s%% off at %2$d or more websites', WAAS_HOST_TEXTDOMAIN), $percent, $tier['sites']);
            }
        }

        return implode(', ', $parts);
    }
}
