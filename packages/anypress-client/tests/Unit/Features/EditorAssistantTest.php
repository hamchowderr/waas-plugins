<?php

namespace Tests\Unit\Features;

use Brain\Monkey\Functions;
use Tests\Unit\TestCase;
use AnyPressClient\Core\SignedRequest;
use AnyPressClient\Features\EditorAssistant;

class EditorAssistantTest extends TestCase
{
    private const KEY = 'k3y-k3y-k3y-k3y-k3y-k3y-k3y-k3y-k3y';

    private array $sent = [];
    private array $answer = [];

    protected function setUp(): void
    {
        parent::setUp();
        putenv(SignedRequest::KEY_ENV . '=' . self::KEY);
        $this->sent = [];
        $this->answer = ['code' => 200, 'body' => '{"url":"https://bakery.example/.assistant/from-shop?handoff=abc"}'];
        Functions\when('home_url')->justReturn('https://Bakery.example/');
        Functions\when('wp_parse_url')->alias(fn ($url, $part = -1) => parse_url($url, $part));
        Functions\when('is_wp_error')->alias(fn ($thing) => $thing instanceof \WP_Error);
        Functions\when('__')->returnArg();
        Functions\when('get_transient')->justReturn(false);
        Functions\when('set_transient')->justReturn(true);
        Functions\when('wp_remote_post')->alias(function ($url, $args) {
            $this->sent[] = ['url' => $url, 'args' => $args];
            return ['response' => ['code' => $this->answer['code']], 'body' => $this->answer['body']];
        });
        Functions\when('wp_remote_retrieve_response_code')->alias(fn ($response) => $response['response']['code']);
        Functions\when('wp_remote_retrieve_body')->alias(fn ($response) => $response['body']);
        if (!class_exists('WP_Error')) {
            eval('class WP_Error { public $code; public $data; function __construct($code = "", $message = "", $data = null) { $this->code = $code; $this->data = $data; } }');
        }
    }

    protected function tearDown(): void
    {
        putenv(SignedRequest::KEY_ENV);
        parent::tearDown();
    }

    public function test_asks_the_api_for_a_sign_in_signed_with_the_store_key(): void
    {
        $answer = (new EditorAssistant())->handoff();

        $this->assertSame(['url' => 'https://bakery.example/.assistant/from-shop?handoff=abc'], $answer);
        $this->assertCount(1, $this->sent);
        $this->assertSame('https://api.anypress.io/oauth/site/store-handoff', $this->sent[0]['url']);
        $body = $this->sent[0]['args']['body'];
        // The assistant on the website's own domain.
        $this->assertSame(['site' => 'bakery.example', 'assistantUrl' => 'https://bakery.example/.assistant'], json_decode($body, true));
        $this->assertTrue(SignedRequest::verify(self::KEY, $this->sent[0]['args']['headers']['X-AnyPress-Signature'], 'POST', '/oauth/site/store-handoff', $body));
    }

    public function test_never_frames_an_address_that_is_not_the_assistant(): void
    {
        $this->answer['body'] = '{"url":"https://evil.example/from-shop?handoff=abc"}';
        $this->assertInstanceOf(\WP_Error::class, (new EditorAssistant())->handoff());

        // A look-alike address that only starts with the assistant's.
        $this->answer['body'] = '{"url":"https://bakery.example/.assistant.evil/from-shop"}';
        $this->assertInstanceOf(\WP_Error::class, (new EditorAssistant())->handoff());
        $this->answer['body'] = '{"url":"https://bakery.example.evil.example/.assistant/from-shop"}';
        $this->assertInstanceOf(\WP_Error::class, (new EditorAssistant())->handoff());

        $this->answer = ['code' => 403, 'body' => '{"message":"no"}'];
        $this->assertInstanceOf(\WP_Error::class, (new EditorAssistant())->handoff());
    }

    public function test_the_assistant_is_on_the_websites_own_domain_unless_set(): void
    {
        $this->assertSame('https://bakery.example/.assistant', EditorAssistant::assistant_url());

        putenv('ANYPRESS_ASSISTANT_URL=https://assistant.example/');
        try {
            $this->assertSame('https://assistant.example', EditorAssistant::assistant_url());
        } finally {
            putenv('ANYPRESS_ASSISTANT_URL');
        }
    }

    public function test_only_on_a_store_for_people_who_can_change_its_design(): void
    {
        Functions\when('current_user_can')->alias(fn ($cap) => $cap === 'edit_theme_options');
        $this->assertTrue(EditorAssistant::is_available());

        Functions\when('current_user_can')->justReturn(false);
        $this->assertFalse(EditorAssistant::is_available());

        Functions\when('current_user_can')->justReturn(true);
        putenv(SignedRequest::KEY_ENV);
        $this->assertFalse(EditorAssistant::is_available());
    }

    /**
     * A demo on anypress.io/try: no site key, the demo sign-in anypress.io put in it, and the demos' own chat.
     *
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_a_demo_opens_the_demo_chat_with_its_own_sign_in(): void
    {
        putenv(SignedRequest::KEY_ENV);
        $token = 'apdemo_' . str_repeat('a', 43);
        define('ANYPRESS_DEMO_ASSISTANT', $token);
        Functions\when('current_user_can')->justReturn(true);

        $this->assertTrue(EditorAssistant::is_available());
        $this->assertSame('https://demo.anypress.site/.assistant', EditorAssistant::assistant_url());
        $this->assertSame(['url' => 'https://demo.anypress.site/.assistant/from-demo?demo=' . $token], (new EditorAssistant())->handoff());
        $this->assertSame([], $this->sent);
    }

    public function test_no_panel_without_a_site_key_or_a_demo_sign_in(): void
    {
        putenv(SignedRequest::KEY_ENV);
        Functions\when('current_user_can')->justReturn(true);

        $this->assertFalse(EditorAssistant::is_available());
        $this->assertSame('', EditorAssistant::demo_token());
    }
}
