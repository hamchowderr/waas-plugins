<?php

namespace Tests\Unit;

use AnyPressExport\ImportCode;
use PHPUnit\Framework\TestCase;

final class ImportCodeTest extends TestCase
{
    private static function make(array $data): string
    {
        return 'AP1.' . rtrim(strtr(base64_encode(json_encode($data, JSON_UNESCAPED_SLASHES)), '+/', '-_'), '=');
    }

    public function testReadsTheApiAndTokenAndIgnoresWhitespace(): void
    {
        $code = ImportCode::parse("  " . chunk_split(self::make(['u' => 'https://api.anypress.io/', 't' => 'tok_en-1']), 20, "\n"));
        $this->assertSame('https://api.anypress.io', $code->apiUrl);
        $this->assertSame('tok_en-1', $code->token);
        $this->assertSame(self::make(['u' => 'https://api.anypress.io', 't' => 'tok_en-1']), $code->code());
        // The same code the API hands out (JSON.stringify, then base64url).
        $this->assertSame('AP1.eyJ1IjoiaHR0cHM6Ly9hcGkuYW55cHJlc3MuaW8iLCJ0IjoidG9rX2VuLTEifQ', $code->code());
    }

    public function testRefusesSomethingElse(): void
    {
        $this->expectExceptionMessage('starts with AP1.');
        ImportCode::parse('hello');
    }

    public function testRefusesAnIncompleteCode(): void
    {
        $this->expectExceptionMessage('incomplete');
        ImportCode::parse(substr(self::make(['u' => 'https://api.anypress.io', 't' => 'x']), 0, 12));
    }

    public function testRefusesPlainHttpUnlessAllowed(): void
    {
        $plain = self::make(['u' => 'http://localhost:4111', 't' => 'x']);
        $this->assertSame('http://localhost:4111', ImportCode::parse($plain, true)->apiUrl);
        $this->expectExceptionMessage('secure');
        ImportCode::parse($plain);
    }
}
