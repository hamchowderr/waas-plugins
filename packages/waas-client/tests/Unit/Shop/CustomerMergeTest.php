<?php

namespace Tests\Unit\Shop;

use Tests\Unit\TestCase;
use WaaSClient\Shop\CustomerMerge;

class CustomerMergeTest extends TestCase
{
    public function test_keeps_the_customer_with_the_account_email(): void
    {
        $plan = CustomerMerge::plan([
            ['id' => 3, 'user_id' => 4, 'email' => 'carol@example.com'],
            ['id' => 1, 'user_id' => 2, 'email' => 'alice@example.com'],
            ['id' => 6, 'user_id' => 4, 'email' => 'carol.work@example.com'],
            ['id' => 5, 'user_id' => 0, 'email' => 'guest@example.com'],
            ['id' => 7, 'user_id' => null, 'email' => 'guest2@example.com'],
        ], [2 => 'alice@example.com', 4 => 'Carol@Example.com']);

        $this->assertSame([[
            'user_id' => 4,
            'keep' => 3,
            'merge' => [6],
            'emails' => ['carol@example.com', 'carol.work@example.com'],
        ]], $plan);
    }

    public function test_without_a_matching_email_keeps_the_oldest(): void
    {
        $plan = CustomerMerge::plan([
            ['id' => 9, 'user_id' => 8, 'email' => 'b@example.com'],
            ['id' => 4, 'user_id' => 8, 'email' => 'a@example.com'],
            ['id' => 12, 'user_id' => 8, 'email' => 'c@example.com'],
        ], [8 => 'new@example.com']);

        $this->assertSame(4, $plan[0]['keep']);
        $this->assertSame([9, 12], $plan[0]['merge']);
    }

    public function test_nothing_to_merge(): void
    {
        $this->assertSame([], CustomerMerge::plan([['id' => 1, 'user_id' => 2, 'email' => 'a@example.com']], []));
    }
}
