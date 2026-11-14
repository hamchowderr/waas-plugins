<?php

namespace Tests\Unit\Features;

use AnyPressClient\Features\SiteBrand;
use Tests\Unit\TestCase;

class SiteBrandTest extends TestCase
{
    public function test_generator_tag_is_left_out(): void
    {
        SiteBrand::init();
        $this->assertNotFalse(has_filter('the_generator', '__return_empty_string'));
    }
}
