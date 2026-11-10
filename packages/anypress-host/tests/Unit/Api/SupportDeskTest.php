<?php

namespace Tests\Unit\Api;

use Brain\Monkey\Functions;
use Tests\Unit\TestCase;
use AnyPressHost\Api\SupportDesk;
use AnyPressHost\Core\SecretBox;
use AnyPressHost\Features\Webhooks;
use WP_REST_Request;

/** The API's support calls: signed like its webhooks, used once, and only with Fluent Support active. */
class SupportDeskTest extends TestCase
{
    private const SECRET = 'whsec_MfKQ9r8GKYqrTwjUPD8ILPZIo2LaLaSw';

    private array $options = [];
    private array $transients = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->options = [];
        $this->transients = [];
        Functions\when('wp_salt')->justReturn('auth-salt');
        Functions\when('get_option')->alias(fn ($name, $default = false) => $this->options[$name] ?? $default);
        Functions\when('get_transient')->alias(fn ($name) => $this->transients[$name] ?? false);
        Functions\when('set_transient')->alias(function ($name, $value) {
            $this->transients[$name] = $value;
            return true;
        });
        Functions\when('esc_html')->alias(fn ($text) => htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8'));
        Functions\when('wp_strip_all_tags')->alias(fn ($text) => strip_tags((string) $text));
        Functions\when('get_gmt_from_date')->alias(fn ($date) => $date);
        $this->options[Webhooks::OPTION] = ['id' => 'ep-1', 'secret' => SecretBox::seal(self::SECRET, 'anypress-host-webhook-secret')];
    }

    private static function sign(string $secret, string $id, string $timestamp, string $body): string
    {
        $key = base64_decode(substr($secret, strlen('whsec_')));
        return 'v1,' . base64_encode(hash_hmac('sha256', $id . '.' . $timestamp . '.' . $body, $key, true));
    }

    private function request(array $data, string $id = 'sup_1', ?string $signature = null): WP_REST_Request
    {
        $body = json_encode($data);
        $timestamp = (string) time();
        $request = new WP_REST_Request('POST', '/anypress-host/v1/support');
        $request->set_header('webhook-id', $id);
        $request->set_header('webhook-timestamp', $timestamp);
        $request->set_header('webhook-signature', $signature ?? self::sign(self::SECRET, $id, $timestamp, $body));
        $request->set_body($body);
        return $request;
    }

    public function test_a_bad_signature_gets_401(): void
    {
        $response = (new SupportDesk())->handle($this->request(['action' => 'list'], 'sup_1', 'v1,' . base64_encode('nope')));
        $this->assertSame(401, $response->get_status());
    }

    public function test_without_fluent_support_a_signed_call_gets_503(): void
    {
        $this->assertFalse(SupportDesk::available());
        $response = (new SupportDesk())->handle($this->request(['action' => 'list', 'customer' => ['email' => 'owner@example.com']]));
        $this->assertSame(503, $response->get_status());
        $this->assertSame('no_desk', $response->get_data()['code']);
    }

    public function test_the_same_signed_request_works_only_once(): void
    {
        $desk = new SupportDesk();
        $request = $this->request(['action' => 'list', 'customer' => ['email' => 'owner@example.com']], 'sup_2');
        $this->assertSame(503, $desk->handle($request)->get_status());
        $this->assertSame(409, $desk->handle($request)->get_status());
    }

    public function test_messages_are_stored_as_escaped_paragraphs_and_read_back_as_text(): void
    {
        $stored = SupportDesk::message("The booking page says <b>error</b>.\nSince today.\n\nPlease help");
        $this->assertSame('<p>The booking page says &lt;b&gt;error&lt;/b&gt;.<br>Since today.</p><p>Please help</p>', $stored);
        $this->assertSame("The booking page says <b>error</b>.\nSince today.\n\nPlease help", SupportDesk::plain($stored));
        $this->assertSame('', SupportDesk::message("  \n "));
    }

    public function test_a_ticket_shows_only_its_own_fields(): void
    {
        $ticket = (object) ['id' => '41', 'title' => 'Help', 'status' => 'new', 'created_at' => '2026-11-10 03:20:00', 'updated_at' => null, 'content' => '<p>x</p>', 'customer_id' => 3, 'hash' => 'secret', 'agent_id' => 5];
        $this->assertSame(
            ['id' => 41, 'title' => 'Help', 'status' => 'new', 'createdAt' => '2026-11-10T03:20:00+00:00', 'updatedAt' => null, 'url' => null],
            SupportDesk::ticket_view($ticket, false)
        );
    }
}
