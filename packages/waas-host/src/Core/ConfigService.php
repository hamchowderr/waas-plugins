<?php

namespace WaaSHost\Core;

// Exit if accessed directly
if ( !defined( 'ABSPATH' ) )
    exit;

class ConfigService
{
    public function get_api_key()
    {
        $value = defined('WPCS_API_KEY') ? WPCS_API_KEY : get_option('wpcs_credentials_api_key_setting');
        return apply_filters('wpcs_settings_api_key', $value);
    }

    public function get_api_secret()
    {
        $value = defined('WPCS_API_SECRET') ? WPCS_API_SECRET : get_option('wpcs_credentials_api_secret_setting');
        return apply_filters('wpcs_settings_api_secret', $value);
    }

    public function get_api_region()
    {
        $value = defined('WPCS_API_REGION') ? WPCS_API_REGION : get_option('wpcs_credentials_region_setting');
        return apply_filters('wpcs_settings_api_region', $value);
    }

    /**
     * Base URL of the provisioning API, without a trailing slash.
     * WPCS_API_BASE_URL (constant) or the base URL setting wins; otherwise the
     * legacy WPCS host for the configured region. False when neither is set.
     */
    public function get_api_base_url()
    {
        $value = defined('WPCS_API_BASE_URL') ? WPCS_API_BASE_URL : get_option('wpcs_credentials_api_base_url_setting');
        if (empty($value)) {
            $region = $this->get_api_region();
            $value = empty($region) ? false : 'https://api.' . $region . '.wpcs.io';
        }

        $value = apply_filters('wpcs_settings_api_base_url', $value);
        return empty($value) ? false : untrailingslashit($value);
    }

    /**
     * IPs customers point their domain's A records at. WPCS_DNS_A_RECORDS (constant,
     * a comma-separated string or an array) wins over the setting. Invalid IPs are dropped.
     * @return string[]
     */
    public function get_dns_a_records(): array
    {
        $value = defined('WPCS_DNS_A_RECORDS') ? WPCS_DNS_A_RECORDS : get_option('wpcs_dns_a_records_setting', '');
        $value = apply_filters('wpcs_settings_dns_a_records', $value);

        return self::parse_ip_list($value);
    }

    /**
     * Hostname customers point a subdomain's CNAME record at. WPCS_DNS_CNAME_TARGET
     * (constant) wins over the setting. Empty string when not set or not a hostname.
     */
    public function get_dns_cname_target(): string
    {
        $value = defined('WPCS_DNS_CNAME_TARGET') ? WPCS_DNS_CNAME_TARGET : get_option('wpcs_dns_cname_target_setting', '');
        $value = apply_filters('wpcs_settings_dns_cname_target', $value);

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

    public function check_credentials()
    {
        $base_url_exists = $this->get_api_base_url() !== false;
        $key_exists = $this->get_api_key() !== false;
        $secret_exists = $this->get_api_secret() !== false;
        return $base_url_exists && $key_exists && $secret_exists;
    }
}
