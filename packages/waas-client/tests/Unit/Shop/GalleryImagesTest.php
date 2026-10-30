<?php

namespace Tests\Unit\Shop;

use Tests\Unit\TestCase;
use WaaSClient\Shop\GalleryImages;

class GalleryImagesTest extends TestCase
{
    private function describe(): callable
    {
        return fn (int $id) => $id === 99 ? null : ['id' => $id, 'url' => 'https://shop.test/' . $id . '.png', 'name' => 'image-' . $id];
    }

    public function test_adds_the_woocommerce_gallery_after_the_featured_image(): void
    {
        $merged = GalleryImages::merge([['id' => 10, 'url' => 'https://shop.test/10.png', 'name' => 't-shirt']], [10, 21, 22], $this->describe());

        $this->assertSame([10, 21, 22], array_column($merged['gallery'], 'id'));
        $this->assertSame('t-shirt', $merged['gallery'][0]['name']);
        $this->assertSame(2, $merged['added']);
        $this->assertSame([], $merged['missing']);
    }

    public function test_a_second_run_adds_nothing(): void
    {
        $first = GalleryImages::merge([['id' => 10]], [10, 21], $this->describe());
        $second = GalleryImages::merge($first['gallery'], [10, 21], $this->describe());

        $this->assertSame(0, $second['added']);
        $this->assertSame($first['gallery'], $second['gallery']);
    }

    public function test_reports_images_no_longer_in_the_media_library(): void
    {
        $merged = GalleryImages::merge([], [0, 10, 99], $this->describe());

        $this->assertSame([10], array_column($merged['gallery'], 'id'));
        $this->assertSame([99], $merged['missing']);
    }
}
