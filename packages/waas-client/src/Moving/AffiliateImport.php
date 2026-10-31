<?php

namespace WaaSClient\Moving;

/**
 * Affiliates into FluentAffiliate, with FluentAffiliate's own migrators (AffiliateWP, Affiliate Manager,
 * Solid Affiliate, SliceWP or Ultimate Affiliate Pro on the same website): affiliate groups, affiliates, referrals,
 * customers, payouts and visits, with earnings recounted. FluentAffiliate keeps the source ids, so, as in its own
 * screen, it only runs into a FluentAffiliate that holds nothing yet.
 */
class AffiliateImport extends NativeImport
{
    private const NS = '\FluentAffiliate\App\Services\Migrator\Providers\\';
    /** slug => [name, the constant that says the plugin is active, FluentAffiliate's migrator class]. */
    private const SOURCES = [
        'affiliate_wp' => ['AffiliateWP', 'AFFILIATEWP_VERSION', 'AffiliateWP'],
        'affiliate_manager' => ['Affiliates Manager', 'WPAM_PLUGIN_FILE', 'AffiliateManagerMigrator'],
        'solid_affiliate' => ['Solid Affiliate', 'SOLID_AFFILIATE_DIR', 'SolidAffiliate'],
        'slicewp' => ['SliceWP', 'SLICEWP_VERSION', 'SliceWP'],
        'ultimate_affiliate' => ['Ultimate Affiliate Pro', 'UAP_PLUGIN_VER', 'UltimateAffiliate'],
    ];
    private const STAGES = [
        'affiliate_groups' => 'migrateAffiliateGroups',
        'affiliates' => 'migrateAffiliates',
        'referrals' => 'migrateReferrals',
        'customers' => 'migrateCustomers',
        'payouts' => 'migratePayouts',
        'visits' => 'migrateVisits',
        'creatives' => 'migrateCreatives',
    ];
    /** FluentAffiliate's tables (without the prefix) => the WHERE that picks its rows. */
    private const TABLES = [
        'affiliate_groups' => ['fa_meta', "object_type = 'affiliate_group'"],
        'affiliates' => ['fa_affiliates', '1=1'],
        'referrals' => ['fa_referrals', '1=1'],
        'customers' => ['fa_customers', '1=1'],
        'payouts' => ['fa_payouts', '1=1'],
        'payout_transactions' => ['fa_payout_transactions', '1=1'],
        'visits' => ['fa_visits', '1=1'],
    ];

    public function key(): string
    {
        return 'affiliate';
    }

    public function target_name(): string
    {
        return 'FluentAffiliate';
    }

    protected function target_version(): string
    {
        return defined('FLUENT_AFFILIATE_VERSION') && class_exists(self::NS . 'SliceWP') ? (string) FLUENT_AFFILIATE_VERSION : '';
    }

    private static function migrator(string $source)
    {
        $class = self::NS . self::SOURCES[$source][2];

        return new $class();
    }

    protected function sources(): array
    {
        $sources = [];
        foreach (self::SOURCES as $slug => [$name, $constant]) {
            $found = defined($constant);
            $counts = [];
            if ($found && $this->target_version() !== '') {
                $all = (array) self::migrator($slug)->getCounts();
                foreach (['affiliate_groups', 'affiliates', 'referrals', 'customers', 'payouts', 'visits', 'creatives'] as $key) {
                    $counts[$key] = (int) ($all[$key] ?? 0);
                }
            }
            $sources[$slug] = ['name' => $name, 'found' => $found, 'counts' => $counts];
        }

        return $sources;
    }

    protected function target_counts(): array
    {
        $counts = [];
        foreach (self::TABLES as $key => [$table, $where]) {
            $counts[$key] = self::count($table, $where);
        }

        return $counts;
    }

    protected function target_blocker(array $counts): string
    {
        if (array_sum($counts)) {
            return __('FluentAffiliate already holds affiliates, referrals or payouts. Its importer keeps the source ids, so it only brings affiliates into an empty FluentAffiliate. Move these by hand, or clear FluentAffiliate first if they are only tests.', 'waas-client');
        }

        return '';
    }

    protected function import(string $source, array $before): array
    {
        $migrator = self::migrator($source);
        if (class_exists('\FluentAffiliate\App\Services\Migrator\BaseMigrator')) {
            \FluentAffiliate\App\Services\Migrator\BaseMigrator::clearAffiliateIdMaps();
        }
        // As FluentAffiliate's own command line runs it: a fresh status, then each stage until "completed".
        $migrator->updateCurrentStatus([], false);
        $status = $migrator->getCurrentStatus();
        $status['current_stage'] = 'affiliate_groups';
        $migrator->updateCurrentStatus($status, false);
        for ($guard = 0; $guard < 100000; $guard++) {
            $migrator->setTimeLimit(max(30, (int) \FluentAffiliate\App\Helper\Utility::getMaxRunTime()));
            $status = $migrator->getCurrentStatus();
            $stage = (string) ($status['current_stage'] ?? 'affiliate_groups');
            if ($stage === 'completed' || !isset(self::STAGES[$stage])) {
                break;
            }
            $migrator->{self::STAGES[$stage]}($status);
        }
        // FluentAffiliate was empty before, so everything up to these ids came from the import.
        $upto = [];
        foreach (self::TABLES as $key => [$table]) {
            $upto[$key] = self::max_id($table);
        }

        return ['upto' => $upto, 'skipped' => 0];
    }

    protected function compare_keys(): array
    {
        return [
            'affiliate_groups' => 'affiliate_groups',
            'affiliates' => 'affiliates',
            'referrals' => 'referrals',
            'customers' => 'customers',
            'payouts' => 'payouts',
            'visits' => 'visits',
        ];
    }

    protected function remove(array $state, bool $dry_run): array
    {
        $upto = (array) ($state['upto'] ?? []);
        if (!$upto && empty($state['done'])) {
            // The run died before it finished: FluentAffiliate was empty before, so all of it came from the import.
            foreach (self::TABLES as $key => [$table]) {
                $upto[$key] = self::max_id($table);
            }
        }
        $removed = [];
        foreach (self::TABLES as $key => [$table, $where]) {
            $removed[$key] = self::delete_range($table, 0, (int) ($upto[$key] ?? 0), $dry_run, 'id', $where === '1=1' ? '' : $where);
        }
        if (!$dry_run) {
            $prefix = ['affiliate_wp' => 'affwp', 'affiliate_manager' => 'wpam', 'solid_affiliate' => 'solid_affiliate', 'slicewp' => 'slicewp', 'ultimate_affiliate' => 'ultimate_affiliate'][$state['source']] ?? '';
            if ($prefix !== '') {
                delete_option('_fla_' . $prefix . '_migrations_status');
            }
            if (class_exists('\FluentAffiliate\App\Services\Migrator\BaseMigrator')) {
                \FluentAffiliate\App\Services\Migrator\BaseMigrator::clearAffiliateIdMaps();
            }
        }

        return $removed;
    }

    protected function unsupported(): array
    {
        return [
            'yith-woocommerce-affiliates/init.php' => 'YITH WooCommerce Affiliates',
            'affiliate-for-woocommerce/affiliate-for-woocommerce.php' => 'Affiliate for WooCommerce',
            'easy-affiliate/easy-affiliate.php' => 'Easy Affiliate',
        ];
    }

    protected function lost(string $source): array
    {
        return [
            __('Affiliate program settings (commission rates, cookie length, payout methods): set them again in FluentAffiliate.', 'waas-client'),
            __('Creatives (banners and links) need FluentAffiliate Pro.', 'waas-client'),
        ];
    }
}
