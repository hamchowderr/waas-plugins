<?php

namespace Tests\Unit\Forms;

use Tests\Unit\TestCase;
use WaaSClient\Forms\DroppedFields;

class DroppedFieldsTest extends TestCase
{
    private function fluent(array $names): array
    {
        $fields = [];
        foreach ($names as $name) {
            $fields[] = ['element' => 'input_text', 'attributes' => ['name' => $name]];
        }
        // A name field keeps its parts one level down.
        $fields[] = ['element' => 'input_name', 'attributes' => ['name' => 'name_1'], 'fields' => [
            'first_name' => ['element' => 'input_text', 'attributes' => ['name' => 'first_name']],
        ]];

        return ['fields' => $fields, 'submitButton' => ['element' => 'button', 'attributes' => ['type' => 'submit']]];
    }

    public function test_cf7_fields_missing_from_the_copy_are_dropped_even_when_the_importer_says_nothing(): void
    {
        $source = [
            ['name' => 'your-name', 'label' => 'your-name', 'type' => 'text'],
            ['name' => 'your-phone', 'label' => 'your-phone', 'type' => 'tel'],
            ['name' => 'source-id', 'label' => 'source-id', 'type' => 'hidden'],
            ['name' => 'capital-quiz', 'label' => 'capital-quiz', 'type' => 'quiz'],
        ];

        $dropped = DroppedFields::find('contactform7', $source, $this->fluent(['your-name']), [], false);

        $this->assertSame(['your-phone', 'source-id', 'capital-quiz'], array_column($dropped, 'name'));
        $this->assertSame(['tel', 'hidden', 'quiz'], array_column($dropped, 'type'));
        $this->assertSame('Needs Fluent Forms Pro.', $dropped[0]['reason']);
        $this->assertSame('Fluent Forms has no quiz question.', $dropped[2]['reason']);
    }

    public function test_layout_fields_count_as_dropped_only_when_the_importer_lists_them(): void
    {
        $source = [
            ['name' => 'name_1', 'label' => 'Name', 'type' => 'name'],
            ['name' => 'divider_18', 'label' => 'Section', 'type' => 'divider', 'layout' => true],
            ['name' => 'pagebreak_9', 'label' => 'Next', 'type' => 'pagebreak', 'layout' => true],
            ['name' => 'gdpr-checkbox_17', 'label' => 'GDPR Agreement', 'type' => 'gdpr-checkbox'],
        ];

        $dropped = DroppedFields::find('wpforms', $source, $this->fluent([]), ['Next', 'GDPR Agreement'], false);

        $this->assertSame(['Next', 'GDPR Agreement'], array_column($dropped, 'label'));
        $this->assertSame('Needs Fluent Forms Pro.', $dropped[0]['reason']);
        $this->assertSame('Fluent Forms\' importer has no matching field.', $dropped[1]['reason']);
    }

    public function test_with_pro_the_reason_is_not_pro(): void
    {
        $this->assertSame('Fluent Forms\' importer has no matching field.', DroppedFields::reason('ninja_forms', 'phone', true));
        $this->assertSame('Needs Fluent Forms Pro.', DroppedFields::reason('ninja_forms', 'phone', false));
    }

    public function test_names_are_found_at_any_depth(): void
    {
        $this->assertSame(['a', 'name_1', 'first_name'], DroppedFields::fluent_names($this->fluent(['a'])));
    }
}
