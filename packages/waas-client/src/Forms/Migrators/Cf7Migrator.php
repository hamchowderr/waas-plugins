<?php

namespace WaaSClient\Forms\Migrators;

use FluentForm\App\Services\Migrator\Classes\ContactForm7Migrator;
use WaaSClient\Forms\FormFixes;

/**
 * Fluent Forms' own Contact Form 7 importer, with its known mistakes put right where they happen:
 * - the notifications are built from the CF7 form's Mail and Mail (2) (the importer writes one disabled generic
 *   email, as about ten malformed rows), and the confirmation from its "sent" message;
 * - a placeholder stays a placeholder (the importer makes it the field's value);
 * - a date field is a date, not a date and time;
 * - entries come from every Flamingo message (the importer reads only Flamingo's first page of 10) with the date
 *   each was sent, oldest first.
 *
 * Only loaded when Fluent Forms is active (the parent class must exist).
 */
class Cf7Migrator extends ContactForm7Migrator
{
    use SourceAccess;

    /** Per entry, in import order: the visitor's IP, browser and page, which the importer leaves empty. */
    public $entryDetails = [];

    /** Mail tags the emails used that Fluent Forms has no equivalent for. */
    public $unknownTags = [];

    /** Mail settings the importer can't carry over (attachments, additional settings). */
    public $mailNotes = [];

    public function getFields($form)
    {
        $fields = parent::getFields($form);
        if (!is_array($fields) || empty($fields['fields'])) {
            return $fields;
        }
        // [text your-name placeholder "Jane Doe"]: "Jane Doe" is the placeholder, not a value.
        $placeholders = [];
        foreach ($this->tags($form['ID']) as $tag) {
            if ($tag->name !== '' && ($tag->has_option('placeholder') || $tag->has_option('watermark')) && isset($tag->values[0])) {
                $placeholders[$tag->name] = (string) $tag->values[0];
            }
        }
        foreach ($fields['fields'] as $i => $field) {
            $name = $field['attributes']['name'] ?? '';
            if (isset($placeholders[$name]) && ($field['attributes']['value'] ?? '') === $placeholders[$name]) {
                $fields['fields'][$i]['attributes']['placeholder'] = $placeholders[$name];
                $fields['fields'][$i]['attributes']['value'] = '';
            }
        }

        return $fields;
    }

    protected function formatFieldData($args, $type)
    {
        $args = parent::formatFieldData($args, $type);
        if ($type === 'input_date') {
            // CF7's date field is a plain date; "Y-m-d H:i" would add a time picker.
            $args['format'] = 'Y-m-d';
        }

        return $args;
    }

    protected function getFormMetas($form)
    {
        $metas = parent::getFormMetas($form);
        $contact_form = class_exists('WPCF7_ContactForm') ? \WPCF7_ContactForm::get_instance($form['ID']) : null;
        if (!$contact_form) {
            return $metas;
        }

        $fields = array_values(array_filter(array_map(fn ($tag) => $tag->name, $this->tags($form['ID']))));
        $site = ['date_format' => get_option('date_format'), 'time_format' => get_option('time_format')];
        $notifications = [];
        foreach (['mail' => __('Mail', 'waas-client'), 'mail_2' => __('Mail (2)', 'waas-client')] as $prop => $label) {
            $mail = $contact_form->prop($prop);
            // Mail (2) is off on most forms; an empty, switched-off autoresponder isn't worth carrying over.
            if (!is_array($mail) || ($prop === 'mail_2' && empty($mail['active']))) {
                continue;
            }
            $notifications[] = FormFixes::cf7_notification($mail, $label, $fields, $site, $this->unknownTags);
            if (trim((string) ($mail['attachments'] ?? '')) !== '') {
                $this->mailNotes[] = sprintf(
                    /* translators: 1: Mail or Mail (2), 2: the attachments setting, such as [your-file] */
                    __('%1$s attached %2$s to the email; Fluent Forms\' emails don\'t carry attachments.', 'waas-client'),
                    $label,
                    trim((string) $mail['attachments'])
                );
            }
        }
        // One row per notification (the importer passes a single notification where a list is expected).
        if ($notifications) {
            $metas['notifications'] = $notifications;
        } else {
            unset($metas['notifications']);
        }

        $messages = $contact_form->prop('messages');
        $sent = is_array($messages) ? trim((string) ($messages['mail_sent_ok'] ?? '')) : '';
        if ($sent !== '') {
            $metas['formSettings']['confirmation']['messageToShow'] = '<p>' . esc_html($sent) . '</p>';
            $metas['formSettings']['confirmation']['redirectTo'] = 'samePage';
            // CF7 clears the form and shows the message under it.
            $metas['formSettings']['confirmation']['samePageFormBehavior'] = 'reset_form';
        }

        $additional = trim((string) $contact_form->prop('additional_settings'));
        if ($additional !== '') {
            $this->mailNotes[] = sprintf(
                /* translators: %s: CF7's additional settings, such as demo_mode: on */
                __('Additional settings aren\'t carried over: %s', 'waas-client'),
                preg_replace('/\s+/', ' ', $additional)
            );
        }

        return $metas;
    }

    public function getEntries($formId)
    {
        $this->entryDetails = [];
        if (!class_exists('Flamingo_Inbound_Message')) {
            return [];
        }
        $post = get_post($formId);
        if (!$post) {
            return [];
        }
        $messages = \Flamingo_Inbound_Message::find([
            'channel' => $post->post_name,
            'posts_per_page' => -1,
            'orderby' => 'date',
            'order' => 'ASC',
        ]);
        $entries = [];
        foreach ($messages as $message) {
            $fields = is_array($message->fields) ? $message->fields : [];
            $sent = get_post($message->id());
            if ($sent) {
                $fields['created_at'] = $sent->post_date;
                $fields['updated_at'] = $sent->post_modified;
            }
            if (!$fields) {
                continue;
            }
            $meta = is_array($message->meta) ? $message->meta : [];
            $entries[] = $fields;
            $this->entryDetails[] = [
                'ip' => (string) ($meta['remote_ip'] ?? ''),
                'browser' => (string) ($meta['user_agent'] ?? ''),
                'source_url' => (string) ($meta['url'] ?? ''),
            ];
        }

        return $entries;
    }

    /** Reads the mail settings as a conversion would (for a dry run's report), without saving anything. */
    public function preview($form): void
    {
        $this->getFormMetas($form);
    }

    /** @return \WPCF7_FormTag[] */
    public function tags($form_id): array
    {
        $contact_form = class_exists('WPCF7_ContactForm') ? \WPCF7_ContactForm::get_instance($form_id) : null;

        return $contact_form ? $contact_form->scan_form_tags() : [];
    }
}
