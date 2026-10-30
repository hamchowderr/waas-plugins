<?php

namespace WaaSHost\Features;

use Exception;
use WaaSHost\Commerce\Commerce;
use WaaSHost\Core\ProvisioningApi;
use WaaSHost\Core\Tenant;

/**
 * What a customer can change on their website: its address (own domain) and the domain its email is sent from.
 * Used by the account page's forms (UserAccountSubscriptionsSettings) and by the account REST API (AccountController),
 * so both behave the same. Callers check first that the signed-in user owns the subscription.
 */
class WebsiteSettings
{
    public const INVALID_DOMAIN_MESSAGE = 'Please enter a valid domain name, like example.com.';
    public const GENERIC_ERROR_MESSAGE = 'That did not work. Please try again later or contact support.';

    private ProvisioningApi $provisioning_api;

    public function __construct(ProvisioningApi $provisioning_api)
    {
        $this->provisioning_api = $provisioning_api;
    }

    /**
     * Subscriptions of the customer that show a website (it has a store, or one is being set up), newest first.
     *
     * @return array<int|string>
     */
    public static function customer_websites(int $user_id): array
    {
        $commerce = Commerce::adapter();
        if ($commerce === null || $user_id === 0) {
            return [];
        }

        return array_values(array_filter($commerce->get_customer_subscription_ids($user_id), fn ($subscription_id) => Tenant::shown_to_customer(
            $commerce->get_subscription_meta($subscription_id, Tenant::TENANT_EXTERNAL_ID_META),
            $commerce->get_subscription_meta($subscription_id, Tenant::TENANT_STATE)
        )));
    }

    /** A domain as the customer typed it, lowercased and trimmed. */
    public static function normalize_domain($domain): string
    {
        return strtolower(trim(sanitize_text_field((string) $domain)));
    }

    /**
     * Makes $domain the website's main address. The old address is removed five minutes later, so visitors are not
     * cut off while DNS catches up. The same address again changes nothing.
     *
     * @return string the website's address
     * @throws WebsiteSettingsError when the domain is not valid (invalid input) or the API refuses or fails
     */
    public function change_address($subscription_id, $domain): string
    {
        $domain = self::normalize_domain($domain);
        if (!UserAccountSubscriptionsSettings::is_valid_hostname($domain)) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- not printed here; every place that shows the message escapes it.
            throw WebsiteSettingsError::invalid_input(self::INVALID_DOMAIN_MESSAGE);
        }

        $commerce = Commerce::require_adapter();
        $external_id = $commerce->get_subscription_meta($subscription_id, Tenant::TENANT_EXTERNAL_ID_META);
        $current_domain = $commerce->get_subscription_meta($subscription_id, Tenant::DOMAIN_NAME_META);
        if ($domain === $current_domain) {
            return $domain;
        }

        try {
            $this->provisioning_api->add_tenant_domain([
                'external_id' => $external_id,
                'domain_name' => $domain,
            ]);
        } catch (Exception $e) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- not printed here; every place that shows the message escapes it.
            throw WebsiteSettingsError::from_api($e);
        }

        $commerce->update_subscription_meta($subscription_id, Tenant::DOMAIN_NAME_META, $domain);
        if ($current_domain) {
            wp_schedule_single_event(time() + 300, 'remove_tenant_old_domain', [$external_id, $current_domain]);
        }

        return $domain;
    }

    /**
     * The website's own sending domain (domainName, status, DNS records), or null while it sends from the platform's
     * address.
     *
     * @throws WebsiteSettingsError when the API can't be reached
     */
    public function get_email_sender($subscription_id)
    {
        try {
            return $this->provisioning_api->get_mail_domain($this->external_id($subscription_id));
        } catch (Exception $e) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- not printed here; every place that shows the message escapes it.
            throw WebsiteSettingsError::from_api($e);
        }
    }

    /**
     * Sends the website's email from no-reply@$domain once its DNS records are verified.
     *
     * @return mixed the new sending domain (domainName, status, DNS records)
     * @throws WebsiteSettingsError when the domain is not valid (invalid input) or the API refuses or fails
     */
    public function set_email_sender($subscription_id, $domain)
    {
        $domain = self::normalize_domain($domain);
        if (!UserAccountSubscriptionsSettings::is_valid_hostname($domain)) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- not printed here; every place that shows the message escapes it.
            throw WebsiteSettingsError::invalid_input(self::INVALID_DOMAIN_MESSAGE);
        }

        try {
            return $this->provisioning_api->set_mail_domain($this->external_id($subscription_id), $domain);
        } catch (Exception $e) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- not printed here; every place that shows the message escapes it.
            throw WebsiteSettingsError::from_api($e);
        }
    }

    /**
     * Moves the website's email back to the platform's address.
     *
     * @throws WebsiteSettingsError when the API refuses or fails
     */
    public function remove_email_sender($subscription_id): void
    {
        try {
            $this->provisioning_api->remove_mail_domain($this->external_id($subscription_id));
        } catch (Exception $e) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- not printed here; every place that shows the message escapes it.
            throw WebsiteSettingsError::from_api($e);
        }
    }

    /**
     * The Domain Connect link that adds the DNS records for $domain at its DNS host, or why there is none when the
     * host could make one (`conflict`, from the API and written for the customer: say, an older DKIM key already on
     * the domain). Null when the host can't (no Domain Connect, or not the platform's template) or the answer is not
     * a safe https link.
     *
     * @param string $service website (the website's address) or email (its sending domain)
     * @return array{url: string|null, dns_provider: string, conflict: string|null}|null
     * @throws WebsiteSettingsError when the API can't be reached
     */
    public function domain_connect_link($subscription_id, string $domain, string $service): ?array
    {
        try {
            $link = $this->provisioning_api->domain_connect($this->external_id($subscription_id), $domain, $service);
        } catch (Exception $e) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- not printed here; every place that shows the message escapes it.
            throw WebsiteSettingsError::from_api($e);
        }
        $provider = is_string($link->dnsProvider ?? null) ? $link->dnsProvider : '';
        $conflict = is_string($link->conflict ?? null) ? trim($link->conflict) : '';
        if ($conflict !== '') {
            return ['url' => null, 'dns_provider' => $provider, 'conflict' => $conflict];
        }
        if (empty($link->supported) || empty($link->url) || !is_string($link->url) || strpos($link->url, 'https://') !== 0) {
            return null;
        }

        return ['url' => $link->url, 'dns_provider' => $provider, 'conflict' => null];
    }

    /** The API's own explanation for a refused request (400), else a generic message. */
    public static function api_error_message(Exception $e): string
    {
        if (preg_match('/^Failed: 400 - (.*)$/s', $e->getMessage(), $m)) {
            $body = json_decode($m[1]);
            if (isset($body->message) && is_string($body->message)) {
                return $body->message;
            }
        }
        return self::GENERIC_ERROR_MESSAGE;
    }

    private function external_id($subscription_id): string
    {
        $commerce = Commerce::adapter();

        return $commerce === null ? '' : (string) $commerce->get_subscription_meta($subscription_id, Tenant::TENANT_EXTERNAL_ID_META);
    }
}
