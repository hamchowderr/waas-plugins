<?php

namespace AnyPressHost\Core;

// Exit if accessed directly
if (!defined('ABSPATH'))
    exit;

class ConfigService
{
    /** wp-config constants are named ANYPRESS_<NAME>; null when it is not defined. */
    public static function constant(string $name)
    {
        return defined('ANYPRESS_' . $name) ? constant('ANYPRESS_' . $name) : null;
    }

    public static function has_constant(string $name): bool
    {
        return defined('ANYPRESS_' . $name);
    }

    public function get_api_key()
    {
        $value = self::has_constant('API_KEY') ? self::constant('API_KEY') : get_option('anypress_api_key');
        return apply_filters('anypress_settings_api_key', $value);
    }

    public function get_api_secret()
    {
        $value = self::has_constant('API_SECRET') ? self::constant('API_SECRET') : get_option('anypress_api_secret');
        return apply_filters('anypress_settings_api_secret', $value);
    }

    /**
     * Base URL of the provisioning API, without a trailing slash: the ANYPRESS_API_BASE_URL constant
     * or the base URL setting. False when neither is set.
     */
    /** The AnyPress API: where a storefront connects unless ANYPRESS_API_BASE_URL or the setting names another. */
    public const ANYPRESS_API_URL = 'https://api.anypress.io';

    public function get_api_base_url()
    {
        // An empty setting means the AnyPress API, so a key and secret alone connect.
        $value = self::has_constant('API_BASE_URL') ? self::constant('API_BASE_URL') : (get_option('anypress_api_base_url') ?: self::ANYPRESS_API_URL);
        $value = apply_filters('anypress_settings_api_base_url', $value);
        return empty($value) ? false : untrailingslashit($value);
    }

    /** AnyPress's own website assistant, and the API addresses it signs owners in with. */
    /** The assistant setting's value for "on each customer's own website, at /.assistant". */
    public const ASSISTANT_ON_WEBSITE = 'website';
    /** The old shared address. A saved value of it now means ASSISTANT_ON_WEBSITE. */
    private const OLD_ANYPRESS_ASSISTANT_URL = 'https://assistant.anypress.io';
    private const ANYPRESS_API_HOSTS = ['api.anypress.io'];

    /**
     * The website assistant (ANYPRESS_ASSISTANT_URL or the setting): ASSISTANT_ON_WEBSITE for the assistant on
     * each customer's own website, or another assistant's address without a trailing slash.
     * A storefront on the AnyPress API offers the assistant on the website until the setting is saved empty.
     * Empty when the storefront doesn't offer it.
     */
    public function get_assistant_url(): string
    {
        $value = self::has_constant('ASSISTANT_URL') ? self::constant('ASSISTANT_URL') : get_option('anypress_assistant_url', $this->default_assistant_url());
        return self::sanitize_assistant_url((string) apply_filters('anypress_settings_assistant_url', $value));
    }

    /** The assistant on the website for a storefront on the AnyPress API, '' for any other API (it can't sign in there). */
    public function default_assistant_url(): string
    {
        $host = wp_parse_url((string) $this->get_api_base_url(), PHP_URL_HOST);
        return in_array($host, self::ANYPRESS_API_HOSTS, true) ? self::ASSISTANT_ON_WEBSITE : '';
    }

    /** '' (off), ASSISTANT_ON_WEBSITE, or an assistant's address without a trailing slash. */
    public static function sanitize_assistant_url($value): string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return '';
        }
        if (strtolower($value) === self::ASSISTANT_ON_WEBSITE || untrailingslashit(strtolower($value)) === self::OLD_ANYPRESS_ASSISTANT_URL) {
            return self::ASSISTANT_ON_WEBSITE;
        }
        return untrailingslashit(esc_url_raw($value));
    }

    /**
     * IPs customers point their domain's A records at. ANYPRESS_DNS_A_RECORDS (constant,
     * a comma-separated string or an array) wins over the setting. Invalid IPs are dropped.
     * @return string[]
     */
    public function get_dns_a_records(): array
    {
        $value = self::has_constant('DNS_A_RECORDS') ? self::constant('DNS_A_RECORDS') : get_option('anypress_dns_a_records', '');
        $value = apply_filters('anypress_settings_dns_a_records', $value);

        return self::parse_ip_list($value);
    }

    /**
     * Hostname customers point a subdomain's CNAME record at. ANYPRESS_DNS_CNAME_TARGET
     * (constant) wins over the setting. Empty string when not set or not a hostname.
     */
    public function get_dns_cname_target(): string
    {
        $value = self::has_constant('DNS_CNAME_TARGET') ? self::constant('DNS_CNAME_TARGET') : get_option('anypress_dns_cname_target', '');
        $value = apply_filters('anypress_settings_dns_cname_target', $value);

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
