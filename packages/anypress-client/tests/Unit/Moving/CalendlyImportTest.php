<?php

namespace Tests\Unit\Moving;

use AnyPressClient\Moving\CalendlyApi;
use AnyPressClient\Moving\CalendlyImport;
use Brain\Monkey;
use Tests\Unit\TestCase;

class CalendlyImportTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $text = fn ($t) => trim(strip_tags((string) $t));
        Monkey\Functions\when('sanitize_text_field')->alias($text);
        Monkey\Functions\when('sanitize_textarea_field')->alias($text);
        Monkey\Functions\when('sanitize_email')->alias(fn ($e) => strtolower(trim((string) $e)));
        Monkey\Functions\when('is_email')->alias(fn ($e) => (bool) filter_var($e, FILTER_VALIDATE_EMAIL));
        Monkey\Functions\when('wp_kses_post')->alias(fn ($h) => preg_replace('#<script\b[^>]*>.*?</script>#is', '', (string) $h));
        Monkey\Functions\when('esc_url_raw')->alias(fn ($u) => filter_var($u, FILTER_VALIDATE_URL) ? (string) $u : '');
        Monkey\Functions\when('wp_http_validate_url')->alias(fn ($u) => filter_var($u, FILTER_VALIDATE_URL) ? $u : false);
        Monkey\Functions\when('wp_parse_url')->alias(fn ($u, $c = -1) => parse_url($u, $c));
        Monkey\Functions\when('__')->returnArg();
    }

    public function test_weekday_rules_become_weekly_hours(): void
    {
        $week = CalendlyImport::weekly([
            ['type' => 'wday', 'wday' => 'monday', 'intervals' => [['from' => '13:00', 'to' => '17:00'], ['from' => '09:00', 'to' => '12:00']]],
            ['type' => 'wday', 'wday' => 'sunday', 'intervals' => []],
            ['type' => 'wday', 'wday' => 'tuesday', 'intervals' => [['from' => '17:00', 'to' => '09:00']]],
            ['type' => 'date', 'date' => '2030-01-02', 'intervals' => [['from' => '10:00', 'to' => '11:00']]],
        ]);

        $this->assertSame(['sun', 'mon', 'tue', 'wed', 'thu', 'fri', 'sat'], array_keys($week));
        $this->assertTrue($week['mon']['enabled']);
        $this->assertSame([['start' => '13:00', 'end' => '17:00'], ['start' => '09:00', 'end' => '12:00']], $week['mon']['slots']);
        $this->assertFalse($week['sun']['enabled']);
        $this->assertFalse($week['tue']['enabled'], 'an interval that ends before it starts is dropped');
        $this->assertFalse($week['wed']['enabled']);
    }

    public function test_dated_rules_with_hours_become_overrides_and_days_off_are_left_out(): void
    {
        $dates = CalendlyImport::overrides([
            ['type' => 'date', 'date' => '2030-01-02', 'intervals' => [['from' => '10:00', 'to' => '11:00']]],
            ['type' => 'date', 'date' => '2030-01-03', 'intervals' => []],
            ['type' => 'date', 'date' => 'soon', 'intervals' => [['from' => '10:00', 'to' => '11:00']]],
            ['type' => 'wday', 'wday' => 'monday', 'intervals' => [['from' => '10:00', 'to' => '11:00']]],
        ]);

        $this->assertSame(['2030-01-02' => [['start' => '10:00', 'end' => '11:00']]], $dates);
    }

    /** @dataProvider locations */
    public function test_locations(array $calendly, ?array $fluent): void
    {
        $this->assertSame($fluent, CalendlyImport::location($calendly));
    }

    public static function locations(): array
    {
        return [
            'zoom' => [['kind' => 'zoom_conference'], ['type' => 'online_meeting', 'title' => 'Zoom', 'meeting_link' => '', 'display_on_booking' => 'no']],
            'webex with a link' => [['kind' => 'webex_conference', 'location' => 'https://webex.example/m/1'], ['type' => 'online_meeting', 'title' => 'Webex', 'meeting_link' => 'https://webex.example/m/1', 'display_on_booking' => 'no']],
            'in person' => [['kind' => 'physical', 'location' => '12 Main St'], ['type' => 'in_person_organizer', 'title' => 'In person', 'description' => '12 Main St', 'display_on_booking' => 'yes']],
            'invitee says where' => [['kind' => 'ask_invitee'], ['type' => 'in_person_guest', 'title' => 'Your address']],
            'host calls' => [['kind' => 'outbound_call'], ['type' => 'phone_guest', 'title' => 'Phone call']],
            'invitee calls' => [['kind' => 'inbound_call', 'phone_number' => '+1 555 0100'], ['type' => 'phone_organizer', 'title' => 'Phone call', 'host_phone_number' => '+1 555 0100', 'display_on_booking' => 'yes']],
            'custom' => [['kind' => 'custom', 'location' => 'On Signal'], ['type' => 'custom', 'title' => 'On Signal', 'description' => 'On Signal', 'display_on_booking' => 'yes']],
            'unknown' => [['kind' => 'something_new'], null],
        ];
    }

    public function test_event_types(): void
    {
        $group = CalendlyImport::event(['name' => 'Workshop', 'duration' => 89.6, 'kind' => 'group', 'active' => false, 'description_html' => '<p>Hi</p><script>x()</script>', 'color' => '#8247f5', 'locations' => [['kind' => 'nothing'], ['kind' => 'outbound_call']]], 7, 0);

        $this->assertSame('Workshop', $group['title']);
        $this->assertSame(90, $group['duration']);
        $this->assertSame('draft', $group['status']);
        $this->assertSame('group', $group['event_type']);
        $this->assertSame(2, $group['max_book_per_slot'], 'a group event with no seat count seen gets at least two seats');
        $this->assertSame('<p>Hi</p>', $group['description']);
        $this->assertSame([['type' => 'phone_guest', 'title' => 'Phone call']], $group['location_settings']);
        $this->assertSame(['existing_schedule', 7], [$group['availability_type'], $group['availability_id']]);
        $this->assertSame('#8247f5', $group['color_schema']);

        $solo = CalendlyImport::event(['duration' => 30, 'active' => true, 'color' => 'red'], 3, 9);
        $this->assertSame(['Calendly meeting', 'active', 'single', 1, '#0099ff'], [$solo['title'], $solo['status'], $solo['event_type'], $solo['max_book_per_slot'], $solo['color_schema']]);
    }

    public function test_bookings(): void
    {
        $slot = (object) ['id' => 4, 'calendar_id' => 2, 'user_id' => 1, 'event_type' => 'single'];
        $event = ['start_time' => '2030-05-01T15:00:00.000000Z', 'end_time' => '2030-05-01T15:45:00.000000Z', 'location' => ['type' => 'zoom', 'join_url' => 'https://zoom.example/j/1']];
        $data = CalendlyImport::booking($event, ['email' => 'Pat@Example.com', 'name' => 'Pat de la Cruz', 'timezone' => 'Not/AZone', 'questions_and_answers' => [['question' => 'Why?', 'answer' => 'Because']]], $slot, 'America/New_York');

        $this->assertSame('pat@example.com', $data['email']);
        $this->assertSame(['Pat', 'de la Cruz'], [$data['first_name'], $data['last_name']]);
        $this->assertSame('America/New_York', $data['person_time_zone']);
        $this->assertSame(['2030-05-01 15:00:00', '2030-05-01 15:45:00', 45], [$data['start_time'], $data['end_time'], $data['slot_minutes']]);
        $this->assertSame(['type' => 'online_meeting', 'online_platform_link' => 'https://zoom.example/j/1'], $data['location_details']);
        $this->assertSame('Why?: Because', $data['message']);
        $this->assertSame([0, 1, 4, 2, 'scheduled', 'calendly'], [$data['person_user_id'], $data['host_user_id'], $data['event_id'], $data['calendar_id'], $data['status'], $data['source']]);

        $this->assertNull(CalendlyImport::booking($event, ['email' => 'not an email'], $slot, 'UTC'));
        $this->assertNull(CalendlyImport::booking(['start_time' => '2030-05-01T15:00:00Z', 'end_time' => '2030-05-01T14:00:00Z'], ['email' => 'a@example.com'], $slot, 'UTC'));
    }

    public function test_booking_locations_without_a_link(): void
    {
        $this->assertSame(['type' => 'in_person_organizer', 'description' => '12 Main St'], CalendlyImport::booking_location(['type' => 'physical', 'location' => '12 Main St']));
        $this->assertSame(['type' => 'phone_guest', 'description' => '+1 555'], CalendlyImport::booking_location(['type' => 'outbound_call', 'location' => '+1 555']));
        $this->assertSame(['type' => 'custom', 'description' => ''], CalendlyImport::booking_location([]));
    }

    public function test_uuid_from_a_calendly_uri(): void
    {
        $this->assertSame('ABC123', CalendlyApi::uuid('https://api.calendly.com/scheduled_events/ABC123'));
    }
}
