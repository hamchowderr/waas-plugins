<?php

namespace AnyPressMove;

if (!defined('ABSPATH')) {
    exit;
}

use AnyPressMove\Lib\Crm\CrmAbilities;
use AnyPressMove\Lib\Crm\CrmCommand;
use AnyPressMove\Lib\Crm\CrmSwitch;
use AnyPressMove\Lib\Forms\ConditionalConfirmations;
use AnyPressMove\Lib\Forms\FormsAbilities;
use AnyPressMove\Lib\Forms\FormsCommand;
use AnyPressMove\Lib\Forms\FormsSwitch;
use AnyPressMove\Lib\Moving\AffiliateImport;
use AnyPressMove\Lib\Moving\CalendlyImport;
use AnyPressMove\Lib\Moving\CircleImport;
use AnyPressMove\Lib\Moving\CommunityImport;
use AnyPressMove\Lib\Moving\CourseImport;
use AnyPressMove\Lib\Moving\ImportAbilities;
use AnyPressMove\Lib\Moving\ImportCommand;
use AnyPressMove\Lib\Moving\MemberPressImport;
use AnyPressMove\Lib\Moving\SkoolImport;
use AnyPressMove\Lib\Moving\SupportImport;
use AnyPressMove\Lib\Shop\ShopAbilities;
use AnyPressMove\Lib\Shop\ShopCommand;
use AnyPressMove\Lib\Shop\ShopSwitch;

/**
 * The same switches AnyPress Client runs on AnyPress websites, for any WordPress site: the Move to Fluent page under
 * Tools, the check-* and switch-* abilities and `wp anypress <switch> switch`.
 */
class Plugin
{
    public static function start(): void
    {
        new Category();
        // Fluent Forms copies of conditional confirmations need this filter on every request after the forms switch.
        new ConditionalConfirmations();

        $forms = new FormsSwitch();
        $shop = new ShopSwitch();
        $crm = new CrmSwitch();
        $imports = [new SupportImport(), new AffiliateImport(), new CommunityImport(), new CourseImport(), new CircleImport(), new SkoolImport(), new CalendlyImport(), new MemberPressImport()];

        new FormsAbilities($forms);
        new ShopAbilities($shop);
        new CrmAbilities($crm);
        new ImportAbilities(...$imports);
        new MovePage($forms, $shop, $crm, ...$imports);

        if (defined('WP_CLI') && WP_CLI) {
            \WP_CLI::add_command('anypress forms', FormsCommand::class);
            \WP_CLI::add_command('anypress shop', ShopCommand::class);
            \WP_CLI::add_command('anypress crm', CrmCommand::class);
            foreach ($imports as $import) {
                \WP_CLI::add_command('anypress ' . $import->key(), new ImportCommand($import));
            }
        }
    }
}
