<?php

namespace WaaSHost\Features;

use WaaSHost\Commerce\Commerce;
use WaaSHost\Core\Exceptions\InvalidDomainException;
use WaaSHost\Core\WPCSService;
use WaaSHost\Core\WPCSTenant;

/**
 * A website name that turns out to be taken when the store is created. Checkout accepts a name it could not check
 * with the API (the API was down), so the create job can still find it taken. The store is then parked as "Needs
 * New Name": the create is not retried, the admin and the customer are told once, and the customer picks a new
 * name on their account page. That name gets the same checks as at checkout, and the create is queued again.
 */
class NameConflicts
{
    public const CHOOSE_ACTION = 'wpcs_choose_website_name';
    public const RESULT_ARG = 'wpcs_website_name';

    private WPCSService $wpcs_service;
    private StoreJobs $jobs;

    public function __construct(WPCSService $wpcs_service, StoreJobs $jobs)
    {
        $this->wpcs_service = $wpcs_service;
        $this->jobs = $jobs;

        add_action('admin_post_' . self::CHOOSE_ACTION, [$this, 'handle_choose_request']);
        add_action('admin_notices', [$this, 'show_admin_notice']);
    }

    /**
     * The create job found the name taken: stop, remember what to create, tell the admin and the customer.
     *
     * @param array $store the create job's arguments (external_id, request, product_role)
     */
    public function park($subscription_id, array $store, string $error): void
    {
        $commerce = Commerce::require_adapter();
        $name = (string) ($store['request']['name'] ?? '');

        $commerce->update_subscription_meta($subscription_id, WPCSTenant::WPCS_NAME_CONFLICT_META, [
            'store' => $store,
            'name' => $name,
            'domain' => (string) ($store['request']['custom_domain_name'] ?? ''),
            'error' => $error,
            'at' => time(),
        ]);
        // The domain isn't this store's: don't let the local duplicate check hold it.
        $commerce->update_subscription_meta($subscription_id, WPCSTenant::WPCS_DOMAIN_NAME_META, '');
        $this->jobs->set_status($subscription_id, WPCSTenant::NEEDS_NEW_NAME);

        $this->email_admin($subscription_id, $name, $error);
        $this->email_customer($subscription_id, $name);

        do_action('wpcs_tenant_name_conflict', $subscription_id, $name);
    }

    /**
     * The customer's new name: checked like at checkout, except that here the API must answer (the customer is
     * waiting for the result, and a second conflict would only repeat this).
     *
     * @return string|null the message for the customer, or null when the store was queued with the new name
     */
    public function choose_new_name($subscription_id, string $website_name): ?string
    {
        $commerce = Commerce::require_adapter();
        $conflict = $commerce->get_subscription_meta($subscription_id, WPCSTenant::WPCS_NAME_CONFLICT_META);
        if (!is_array($conflict) || $commerce->get_subscription_meta($subscription_id, WPCSTenant::WPCS_TENANT_STATE) !== WPCSTenant::NEEDS_NEW_NAME) {
            return __('This website does not need a new name.', WPCS_WAAS_HOST_TEXTDOMAIN);
        }

        $website_name = trim(sanitize_text_field($website_name));
        $label = sanitize_title_with_dashes(remove_accents($website_name));
        if ($label === '' || strlen($label) > 63) {
            return __('Please use a website name of 1 to 63 letters, digits and dashes.', WPCS_WAAS_HOST_TEXTDOMAIN);
        }

        $store = $conflict['store'];
        $root_domain = (string) get_option('wpcs_host_settings_root_domain', '');
        $domain_name = $root_domain === '' ? '' : $label . '.' . $root_domain;

        if ($domain_name !== '') {
            if ($commerce->is_domain_taken($domain_name)) {
                return __('Website name is already taken! Please use a different one.', WPCS_WAAS_HOST_TEXTDOMAIN);
            }
            try {
                if (!$this->wpcs_service->domain_available($domain_name)) {
                    return __('Website name is already taken! Please use a different one.', WPCS_WAAS_HOST_TEXTDOMAIN);
                }
            } catch (InvalidDomainException $e) {
                return __('The resulting domain is not valid.', WPCS_WAAS_HOST_TEXTDOMAIN);
            } catch (\Throwable $e) {
                return __('We could not check the website name right now. Please try again in a minute.', WPCS_WAAS_HOST_TEXTDOMAIN);
            }
            $store['request']['custom_domain_name'] = $domain_name;
        }

        $store['request']['name'] = $website_name;
        // The earlier create never succeeded (it was refused), so a fresh externalId is safe.
        $store['external_id'] = wp_generate_uuid4();

        $commerce->update_subscription_meta($subscription_id, WPCSTenant::WPCS_NAME_CONFLICT_META, '');
        $commerce->update_subscription_meta($subscription_id, WPCSTenant::WPCS_DOMAIN_NAME_META, $domain_name);
        $this->jobs->enqueue($subscription_id, 'create', $store);
        $this->jobs->set_status($subscription_id, WPCSTenant::QUEUED);

        return null;
    }

    /** The customer's page with the form: the subscription page, unless the cart shows websites on a page of their own. */
    public static function form_url($subscription_id): string
    {
        $url = Commerce::require_adapter()->get_subscription_url($subscription_id);

        return (string) apply_filters('wpcs_choose_website_name_url', $url, $subscription_id);
    }

    /** The customer's form, on their subscription or website page. */
    public static function render_form($subscription_id): void
    {
        $result = isset($_GET[self::RESULT_ARG]) ? sanitize_text_field(wp_unslash($_GET[self::RESULT_ARG])) : '';
        ?>
        <form class="wpcs-choose-website-name" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <p><?php esc_html_e('The website name you picked was taken by someone else just before we could set up your website. Choose another name and we will set it up right away.', WPCS_WAAS_HOST_TEXTDOMAIN); ?></p>
            <?php if ($result !== '' && $result !== 'ok') { ?>
                <p class="wpcs-choose-website-name-error"><?php echo esc_html($result); ?></p>
            <?php } ?>
            <input type="hidden" name="action" value="<?php echo esc_attr(self::CHOOSE_ACTION); ?>" />
            <input type="hidden" name="subscription_id" value="<?php echo esc_attr((string) $subscription_id); ?>" />
            <?php wp_nonce_field(self::CHOOSE_ACTION . '_' . $subscription_id); ?>
            <label><?php esc_html_e('New website name', WPCS_WAAS_HOST_TEXTDOMAIN); ?>
                <input type="text" name="website_name" required maxlength="63" />
            </label>
            <button type="submit" class="button"><?php esc_html_e('Use this name', WPCS_WAAS_HOST_TEXTDOMAIN); ?></button>
        </form>
        <?php
    }

    public function handle_choose_request(): void
    {
        $subscription_id = isset($_POST['subscription_id']) ? sanitize_text_field(wp_unslash($_POST['subscription_id'])) : '';
        $subscription_id = ctype_digit($subscription_id) ? (int) $subscription_id : $subscription_id;
        check_admin_referer(self::CHOOSE_ACTION . '_' . $subscription_id);

        $commerce = Commerce::require_adapter();
        $user_id = get_current_user_id();
        if ($user_id === 0 || $commerce->get_customer_id($subscription_id) !== $user_id) {
            wp_die(esc_html__('You are not allowed to do this.', WPCS_WAAS_HOST_TEXTDOMAIN), 403);
        }

        $error = $this->choose_new_name($subscription_id, isset($_POST['website_name']) ? (string) wp_unslash($_POST['website_name']) : '');

        wp_safe_redirect(add_query_arg(self::RESULT_ARG, rawurlencode($error ?? 'ok'), self::form_url($subscription_id)));
        exit;
    }

    public function show_admin_notice(): void
    {
        $commerce = Commerce::adapter();
        if ($commerce === null || !current_user_can('manage_options')) {
            return;
        }

        $waiting = $commerce->find_subscriptions_by_meta(WPCSTenant::WPCS_NAME_CONFLICT_META, ['']);
        if (!$waiting) {
            return;
        }

        echo '<div class="notice notice-warning"><p><strong>' . esc_html(sprintf(
            _n('%d website is waiting for its customer to choose a new name.', '%d websites are waiting for their customers to choose a new name.', count($waiting), WPCS_WAAS_HOST_TEXTDOMAIN),
            count($waiting)
        )) . '</strong></p><ul>';
        foreach ($waiting as $subscription_id => $conflict) {
            echo '<li>' . esc_html(sprintf('Subscription #%s: "%s" was taken (%s). The customer was emailed.', $subscription_id, $conflict['name'] ?? '', $conflict['domain'] ?? '')) . '</li>';
        }
        echo '</ul></div>';
    }

    private function email_admin($subscription_id, string $name, string $error): void
    {
        $subject = sprintf(__('[%s] A website name was taken; the customer has to choose a new one', WPCS_WAAS_HOST_TEXTDOMAIN), wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES));
        $message = sprintf(
            __("The website name \"%1\$s\" for subscription #%2\$s was taken when the store was created (the name was accepted at checkout while the provisioning API could not be reached).\n\nAPI answer: %3\$s\n\nThe customer has been emailed a link to choose a new name. Nothing else is needed unless they contact you.\n%4\$s", WPCS_WAAS_HOST_TEXTDOMAIN),
            $name,
            $subscription_id,
            $error,
            admin_url()
        );

        if (!wp_mail((string) get_option('admin_email'), $subject, $message)) {
            error_log('WaaS Host: could not email the admin about the name conflict for subscription ' . $subscription_id);
        }
    }

    private function email_customer($subscription_id, string $name): void
    {
        $commerce = Commerce::require_adapter();
        $to = $commerce->get_customer_email($subscription_id);
        if ($to === '') {
            return;
        }

        $subject = sprintf(__('[%s] Please choose a new name for your website', WPCS_WAAS_HOST_TEXTDOMAIN), wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES));
        $message = sprintf(
            __("Thank you for your order. The website name \"%1\$s\" was taken by someone else just before we could set up your website.\n\nPlease choose another name here, and we will set up your website right away:\n%2\$s", WPCS_WAAS_HOST_TEXTDOMAIN),
            $name,
            self::form_url($subscription_id)
        );

        if (!wp_mail($to, $subject, $message)) {
            error_log('WaaS Host: could not email the customer about the name conflict for subscription ' . $subscription_id);
        }
    }
}
