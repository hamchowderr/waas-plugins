<?php

namespace Tests\Unit;

use AnyPressExport\ApiError;
use PHPUnit\Framework\TestCase;

final class ApiErrorTest extends TestCase
{
    public function testGatewayErrorsAndNoAnswerPass(): void
    {
        foreach ([0, 429, 502, 503, 504] as $status) $this->assertTrue((new ApiError('x', $status))->passing(), "HTTP $status");
    }

    public function testRealRefusalsDoNotPass(): void
    {
        foreach ([400, 403, 404, 409, 500] as $status) $this->assertFalse((new ApiError('x', $status))->passing(), "HTTP $status");
    }
}
