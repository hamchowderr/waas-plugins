<?php

namespace AnyPressHost\Api;

use Exception;
use AnyPressHost\Commerce\Commerce;
use AnyPressHost\Core\ConfigService;
use AnyPressHost\Core\ProvisioningApi;
use AnyPressHost\Core\Tenant;
use AnyPressHost\Features\PluginBootstrap;
use WP_REST_Request;

/**
 * The "Ask the website assistant" button on the customer's account page. The owner is already signed in
 * here, so the storefront vouches for them: this route asks the API for a one-time handoff for the owner's
 * website and sends the owner to the assistant with it. With the default assistant ("website") that is the website's
 * own https://<its domain>/.assistant, so the owner lands on their own site. The assistant trades it, with its own PKCE
 * challenge, for a sign-in; the tokens never pass through this site.
 */
class AssistantSignIn
{
    private ConfigService $config;
    private ProvisioningApi $api;

    public function __construct(ConfigService $config, ProvisioningApi $api)
    {
        $this->config = $config;
        $this->api = $api;
        add_action('rest_api_init', [$this, 'register_rest_routes']);
    }

    /** The button's link for a website, or '' when this storefront doesn't offer the assistant. */
    public static function link($subscription_id): string
    {
        if ((new ConfigService())->get_assistant_url() === '') {
            return '';
        }
        return get_rest_url(null, PluginBootstrap::API_V1_NAMESPACE) . '/tenant/assistant?subscription_id=' . rawurlencode((string) $subscription_id)
            . '&_wpnonce=' . wp_create_nonce('wp_rest');
    }

    public function register_rest_routes()
    {
        register_rest_route(PluginBootstrap::API_V1_NAMESPACE, '/tenant/assistant', [
            'methods' => 'GET',
            'callback' => [$this, 'handle'],
            'permission_callback' => [SingleLogin::class, 'guard_generate_single_login_link'],
            'args' => [
                'subscription_id' => ['required' => true, 'sanitize_callback' => 'absint'],
            ],
        ]);
    }

    public function handle(WP_REST_Request $request)
    {
        $step = $this->next_step((int) $request->get_param('subscription_id'));
        if (isset($step['error'])) {
            wp_die(esc_html($step['error']), '', ['response' => (int) $step['status'], 'back_link' => true]);
        }
        // The assistant's own address, not this site, so not wp_safe_redirect().
        wp_redirect($step['redirect']); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect
        exit();
    }

    /**
     * Where to send the owner: the assistant, with the API's one-time handoff for their website.
     * @return array{redirect: string}|array{error: string, status: int}
     */
    public function next_step(int $subscription_id): array
    {
        $assistant = $this->config->get_assistant_url();
        $commerce = Commerce::require_adapter();
        $external_id = (string) $commerce->get_subscription_meta($subscription_id, Tenant::TENANT_EXTERNAL_ID_META);
        $ready = $commerce->get_subscription_meta($subscription_id, Tenant::TENANT_STATE) === Tenant::READY;
        $not_yet = ['error' => __('The website assistant is not available for this website yet.', 'anypress-host'), 'status' => 404];
        if ($assistant === '' || $external_id === '' || !$ready) {
            return $not_yet;
        }
        if ($assistant === ConfigService::ASSISTANT_ON_WEBSITE) {
            $assistant = self::on_website($commerce, $subscription_id);
            if ($assistant === '') {
                return $not_yet;
            }
        }
        try {
            $next = $this->api->site_handoff($external_id, $assistant);
        } catch (Exception $e) {
            $next = '';
        }
        // Only ever on to the assistant this storefront is set up with.
        if (strpos($next, $assistant . '/from-shop?') !== 0) {
            return ['error' => __('The website assistant could not be opened right now. Please try again in a minute.', 'anypress-host'), 'status' => 502];
        }
        return ['redirect' => $next];
    }

    /** The default assistant on the website's own domain (its custom domain, else its first one), or '' without one. */
    private static function on_website($commerce, int $subscription_id): string
    {
        $domain = (string) ($commerce->get_subscription_meta($subscription_id, Tenant::DOMAIN_NAME_META)
            ?: $commerce->get_subscription_meta($subscription_id, Tenant::BASE_DOMAIN_NAME_META));
        $domain = strtolower(preg_replace('#^https?://#i', '', rtrim(trim($domain), '/')));
        if (!preg_match('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+$/', $domain)) {
            return '';
        }
        return 'https://' . $domain . '/.assistant';
    }
}
