<?php

namespace Tests\Unit\Forms;

use AnyPressClient\Forms\GravityFixes;
use AnyPressClient\Forms\PageSwitcher;
use Brain\Monkey;
use Tests\Unit\TestCase;

class GravityFixesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Monkey\Functions\when('sanitize_title')->alias(fn ($t) => trim(preg_replace('/[^a-z0-9]+/', '-', strtolower((string) $t)), '-'));
    }

    private function nameField(): array
    {
        return [
            'id' => 1, 'type' => 'name', 'label' => 'Name',
            'inputs' => [
                ['id' => '1.2', 'label' => 'Prefix', 'isHidden' => true],
                ['id' => '1.3', 'label' => 'First'],
                ['id' => '1.4', 'label' => 'Middle', 'isHidden' => true],
                ['id' => '1.6', 'label' => 'Last'],
                ['id' => '1.8', 'label' => 'Suffix', 'isHidden' => true],
            ],
        ];
    }

    public function test_name_parts_show_as_in_gravity_forms(): void
    {
        // What the importer writes: every part "visible" copied from isHidden (true for the hidden middle name).
        $fluent = [1 => ['element' => 'input_name', 'attributes' => ['name' => 'name_1'], 'fields' => [
            'first_name' => ['settings' => ['visible' => true]],
            'middle_name' => ['settings' => ['visible' => true]],
            'last_name' => ['settings' => ['visible' => true]],
        ]]];

        $fixed = GravityFixes::fields($fluent, [$this->nameField()]);

        $this->assertTrue($fixed[1]['fields']['first_name']['settings']['visible']);
        $this->assertFalse($fixed[1]['fields']['middle_name']['settings']['visible']);
        $this->assertTrue($fixed[1]['fields']['last_name']['settings']['visible']);
    }

    public function test_address_line_two_is_never_required_and_hidden_parts_stay_hidden(): void
    {
        $source = ['id' => 4, 'type' => 'address', 'label' => 'Address', 'inputs' => [
            ['id' => '4.1'], ['id' => '4.2'], ['id' => '4.3'], ['id' => '4.4'], ['id' => '4.5'], ['id' => '4.6', 'isHidden' => true],
        ]];
        $part = ['settings' => ['visible' => true, 'validation_rules' => ['required' => ['value' => true]]]];
        $fluent = ['container' => ['columns' => [['fields' => [[
            'element' => 'address',
            'attributes' => ['name' => 'address_4'],
            'fields' => array_fill_keys(GravityFixes::ADDRESS_PARTS, $part),
        ]]]]]];

        $fixed = GravityFixes::fields($fluent, [$source]);
        $address = $fixed['container']['columns'][0]['fields'][0]['fields'];

        $this->assertFalse($address['address_line_2']['settings']['validation_rules']['required']['value']);
        $this->assertTrue($address['address_line_1']['settings']['validation_rules']['required']['value']);
        $this->assertFalse($address['country']['settings']['visible']);
        $this->assertTrue($address['city']['settings']['visible']);
    }

    public function test_dates_and_times_keep_their_format(): void
    {
        $fluent = [
            ['element' => 'input_date', 'attributes' => ['name' => 'event_date_5'], 'settings' => ['date_format' => 'm/d/Y']],
            ['element' => 'input_date', 'attributes' => ['name' => 'start_time_6'], 'settings' => ['date_format' => 'H:i']],
        ];
        $source = [
            ['id' => 5, 'type' => 'date', 'label' => 'Event date', 'dateFormat' => 'dmy_dot'],
            ['id' => 6, 'type' => 'time', 'label' => 'Start time', 'timeFormat' => '12'],
        ];

        $fixed = GravityFixes::fields($fluent, $source);

        $this->assertSame('d.m.Y', $fixed[0]['settings']['date_format']);
        $this->assertSame('h:i K', $fixed[1]['settings']['date_format']);
    }

    public function test_a_one_box_name_field_is_read_as_text(): void
    {
        $fields = GravityFixes::readable_fields([(object) ['id' => 2, 'type' => 'name', 'label' => 'Your name', 'nameFormat' => 'simple', 'inputs' => null]]);

        $this->assertSame('text', $fields[0]['type']);
        $this->assertSame('your_name_2', GravityFixes::input_name($fields[0]));
    }

    public function test_restrictions_keep_their_values(): void
    {
        $restrictions = [
            'requireLogin' => ['enabled' => true, 'requireLoginMsg' => true],
            'limitNumberOfEntries' => ['enabled' => true, 'numberOfEntries' => true, 'period' => true, 'limitReachedMsg' => true],
            'scheduleForm' => ['enabled' => true, 'start' => true, 'end' => true, 'pendingMsg' => true, 'expiredMsg' => true],
        ];
        $form = [
            'requireLoginMessage' => 'Sign in first.', 'limitEntries' => true, 'limitEntriesCount' => '50', 'limitEntriesPeriod' => 'week',
            'limitEntriesMessage' => 'Full this week.', 'scheduleForm' => true, 'scheduleStart' => '12/01/2026', 'scheduleStartHour' => '9',
            'scheduleStartMinute' => '30', 'scheduleStartAmpm' => 'pm', 'scheduleEnd' => '12/31/2026', 'scheduleEndHour' => '12',
            'scheduleEndMinute' => '0', 'scheduleEndAmpm' => 'am', 'schedulePendingMessage' => 'Not yet.', 'scheduleMessage' => 'Closed.',
        ];

        $fixed = GravityFixes::restrictions($restrictions, $form);

        $this->assertSame('Sign in first.', $fixed['requireLogin']['requireLoginMsg']);
        $this->assertSame(50, $fixed['limitNumberOfEntries']['numberOfEntries']);
        $this->assertSame('week', $fixed['limitNumberOfEntries']['period']);
        $this->assertSame('Full this week.', $fixed['limitNumberOfEntries']['limitReachedMsg']);
        $this->assertSame('2026-12-01 21:30:00', $fixed['scheduleForm']['start']);
        $this->assertSame('2026-12-31 00:00:00', $fixed['scheduleForm']['end']);
        $this->assertSame('Not yet.', $fixed['scheduleForm']['pendingMsg']);
        $this->assertSame('Closed.', $fixed['scheduleForm']['expiredMsg']);
    }

    public function test_the_default_confirmation_comes_first_and_other_events_are_left_out(): void
    {
        $form = GravityFixes::ordered_form([
            'confirmations' => ['a1' => ['name' => 'VIP redirect', 'isDefault' => false], 'b2' => ['name' => 'Default', 'isDefault' => true]],
            'notifications' => ['n1' => ['name' => 'Admin'], 'n2' => ['name' => 'Saved', 'event' => 'form_saved'], 'n3' => ['name' => 'User', 'event' => 'form_submission']],
        ]);

        $this->assertSame(['Default', 'VIP redirect'], array_column($form['confirmations'], 'name'));
        $this->assertSame(['Admin', 'User'], array_column($form['notifications'], 'name'));
    }

    public function test_an_address_with_empty_parts_is_read_by_part(): void
    {
        $inputs = [['id' => '4.1'], ['id' => '4.2'], ['id' => '4.3'], ['id' => '4.4'], ['id' => '4.5'], ['id' => '4.6']];

        $value = GravityFixes::address_value($inputs, ['4.1' => '1 Main St', '4.3' => 'Atlanta', '4.6' => 'United States']);

        $this->assertSame(['address_line_1' => '1 Main St', 'city' => 'Atlanta', 'country' => 'United States'], $value);
    }

    public function test_page_switcher_swaps_gravity_forms_shortcodes_and_blocks(): void
    {
        $switcher = new PageSwitcher(['gravityform' => [['id' => 3, 'fluent' => 9]]]);
        $content = "[gravityform id=\"3\" title=\"false\" ajax=\"true\"]\n[gravityforms id=3]\n"
            . "[gravityforms action=\"conditional\" merge_tag=\"{Name:1}\"]x[/gravityforms]\n"
            . '<!-- wp:gravityforms/form {"formId":"3","title":false} /-->';

        $result = $switcher->rewrite($content);

        $this->assertSame(
            "[fluentform id=\"9\"]\n[fluentform id=\"9\"]\n[gravityforms action=\"conditional\" merge_tag=\"{Name:1}\"]x[/gravityforms]\n"
            . '<!-- wp:fluentfom/guten-block {"formId":"9"} /-->',
            $result['content']
        );
        $this->assertSame([], $result['missing']);
    }
}
