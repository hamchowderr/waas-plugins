<?php

namespace WaaSClient\Features;

use WaaSClient\Core\HttpService;

/**
 * Talks to the storefront (WaaS Host) from the template website and turns its answers into plain notices:
 * the "reload your roles" ping after roles change, and the Settings page's connection test.
 *
 * Each outcome is ['type' => success|warning|error, 'message' => string, 'code' => int, 'time' => int].
 */
class StorefrontStatus
{
    const PING_OPTION = 'waas_client_last_roles_ping';
    const TEST_OPTION = 'waas_client_last_connection_test';
    const UPDATE_ROUTE = '/user-role-plan/update';
    const API_NAMESPACE = 'waas-host/v1';

    /** Asks the storefront to reload the plan roles, and remembers how it answered. */
    public static function notify_storefront(HttpService $http, string $host_url): array
    {
        if ($host_url === '') {
            $outcome = self::outcome('error', __('The storefront was not told: no storefront URL is set. Add it under WaaS Client > Settings.', 'waas-client'));
        } else {
            $outcome = self::interpret_roles_ping($http->request(self::UPDATE_ROUTE));
        }

        update_option(self::PING_OPTION, $outcome, false);

        return $outcome;
    }

    /** A harmless read of the storefront's WaaS Host API index; it does not refresh any roles. */
    public static function test_connection(HttpService $http, string $host_url): array
    {
        if ($host_url === '') {
            $outcome = self::outcome('error', __('No storefront URL is set yet. Enter it below and save, then test again.', 'waas-client'));
        } else {
            $outcome = self::interpret_connection_test($http->request(''), $host_url);
        }

        update_option(self::TEST_OPTION, $outcome, false);

        return $outcome;
    }

    /** @param array{code: int, body: mixed, error: ?string} $result */
    public static function interpret_roles_ping(array $result): array
    {
        $code = (int) ($result['code'] ?? 0);

        if ($code === 0) {
            /* translators: %s: the error message, for example a timeout or SSL problem */
            return self::outcome('error', sprintf(__('The storefront could not be reached, so it was not told: %s', 'waas-client'), (string) ($result['error'] ?? '')), $code);
        }
        if ($code === 200 && ($result['body'] ?? null) === true) {
            return self::outcome('success', __('The storefront was told and reloaded the plan roles from the template version labelled Production.', 'waas-client'), $code);
        }
        if ($code === 429) {
            return self::outcome('warning', __('The storefront reloaded its roles less than a minute ago, so it skipped this update. In a minute, click Refresh roles on any plan product on the storefront.', 'waas-client'), $code);
        }
        if (self::is_no_route($result)) {
            return self::outcome('error', __('The storefront did not recognise the request. Check that WaaS Host is installed and active on it, and that the storefront URL is right.', 'waas-client'), $code);
        }
        if ($code === 404) {
            return self::outcome('error', __('The storefront was told, but could not load the roles. Check in WaaS that a template version is labelled Production and deployed, and that it has WaaS Client active with at least one role.', 'waas-client'), $code);
        }

        /* translators: %d: HTTP status code */
        return self::outcome('error', sprintf(__('The storefront answered with an unexpected status (HTTP %d), so the roles may not have been reloaded.', 'waas-client'), $code), $code);
    }

    /** @param array{code: int, body: mixed, error: ?string} $result */
    public static function interpret_connection_test(array $result, string $host_url): array
    {
        $code = (int) ($result['code'] ?? 0);
        $body = $result['body'] ?? null;

        if ($code === 0) {
            /* translators: 1: storefront URL, 2: error message */
            return self::outcome('error', sprintf(__('Could not reach %1$s: %2$s', 'waas-client'), $host_url, (string) ($result['error'] ?? '')), $code);
        }
        if ($code === 200 && is_array($body) && ($body['namespace'] ?? null) === self::API_NAMESPACE) {
            $routes = array_keys((array) ($body['routes'] ?? []));
            if (!in_array('/' . self::API_NAMESPACE . self::UPDATE_ROUTE, $routes, true)) {
                /* translators: %s: storefront URL */
                return self::outcome('warning', sprintf(__('%s answered and WaaS Host is active, but it does not accept role updates. Update WaaS Host on the storefront.', 'waas-client'), $host_url), $code);
            }

            /* translators: %s: storefront URL */
            return self::outcome('success', sprintf(__('Connected: %s answered and WaaS Host is active.', 'waas-client'), $host_url), $code);
        }
        if ($code === 404) {
            /* translators: %s: storefront URL */
            return self::outcome('error', sprintf(__('%s answered, but WaaS Host is not active there. Check the URL, and that WaaS Host is installed and active on the storefront.', 'waas-client'), $host_url), $code);
        }
        if ($code === 401 || $code === 403) {
            /* translators: 1: storefront URL, 2: HTTP status code */
            return self::outcome('error', sprintf(__('%1$s refused the request (HTTP %2$d). A security plugin or firewall on the storefront may be blocking its REST API.', 'waas-client'), $host_url, $code), $code);
        }

        /* translators: 1: storefront URL, 2: HTTP status code */
        return self::outcome('error', sprintf(__('%1$s answered with an unexpected status (HTTP %2$d). Check that the URL points at the storefront.', 'waas-client'), $host_url, $code), $code);
    }

    /** The last stored outcome, or null. */
    public static function last(string $option): ?array
    {
        $value = get_option($option, null);

        return is_array($value) && isset($value['type'], $value['message']) ? $value : null;
    }

    public static function render_notice(array $outcome, bool $dismissible = true): void
    {
        $type = in_array($outcome['type'] ?? '', ['success', 'warning', 'error'], true) ? $outcome['type'] : 'info';
        printf(
            '<div class="notice notice-%1$s%2$s"><p>%3$s</p></div>',
            esc_attr($type),
            $dismissible ? ' is-dismissible' : '',
            esc_html((string) $outcome['message'])
        );
    }

    /** "Connected: … (5 minutes ago)" for a status row. */
    public static function describe(?array $outcome, string $never): string
    {
        if ($outcome === null) {
            return esc_html($never);
        }

        $icons = ['success' => 'yes-alt', 'warning' => 'warning', 'error' => 'dismiss'];
        $html = '<span class="waas-status waas-status-' . esc_attr($outcome['type']) . '">'
            . '<span class="dashicons dashicons-' . esc_attr($icons[$outcome['type']] ?? 'info') . '" aria-hidden="true"></span> '
            . esc_html((string) $outcome['message']) . '</span>';

        if (!empty($outcome['time'])) {
            /* translators: %s: a time span such as "5 mins" */
            $html .= ' <span class="description">' . esc_html(sprintf(__('(%s ago)', 'waas-client'), human_time_diff((int) $outcome['time']))) . '</span>';
        }

        return $html;
    }

    private static function is_no_route(array $result): bool
    {
        $body = $result['body'] ?? null;

        return is_array($body) && ($body['code'] ?? null) === 'rest_no_route';
    }

    private static function outcome(string $type, string $message, int $code = 0): array
    {
        return ['type' => $type, 'message' => $message, 'code' => $code, 'time' => time()];
    }
}
