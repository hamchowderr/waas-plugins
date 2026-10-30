<?php

namespace WaaSHost\Api;

use Exception;
use WaaSHost\Commerce\Commerce;
use WaaSHost\Core\ConfigService;
use WaaSHost\Core\ProvisioningApi;
use WaaSHost\Core\Tenant;
use WaaSHost\Features\PluginBootstrap;
use WP_REST_Request;

/**
 * The "Website assistant" button on the customer's account page. The owner is already signed in here,
 * so the storefront vouches for them and the assistant opens without "Sign in with your website":
 *
 * 1. The button opens this route, which sends the owner to the assistant's /from-shop with this route's address.
 * 2. The assistant starts its own sign-in (PKCE) and sends the owner back here with its client id,
 *    redirect address, code challenge and state.
 * 3. This route asks the API for a code for the owner's website only, and sends the owner on to the
 *    assistant, which finishes the sign-in. The tokens never pass through this site.
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
                'client_id' => ['sanitize_callback' => 'sanitize_text_field'],
                'redirect_uri' => ['sanitize_callback' => 'esc_url_raw'],
                'code_challenge' => ['sanitize_callback' => 'sanitize_text_field'],
                'state' => ['sanitize_callback' => 'sanitize_text_field'],
            ],
        ]);
    }

    public function handle(WP_REST_Request $request)
    {
        $step = $this->next_step(
            (int) $request->get_param('subscription_id'),
            (string) $request->get_param('client_id'),
            (string) $request->get_param('redirect_uri'),
            (string) $request->get_param('code_challenge'),
            (string) $request->get_param('state')
        );
        if (isset($step['error'])) {
            wp_die(esc_html($step['error']), '', ['response' => $step['status'], 'back_link' => true]);
        }
        // The assistant's own address, not this site, so not wp_safe_redirect().
        wp_redirect($step['redirect']); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect
        exit();
    }

    /**
     * Where to send the owner next: on to the assistant to start its sign-in (no client id yet), or back
     * to it with the API's code.
     * @return array{redirect: string}|array{error: string, status: int}
     */
    public function next_step(int $subscription_id, string $client_id, string $redirect_uri, string $code_challenge, string $state): array
    {
        $assistant = $this->config->get_assistant_url();
        $commerce = Commerce::require_adapter();
        $external_id = (string) $commerce->get_subscription_meta($subscription_id, Tenant::TENANT_EXTERNAL_ID_META);
        $ready = $commerce->get_subscription_meta($subscription_id, Tenant::TENANT_STATE) === Tenant::READY;
        if ($assistant === '' || $external_id === '' || !$ready) {
            return ['error' => __('The website assistant is not available for this website yet.', 'waas-host'), 'status' => 404];
        }

        if ($client_id === '') {
            $back = add_query_arg(['subscription_id' => $subscription_id, '_wpnonce' => wp_create_nonce('wp_rest')], get_rest_url(null, PluginBootstrap::API_V1_NAMESPACE) . '/tenant/assistant');
            return ['redirect' => $assistant . '/from-shop?shop=' . rawurlencode($back)];
        }

        // Only ever back to the assistant this storefront is set up with.
        if ($redirect_uri !== $assistant . '/callback') {
            return ['error' => __('This sign-in did not come from the website assistant.', 'waas-host'), 'status' => 400];
        }
        $params = ['clientId' => $client_id, 'redirectUri' => $redirect_uri, 'codeChallenge' => $code_challenge];
        if ($state !== '') {
            $params['state'] = $state;
        }
        try {
            $next = $this->api->site_sign_in($external_id, $params);
        } catch (Exception $e) {
            $next = '';
        }
        if (strpos($next, $redirect_uri . '?') !== 0) {
            return ['error' => __('The website assistant could not be opened right now. Please try again in a minute.', 'waas-host'), 'status' => 502];
        }
        return ['redirect' => $next];
    }
}
