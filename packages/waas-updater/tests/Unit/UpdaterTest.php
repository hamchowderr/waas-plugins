<?php

namespace Tests\Unit;

use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use WaaSUpdater\Updater;

/**
 * Updates from our own channel (waas-hw4): what is offered, what is cached, and that nothing is
 * installed unless the download matches its checksum and a trusted key signed it.
 */
class UpdaterTest extends TestCase
{
    const SLUG = 'wpcs-waas-host';
    const BASENAME = 'waas-host/index.php';
    const API = 'https://waas-api.example.test';

    /** @var string */
    private $secret_key;
    /** @var string */
    private $public_key;
    /** @var array */
    private $transients = [];
    /** @var array Requests sent to the API: [url, args]. */
    private $requests = [];
    /** @var array|\WP_Error What the API answers next. */
    private $answer;
    /** @var string[] Temporary files to remove. */
    private $files = [];

    protected function setUp(): void
    {
        parent::setUp();
        $pair = sodium_crypto_sign_keypair();
        $this->secret_key = sodium_crypto_sign_secretkey($pair);
        $this->public_key = base64_encode(sodium_crypto_sign_publickey($pair));
        $this->transients = [];
        $this->requests = [];

        Functions\when('is_wp_error')->alias(function ($v) { return $v instanceof \WP_Error; });
        Functions\when('wp_parse_url')->alias('parse_url');
        Functions\when('sanitize_url')->alias(function ($url) { return strpos($url, '://') === false ? 'http://' . $url : $url; });
        Functions\when('untrailingslashit')->alias(function ($s) { return rtrim($s, '/\\'); });
        Functions\when('trailingslashit')->alias(function ($s) { return rtrim($s, '/\\') . '/'; });
        Functions\when('esc_html')->alias('htmlspecialchars');
        Functions\when('home_url')->justReturn('https://shop.example.test');
        Functions\when('get_bloginfo')->justReturn('6.8.1');
        Functions\when('get_site_transient')->alias(function ($k) { return array_key_exists($k, $this->transients) ? $this->transients[$k][0] : false; });
        Functions\when('set_site_transient')->alias(function ($k, $v, $ttl) { $this->transients[$k] = [$v, $ttl]; return true; });
        Functions\when('delete_site_transient')->alias(function ($k) { unset($this->transients[$k]); return true; });
        Functions\when('wp_remote_get')->alias(function ($url, $args) {
            $this->requests[] = [$url, $args];
            return $this->answer;
        });
        Functions\when('wp_remote_retrieve_response_code')->alias(function ($r) { return $r['response']['code']; });
        Functions\when('wp_remote_retrieve_body')->alias(function ($r) { return $r['body']; });
    }

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            @unlink($file);
        }
        parent::tearDown();
    }

    private function updater(array $overrides = []): Updater
    {
        return new Updater($overrides + [
            'slug' => self::SLUG,
            'name' => 'WaaS Host',
            'basename' => self::BASENAME,
            'version' => '2.2.7',
            'update_uri' => 'wpcs-waas-host',
            'public_keys' => [$this->public_key],
            'credentials' => function () {
                return ['base_url' => self::API . '/', 'key' => 'key-id', 'secret' => 'not-a-secret'];
            },
        ]);
    }

    private function zip(string $contents = 'zip bytes'): string
    {
        $file = tempnam(sys_get_temp_dir(), 'waas');
        file_put_contents($file, $contents);
        $this->files[] = $file;
        return $file;
    }

    private function sign(string $version, string $sha256, ?string $secret_key = null, string $slug = self::SLUG): string
    {
        return base64_encode(sodium_crypto_sign_detached("waas-release-v1\n$slug\n$version\n$sha256", $secret_key ?? $this->secret_key));
    }

    private function release(string $version = '2.3.0', array $extra = []): array
    {
        $sha256 = hash('sha256', 'zip bytes');
        return $extra + [
            'slug' => self::SLUG,
            'version' => $version,
            'channel' => 'stable',
            'pinned' => false,
            'package' => 'https://r2.example.test/releases/wpcs-waas-host/' . $version . '/wpcs-waas-host.zip?X-Amz-Signature=abc',
            'packageExpiresIn' => 600,
            'sha256' => $sha256,
            'signature' => $this->sign($version, $sha256),
            'requires' => '6.4',
            'requiresPhp' => '8.1',
            'tested' => '6.8',
            'releasedAt' => '2026-10-07T12:00:00.000Z',
            'changelog' => "* Our own update channel\n* <b>safe</b>",
        ];
    }

    private function answers(int $code, $body): void
    {
        $this->answer = ['response' => ['code' => $code], 'body' => is_string($body) ? $body : json_encode($body)];
    }

    public function test_it_registers_its_hooks_on_the_update_uri_host(): void
    {
        $this->assertTrue($this->updater()->register());
        $this->assertNotFalse(has_filter('update_plugins_wpcs-waas-host'));
        $this->assertNotFalse(has_filter('upgrader_pre_download'));
        $this->assertNotFalse(has_filter('upgrader_source_selection'));
        $this->assertNotFalse(has_filter('auto_update_plugin'));
    }

    public function test_without_a_trusted_key_nothing_is_registered_or_asked(): void
    {
        $this->assertSame([], Updater::PUBLIC_KEYS, 'Keys are added only when the owner hands over the public key.');
        $this->assertFalse($this->updater(['public_keys' => []])->register());
        $this->assertFalse(has_filter('update_plugins_wpcs-waas-host'));
        $this->assertSame([], $this->requests);
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_channel_off_registers_nothing(): void
    {
        define('WAAS_UPDATE_CHANNEL', 'off');
        $this->assertNull(Updater::channel());
        $this->assertFalse($this->updater()->register());
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_the_beta_channel_is_asked_for_when_set(): void
    {
        define('WAAS_UPDATE_CHANNEL', 'beta');
        $this->answers(200, $this->release('2.3.0-beta.1'));
        $update = $this->updater()->check_update(false, [], self::BASENAME);
        $this->assertSame('2.3.0-beta.1', $update['new_version']);
        $this->assertStringContainsString('channel=beta', $this->requests[0][0]);
    }

    public function test_it_offers_the_release_with_a_package_marker_not_the_expiring_link(): void
    {
        $this->answers(200, $this->release());
        $update = $this->updater()->check_update(false, ['Version' => '2.2.7'], self::BASENAME);

        $this->assertSame('2.3.0', $update['new_version']);
        $this->assertSame('waas-update://wpcs-waas-host/2.3.0', $update['package']);
        $this->assertSame('wpcs-waas-host', $update['id']);
        $this->assertSame(self::BASENAME, $update['plugin']);
        $this->assertSame('8.1', $update['requires_php']);

        [$url, $args] = $this->requests[0];
        $this->assertSame(self::API . '/v1/plugins/wpcs-waas-host/update?version=2.2.7&channel=stable&site=https%3A%2F%2Fshop.example.test&wp=6.8.1&php=' . rawurlencode(PHP_VERSION), $url);
        $this->assertSame('Basic ' . base64_encode('key-id:not-a-secret'), $args['headers']['Authorization']);
        $this->assertSame(10, $args['timeout']);
    }

    public function test_other_plugins_are_left_alone(): void
    {
        $this->assertSame('theirs', $this->updater()->check_update('theirs', [], 'akismet/akismet.php'));
        $this->assertSame([], $this->requests);
    }

    public function test_a_release_is_cached_for_twelve_hours(): void
    {
        $this->answers(200, $this->release());
        $this->updater()->check_update(false, [], self::BASENAME);
        $this->assertSame(Updater::CACHE_TTL, $this->transients['waas_update_wpcs-waas-host_stable'][1]);

        // A new request (a new Updater) reads the cache, not the API.
        $update = $this->updater()->check_update(false, [], self::BASENAME);
        $this->assertSame('2.3.0', $update['new_version']);
        $this->assertCount(1, $this->requests);
    }

    public function test_check_again_asks_the_channel_not_the_cache(): void
    {
        $this->answers(200, $this->release());
        $this->updater()->check_update(false, [], self::BASENAME);
        $_GET['force-check'] = '1';
        try {
            $this->updater()->check_update(false, [], self::BASENAME);
        } finally {
            unset($_GET['force-check']);
        }
        $this->assertCount(2, $this->requests);
    }

    public function test_an_unreachable_channel_offers_nothing_and_is_asked_again_only_after_an_hour(): void
    {
        $this->answer = new \WP_Error('http_request_failed', 'cURL error 28: timed out');
        $this->assertFalse($this->updater()->check_update(false, [], self::BASENAME));
        $this->assertSame(Updater::FAILURE_TTL, $this->transients['waas_update_wpcs-waas-host_stable'][1]);

        $this->assertFalse($this->updater()->check_update(false, [], self::BASENAME));
        $this->assertCount(1, $this->requests);
    }

    /** @dataProvider bad_answers */
    public function test_errors_and_invalid_answers_offer_nothing(int $code, $body): void
    {
        $this->answers($code, $body);
        $this->assertFalse($this->updater()->check_update(false, [], self::BASENAME));
        $this->assertArrayHasKey('error', $this->transients['waas_update_wpcs-waas-host_stable'][0]);
    }

    public function bad_answers(): array
    {
        $ok = [
            'slug' => self::SLUG, 'version' => '2.3.0', 'package' => 'https://r2.example.test/x.zip',
            'sha256' => str_repeat('a', 64), 'signature' => 'c2ln',
        ];
        return [
            'nothing released' => [404, ['statusCode' => 404, 'message' => 'No stable release of wpcs-waas-host']],
            'not configured' => [503, ['statusCode' => 503, 'message' => 'Plugin updates are not configured']],
            'bad credentials' => [403, ['statusCode' => 403, 'message' => 'Invalid API key or secret']],
            'not JSON' => [200, '<html>proxy error</html>'],
            'another plugin' => [200, ['slug' => 'waas-payments'] + $ok],
            'bad version' => [200, ['version' => 'latest'] + $ok],
            'not a URL' => [200, ['package' => 'file:///etc/passwd'] + $ok],
            'bad checksum' => [200, ['sha256' => 'abc'] + $ok],
        ];
    }

    public function test_without_credentials_nothing_is_asked(): void
    {
        $updater = $this->updater(['credentials' => function () { return null; }]);
        $this->assertFalse($updater->check_update(false, [], self::BASENAME));
        $this->assertSame([], $this->requests);
    }

    public function test_by_default_credentials_come_from_the_waas_update_credentials_filter(): void
    {
        Filters\expectApplied('waas_update_credentials')->once()->with(null)
            ->andReturn(['base_url' => 'https://api.from-filter.test', 'key' => 'k', 'secret' => 's']);
        $this->answers(200, $this->release());
        $updater = new Updater(['slug' => self::SLUG, 'name' => 'WaaS Host', 'basename' => self::BASENAME, 'version' => '2.2.7', 'update_uri' => 'wpcs-waas-host', 'public_keys' => [$this->public_key]]);

        $updater->check_update(false, [], self::BASENAME);
        $this->assertStringStartsWith('https://api.from-filter.test/v1/plugins/wpcs-waas-host/update?', $this->requests[0][0]);
    }

    public function test_view_details_shows_the_release_and_escapes_the_changelog(): void
    {
        $this->answers(200, $this->release());
        $info = $this->updater()->plugin_information(false, 'plugin_information', (object) ['slug' => self::SLUG]);
        $this->assertSame('2.3.0', $info->version);
        $this->assertSame('waas-update://wpcs-waas-host/2.3.0', $info->download_link);
        $this->assertStringContainsString('&lt;b&gt;safe&lt;/b&gt;', $info->sections['changelog']);

        $this->assertFalse($this->updater()->plugin_information(false, 'plugin_information', (object) ['slug' => 'akismet']));
        $this->assertFalse($this->updater()->plugin_information(false, 'query_plugins', (object) ['slug' => self::SLUG]));
    }

    public function test_install_downloads_a_fresh_link_and_returns_the_verified_file(): void
    {
        $zip = $this->zip();
        $this->answers(200, $this->release());
        $this->updater()->check_update(false, [], self::BASENAME); // cached, link now stale
        $fresh = $this->release('2.3.0', ['package' => 'https://r2.example.test/fresh.zip']);
        $this->answers(200, $fresh);
        Functions\expect('download_url')->once()->with('https://r2.example.test/fresh.zip', 300)->andReturn($zip);

        $this->assertSame($zip, $this->updater()->pre_download(false, 'waas-update://wpcs-waas-host/2.3.0'));
        $this->assertCount(2, $this->requests);
    }

    public function test_other_packages_and_earlier_replies_pass_through(): void
    {
        Functions\expect('download_url')->never();
        $this->assertFalse($this->updater()->pre_download(false, 'https://downloads.wordpress.org/plugin/akismet.zip'));
        $this->assertFalse($this->updater()->pre_download(false, 'waas-update://waas-payments/2.3.0'));
        $this->assertSame('/tmp/x.zip', $this->updater()->pre_download('/tmp/x.zip', 'waas-update://wpcs-waas-host/2.3.0'));
        $this->assertSame([], $this->requests);
    }

    public function test_a_download_that_does_not_match_its_checksum_is_refused_and_deleted(): void
    {
        $zip = $this->zip('tampered bytes');
        $this->answers(200, $this->release());
        Functions\when('download_url')->justReturn($zip);

        $result = $this->updater()->pre_download(false, 'waas-update://wpcs-waas-host/2.3.0');
        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('waas_update_checksum', $result->get_error_code());
        $this->assertFileDoesNotExist($zip);
    }

    /** @dataProvider bad_signatures */
    public function test_a_download_without_a_trusted_signature_is_refused_and_deleted(callable $signature): void
    {
        $zip = $this->zip();
        $release = $this->release();
        $release['signature'] = $signature($this, $release['sha256']);
        $this->answers(200, $release);
        Functions\when('download_url')->justReturn($zip);

        $result = $this->updater()->pre_download(false, 'waas-update://wpcs-waas-host/2.3.0');
        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('waas_update_signature', $result->get_error_code());
        $this->assertFileDoesNotExist($zip);
    }

    public function bad_signatures(): array
    {
        return [
            'another key' => [function ($t, $sha) { return $t->sign('2.3.0', $sha, sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair())); }],
            'another version' => [function ($t, $sha) { return $t->sign('2.2.9', $sha); }],
            'another plugin' => [function ($t, $sha) { return $t->sign('2.3.0', $sha, null, 'waas-payments'); }],
            'not base64' => [function () { return '!!!'; }],
            'too short' => [function () { return base64_encode('short'); }],
            'empty' => [function () { return ''; }],
        ];
    }

    public function test_a_second_key_works_during_a_rotation(): void
    {
        $old = sodium_crypto_sign_keypair();
        $zip = $this->zip();
        $this->answers(200, $this->release());
        Functions\when('download_url')->justReturn($zip);

        $updater = $this->updater(['public_keys' => [base64_encode(sodium_crypto_sign_publickey($old)), 'not-a-key', $this->public_key]]);
        $this->assertSame($zip, $updater->pre_download(false, 'waas-update://wpcs-waas-host/2.3.0'));
    }

    public function test_install_stops_if_the_release_changed_since_the_check(): void
    {
        Functions\expect('download_url')->never();
        $this->answers(200, $this->release('2.3.1'));
        $result = $this->updater()->pre_download(false, 'waas-update://wpcs-waas-host/2.3.0');
        $this->assertSame('waas_update_changed', $result->get_error_code());
    }

    public function test_install_never_downgrades(): void
    {
        Functions\expect('download_url')->never();
        $this->answers(200, $this->release('2.2.7'));
        $result = $this->updater()->pre_download(false, 'waas-update://wpcs-waas-host/2.2.7');
        $this->assertSame('waas_update_not_newer', $result->get_error_code());
    }

    public function test_install_stops_when_the_channel_is_unreachable(): void
    {
        Functions\expect('download_url')->never();
        $this->answer = new \WP_Error('http_request_failed', 'Could not resolve host');
        $result = $this->updater()->pre_download(false, 'waas-update://wpcs-waas-host/2.3.0');
        $this->assertSame('waas_update_unavailable', $result->get_error_code());
    }

    public function test_a_failed_download_is_passed_on(): void
    {
        $this->answers(200, $this->release());
        $error = new \WP_Error('http_404', 'Not Found');
        Functions\when('download_url')->justReturn($error);
        $this->assertSame($error, $this->updater()->pre_download(false, 'waas-update://wpcs-waas-host/2.3.0'));
    }

    public function test_the_zip_folder_is_renamed_to_the_installed_folder(): void
    {
        global $wp_filesystem;
        $wp_filesystem = \Mockery::mock();
        $wp_filesystem->shouldReceive('move')->once()->with('/tmp/upgrade/abc/waas-host-2.3.0', '/tmp/upgrade/abc/waas-host', true)->andReturn(true);

        $result = $this->updater()->keep_folder_name('/tmp/upgrade/abc/waas-host-2.3.0/', '/tmp/upgrade/abc', null, ['plugin' => self::BASENAME]);
        $this->assertSame('/tmp/upgrade/abc/waas-host/', $result);

        // Already right, or another plugin: untouched.
        $this->assertSame('/tmp/upgrade/abc/waas-host/', $this->updater()->keep_folder_name('/tmp/upgrade/abc/waas-host/', '/tmp/upgrade/abc', null, ['plugin' => self::BASENAME]));
        $this->assertSame('/tmp/upgrade/x/akismet/', $this->updater()->keep_folder_name('/tmp/upgrade/x/akismet/', '/tmp/upgrade/x', null, ['plugin' => 'akismet/akismet.php']));
        $wp_filesystem = null;
    }

    public function test_by_default_an_admin_installs_updates(): void
    {
        $item = (object) ['plugin' => self::BASENAME, 'new_version' => '2.2.8'];
        $this->assertFalse($this->updater()->auto_update(true, $item));
        $this->assertTrue($this->updater()->auto_update(true, (object) ['plugin' => 'akismet/akismet.php']));
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_with_auto_update_patch_only_patch_releases_install_themselves(): void
    {
        define('WAAS_AUTO_UPDATE', 'patch');
        $updater = $this->updater();
        $this->assertTrue($updater->auto_update(null, (object) ['plugin' => self::BASENAME, 'new_version' => '2.2.8']));
        $this->assertFalse($updater->auto_update(null, (object) ['plugin' => self::BASENAME, 'new_version' => '2.3.0']));
        $this->assertFalse($updater->auto_update(null, (object) ['plugin' => self::BASENAME, 'new_version' => '2.2.8-beta.1']));
    }

    public function test_is_patch_update(): void
    {
        $this->assertTrue(Updater::is_patch_update('2.2.7', '2.2.10'));
        $this->assertFalse(Updater::is_patch_update('2.2.7', '2.2.7'));
        $this->assertFalse(Updater::is_patch_update('2.2.7', '2.2.6'));
        $this->assertFalse(Updater::is_patch_update('2.2.7', '2.3.0'));
        $this->assertFalse(Updater::is_patch_update('2.2.7', '3.2.8'));
        $this->assertFalse(Updater::is_patch_update('2.2.7-beta.1', '2.2.8'));
        $this->assertFalse(Updater::is_patch_update('2.2.7', ''));
    }

    public function test_after_its_update_the_cache_is_forgotten(): void
    {
        $this->answers(200, $this->release());
        $updater = $this->updater();
        $updater->check_update(false, [], self::BASENAME);

        $updater->forget(null, ['action' => 'update', 'type' => 'plugin', 'plugins' => ['akismet/akismet.php']]);
        $this->assertArrayHasKey('waas_update_wpcs-waas-host_stable', $this->transients);
        $updater->forget(null, ['action' => 'update', 'type' => 'plugin', 'plugins' => [self::BASENAME]]);
        $this->assertArrayNotHasKey('waas_update_wpcs-waas-host_stable', $this->transients);
    }

    /** The release workflow signs with OpenSSL (scripts/release/sign.sh); the plugin verifies with sodium. */
    public function test_a_signature_made_by_the_release_script_verifies(): void
    {
        exec('openssl version 2>&1', $out, $status);
        if ($status !== 0 || strpos(implode('', $out), 'OpenSSL 3') !== 0) {
            $this->markTestSkipped('Needs OpenSSL 3');
        }
        $dir = sys_get_temp_dir() . '/waas-sign-' . bin2hex(random_bytes(4));
        mkdir($dir, 0700);
        $key = $dir . '/key.pem';
        $scripts = dirname(__DIR__, 4) . '/scripts/release';
        try {
            exec('openssl genpkey -algorithm ed25519 -out ' . escapeshellarg($key) . ' 2>&1', $out, $status);
            $this->assertSame(0, $status);
            $public = trim(shell_exec(escapeshellarg("$scripts/public-key.sh") . ' ' . escapeshellarg($key)));
            $sha = hash('sha256', 'zip bytes');
            $signature = trim(shell_exec(implode(' ', array_map('escapeshellarg', ["$scripts/sign.sh", self::SLUG, '2.3.0', $sha, $key]))));

            $this->assertTrue(Updater::signature_valid(self::SLUG, '2.3.0', $sha, $signature, [$public]));
            $this->assertFalse(Updater::signature_valid(self::SLUG, '2.3.1', $sha, $signature, [$public]));
            $this->assertFalse(Updater::signature_valid(self::SLUG, '2.3.0', $sha, $signature, [$this->public_key]));
        } finally {
            @unlink($key);
            @rmdir($dir);
        }
    }
}
