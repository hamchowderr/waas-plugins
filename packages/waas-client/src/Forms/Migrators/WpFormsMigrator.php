<?php

namespace WaaSClient\Forms\Migrators;

use FluentForm\App\Services\Migrator\Classes\WpFormsMigrator as FluentWpFormsMigrator;
use WaaSClient\Forms\FormFixes;

/**
 * Fluent Forms' own WPForms importer, with the confirmations put right: the importer makes the last confirmation
 * the form's main one, so a conditional redirect becomes what every visitor gets. Here the first confirmation that
 * applies to every submission is the main one, and the conditional ones stay conditional.
 *
 * Only loaded when Fluent Forms is active (the parent class must exist).
 */
class WpFormsMigrator extends FluentWpFormsMigrator
{
    use SourceAccess;

    protected function getFormMetas($form)
    {
        $metas = parent::getFormMetas($form);
        [$main, $others] = FormFixes::wpforms_confirmations(
            $metas['formSettings']['confirmation'] ?? null,
            is_array($metas['confirmations'] ?? null) ? $metas['confirmations'] : []
        );
        if ($main) {
            $metas['formSettings']['confirmation'] = $main;
        }
        $metas['confirmations'] = $others;
        if (!$others) {
            unset($metas['confirmations']);
        }

        return $metas;
    }
}
