<?php

namespace Tests\Unit\Features;

use AnyPressClient\Core\SignedRequest;
use AnyPressClient\Features\DownloadSite;
use Brain\Monkey\Functions;
use Tests\Unit\TestCase;

/** Tools > Download my site (src/Features/DownloadSite.php), with the API's answer faked. */
class DownloadSiteTest extends TestCase
{
    /** @var list<array{url: string, args: array}> */
    private array $posts = [];
    /** @var array|null The API's next answer; null fails the request. */
    private ?array $answer = null;

    protected function setUp(): void
    {
        parent::setUp();
        putenv('ANYPRESS_IS_TENANT=true');
        putenv('ANYPRESS_SITE_KEY=' . str_repeat('k', 43));
        putenv('ANYPRESS_API_URL=https://api.example');
        $this->posts = [];
        Functions\when('home_url')->justReturn('https://Shop.Example.com');
        Functions\when('wp_parse_url')->alias(fn ($url, $part = -1) => parse_url($url, $part));
        Functions\when('is_wp_error')->justReturn(false);
        Functions\when('wp_remote_retrieve_response_code')->alias(fn ($r) => $r['response']['code']);
        Functions\when('wp_remote_retrieve_body')->alias(fn ($r) => $r['body']);
        Functions\when('add_action')->justReturn(true);
        Functions\when('get_option')->justReturn(false);
        Functions\when('get_transient')->justReturn(false);
        Functions\when('set_transient')->justReturn(true);
    }

    protected function tearDown(): void
    {
        putenv('ANYPRESS_IS_TENANT');
        putenv('ANYPRESS_SITE_KEY');
        putenv('ANYPRESS_API_URL');
        parent::tearDown();
    }

    private function page(): DownloadSite
    {
        return new DownloadSite(function (string $url, array $args) {
            $this->posts[] = ['url' => $url, 'args' => $args];
            return $this->answer === null
                ? ['response' => ['code' => 502], 'body' => '']
                : ['response' => ['code' => 200], 'body' => json_encode($this->answer)];
        });
    }

    public function testAsksForItsOwnSiteSignedWithItsKey(): void
    {
        $this->answer = ['download' => null];
        $this->assertSame(['download' => null], $this->page()->ask(false));
        $post = $this->posts[0];
        $this->assertSame('https://api.example/sites/download', $post['url']);
        $this->assertSame('{"site":"shop.example.com"}', $post['args']['body']);
        $header = $post['args']['headers']['X-AnyPress-Signature'];
        $this->assertTrue(SignedRequest::verify(str_repeat('k', 43), $header, 'POST', '/sites/download', $post['args']['body']));
    }

    public function testStartAsksForANewCopy(): void
    {
        $this->answer = ['download' => ['statusCode' => 0]];
        $this->assertSame(0, $this->page()->ask(true)['download']['statusCode']);
        $this->assertSame('{"site":"shop.example.com","start":true}', $this->posts[0]['args']['body']);
    }

    public function testNullWhenTheApiCannotBeReached(): void
    {
        $this->answer = null;
        $this->assertNull($this->page()->ask(false));
        $this->answer = ['something' => 'else'];
        $this->assertNull($this->page()->ask(false));
    }
}
