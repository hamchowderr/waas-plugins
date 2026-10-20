<?php

namespace Tests\Unit\Core;

use Brain\Monkey\Functions;
use Tests\Unit\TestCase;
use WaaSClient\Core\ConnectLink;

class ConnectLinkTest extends TestCase
{
    private const KEY = 'k3y-k3y-k3y-k3y-k3y-k3y-k3y-k3y-k3y';

    protected function setUp(): void
    {
        parent::setUp();
        Functions\when('wp_parse_url')->alias('parse_url');
    }

    private function link(array $change = []): array
    {
        $link = array_merge([
            'request' => '6f1c2a5e-0b8e-4c39-9a51-3f0d3c2b9e11',
            'app' => 'Claude',
            'to' => 'claude.ai',
            'write' => '1',
            'back' => 'https://api.anypress.io/oauth/site/answer',
            't' => (string) (time() + 600),
        ], $change);

        return $link + ['s' => ConnectLink::sign(self::KEY, $link)];
    }

    public function test_a_link_the_api_signed_passes(): void
    {
        $link = $this->link();

        $this->assertSame(array_diff_key($link, ['s' => 1]), ConnectLink::verify(self::KEY, $link));
    }

    public function test_the_api_signature_matches(): void
    {
        // The same fields signed by the API's connectLinkSignature (src/sitekeys.ts).
        $fields = ['request' => 'r1', 'app' => 'Claude', 'to' => 'claude.ai', 'write' => '1', 'back' => 'https://api.example.test/oauth/site/answer', 't' => '2000000000'];
        $this->assertSame(hash_hmac('sha256', "waas-connect\nr1\nClaude\nclaude.ai\n1\nhttps://api.example.test/oauth/site/answer\n2000000000", self::KEY), ConnectLink::sign(self::KEY, $fields));
    }

    public function test_a_changed_expired_or_unsigned_link_fails(): void
    {
        $changed = $this->link();
        $changed['app'] = 'Evil App';
        $this->assertNull(ConnectLink::verify(self::KEY, $changed), 'changed after signing');

        $changed = $this->link();
        $changed['back'] = 'https://evil.example/answer';
        $this->assertNull(ConnectLink::verify(self::KEY, $changed), 'answer sent elsewhere');

        $this->assertNull(ConnectLink::verify(self::KEY, $this->link(['t' => (string) (time() - 1)])), 'expired');
        $this->assertNull(ConnectLink::verify('another-key-another-key-another-key', $this->link()), 'another store');
        $this->assertNull(ConnectLink::verify('', $this->link()), 'no site key');
        $this->assertNull(ConnectLink::verify(self::KEY, $this->link(['back' => 'http://api.anypress.io/oauth/site/answer'])), 'not https');
        $unsigned = $this->link();
        unset($unsigned['s']);
        $this->assertNull(ConnectLink::verify(self::KEY, $unsigned), 'unsigned');
    }

    public function test_the_answer_is_signed_for_the_api(): void
    {
        $link = $this->link();
        $url = ConnectLink::answer_url(self::KEY, $link, 'allow-read');

        $this->assertStringStartsWith('https://api.anypress.io/oauth/site/answer?', $url);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $this->assertSame($link['request'], $query['request']);
        $this->assertSame('allow-read', $query['answer']);
        $this->assertSame(hash_hmac('sha256', "waas-connect-answer\n{$link['request']}\nallow-read", self::KEY), $query['s']);
    }
}
