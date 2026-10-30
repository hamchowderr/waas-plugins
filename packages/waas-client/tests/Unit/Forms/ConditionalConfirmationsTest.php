<?php

namespace Tests\Unit\Forms;

use Brain\Monkey\Functions;
use Tests\Unit\TestCase;
use WaaSClient\Forms\ConditionalConfirmations;

class ConditionalConfirmationsTest extends TestCase
{
    public function test_only_switched_on_confirmations_with_rules_apply(): void
    {
        $rules = ['status' => true, 'type' => 'all', 'conditions' => [['field' => 'select_6', 'operator' => '=', 'value' => 'Support']]];

        $this->assertTrue(ConditionalConfirmations::applies(['conditionals' => $rules]));
        $this->assertTrue(ConditionalConfirmations::applies(['active' => true, 'conditionals' => $rules]));
        $this->assertFalse(ConditionalConfirmations::applies(['active' => false, 'conditionals' => $rules]));
        $this->assertFalse(ConditionalConfirmations::applies(['conditionals' => ['status' => false] + $rules]));
        $this->assertFalse(ConditionalConfirmations::applies(['conditionals' => ['status' => true, 'conditions' => []]]));
        $this->assertFalse(ConditionalConfirmations::applies('not a confirmation'));
    }

    public function test_the_confirmation_is_unchanged_without_fluent_forms_rule_check_or_for_other_forms(): void
    {
        Functions\expect('add_filter')->once();
        Functions\when('get_option')->justReturn([12]);
        $confirmation = ['redirectTo' => 'samePage'];

        $form = (object) ['id' => 7];

        $this->assertSame($confirmation, (new ConditionalConfirmations())->apply($confirmation, ['a' => 1], $form));
    }
}
