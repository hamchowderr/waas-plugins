<?php

namespace WaaSHost\Core;

// Exit if accessed directly
if ( !defined( 'ABSPATH' ) )
    exit;

class ConfigService
{
    /**
     * wp-config constants are named WAAS_<NAME>. The WPCS_<NAME> spelling from before the rename
     * still works; WAAS_ wins when both are defined.
     */
    public static function constant(string $name)
    {
        foreach (['WAAS_', 'WPCS_'] as $prefix) {
            if (defined($prefix . $name)) {
                return constant($prefix . $name);
            }
        }
        return null;
    }

    public static function has_constant(string $name): bool
    {
        return defined('WAAS_' . $name) || defined('WPCS_' . $name);
    }

    public function get_api_key()
    {
        $value = self::has_constant('API_KEY') ? self::constant('API_KEY') : get_option('waas_api_key');
        return apply_filters('waas_settings_api_key', $value);
    }

    public function get_api_secret()
    {
        $value = self::has_constant('API_SECRET') ? self::constant('API_SECRET') : get_option('waas_api_secret');
        return apply_filters('waas_settings_api_secret', $value);
    }

    /**
     * Base URL of the provisioning API, without a trailing slash: the WAAS_API_BASE_URL constant
     * or the base URL setting. False when neither is set.
     */
    public function get_api_base_url()
    {
        $value = self::has_constant('API_BASE_URL') ? self::constant('API_BASE_URL') : get_option('waas_api_base_url');
        $value = apply_filters('waas_settings_api_base_url', $value);
        return empty($value) ? false : untrailingslashit($value);
    }

    /** AnyPress's own website assistant, and the API addresses it signs owners in with. */
    public const ANYPRESS_ASSISTANT_URL = 'https://assistant.anypress.io';
    private const ANYPRESS_API_HOSTS = ['api.anypress.io', 'waas-api.otakusolutions.io'];

    /**
     * The website assistant's address (WAAS_ASSISTANT_URL or the setting), without a trailing slash.
     * A storefront on the AnyPress API offers AnyPress's assistant until the setting is saved empty.
     * Empty when the storefront doesn't offer it.
     */
    public function get_assistant_url(): string
    {
        $value = self::has_constant('ASSISTANT_URL') ? self::constant('ASSISTANT_URL') : get_option('waas_assistant_url', $this->default_assistant_url());
        $value = (string) apply_filters('waas_settings_assistant_url', $value);
        return $value === '' ? '' : untrailingslashit(esc_url_raw($value));
    }

    /** AnyPress's assistant for a storefront on the AnyPress API, '' for any other API (it can't sign in there). */
    public function default_assistant_url(): string
    {
        $host = wp_parse_url((string) $this->get_api_base_url(), PHP_URL_HOST);
        return in_array($host, self::ANYPRESS_API_HOSTS, true) ? self::ANYPRESS_ASSISTANT_URL : '';
    }

    /**
     * IPs customers point their domain's A records at. WAAS_DNS_A_RECORDS (constant,
     * a comma-separated string or an array) wins over the setting. Invalid IPs are dropped.
     * @return string[]
     */
    public function get_dns_a_records(): array
    {
        $value = self::has_constant('DNS_A_RECORDS') ? self::constant('DNS_A_RECORDS') : get_option('waas_dns_a_records', '');
        $value = apply_filters('waas_settings_dns_a_records', $value);

        return self::parse_ip_list($value);
    }

    /**
     * Hostname customers point a subdomain's CNAME record at. WAAS_DNS_CNAME_TARGET
     * (constant) wins over the setting. Empty string when not set or not a hostname.
     */
    public function get_dns_cname_target(): string
    {
        $value = self::has_constant('DNS_CNAME_TARGET') ? self::constant('DNS_CNAME_TARGET') : get_option('waas_dns_cname_target', '');
        $value = apply_filters('waas_settings_dns_cname_target', $value);

        return self::sanitize_hostname($value);
    }

    /** @param string|string[]|mixed $value @return string[] */
    public static function parse_ip_list($value): array
    {
        $items = is_array($value) ? $value : preg_split('/[\s,]+/', (string) $value);

        $ips = array_filter(array_map('trim', $items), fn ($ip) => filter_var($ip, FILTER_VALIDATE_IP) !== false);
        return array_values(array_unique($ips));
    }

    /** Sanitizes the A-records setting to a comma-separated list of valid IPs. */
    public static function sanitize_ip_list($value): string
    {
        return implode(', ', self::parse_ip_list($value));
    }

    /** @param mixed $value */
    public static function sanitize_hostname($value): string
    {
        $host = strtolower(rtrim(trim((string) $value), '.'));
        $valid = $host !== ''
            && strlen($host) <= 253
            && strpos($host, '.') !== false
            && filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) !== false;

        return $valid ? $host : '';
    }

    /** True when the API base URL, key and secret are all filled in (saved empty fields don't count). */
    public function check_credentials()
    {
        return !empty($this->get_api_base_url()) && !empty($this->get_api_key()) && !empty($this->get_api_secret());
    }
}
