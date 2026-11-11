<?php

namespace AnyPressClient\Forms\Migrators;

use FluentForm\App\Services\Migrator\Classes\GravityFormsMigrator;
use AnyPressClient\Forms\GravityFixes;

/**
 * Fluent Forms' own Gravity Forms importer, with its known mistakes put right where they happen (see GravityFixes):
 * name and address parts shown or hidden as in Gravity Forms, a one-box name field read as text, dates and times in
 * their own format, the real entry limits, schedule and messages, the default confirmation as the main one, and only
 * the notifications sent on submission. An address entry with an empty part no longer stops the import (the importer
 * pairs the parts it found with all six part names). Entries also keep the visitor's IP address, browser and page.
 *
 * Only loaded when Fluent Forms is active (the parent class must exist).
 */
class GravityMigrator extends GravityFormsMigrator
{
    use SourceAccess;

    /** Per entry, in import order: the visitor's IP, browser and page, which the importer leaves empty. */
    public $entryDetails = [];

    public function getFields($form)
    {
        $source = GravityFixes::readable_fields((array) ($form['fields'] ?? []));
        $form['fields'] = $source;
        $fields = parent::getFields($form);
        if (is_array($fields) && !empty($fields['fields'])) {
            $fields['fields'] = GravityFixes::fields($fields['fields'], $source);
        }

        return $fields;
    }

    public function getFormMetas($form)
    {
        $metas = parent::getFormMetas(GravityFixes::ordered_form($form));
        if (isset($metas['formSettings']['restrictions']) && is_array($metas['formSettings']['restrictions'])) {
            $metas['formSettings']['restrictions'] = GravityFixes::restrictions($metas['formSettings']['restrictions'], $form);
        }

        return $metas;
    }

    protected function getSubmissionArrayValue($type, $field, $inputs, $submission)
    {
        if ($type === 'address') {
            return GravityFixes::address_value((array) $inputs, (array) $submission);
        }

        return parent::getSubmissionArrayValue($type, $field, $inputs, $submission);
    }

    public function getEntries($formId)
    {
        $this->entryDetails = [];
        $entries = parent::getEntries($formId);
        if (!is_array($entries) || !$entries) {
            return $entries;
        }
        // The same entries the importer read, in the same order.
        $total = \GFAPI::count_entries($formId);
        $limit = apply_filters('fluentform/entry_migration_max_limit', static::DEFAULT_ENTRY_MIGRATION_MAX_LIMIT, $this->key, $total, $formId);
        $rows = \GFAPI::get_entries($formId, [], ['key' => 'id', 'direction' => 'ASC'], ['offset' => 0, 'page_size' => $limit]);
        foreach (is_array($rows) ? $rows : [] as $row) {
            $this->entryDetails[] = [
                'ip' => (string) ($row['ip'] ?? ''),
                'browser' => (string) ($row['user_agent'] ?? ''),
                'source_url' => (string) ($row['source_url'] ?? ''),
            ];
        }

        return $entries;
    }
}
