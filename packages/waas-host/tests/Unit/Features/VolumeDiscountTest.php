<?php

namespace Tests\Unit\Features;

use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use Tests\Unit\TestCase;
use WaaSHost\Features\VolumeDiscount;

class VolumeDiscountTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Functions\stubTranslationFunctions();
    }

    public function test_off_by_default(): void
    {
        Functions\when('get_option')->alias(fn ($key, $default = false) => $default);

        $this->assertFalse(VolumeDiscount::is_enabled());
    }

    public function test_on_when_the_option_is_checked(): void
    {
        Functions\when('get_option')->alias(fn ($key, $default = false) => $key === VolumeDiscount::ENABLED_OPTION ? 'on' : $default);

        $this->assertTrue(VolumeDiscount::is_enabled());
    }

    /** @dataProvider owner_tiers */
    public function test_owner_tiers(int $sites, ?int $percent): void
    {
        $tier = VolumeDiscount::tier_for($sites);

        $this->assertSame($percent, $tier === null ? null : (int) $tier['percent']);
    }

    public function owner_tiers(): array
    {
        return [
            'one site' => [1, null],
            'two sites' => [2, null],
            'third site' => [3, 10],
            'nine sites' => [9, 10],
            'tenth site' => [10, 20],
            '24 sites' => [24, 20],
            '25th site' => [25, 30],
            '200 sites' => [200, 30],
        ];
    }

    public function test_tiers_can_be_filtered_and_bad_ones_are_dropped(): void
    {
        Filters\expectApplied('waas_volume_discount_tiers')->andReturn([
            ['sites' => 5, 'percent' => 15],
            ['sites' => 2, 'percent' => 5, 'code' => 'MULTI'],
            ['sites' => 0, 'percent' => 50],
            ['sites' => 8, 'percent' => 150],
            ['sites' => 9, 'percent' => 20, 'code' => 'MULTI'],
            'junk',
        ]);

        $this->assertSame([
            ['sites' => 2, 'percent' => 5.0, 'code' => 'MULTI'],
            ['sites' => 5, 'percent' => 15.0, 'code' => 'VOLUME15'],
        ], VolumeDiscount::tiers());
    }

    public function test_tier_by_code(): void
    {
        $this->assertSame(20.0, VolumeDiscount::tier_with_code('VOLUME20')['percent']);
        $this->assertNull(VolumeDiscount::tier_with_code('volume20'));
        $this->assertNull(VolumeDiscount::tier_with_code('SUMMER'));
    }

    public function test_describes_the_tiers_for_the_settings_page(): void
    {
        $this->assertSame(
            '10% off at 3 to 9 websites, 20% off at 10 to 24 websites, 30% off at 25 or more websites',
            VolumeDiscount::describe_tiers()
        );
    }
}
