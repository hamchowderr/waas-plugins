<?php

namespace AnyPressClient\Forms;

/**
 * What a converted form lost in the forms switch, besides its dropped fields: mail tags, messages, show/hide rules.
 * Also lists the source form's fields under the names Fluent Forms' importer gives them.
 */
final class FormLosses
{
    /**
     * What the form had that its Fluent Forms copy doesn't, besides the dropped fields, and other things to know.
     *
     * @return array{0: string[], 1: string[]} [lost, notes]
     */
    public static function find(string $type, $migrator, $item, array $dropped): array
    {
        $lost = [];
        $notes = [];
        if ($type === 'contactform7') {
            $contact_form = class_exists('WPCF7_ContactForm') ? \WPCF7_ContactForm::get_instance($item['ID']) : null;
            $dropped_names = array_filter(array_column($dropped, 'name'));
            $mentioned = [];
            foreach (['mail', 'mail_2'] as $prop) {
                $mail = $contact_form ? $contact_form->prop($prop) : null;
                if (!is_array($mail) || ($prop === 'mail_2' && empty($mail['active']))) {
                    continue;
                }
                $text = implode(' ', array_map('strval', array_intersect_key($mail, array_flip(['subject', 'sender', 'recipient', 'body', 'additional_headers']))));
                foreach ($dropped_names as $name) {
                    if (preg_match('/\[(?:_raw_)?' . preg_quote($name, '/') . '\]/', $text)) {
                        $mentioned[] = '[' . $name . ']';
                    }
                }
            }
            if ($mentioned) {
                $lost[] = sprintf(
                    /* translators: %s: mail tags such as [your-phone] */
                    __('The emails mention fields that weren\'t brought in, which now show nothing: %s', 'anypress-client'),
                    implode(', ', array_unique($mentioned))
                );
            }
            if ($migrator->unknownTags) {
                $lost[] = sprintf(
                    /* translators: %s: mail tags such as [_post_author] */
                    __('Mail tags Fluent Forms has no equivalent for were left out of the emails: %s', 'anypress-client'),
                    implode(', ', array_unique($migrator->unknownTags))
                );
            }
            foreach (array_unique($migrator->mailNotes) as $note) {
                $lost[] = $note;
            }
            // Messages changed from CF7's own wording, other than "sent" (which becomes the confirmation).
            $messages = $contact_form ? (array) $contact_form->prop('messages') : [];
            $defaults = function_exists('wpcf7_messages') ? wp_list_pluck(wpcf7_messages(), 'default') : [];
            $custom = [];
            foreach ($messages as $key => $message) {
                if ($key !== 'mail_sent_ok' && isset($defaults[$key]) && trim((string) $message) !== trim((string) $defaults[$key])) {
                    $custom[] = $key;
                }
            }
            if ($custom) {
                $lost[] = sprintf(
                    /* translators: %s: CF7 message names such as validation_error */
                    __('Your own wording for these Contact Form 7 messages isn\'t carried over (Fluent Forms uses its own): %s', 'anypress-client'),
                    implode(', ', $custom)
                );
            }
        }
        if ($type === 'wpforms') {
            $rules = [];
            foreach ((array) ($item['fields'] ?? []) as $field) {
                if (!empty($field['conditional_logic'])) {
                    $rules[] = (string) ($field['label'] ?? ('#' . ($field['id'] ?? '')));
                }
            }
            if ($rules) {
                $lost[] = sprintf(
                    /* translators: %s: field labels */
                    __('Show/hide rules on these fields aren\'t carried over, so they always show: %s', 'anypress-client'),
                    implode(', ', $rules)
                );
            }
            $confirmations = (array) ($item['settings']['confirmations'] ?? []);
            $conditional = array_filter($confirmations, fn ($c) => !empty($c['conditional_logic']));
            if ($conditional && !FormsSwitch::pro()) {
                $notes[] = __('Fluent Forms runs conditional confirmations (such as a redirect for one answer) only with Pro; AnyPress Client runs them for this form while Pro is off.', 'anypress-client');
            }
        }
        if ($type === 'gravityform') {
            [$lost, $notes] = self::gravity($item, $lost, $notes);
        }
        if ($dropped) {
            $notes[] = __('Entries keep the values of dropped fields, but the form no longer asks for them.', 'anypress-client');
        }

        return [$lost, $notes];
    }

    /** What a Gravity Forms form loses: show/hide rules on fields, and the add-on feeds (Mailchimp, Stripe...). */
    private static function gravity($item, array $lost, array $notes): array
    {
        $rules = GravityFixes::conditional_fields((array) $item);
        if ($rules) {
            $lost[] = sprintf(
                /* translators: %s: field labels */
                __('Show/hide rules on these fields aren\'t carried over, so they always show: %s', 'anypress-client'),
                implode(', ', $rules)
            );
        }
        $feeds = class_exists('GFAPI') && method_exists('GFAPI', 'get_feeds') ? \GFAPI::get_feeds(null, (int) ($item['id'] ?? 0)) : [];
        $addons = is_array($feeds) ? array_values(array_unique(array_filter(array_map(fn ($feed) => (string) ($feed['addon_slug'] ?? ''), $feeds)))) : [];
        if ($addons) {
            $lost[] = sprintf(
                /* translators: %s: Gravity Forms add-on slugs such as gravityformsmailchimp */
                __('Gravity Forms add-on feeds aren\'t carried over; set the same up under the Fluent Forms form\'s Integrations: %s', 'anypress-client'),
                implode(', ', $addons)
            );
        }
        if (!empty($item['enableHoneypot'])) {
            $notes[] = __('Gravity Forms\' anti-spam honeypot was on; Fluent Forms has its own honeypot under Global Settings.', 'anypress-client');
        }

        return [$lost, $notes];
    }

    /**
     * The source form's fields, each with the name Fluent Forms' importer gives it.
     *
     * @return array<int, array{name: string, label: string, type: string, layout?: bool}>
     */
    public static function source_fields(string $type, $migrator, $item): array
    {
        $fields = [];
        if ($type === 'contactform7') {
            foreach ($migrator->tags($item['ID']) as $tag) {
                if ($tag->basetype === 'submit' || $tag->name === '') {
                    continue;
                }
                $fields[] = ['name' => (string) $tag->name, 'label' => (string) $tag->name, 'type' => (string) $tag->basetype];
            }
        }
        if ($type === 'wpforms') {
            foreach ((array) ($item['fields'] ?? []) as $field) {
                $field_type = (string) ($field['type'] ?? '');
                // The first and last page breaks only mark where the pages start and end.
                if ($field_type === 'pagebreak' && in_array($field['position'] ?? '', ['top', 'bottom'], true)) {
                    continue;
                }
                $fields[] = [
                    'name' => $field_type . '_' . ($field['id'] ?? ''),
                    'label' => (string) ($field['label'] ?? ''),
                    'type' => $field_type,
                    'layout' => in_array($field_type, ['divider', 'html', 'pagebreak', 'content', 'layout', 'entry-preview'], true),
                ];
            }
        }
        if ($type === 'gravityform') {
            $fields = GravityFixes::source_fields((array) $item);
        }
        if ($type === 'ninja_forms') {
            foreach ((array) ($item['fields'] ?? []) as $field) {
                $field_type = (string) ($field['type'] ?? '');
                if ($field_type === 'submit') {
                    continue;
                }
                $name = (string) ($field['key'] ?? '');
                $fields[] = [
                    'name' => $name !== '' ? $name : str_replace('.', '_', (string) ($field['id'] ?? '')),
                    'label' => (string) ($field['label'] ?? ''),
                    'type' => $field_type,
                    'layout' => in_array($field_type, ['hr', 'html'], true),
                ];
            }
        }

        return $fields;
    }
}
