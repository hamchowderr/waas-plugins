<?php

namespace Tests\Unit\Crm;

use AnyPressClient\Crm\HighLevelLink;
use Brain\Monkey\Functions;
use Tests\Unit\TestCase;

/**
 * WP Fusion connected to a HighLevel site's sub-account with the API's token (src/Crm/HighLevelLink.php), with the
 * API's answer and the options faked.
 */
class HighLevelLinkTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $options = [];
    /** @var array<string, mixed> WP Fusion's settings. */
    private array $wpf = [];
    /** @var list<array{url: string, args: array}> */
    private array $posts = [];
    /** @var array|null The API's next answer; null fails the request. */
    private ?array $answer = null;
    private array $scheduled = [];

    protected function setUp(): void
    {
        parent::setUp();
        putenv('ANYPRESS_IS_TENANT=true');
        putenv('ANYPRESS_SITE_KEY=' . str_repeat('k', 43));
        putenv('ANYPRESS_API_URL=https://api.example');
        $this->options = [];
        $this->wpf = [];
        $this->posts = [];
        $this->scheduled = [];
        Functions\when('get_option')->alias(fn ($name, $default = false) => $this->options[$name] ?? $default);
        Functions\when('update_option')->alias(function ($name, $value) {
            $this->options[$name] = $value;
            return true;
        });
        Functions\when('delete_option')->alias(function ($name) {
            unset($this->options[$name]);
            return true;
        });
        Functions\when('home_url')->justReturn('https://Shop.Example.com');
        Functions\when('wp_parse_url')->alias(fn ($url, $part = -1) => parse_url($url, $part));
        Functions\when('is_wp_error')->alias(fn ($thing) => $thing instanceof \WP_Error);
        Functions\when('wp_remote_retrieve_response_code')->alias(fn ($r) => $r['response']['code']);
        Functions\when('wp_remote_retrieve_body')->alias(fn ($r) => $r['body']);
        Functions\when('wp_schedule_single_event')->alias(function ($time, $hook) {
            $this->scheduled[] = $hook;
            return true;
        });
        Functions\when('add_action')->justReturn(true);
        Functions\when('add_filter')->justReturn(true);
        Functions\when('wpf_get_option')->alias(fn ($key, $default = false) => $this->wpf[$key] ?? $default);
        $settings = new class ($this->wpf) {
            public $values;
            public function __construct(array &$values)
            {
                $this->values = &$values;
            }
            public function set_multiple(array $values): void
            {
                $this->values = array_merge($this->values, $values);
            }
            public function get($key)
            {
                return $this->values[$key] ?? false;
            }
        };
        Functions\when('wp_fusion')->justReturn((object) ['settings' => $settings]);
        if (!class_exists('WP_Error')) {
            eval('class WP_Error { public $code; public $data; function __construct($code = "", $message = "", $data = null) { $this->code = $code; $this->data = $data; } }');
        }
    }

    protected function tearDown(): void
    {
        putenv('ANYPRESS_IS_TENANT');
        putenv('ANYPRESS_SITE_KEY');
        putenv('ANYPRESS_API_URL');
        parent::tearDown();
    }

    private function link(): HighLevelLink
    {
        return new HighLevelLink(function (string $url, array $args) {
            $this->posts[] = ['url' => $url, 'args' => $args];
            return $this->answer === null ? new \WP_Error('down', 'down') : ['response' => ['code' => 200], 'body' => json_encode($this->answer)];
        });
    }

    private function linked(int $expiresIn = 86000): array
    {
        return ['crm' => 'highlevel', 'locationId' => 'loc-1', 'accessToken' => 'token-1', 'expiresAt' => gmdate('c', time() + $expiresIn)];
    }

    public function test_only_highlevel_stores_with_a_site_key(): void
    {
        define('ANYPRESS_TENANT_ROLES', 'highlevel');
        $this->assertTrue(HighLevelLink::applies());
        putenv('ANYPRESS_SITE_KEY');
        $this->assertFalse(HighLevelLink::applies());
    }

    public function test_connects_wp_fusion_to_the_sub_account_once(): void
    {
        $this->answer = $this->linked();
        $link = $this->link()->check();

        $this->assertSame(['location_id' => 'loc-1', 'token' => 'token-1'], array_slice($link, 0, 2));
        $this->assertCount(1, $this->posts);
        $this->assertSame('https://api.example/apps/site/crm-link', $this->posts[0]['url']);
        $this->assertSame('{"site":"shop.example.com"}', $this->posts[0]['args']['body']);
        $this->assertMatchesRegularExpression('/^t=\d+,n=[0-9a-f]{32},s=[0-9a-f]{64}$/', $this->posts[0]['args']['headers']['X-AnyPress-Signature']);

        $this->assertSame('highlevel', $this->wpf['crm']);
        $this->assertTrue($this->wpf['connection_configured']);
        $this->assertSame('loc-1', $this->wpf['highlevel_location_id']);
        $this->assertTrue($this->wpf['create_users']);
        $this->assertSame(['website user'], $this->wpf['assign_tags']);
        $this->assertSame('firstName', $this->wpf['contact_fields']['first_name']['crm_field']);
        $this->assertSame([HighLevelLink::SYNC_EVENT], $this->scheduled);

        // A fresh token isn't asked for again, and WP Fusion reads it.
        $this->link()->check();
        $this->assertCount(1, $this->posts);
        $this->assertSame('token-1', $this->link()->token(''));
        $this->assertSame('loc-1', $this->link()->location_id(''));
    }

    public function test_renews_a_token_about_to_end(): void
    {
        $this->wpf = ['crm' => 'highlevel', 'connection_configured' => true];
        $this->options[HighLevelLink::OPTION] = ['location_id' => 'loc-1', 'token' => 'old', 'expires' => time() + 600];
        $this->answer = ['accessToken' => 'token-2'] + $this->linked();
        $this->assertSame('token-2', $this->link()->token(''));
        $this->assertSame([], $this->scheduled, 'an existing connection is not set up again');
    }

    public function test_leaves_another_crm_alone(): void
    {
        $this->wpf = ['crm' => 'activecampaign', 'connection_configured' => true];
        $this->answer = $this->linked();
        $this->link()->check();
        $this->assertSame('activecampaign', $this->wpf['crm']);
        $this->assertSame('their-token', $this->link()->token('their-token'));
    }

    public function test_takes_over_from_the_stores_own_fluentcrm(): void
    {
        // A store made before HighLevel sites had their own plugins: WP Fusion connected to FluentCRM by the template.
        $this->wpf = ['crm' => 'fluentcrm', 'connection_configured' => true, 'contact_fields' => ['billing_address_2' => ['crm_field' => 'address_line_2', 'active' => false]]];
        $this->answer = $this->linked();
        $this->link()->check();
        $this->assertSame('highlevel', $this->wpf['crm']);
        $this->assertSame('loc-1', $this->wpf['highlevel_location_id']);
        $this->assertArrayNotHasKey('billing_address_2', $this->wpf['contact_fields'], "FluentCRM's field map is not kept");
        $this->assertSame('firstName', $this->wpf['contact_fields']['first_name']['crm_field']);
        $this->assertSame([HighLevelLink::SYNC_EVENT], $this->scheduled);
    }

    public function test_waits_after_a_failed_or_empty_answer(): void
    {
        $this->answer = null;
        $this->assertNull($this->link()->check());
        $this->link()->check();
        $this->assertCount(1, $this->posts, 'no second try straight after a failure');

        unset($this->options[HighLevelLink::RETRY_OPTION]);
        $this->answer = ['crm' => null];
        $this->assertNull($this->link()->check());
        $this->assertArrayNotHasKey('crm', $this->wpf);
        $this->assertGreaterThan(time() + 3000, $this->options[HighLevelLink::RETRY_OPTION]);
    }

    public function test_check_now_asks_the_api_whatever_was_kept(): void
    {
        // Untied while it waited: a fresh token kept, and a wait set.
        $this->options[HighLevelLink::OPTION] = ['location_id' => 'loc-1', 'token' => 'old', 'expires' => time() + 86000];
        $this->options[HighLevelLink::RETRY_OPTION] = time() + 3000;
        $this->answer = ['crm' => null];
        $this->assertSame(['linked' => false, 'locationId' => null, 'connected' => false, 'crm' => null], $this->link()->check_now());
        $this->assertCount(1, $this->posts);
        $this->assertArrayNotHasKey(HighLevelLink::OPTION, $this->options);

        // Tied: connected straight away, not after the hour's wait.
        $this->answer = $this->linked();
        $this->assertSame(['linked' => true, 'locationId' => 'loc-1', 'connected' => true, 'crm' => 'highlevel'], $this->link()->check_now());
        $this->assertCount(2, $this->posts);
        $this->assertSame('loc-1', $this->wpf['highlevel_location_id']);
    }

    public function test_check_now_only_answers_the_api(): void
    {
        if (!class_exists('WP_REST_Request')) {
            eval('class WP_REST_Request { function get_header($n) {} function get_method() {} function get_route() {} function get_query_params() {} function get_body() {} }');
        }
        $request = \Mockery::mock(\WP_REST_Request::class, [
            'get_header' => 't=' . time() . ',n=' . str_repeat('0', 32) . ',s=' . str_repeat('0', 64),
            'get_method' => 'POST',
            'get_route' => '/anypress-client/v1/site/crm-link',
            'get_query_params' => [],
            'get_body' => '',
        ]);
        $this->assertInstanceOf(\WP_Error::class, $this->link()->authorize($request));
    }

    public function test_brands_wp_fusion_and_highlevel_on_a_reseller_site(): void
    {
        $this->options['anypress_brand_name'] = 'KnowBS';
        Functions\when('__')->returnArg();
        Functions\when('is_admin')->justReturn(true);
        $link = $this->link();

        $crm = (object) ['slug' => 'highlevel', 'name' => 'HighLevel'];
        $link->brand_crm($crm);
        $this->assertSame('KnowBS CRM', $crm->name);
        $other = (object) ['slug' => 'activecampaign', 'name' => 'ActiveCampaign'];
        $link->brand_crm($other);
        $this->assertSame('ActiveCampaign', $other->name);

        $this->assertSame('KnowBS Contact Sync Settings', $link->brand_text('WP Fusion Settings', 'WP Fusion Settings', 'wp-fusion-lite'));
        $this->assertSame('WP Fusion Settings', $link->brand_text('WP Fusion Settings', 'WP Fusion Settings', 'default'));
    }

    public function test_maps_lead_source_to_custom_fields_of_the_same_name(): void
    {
        $this->options[HighLevelLink::OPTION] = ['location_id' => 'loc-1', 'token' => 't', 'expires' => time() + 86000];
        $this->wpf = [
            'crm' => 'highlevel',
            'crm_fields' => ['Custom Fields' => ['f1' => 'UTM Campaign', 'f2' => 'utm-medium', 'f3' => 'Plan']],
            'contact_fields' => ['utm_term' => ['crm_field' => 'kept', 'active' => true]],
        ];
        $crm = new class () {
            public function sync()
            {
                return true;
            }
        };
        $settings = new class ($this->wpf) {
            public $values;
            public function __construct(array &$values)
            {
                $this->values = &$values;
            }
            public function set($key, $value): void
            {
                $this->values[$key] = $value;
            }
        };
        Functions\when('wp_fusion')->justReturn((object) ['settings' => $settings, 'crm' => $crm]);

        $this->link()->sync();
        $this->assertSame('f1', $this->wpf['contact_fields']['utm_campaign']['crm_field']);
        $this->assertSame('f2', $this->wpf['contact_fields']['utm_medium']['crm_field']);
        $this->assertSame('kept', $this->wpf['contact_fields']['utm_term']['crm_field']);
        $this->assertArrayNotHasKey('gclid', $this->wpf['contact_fields']);
    }
}
