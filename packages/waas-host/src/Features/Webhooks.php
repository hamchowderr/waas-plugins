<?php

namespace WaaSHost\Features;

use WaaSHost\Commerce\Commerce;
use WaaSHost\Core\ConfigService;
use WaaSHost\Core\ProvisioningApi;
use WaaSHost\Core\SecretBox;

/**
 * Registers this storefront's webhook endpoint with the provisioning API, so the API says when a website is ready
 * instead of the storefront asking every five seconds (the receiver is WebhookReceiver; the poll stays on, every
 * minute, as a backstop).
 *
 * One endpoint, kept in the OPTION option: its id, URL, the API it was registered with, and its signing secret sealed
 * with SecretBox. It is registered when the Connection settings are saved and the API answers, from the Connection
 * tab's button, and by an hourly check that needs no admin (for API settings in wp-config.php, or a site that moved).
 * A registration the API refuses (an http:// or private address) is recorded with its reason, and polling stays
 * every five seconds.
 */
class Webhooks
{
    public const OPTION = 'waas_host_webhook';
    /** The events the receiver acts on. The rest are left to the poll, or don't concern the storefront. */
    public const EVENT_TYPES = ['tenant.ready', 'tenant.restored', 'tenant.domains_changed', 'tenant.failed'];
    public const ROUTE = '/webhook';
    public const CHECK_HOOK = 'waas_check_webhook_endpoint';
    public const REGISTER_ACTION = 'waas_register_webhook';
    public const DISMISS_FAILURE_ACTION = 'waas_dismiss_tenant_failure';
    /** Subscription meta: a change the API reports as failed after its retries ([action, at]). */
    public const TENANT_FAILURE_META = '_waas_tenant_api_failure';
    private const SECRET_PURPOSE = 'waas-host-webhook-secret';

    private ProvisioningApi $provisioningApi;
    private ConfigService $configService;

    public function __construct(ProvisioningApi $provisioningApi, ConfigService $configService)
    {
        $this->provisioningApi = $provisioningApi;
        $this->configService = $configService;

        add_action('waas_api_connection_saved', [$this, 'connection_saved']);
        add_action(self::CHECK_HOOK, [$this, 'scheduled_check']);
        add_action('init', [$this, 'schedule_check']);
        add_action('admin_init', [$this, 'add_settings_section'], 11);
        add_action('admin_post_' . self::REGISTER_ACTION, [$this, 'handle_register_request']);
        add_action('admin_post_' . self::DISMISS_FAILURE_ACTION, [$this, 'handle_dismiss_failure_request']);
        add_action('admin_notices', [$this, 'show_tenant_failures_notice']);
        add_action('waas_tenant_ready', [self::class, 'clear_tenant_failure']);
    }

    /** The receiver's address, as the API is to call it. */
    public static function endpoint_url(): string
    {
        return (string) apply_filters('waas_webhook_url', rest_url(PluginBootstrap::API_V1_NAMESPACE . self::ROUTE));
    }

    /** @return array{id: string, url: string, api: string, secret: string, registered_at: int, error: string, checked_at: int, last_event_at: int} */
    public static function stored(): array
    {
        $stored = get_option(self::OPTION, []);
        $stored = is_array($stored) ? $stored : [];

        return [
            'id' => (string) ($stored['id'] ?? ''),
            'url' => (string) ($stored['url'] ?? ''),
            'api' => (string) ($stored['api'] ?? ''),
            'secret' => (string) ($stored['secret'] ?? ''),
            'registered_at' => (int) ($stored['registered_at'] ?? 0),
            'error' => (string) ($stored['error'] ?? ''),
            'checked_at' => (int) ($stored['checked_at'] ?? 0),
            'last_event_at' => (int) ($stored['last_event_at'] ?? 0),
        ];
    }

    /** True when the API has an endpoint for this storefront: the status poll then runs every minute. */
    public static function is_registered(): bool
    {
        $stored = self::stored();

        return $stored['id'] !== '' && $stored['secret'] !== '';
    }

    /** The endpoint's signing secret (whsec_…), or '' when there is none or it can't be opened. */
    public static function secret(): string
    {
        $sealed = self::stored()['secret'];

        return $sealed !== '' && SecretBox::is_sealed($sealed) ? SecretBox::open($sealed, self::SECRET_PURPOSE) : '';
    }

    public static function record_event_received(): void
    {
        $stored = self::stored();
        $stored['last_event_at'] = time();
        update_option(self::OPTION, $stored, false);
    }

    /**
     * Registers the endpoint unless the one stored is for this URL and this API. With $force (the Connection tab's
     * button) it is replaced anyway. An endpoint left at another URL or with a secret that can no longer be opened
     * is deleted first, best effort; one on another API can't be reached with these credentials and is left there.
     */
    public function ensure_registered(bool $force = false): array
    {
        if (!$this->configService->check_credentials()) {
            return self::stored();
        }

        $url = self::endpoint_url();
        $api = (string) $this->configService->get_api_base_url();
        $stored = self::stored();

        if (!$force && $stored['id'] !== '' && $stored['url'] === $url && $stored['api'] === $api && self::secret() !== '') {
            return $stored;
        }

        if ($stored['id'] !== '' && $stored['api'] === $api) {
            $this->delete_endpoint($stored['id']);
        }

        // Endpoints at this URL that we hold no secret for, e.g. a registration whose answer never arrived. A
        // storefront can have only 10.
        try {
            foreach ($this->provisioningApi->get_webhook_endpoints() as $endpoint) {
                if (isset($endpoint->id, $endpoint->url) && $endpoint->url === $url && $endpoint->id !== $stored['id']) {
                    $this->delete_endpoint((string) $endpoint->id);
                }
            }
        } catch (\Throwable $e) {
            // The create below reports what is wrong.
        }

        $record = [
            'id' => '',
            'url' => $url,
            'api' => $api,
            'secret' => '',
            'registered_at' => 0,
            'error' => '',
            'checked_at' => time(),
            'last_event_at' => $stored['last_event_at'],
        ];

        try {
            $created = $this->provisioningApi->create_webhook_endpoint($url, self::EVENT_TYPES, (string) __('WaaS Host storefront', 'waas-host'));
            $id = is_object($created) ? (string) ($created->id ?? '') : '';
            $secret = is_object($created) ? (string) ($created->secret ?? '') : '';
            if ($id === '' || strpos($secret, 'whsec_') !== 0) {
                throw new \RuntimeException('The API did not return an endpoint id and signing secret.');
            }

            $record['id'] = $id;
            $record['secret'] = SecretBox::seal($secret, self::SECRET_PURPOSE);
            $record['registered_at'] = time();
        } catch (\Throwable $e) {
            $record['error'] = self::api_error($e);
            error_log('WaaS Host: could not register the webhook endpoint ' . $url . ': ' . $record['error']);
        }

        update_option(self::OPTION, $record, false);

        return $record;
    }

    /** The Connection settings were saved and the API answered. */
    public function connection_saved(): void
    {
        $this->ensure_registered();
    }

    /** Hourly, without an admin: registers when the endpoint is missing or the site's URL or API changed. */
    public function scheduled_check(): void
    {
        if ($this->configService->check_credentials() && $this->provisioningApi->is_reachable()) {
            $this->ensure_registered();
        }
    }

    public function schedule_check(): void
    {
        // Checked only where it costs a visitor nothing.
        if ((is_admin() || wp_doing_cron()) && !wp_next_scheduled(self::CHECK_HOOK)) {
            wp_schedule_event(time(), 'hourly', self::CHECK_HOOK);
        }
    }

    public static function register_url(): string
    {
        return wp_nonce_url(admin_url('admin-post.php?action=' . self::REGISTER_ACTION), self::REGISTER_ACTION);
    }

    public function handle_register_request(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You are not allowed to do this.', 'waas-host'), 403);
        }
        check_admin_referer(self::REGISTER_ACTION);

        $this->ensure_registered(true);

        wp_safe_redirect(AdminSettings::url('connection'));
        exit;
    }

    public function add_settings_section(): void
    {
        // A ready-made storefront only sees this when it needs a hand; the Overview shows live updates as on.
        if (AdminSettings::is_ready_made() && self::is_registered()) {
            return;
        }
        add_settings_section(
            'waas_webhooks',
            __('Webhooks', 'waas-host'),
            [$this, 'render_status'],
            'waas-admin'
        );
    }

    /** The Connection tab's Webhooks section: registered or not, and why. */
    public function render_status(): void
    {
        if (!$this->configService->check_credentials()) {
            echo '<p>' . esc_html__('Once the API is connected, the API tells this storefront when a website is ready.', 'waas-host') . '</p>';
            return;
        }

        $stored = self::stored();
        if (self::is_registered()) {
            echo '<p>' . esc_html__('Registered. The API tells this storefront when a website is ready, and websites being set up are also checked every minute.', 'waas-host') . '</p>';
            echo '<p><code>' . esc_html($stored['url']) . '</code></p>';
            echo '<p class="description">' . esc_html($stored['last_event_at']
                /* translators: %s: date and time */
                ? sprintf(__('Last event received %s.', 'waas-host'), wp_date('Y-m-d H:i', $stored['last_event_at']))
                : __('No events received yet.', 'waas-host')) . '</p>';
            $label = __('Register again', 'waas-host');
        } else {
            $reason = $stored['error'] !== '' ? $stored['error'] : __('Not tried yet.', 'waas-host');
            /* translators: %s: why the API refused the endpoint */
            echo '<p>' . esc_html(sprintf(__('Not registered: %s', 'waas-host'), $reason)) . '</p>';
            echo '<p class="description">' . esc_html__('Websites being set up are checked every five seconds instead. The API only calls https:// addresses it can reach from the internet.', 'waas-host') . '</p>';
            $label = __('Register', 'waas-host');
        }

        echo '<p><a class="ap-btn is-sm" href="' . esc_url(self::register_url()) . '">' . esc_html($label) . '</a></p>';
    }

    /**
     * The API reports that a change to the store still failed after its retries. Recorded on the subscription and
     * shown to admins until dismissed, or until the store becomes Ready.
     */
    public static function record_tenant_failure($subscription_id, string $action): void
    {
        $commerce = Commerce::require_adapter();
        $previous = $commerce->get_subscription_meta($subscription_id, self::TENANT_FAILURE_META);
        if (is_array($previous) && ($previous['action'] ?? '') === $action) {
            return;
        }

        $commerce->update_subscription_meta($subscription_id, self::TENANT_FAILURE_META, ['action' => $action, 'at' => time()]);
        error_log(sprintf('WaaS Host: the provisioning API reports that "%s" failed for the website of subscription %s', $action, $subscription_id));

        do_action('waas_tenant_failed', $subscription_id, $action);
    }

    public static function clear_tenant_failure($subscription_id): void
    {
        $commerce = Commerce::adapter();
        if ($commerce !== null && $commerce->get_subscription_meta($subscription_id, self::TENANT_FAILURE_META)) {
            $commerce->update_subscription_meta($subscription_id, self::TENANT_FAILURE_META, '');
        }
    }

    public function show_tenant_failures_notice(): void
    {
        $commerce = Commerce::adapter();
        // The AnyPress Overview lists these in place.
        if ($commerce === null || !current_user_can('manage_options') || AdminHome::is_plugin_page()) {
            return;
        }

        $failures = array_filter($commerce->find_subscriptions_by_meta(self::TENANT_FAILURE_META, ['']), 'is_array');
        if (!$failures) {
            return;
        }

        echo '<div class="notice notice-error"><p><strong>' . esc_html(sprintf(
            /* translators: %d: number of websites */
            _n('The provisioning API could not finish a change to %d website.', 'The provisioning API could not finish a change to %d websites.', count($failures), 'waas-host'),
            count($failures)
        )) . '</strong></p><ul>';
        foreach ($failures as $subscription_id => $failure) {
            $dismiss_url = wp_nonce_url(
                admin_url('admin-post.php?action=' . self::DISMISS_FAILURE_ACTION . '&subscription_id=' . rawurlencode((string) $subscription_id)),
                self::DISMISS_FAILURE_ACTION . '_' . $subscription_id
            );
            echo '<li>' . esc_html(sprintf(
                'Subscription #%s: "%s" failed at %s. Check the website in the API\'s dashboard.',
                $subscription_id,
                $failure['action'] ?? '',
                wp_date('Y-m-d H:i', (int) ($failure['at'] ?? 0))
            )) . ' <a href="' . esc_url($dismiss_url) . '">' . esc_html__('Dismiss', 'waas-host') . '</a></li>';
        }
        echo '</ul></div>';
    }

    public function handle_dismiss_failure_request(): void
    {
        $subscription_id = isset($_GET['subscription_id']) ? sanitize_text_field(wp_unslash($_GET['subscription_id'])) : '';
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You are not allowed to do this.', 'waas-host'), 403);
        }
        check_admin_referer(self::DISMISS_FAILURE_ACTION . '_' . $subscription_id);

        self::clear_tenant_failure(ctype_digit($subscription_id) ? (int) $subscription_id : $subscription_id);

        wp_safe_redirect(wp_get_referer() ?: admin_url());
        exit;
    }

    private function delete_endpoint(string $id): void
    {
        try {
            $this->provisioningApi->delete_webhook_endpoint($id);
        } catch (\Throwable $e) {
            // Best effort: an endpoint that is already gone, or an API that is down, leaves nothing to do here.
        }
    }

    /** The API's own message ("url must be on a public address") rather than the whole HTTP answer. */
    private static function api_error(\Throwable $e): string
    {
        $message = $e->getMessage();
        if (preg_match('/^Failed: (\d+) - (.*)$/s', $message, $matches)) {
            $body = json_decode($matches[2]);
            if (is_object($body) && isset($body->message) && is_string($body->message)) {
                return $body->message . ' (HTTP ' . $matches[1] . ')';
            }
        }

        return $message;
    }
}
