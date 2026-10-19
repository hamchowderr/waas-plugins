<?php

namespace WaaSHost\Api;

use WaaSHost\Commerce\Commerce;
use WaaSHost\Core\ConfigService;
use WaaSHost\Core\Tenant;
use WaaSHost\Features\NameConflicts;
use WaaSHost\Features\UserAccountSubscriptionsSettings;
use WaaSHost\Features\WebsiteSettings;
use WaaSHost\Features\WebsiteSettingsError;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * The customer's websites as a REST API (namespace waas-host/v1/account), for a front end of the shop's own: the same
 * things the account page shows and does. {id} is the subscription id.
 *
 * Every route's permission goes through authorize(): the user is signed in and, for a website route, owns the
 * subscription (and, except for choosing a new name, it has a store). Signing in is either WordPress's own REST
 * authentication (the login cookie plus a wp_rest nonce in the X-WP-Nonce header), for pages on the storefront, or an
 * access token from the token sign-in (AccountSignIn) in "Authorization: Bearer", for a front end on another domain.
 */
class AccountController
{
    public const NAMESPACE = 'waas-host/v1/account';

    /** What a route needs (see authorize). */
    public const ACCESS_ACCOUNT = 'account';
    public const ACCESS_SUBSCRIPTION = 'subscription';
    public const ACCESS_WEBSITE = 'website';

    /** Sign-in links per user per window. */
    public const LOGIN_LIMIT = 10;
    public const LOGIN_WINDOW = 60;
    public const LOGIN_LIMIT_TRANSIENT = 'waas_account_login_';

    private WebsiteSettings $settings;
    private NameConflicts $name_conflicts;
    private ConfigService $config;
    private AccountTokens $tokens;

    public function __construct(WebsiteSettings $settings, NameConflicts $name_conflicts, ConfigService $config, AccountTokens $tokens)
    {
        $this->settings = $settings;
        $this->name_conflicts = $name_conflicts;
        $this->config = $config;
        $this->tokens = $tokens;

        add_action('rest_api_init', [$this, 'register_routes']);
    }

    public function register_routes(): void
    {
        $website = '/websites/(?P<id>\d+)';
        $domain_arg = ['type' => 'string', 'required' => true];

        $this->route('/me', 'GET', 'me', self::ACCESS_ACCOUNT);
        $this->route('/websites', 'GET', 'list_websites', self::ACCESS_ACCOUNT);
        $this->route("$website/login", 'POST', 'login', self::ACCESS_WEBSITE);
        $this->route("$website/address", 'PUT', 'change_address', self::ACCESS_WEBSITE, ['domain' => $domain_arg]);
        $this->route("$website/email-sender", 'GET', 'get_email_sender', self::ACCESS_WEBSITE);
        $this->route("$website/email-sender", 'PUT', 'set_email_sender', self::ACCESS_WEBSITE, ['domain' => $domain_arg]);
        $this->route("$website/email-sender", 'DELETE', 'remove_email_sender', self::ACCESS_WEBSITE);
        $this->route("$website/domain-connect", 'GET', 'domain_connect', self::ACCESS_WEBSITE, [
            'domain' => $domain_arg,
            'service' => ['type' => 'string', 'enum' => ['website', 'email'], 'default' => 'website'],
        ]);
        $this->route("$website/name", 'POST', 'choose_name', self::ACCESS_SUBSCRIPTION, ['name' => ['type' => 'string', 'required' => true]]);
    }

    private function route(string $path, string $method, string $callback, string $access, array $args = []): void
    {
        register_rest_route(self::NAMESPACE, $path, [
            'methods' => $method,
            'callback' => [$this, $callback],
            'permission_callback' => fn (WP_REST_Request $request) => $this->authorize($request, $access),
            'args' => $args,
        ]);
    }

    /**
     * The one permission check every route uses.
     *
     * @param string $access ACCESS_ACCOUNT (signed in), ACCESS_SUBSCRIPTION (also owns {id}) or ACCESS_WEBSITE (also
     *                       {id} has a store)
     * @return true|WP_Error
     */
    public function authorize(WP_REST_Request $request, string $access)
    {
        $user_id = get_current_user_id();
        if ($user_id === 0 && ($bearer = self::bearer_token($request)) !== '') {
            // Only these routes take tokens, so a token can't be used on the rest of the site's API.
            $user_id = $this->tokens->user_for_access_token($bearer);
            if ($user_id === 0) {
                return new WP_Error('waas_invalid_token', 'Your sign-in has expired. Please sign in again.', ['status' => 401]);
            }
            wp_set_current_user($user_id);
        }
        if ($user_id === 0) {
            return new WP_Error('waas_not_signed_in', 'Please sign in.', ['status' => 401]);
        }
        $commerce = Commerce::adapter();
        if ($commerce === null) {
            return new WP_Error('waas_unavailable', 'Websites are not available on this shop right now.', ['status' => 503]);
        }
        if ($access === self::ACCESS_ACCOUNT) {
            return true;
        }

        // A subscription that doesn't exist has no owner (0), so it is refused like someone else's.
        $subscription_id = self::subscription_id($request);
        if ($subscription_id === 0 || $commerce->get_customer_id($subscription_id) !== $user_id) {
            return new WP_Error('waas_forbidden', 'This website is not in your account.', ['status' => 403]);
        }
        if ($access === self::ACCESS_WEBSITE && empty($commerce->get_subscription_meta($subscription_id, Tenant::TENANT_EXTERNAL_ID_META))) {
            return new WP_Error('waas_not_set_up', 'This website is not set up yet.', ['status' => 409]);
        }

        return true;
    }

    /** GET /me: who is signed in. */
    public function me(WP_REST_Request $request): WP_REST_Response
    {
        $user = wp_get_current_user();

        return self::response([
            'id' => (int) $user->ID,
            'email' => (string) $user->user_email,
            'name' => (string) $user->display_name,
        ]);
    }

    /** GET /websites */
    public function list_websites(WP_REST_Request $request): WP_REST_Response
    {
        $commerce = Commerce::require_adapter();
        $websites = [];
        foreach (WebsiteSettings::customer_websites(get_current_user_id()) as $subscription_id) {
            $state = (string) ($commerce->get_subscription_meta($subscription_id, Tenant::TENANT_STATE) ?: Tenant::PROVISIONING);
            $suspend_at = $state === Tenant::READY ? (int) $commerce->get_subscription_meta($subscription_id, Tenant::TENANT_SUSPEND_AT_META) : 0;
            $domain = $commerce->get_subscription_meta($subscription_id, Tenant::DOMAIN_NAME_META)
                ?: $commerce->get_subscription_meta($subscription_id, Tenant::BASE_DOMAIN_NAME_META);
            $has_store = !empty($commerce->get_subscription_meta($subscription_id, Tenant::TENANT_EXTERNAL_ID_META));

            $websites[] = [
                'id' => is_numeric($subscription_id) ? (int) $subscription_id : $subscription_id,
                'domain' => (string) $domain,
                'status' => $state,
                'status_label' => Tenant::customer_status_label($state),
                'active_until' => $suspend_at > 0 ? gmdate('c', $suspend_at) : null,
                'can_log_in' => $has_store && $state === Tenant::READY,
                'needs_new_name' => $state === Tenant::NEEDS_NEW_NAME,
                'manage_plan_url' => $commerce->get_subscription_url($subscription_id),
            ];
        }

        return self::response(['websites' => $websites, 'custom_address_records' => $this->custom_address_records()]);
    }

    /** POST /websites/{id}/login: a fresh one-time sign-in link, made on request. */
    public function login(WP_REST_Request $request)
    {
        $subscription_id = self::subscription_id($request);
        if (Commerce::require_adapter()->get_subscription_meta($subscription_id, Tenant::TENANT_STATE) !== Tenant::READY) {
            return new WP_Error('waas_not_ready', 'Your website is not ready yet.', ['status' => 409]);
        }
        $retry_after = $this->take_login_slot(get_current_user_id());
        if ($retry_after > 0) {
            // Shaped like WordPress's own REST errors, with Retry-After.
            $response = new WP_REST_Response([
                'code' => 'waas_rate_limited',
                'message' => 'Too many sign-in links in a short time. Please wait a minute and try again.',
                'data' => ['status' => 429],
            ], 429);
            $response->header('Retry-After', (string) $retry_after);
            return $response;
        }

        $url = SingleLogin::get_login_link($subscription_id, SingleLogin::PORTAL_TOKEN_LIFETIME);
        if ($url === '') {
            return new WP_Error('waas_login_unavailable', 'Signing in is not available for this website. Please contact support.', ['status' => 500]);
        }

        return self::response(['url' => $url, 'expires_in' => SingleLogin::PORTAL_TOKEN_LIFETIME]);
    }

    /** PUT /websites/{id}/address */
    public function change_address(WP_REST_Request $request)
    {
        try {
            $domain = $this->settings->change_address(self::subscription_id($request), (string) $request->get_param('domain'));
        } catch (WebsiteSettingsError $e) {
            return self::error($e);
        }

        return self::response(['domain' => $domain]);
    }

    /** GET /websites/{id}/email-sender */
    public function get_email_sender(WP_REST_Request $request)
    {
        try {
            $mail_domain = $this->settings->get_email_sender(self::subscription_id($request));
        } catch (WebsiteSettingsError $e) {
            return self::error($e);
        }

        return self::response(['email_sender' => self::email_sender($mail_domain)]);
    }

    /** PUT /websites/{id}/email-sender */
    public function set_email_sender(WP_REST_Request $request)
    {
        try {
            $mail_domain = $this->settings->set_email_sender(self::subscription_id($request), (string) $request->get_param('domain'));
        } catch (WebsiteSettingsError $e) {
            return self::error($e);
        }

        return self::response(['email_sender' => self::email_sender($mail_domain)]);
    }

    /** DELETE /websites/{id}/email-sender */
    public function remove_email_sender(WP_REST_Request $request)
    {
        try {
            $this->settings->remove_email_sender(self::subscription_id($request));
        } catch (WebsiteSettingsError $e) {
            return self::error($e);
        }

        return self::response(['removed' => true]);
    }

    /** GET /websites/{id}/domain-connect?domain=&service= */
    public function domain_connect(WP_REST_Request $request)
    {
        $domain = WebsiteSettings::normalize_domain($request->get_param('domain'));
        if (!UserAccountSubscriptionsSettings::is_valid_hostname($domain)) {
            return new WP_Error('waas_invalid_input', WebsiteSettings::INVALID_DOMAIN_MESSAGE, ['status' => 400]);
        }
        $service = $request->get_param('service') === 'email' ? 'email' : 'website';

        try {
            $link = $this->settings->domain_connect_link(self::subscription_id($request), $domain, $service);
        } catch (WebsiteSettingsError $e) {
            return self::error($e);
        }

        return self::response([
            'supported' => !empty($link['url']),
            'url' => $link['url'] ?? null,
            'dns_provider' => !empty($link['url']) ? $link['dns_provider'] : null,
            // Why one-click setup is off for this domain, for the customer to read; null when there is no such reason.
            'conflict' => $link['conflict'] ?? null,
        ]);
    }

    /** POST /websites/{id}/name: a new website name when the first one was taken. */
    public function choose_name(WP_REST_Request $request)
    {
        $code = $this->name_conflicts->choose(self::subscription_id($request), (string) $request->get_param('name'));
        $message = NameConflicts::result_message($code);
        if ($code === 'ok') {
            return self::response(['message' => $message]);
        }

        $statuses = ['taken' => 400, 'invalid' => 400, 'invalid_domain' => 400, 'unavailable' => 503];

        return new WP_Error('waas_name_' . $code, $message, ['status' => $statuses[$code] ?? 409]);
    }

    /**
     * The DNS records a customer adds before using their own domain as a website's address (the same for every
     * website), from the storefront's DNS settings. Null when neither is set: custom domains go through support.
     */
    private function custom_address_records(): ?array
    {
        $a_records = $this->config->get_dns_a_records();
        $cname_target = $this->config->get_dns_cname_target();
        if (!$a_records && $cname_target === '') {
            return null;
        }

        return [
            'apex' => array_map(fn ($ip) => ['type' => filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) ? 'AAAA' : 'A', 'value' => $ip], $a_records),
            'subdomain' => $cname_target === '' ? [] : [['type' => 'CNAME', 'value' => $cname_target]],
        ];
    }

    /**
     * Counts a sign-in link against the user's limit.
     *
     * @return int 0 when the link may be made, else seconds until the next one may
     */
    private function take_login_slot(int $user_id): int
    {
        $key = self::LOGIN_LIMIT_TRANSIENT . $user_id;
        $now = time();
        $window = get_transient($key);
        if (!is_array($window) || (int) ($window['start'] ?? 0) <= $now - self::LOGIN_WINDOW) {
            $window = ['start' => $now, 'count' => 0];
        }
        if ((int) $window['count'] >= self::LOGIN_LIMIT) {
            return max(1, (int) $window['start'] + self::LOGIN_WINDOW - $now);
        }

        $window['count'] = (int) $window['count'] + 1;
        set_transient($key, $window, self::LOGIN_WINDOW);

        return 0;
    }

    /** The sending domain as the API answers it, in this API's own shape; null while it sends from the platform. */
    private static function email_sender($mail_domain): ?array
    {
        if (!$mail_domain) {
            return null;
        }

        $records = [];
        foreach (is_array($mail_domain->records ?? null) ? $mail_domain->records : [] as $record) {
            $records[] = [
                'type' => (string) ($record->type ?? ''),
                'name' => (string) ($record->name ?? ''),
                'value' => (string) ($record->value ?? ''),
                'priority' => isset($record->priority) ? (int) $record->priority : null,
            ];
        }
        $domain = (string) ($mail_domain->domainName ?? '');

        return [
            'domain' => $domain,
            'from' => 'no-reply@' . $domain,
            'status' => (string) ($mail_domain->status ?? 'pending'),
            'records' => $records,
        ];
    }

    /** The token in "Authorization: Bearer <token>", or ''. */
    private static function bearer_token(WP_REST_Request $request): string
    {
        $authorization = (string) $request->get_header('authorization');

        return stripos($authorization, 'bearer ') === 0 ? trim(substr($authorization, 7)) : '';
    }

    /** {id} from the path (digits only, see register_routes); 0 when missing. */
    private static function subscription_id(WP_REST_Request $request): int
    {
        $id = (string) $request->get_param('id');

        return ctype_digit($id) ? (int) $id : 0;
    }

    private static function response(array $data): WP_REST_Response
    {
        $response = new WP_REST_Response($data, 200);
        // Sign-in links and DNS records are per user and short-lived: never cache them.
        $response->header('Cache-Control', 'no-store');

        return $response;
    }

    private static function error(WebsiteSettingsError $e): WP_Error
    {
        $code = $e->is_invalid_input() ? 'waas_invalid_input' : ($e->get_status() === 400 ? 'waas_refused' : 'waas_api_error');

        return new WP_Error($code, $e->getMessage(), ['status' => $e->get_status()]);
    }
}
