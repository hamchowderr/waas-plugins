<?php

namespace Tests\Unit\Forms;

use Tests\Unit\TestCase;
use WaaSClient\Forms\FormFixes;

class FormFixesTest extends TestCase
{
    private const FIELDS = ['your-name', 'your-email', 'topic', 'your-message'];
    private const SITE = ['date_format' => 'F j, Y', 'time_format' => 'g:i a'];

    public function test_cf7_mail_becomes_a_fluent_notification(): void
    {
        $mail = [
            'active' => true,
            'subject' => 'CF7 lead: [your-name] about [topic]',
            'sender' => 'Site <wordpress@example.com>',
            'recipient' => 'sales@example.com',
            'body' => "From: [your-name] <[your-email]>\nTopic: [topic]\n\n[your-message]",
            'additional_headers' => "Reply-To: [your-email]\nBcc: audit@example.com",
            'use_html' => false,
        ];

        $notification = FormFixes::cf7_notification($mail, 'Mail', self::FIELDS, self::SITE);

        $this->assertTrue($notification['enabled']);
        $this->assertSame('Mail', $notification['name']);
        $this->assertSame(['type' => 'email', 'email' => 'sales@example.com', 'field' => '', 'routing' => []], $notification['sendTo']);
        $this->assertSame('Site', $notification['fromName']);
        $this->assertSame('wordpress@example.com', $notification['fromEmail']);
        $this->assertSame('{inputs.your-email}', $notification['replyTo']);
        $this->assertSame('audit@example.com', $notification['bcc']);
        $this->assertSame('CF7 lead: {inputs.your-name} about {inputs.topic}', $notification['subject']);
        // Plain text keeps its line breaks and shows the angle brackets as text.
        $this->assertSame("From: {inputs.your-name} &lt;{inputs.your-email}&gt;<br>\nTopic: {inputs.topic}<br>\n<br>\n{inputs.your-message}", $notification['message']);
        $this->assertFalse($notification['conditionals']['status']);
    }

    public function test_an_autoresponder_to_a_field_is_sent_to_that_field(): void
    {
        $mail = ['active' => true, 'subject' => 'Thanks [your-name]', 'sender' => '[_site_title] <wordpress@example.com>', 'recipient' => '[your-email]', 'body' => '<p>Hi</p>', 'use_html' => true];

        $notification = FormFixes::cf7_notification($mail, 'Mail (2)', self::FIELDS, self::SITE);

        $this->assertSame(['type' => 'field', 'email' => '', 'field' => 'your-email', 'routing' => []], $notification['sendTo']);
        $this->assertSame('{wp.site_title}', $notification['fromName']);
        $this->assertSame('<p>Hi</p>', $notification['message']);
    }

    public function test_a_switched_off_mail_stays_off(): void
    {
        $this->assertFalse(FormFixes::cf7_notification(['active' => false], 'Mail (2)', self::FIELDS, self::SITE)['enabled']);
    }

    public function test_special_mail_tags_and_unknown_ones(): void
    {
        $unknown = [];
        $text = FormFixes::cf7_tags('[_site_admin_email] [_date] [_time] [_raw_topic] [_post_author] [_custom_thing] [not-a-field] [_serial_number]', self::FIELDS, self::SITE, $unknown);

        $this->assertSame('{wp.admin_email} {date.F j, Y} {date.g:i a} {inputs.topic}   [not-a-field] {submission.serial_number}', $text);
        $this->assertSame(['[_post_author]', '[_custom_thing]'], $unknown);
    }

    public function test_addresses_and_headers(): void
    {
        $this->assertSame(['My Shop', 'shop@example.com'], FormFixes::split_address('"My Shop" <shop@example.com>'));
        $this->assertSame(['', 'shop@example.com'], FormFixes::split_address('shop@example.com'));
        $this->assertSame(['reply-to' => '[your-email]', 'cc' => 'a@example.com'], FormFixes::headers("Reply-To: [your-email]\r\n\r\nCC:a@example.com\nnot a header"));
    }

    public function test_wpforms_default_confirmation_comes_first_and_the_redirect_stays_conditional(): void
    {
        $message = ['name' => 'Default', 'messageToShow' => '<p>Thanks</p>', 'redirectTo' => 'samePage', 'conditionals' => ['status' => false, 'type' => 'all', 'conditions' => []]];
        $redirect = ['name' => 'Support redirect', 'redirectTo' => 'customUrl', 'customUrl' => 'https://example.com/support-thanks',
            'conditionals' => ['status' => true, 'type' => 'all', 'conditions' => [['field' => 'select_6', 'operator' => '=', 'value' => 'Support']]]];

        // What the importer hands over: the last confirmation (the redirect) as the main one.
        [$main, $others] = FormFixes::wpforms_confirmations($redirect, [$message]);

        $this->assertSame('Default', $main['name']);
        $this->assertSame('samePage', $main['redirectTo']);
        $this->assertCount(1, $others);
        $this->assertSame('Support redirect', $others[0]['name']);
        $this->assertTrue($others[0]['conditionals']['status']);
    }

    public function test_wpforms_confirmations_already_in_order_stay_put(): void
    {
        $message = ['name' => 'Default', 'conditionals' => ['status' => false]];

        [$main, $others] = FormFixes::wpforms_confirmations($message, []);
        $this->assertSame('Default', $main['name']);
        $this->assertSame([], $others);

        [$main, $others] = FormFixes::wpforms_confirmations(null, []);
        $this->assertNull($main);
        $this->assertSame([], $others);
    }

    public function test_ninja_date_formats_become_php_style(): void
    {
        $this->assertSame('m/d/Y', FormFixes::ninja_date_format('MM/DD/YYYY'));
        $this->assertSame('d.m.Y', FormFixes::ninja_date_format('DD.MM.YYYY'));
        $this->assertSame('Y-m-d', FormFixes::ninja_date_format('YYYY-MM-DD'));
        $this->assertSame('l, F j Y', FormFixes::ninja_date_format('dddd, MMMM D YYYY'));
    }

    public function test_entries_oldest_first_keeping_ties_in_order(): void
    {
        $entries = [
            ['n' => 3, 'created_at' => '2026-10-30 10:03:00'],
            ['n' => 2, 'created_at' => '2026-10-30 10:02:00'],
            ['n' => 'b', 'created_at' => '2026-10-30 10:01:00'],
            ['n' => 'a', 'created_at' => '2026-10-30 10:01:00'],
        ];

        $this->assertSame(['b', 'a', 2, 3], array_column(FormFixes::oldest_first($entries), 'n'));
    }
}
