<?php

namespace Tests\Unit\Api;

use AnyPressHost\Api\ProspectsReceiver;
use AnyPressHost\Core\SecretBox;
use AnyPressHost\Features\Webhooks;
use Brain\Monkey\Functions;
use Tests\Unit\TestCase;
use WP_REST_Request;

/** The API's prospect calls: signed like its webhooks, used once, only with FluentCRM, and their notes escaped. */
class ProspectsReceiverTest extends TestCase
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
        Functions\when('esc_url')->alias(fn ($url) => htmlspecialchars((string) $url, ENT_QUOTES, 'UTF-8'));
        Functions\when('sanitize_text_field')->alias(fn ($text) => trim(strip_tags((string) $text)));
        Functions\when('sanitize_email')->alias(fn ($email) => trim((string) $email));
        $this->options[Webhooks::OPTION] = ['id' => 'ep-1', 'secret' => SecretBox::seal(self::SECRET, 'anypress-host-webhook-secret')];
    }

    private static function sign(string $secret, string $id, string $timestamp, string $body): string
    {
        $key = base64_decode(substr($secret, strlen('whsec_')));
        return 'v1,' . base64_encode(hash_hmac('sha256', $id . '.' . $timestamp . '.' . $body, $key, true));
    }

    private function request(array $data, string $id = 'pro_1', ?string $signature = null): WP_REST_Request
    {
        $body = json_encode($data);
        $timestamp = (string) time();
        $request = new WP_REST_Request('POST', '/anypress-host/v1/prospects');
        $request->set_header('webhook-id', $id);
        $request->set_header('webhook-timestamp', $timestamp);
        $request->set_header('webhook-signature', $signature ?? self::sign(self::SECRET, $id, $timestamp, $body));
        $request->set_body($body);
        return $request;
    }

    public function test_a_bad_signature_gets_401(): void
    {
        $response = (new ProspectsReceiver())->handle($this->request(['contacts' => []], 'pro_1', 'v1,' . base64_encode('nope')));
        $this->assertSame(401, $response->get_status());
    }

    public function test_without_fluentcrm_a_signed_call_gets_503(): void
    {
        $this->assertFalse(ProspectsReceiver::available());
        $response = (new ProspectsReceiver())->handle($this->request(['contacts' => []]));
        $this->assertSame(503, $response->get_status());
        $this->assertSame('no_crm', $response->get_data()['code']);
    }

    public function test_the_same_signed_request_works_only_once(): void
    {
        $receiver = new ProspectsReceiver();
        $request = $this->request(['contacts' => []], 'pro_2');
        $this->assertSame(503, $receiver->handle($request)->get_status());
        $this->assertSame(409, $receiver->handle($request)->get_status());
    }

    public function test_the_note_lists_what_was_found_escaped_and_drops_links_that_are_not_web_addresses(): void
    {
        $note = ProspectsReceiver::note([
            'type' => 'Bakery <b>',
            'website' => 'https://bakery.example/',
            'rating' => 4.6,
            'reviews' => 120,
            'socials' => [['network' => 'instagram', 'url' => 'https://instagram.com/bakery'], ['network' => 'x', 'url' => 'javascript:alert(1)']],
            'hours' => ['Mon 8-5'],
            'demoUrl' => 'https://demo.anypress.site/bakery',
            'websiteScore' => 62,
            'websitePlatform' => 'Wix',
        ]);
        $this->assertStringContainsString('<li>Type: Bakery</li>', $note);
        $this->assertStringContainsString('<a href="https://bakery.example/">', $note);
        $this->assertStringContainsString('Google rating: 4.6 (120 reviews)', $note);
        $this->assertStringContainsString('Instagram: <a href="https://instagram.com/bakery">', $note);
        $this->assertStringNotContainsString('javascript', $note);
        $this->assertStringContainsString('Their demo: <a href="https://demo.anypress.site/bakery">', $note);
        $this->assertStringContainsString('Website check: 62 of 100', $note);
        $this->assertStringContainsString('Website runs on: Wix', $note);
        $this->assertStringContainsString('Hours:<br>Mon 8-5', $note);
    }

    public function test_the_mail_guard_leaves_emails_alone_without_fluentcrm(): void
    {
        $this->assertFalse(ProspectsReceiver::skip_prospect_mail(false, ['to' => ['email' => 'owner@bakery.example']]));
        $this->assertTrue(ProspectsReceiver::skip_prospect_mail(true, ['to' => ['email' => 'owner@bakery.example']]));
    }

    public function test_a_known_person_names_the_contact_and_a_shared_inbox_keeps_the_business_name(): void
    {
        $this->assertSame(['first_name' => 'Dana', 'last_name' => 'Rivera Cole'], ProspectsReceiver::names(['name' => 'Bakery Co', 'person' => ' Dana  Rivera Cole '], null));
        $this->assertSame(['first_name' => 'Bakery Co', 'last_name' => ''], ProspectsReceiver::names(['name' => 'Bakery Co'], null));
    }

    public function test_an_existing_contact_keeps_a_typed_name_but_the_finders_business_name_gives_way_to_the_person(): void
    {
        $this->assertSame(['first_name' => 'Dana', 'last_name' => 'Rivera'], ProspectsReceiver::names(['name' => 'Bakery Co', 'person' => 'Dana Rivera'], ['first_name' => 'Bakery Co', 'last_name' => '']));
        $this->assertSame([], ProspectsReceiver::names(['name' => 'Bakery Co', 'person' => 'Dana Rivera'], ['first_name' => 'Sam', 'last_name' => 'Lee']));
        $this->assertSame([], ProspectsReceiver::names(['name' => 'Bakery Co'], ['first_name' => 'Bakery Co', 'last_name' => '']));
    }

    public function test_custom_values_fill_only_fields_the_crm_has_and_dropdowns_take_only_their_options(): void
    {
        $defs = [
            ['slug' => 'customer_group', 'type' => 'select-one', 'options' => ['Point of Sale Companies', 'Churches and Ministries']],
            ['slug' => 'job_title', 'type' => 'text'],
            ['slug' => 'outreach_route', 'type' => 'select-one', 'options' => ['Affiliate', 'Seller']],
        ];
        $c = ['customerGroup' => 'Point of Sale Companies', 'jobTitle' => 'Owner', 'route' => 'Reseller'];
        $this->assertSame(['customer_group' => 'Point of Sale Companies', 'job_title' => 'Owner'], ProspectsReceiver::custom_values($c, $defs, []));
        $this->assertSame(['job_title' => 'Owner'], ProspectsReceiver::custom_values($c, $defs, ['customer_group' => 'Churches and Ministries']));
        $this->assertSame([], ProspectsReceiver::custom_values($c, [['slug' => 'other', 'type' => 'text']], []));
    }

    public function test_only_offering_stage_tags_count_as_lifecycle_tags(): void
    {
        $this->assertTrue(ProspectsReceiver::is_lifecycle_tag('Seller: Interested'));
        $this->assertTrue(ProspectsReceiver::is_lifecycle_tag('Website: Past'));
        $this->assertFalse(ProspectsReceiver::is_lifecycle_tag('Prospect'));
        $this->assertFalse(ProspectsReceiver::is_lifecycle_tag('Prospect: Point of sale'));
    }
}
