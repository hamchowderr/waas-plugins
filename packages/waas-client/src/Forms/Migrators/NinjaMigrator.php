<?php

namespace WaaSClient\Forms\Migrators;

use FluentForm\App\Services\Migrator\Classes\NinjaFormsMigrator;
use WaaSClient\Forms\FormFixes;

/**
 * Fluent Forms' own Ninja Forms importer, with its known mistakes put right:
 * - each email's Reply-To comes from the Ninja email action's own Reply-To (the importer replaces it with the
 *   admin address whenever the email goes to the admin);
 * - a date field's format is converted from Ninja's tokens (MM/DD/YYYY) to Fluent Forms' (m/d/Y);
 * - entries are brought in oldest first, so entry #1 is the first submission (the importer reverses them).
 *
 * Only loaded when Fluent Forms is active (the parent class must exist).
 */
class NinjaMigrator extends NinjaFormsMigrator
{
    use SourceAccess;

    protected function formatFieldData($field)
    {
        [$type, $args] = parent::formatFieldData($field);
        $format = is_array($field) ? (string) ($field['date_format'] ?? '') : '';
        if ($type === 'input_date' && $format !== '' && $format !== 'default') {
            $args['format'] = FormFixes::ninja_date_format($format);
        }

        return [$type, $args];
    }

    public function getFormMetas($form)
    {
        $metas = parent::getFormMetas($form);
        if (empty($metas['notifications']) || !is_array($metas['notifications'])) {
            return $metas;
        }
        // The same email actions, in the same order, as the importer read them.
        $reply_to = [];
        foreach ((array) Ninja_Forms()->form($this->getFormId($form))->get_actions() as $action) {
            if ($action->get_type() !== 'action') {
                continue;
            }
            $settings = $action->get_settings();
            if (($settings['type'] ?? '') === 'email') {
                $reply_to[] = (string) ($settings['reply_to'] ?? '');
            }
        }
        foreach ($metas['notifications'] as $i => $notification) {
            if (array_key_exists($i, $reply_to)) {
                $metas['notifications'][$i]['replyTo'] = $this->tags($reply_to[$i]);
            }
        }

        return $metas;
    }

    public function getEntries($formId)
    {
        $entries = parent::getEntries($formId);

        return is_array($entries) ? FormFixes::oldest_first($entries) : $entries;
    }

    /** Ninja merge tags as Fluent Forms smart codes, as the importer converts them. */
    private function tags(string $text): string
    {
        $codes = $this->dynamicShortcodes() + ['{system:admin_email}' => '{wp.admin_email}'];

        return str_replace(array_keys($codes), array_values($codes), $text);
    }
}
