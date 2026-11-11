<?php

namespace AnyPressClient\Crm\Importers;

/**
 * Adds Brevo and new MailerLite accounts to FluentCRM's own Import screen ("Migrate from another CRM"), next to its
 * Mailchimp, ConvertKit, MailerLite (Classic), Drip and ActiveCampaign importers, through FluentCRM's
 * fluent_crm/saas_migrators and fluent_crm/migrator_driver_class filters. Both filters only run inside FluentCRM, so
 * the importers (which extend FluentCRM's BaseMigrator) load only when it is active.
 */
final class CrmImporters
{
    private const DRIVERS = [
        BrevoMigrator::DRIVER => BrevoMigrator::class,
        MailerLiteMigrator::DRIVER => MailerLiteMigrator::class,
    ];

    public static function register(): void
    {
        add_filter('fluent_crm/saas_migrators', [self::class, 'migrators']);
        add_filter('fluent_crm/migrator_driver_class', [self::class, 'driver'], 10, 2);
    }

    /** @param mixed $migrators */
    public static function migrators($migrators): array
    {
        $migrators = is_array($migrators) ? $migrators : [];
        if (!class_exists('FluentCrm\App\Services\CrmMigrator\BaseMigrator')) {
            return $migrators;
        }
        foreach (self::DRIVERS as $key => $class) {
            $migrators[$key] = (new $class())->getInfo();
        }

        return $migrators;
    }

    /**
     * @param mixed $class
     * @param mixed $driver
     * @return mixed
     */
    public static function driver($class, $driver)
    {
        return $class ?: (self::DRIVERS[(string) $driver] ?? $class);
    }
}
